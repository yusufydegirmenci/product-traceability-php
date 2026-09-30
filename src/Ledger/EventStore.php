<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use PDO;

/**
 * Append-only, hash-chained custody event log — the technical core of the
 * whole traceability system.
 *
 * Two independent layers of protection work together here:
 *
 *   1) DB-ENFORCED APPEND-ONLY (see schema.mysql.sql): the epcis_events table
 *      has BEFORE UPDATE / BEFORE DELETE triggers that abort the statement.
 *      No application role, not even an admin account, can silently edit or
 *      remove a row through normal SQL.
 *
 *   2) HASH CHAIN (this class): every event stores an HMAC-SHA256 of its own
 *      content PLUS the previous event's hash. If anyone ever bypasses layer 1
 *      (e.g. direct file-level DB access, a restore from a doctored backup),
 *      verifyChain() will detect it — the moment one event is altered, every
 *      hash after it stops matching.
 *
 * Corrections are never done by editing a past event. If a fact was wrong,
 * append a new event that supersedes it (GS1 EPCIS calls this an
 * "errorDeclaration" pattern) — the mistake AND its correction both stay
 * permanently visible in the history.
 *
 * RT8.4 DURUM 3 (Kriptografik Split-Brain) SONRASI EKLENDİ: artık TEK bir
 * statik anahtar değil, bir ANAHTAR HALKASI (keyRing) tutuluyor. Her event
 * kendi key_version'ını taşır — verifyChain() her satırı KENDİ dönemindeki
 * anahtarla doğrular (anahtar rotasyonundan sonra da eski kayıtlar hâlâ
 * doğrulanabilir kalır). AYRICA verifyKeyLineage() bunun ÖTESİNDE bir şey
 * kontrol eder: zincirdeki key_version'lar HİÇ GERİYE gitmemeli — teknik
 * olarak geçerli bir hash'e sahip ama "yeni anahtar döneminden SONRA eski
 * anahtarla imzalanmış" bir satır, hash kontrolünü geçer ama SEMANTİK
 * olarak imkansızdır (anahtar rotasyonu geri alınamaz bir olaydır).
 */
final class EventStore
{
    private const GENESIS = 'GENESIS';
    private int $maxAppendRetryAttempts;

    /** @param array<int,string> $keyRing key_version => en az 32 byte'lık HMAC anahtarı */
    public function __construct(
        private PDO $pdo,
        private array $keyRing,
        private int $activeKeyVersion,
        ?int $maxAppendRetryAttempts = null
    ) {
        $this->maxAppendRetryAttempts = $maxAppendRetryAttempts
            ?? (is_file(dirname(__DIR__, 2) . '/config/warehouse.php')
                ? (require dirname(__DIR__, 2) . '/config/warehouse.php')['event_store']['max_append_retry_attempts']
                : 5);
        if (!isset($keyRing[$activeKeyVersion])) {
            throw new \InvalidArgumentException("activeKeyVersion ({$activeKeyVersion}) keyRing içinde yok.");
        }
        foreach ($keyRing as $version => $key) {
            if (strlen($key) < 32) {
                throw new \InvalidArgumentException(
                    "key_version {$version}: HMAC anahtarı en az 32 byte olmalı — random_bytes(32) ile üretilip "
                    . 'bir secrets manager\'da saklanmalı, asla kaynak koduna yazılmamalı.'
                );
            }
        }
    }

    public function appendEvent(
        int $entityId,
        string $bizStep,        // e.g. commissioning | packing | shipping | receiving | decommissioning | destroyed
        string $disposition,    // e.g. active | in_transit | sold | returned | recalled | destroyed
        ?int $actorId,
        ?int $deviceId,
        ?int $locationId,
        ?string $relatedOrderRef,
        array $metadata = [],
        ?string $eventTimeOverride = null, // yalnızca test/backfill amaçlı — production akışında hep null bırakılmalı
        ?string $idempotencyKey = null // v1.1: el terminali retry fırtınasına karşı — UUID v4, aynı fiziksel işlemin TEKRARI olduğunu işaretler
    ): array {
        // v1.1 — ÇEVRİMDIŞI İŞLEM IDEMPOTENCY KONTROLÜ: aynı idempotency_key
        // ile DAHA ÖNCE bir event zaten kaydedilmişse, YENİ bir kayıt
        // OLUŞTURMADAN mevcut olanı döndür. Bu, ağ kopması sonrası el
        // terminalinin "gönderdim mi, gönderemedim mi" belirsizliğiyle
        // AYNI isteği tekrar tekrar göndermesi (retry storm) durumunda
        // stokta mükerrer kayıt/çakışma oluşmasını engeller.
        if ($idempotencyKey !== null) {
            $existing = $this->pdo->prepare('SELECT id, event_hash, event_time FROM epcis_events WHERE idempotency_key = :key');
            $existing->execute([':key' => $idempotencyKey]);
            $row = $existing->fetch(\PDO::FETCH_ASSOC);
            if ($row !== false) {
                return ['id' => (int) $row['id'], 'event_hash' => $row['event_hash'], 'event_time' => $row['event_time'], 'idempotent_replay' => true];
            }
        }

        // KIDEMLI İNCELEME BULGUSU (ampirik olarak kanıtlandı — bkz.
        // chain_race_test.php): AYNI entity_id'ye eşzamanlı iki yazma,
        // İKİSİ DE aynı prev_hash'i okuyup zinciri ÇATALLAYABİLİR. Bunu
        // ÖNLEMEK için "oku, kilitle, yaz" yerine "dene, çakışırsa
        // TEKRAR dene" (optimistic concurrency + retry) deseni kullanılıyor:
        // (entity_id, prev_hash) üzerindeki UNIQUE kısıt, ikinci eşzamanlı
        // yazmayı DB SEVİYESİNDE reddeder — biz bunu yakalayıp prev_hash'i
        // YENİDEN okuyup tekrar deniyoruz.
        // KIDEMLI İNCELEME BULGUSU (ampirik olarak kanıtlandı — bkz.
        // tests/Integration/EventStoreConcurrentAppendTest.php): AYNI
        // entity_id'ye eşzamanlı iki yazma, İKİSİ DE aynı prev_hash'i
        // okuyup zinciri ÇATALLAYABİLİR. Bunu ÖNLEMEK için "oku, kilitle,
        // yaz" yerine "dene, çakışırsa TEKRAR dene" (optimistic
        // concurrency + EXPONENTIAL BACKOFF retry) deseni kullanılıyor:
        // (entity_id, prev_hash) üzerindeki UNIQUE kısıt, ikinci eşzamanlı
        // yazmayı DB SEVİYESİNDE reddeder — biz bunu yakalayıp prev_hash'i
        // YENİDEN okuyup tekrar deniyoruz. Sınır aşılırsa
        // ConcurrencyConflictException fırlatılır (sessizce yutulmaz).
        $maxAttempts = $this->maxAppendRetryAttempts;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $this->attemptInsert($entityId, $bizStep, $disposition, $actorId, $deviceId, $locationId, $relatedOrderRef, $metadata, $eventTimeOverride, $idempotencyKey);
            } catch (\PDOException $e) {
                $isUniqueViolation = str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'Duplicate entry');
                if (!$isUniqueViolation) {
                    throw $e; // başka bir DB hatası — retry MANTIKSIZ, olduğu gibi fırlat
                }
                if ($attempt === $maxAttempts) {
                    throw new ConcurrencyConflictException($entityId, $attempt, $bizStep);
                }
                // Exponential backoff + jitter: 2^attempt ms taban, ±%50 rastgele
                // sapma ("thundering herd"i — tüm bekleyenlerin AYNI anda tekrar
                // denemesini — önlemek için).
                $baseMs = (2 ** $attempt) * 5; // attempt=1→10ms, 2→20ms, 3→40ms, 4→80ms...
                $jitterMs = $baseMs * (random_int(-50, 50) / 100);
                usleep((int) (($baseMs + $jitterMs) * 1000));
            }
        }
        throw new \RuntimeException('appendEvent(): beklenmeyen kod yolu.'); // buraya asla ulaşılmamalı
    }

    private function attemptInsert(
        int $entityId, string $bizStep, string $disposition, ?int $actorId, ?int $deviceId,
        ?int $locationId, ?string $relatedOrderRef, array $metadata, ?string $eventTimeOverride, ?string $idempotencyKey
    ): array {
        $prevHash = $this->getLastHash($entityId);
        $eventTime = $eventTimeOverride ?? (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s.u');
        $keyVersion = $this->activeKeyVersion;

        $payload = [
            'actor_id' => $actorId,
            'biz_step' => $bizStep,
            'device_id' => $deviceId,
            'disposition' => $disposition,
            'entity_id' => $entityId,
            'event_time' => $eventTime,
            'key_version' => $keyVersion,
            'location_id' => $locationId,
            'metadata' => $metadata,
            'prev_hash' => $prevHash,
            'related_order_ref' => $relatedOrderRef,
        ];
        $eventHash = $this->computeHash($payload, $keyVersion);

        $stmt = $this->pdo->prepare(
            'INSERT INTO epcis_events
                (entity_id, biz_step, disposition, event_time, actor_id, device_id,
                 read_point_location_id, related_order_ref, metadata, prev_hash, event_hash, key_version, idempotency_key, created_at)
             VALUES
                (:entity_id, :biz_step, :disposition, :event_time, :actor_id, :device_id,
                 :location_id, :related_order_ref, :metadata, :prev_hash, :event_hash, :key_version, :idempotency_key, :created_at)'
        );
        $stmt->execute([
            ':entity_id' => $entityId,
            ':biz_step' => $bizStep,
            ':disposition' => $disposition,
            ':event_time' => $eventTime,
            ':actor_id' => $actorId,
            ':device_id' => $deviceId,
            ':location_id' => $locationId,
            ':related_order_ref' => $relatedOrderRef,
            ':metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            ':prev_hash' => $prevHash,
            ':event_hash' => $eventHash,
            ':key_version' => $keyVersion,
            ':idempotency_key' => $idempotencyKey,
            ':created_at' => $eventTime,
        ]);

        return ['id' => (int) $this->pdo->lastInsertId(), 'event_hash' => $eventHash, 'event_time' => $eventTime, 'idempotent_replay' => false];
    }

    /**
     * SADECE RED TEAM/test amaçlıdır — gerçek bir saldırganın "eski, iptal
     * edilmiş bir anahtarı ele geçirip onunla imzalamış gibi" davranmasını
     * simüle eder. Normal üretim akışında bu metod ASLA çağrılmamalı;
     * appendEvent() her zaman activeKeyVersion ile imzalar.
     */
    public function appendEventForSimulatedKeyAttack(
        int $entityId, string $bizStep, string $disposition, ?int $actorId,
        ?int $deviceId, ?int $locationId, ?string $relatedOrderRef, array $metadata,
        string $eventTimeOverride, int $forcedKeyVersion, ?string $createdAtOverride = null
    ): array {
        if (!isset($this->keyRing[$forcedKeyVersion])) {
            throw new \InvalidArgumentException("key_version {$forcedKeyVersion} keyRing'de yok — simülasyon bile gerçek bir anahtar gerektirir.");
        }
        $prevHash = $this->getLastHash($entityId);
        $payload = [
            'actor_id' => $actorId, 'biz_step' => $bizStep, 'device_id' => $deviceId,
            'disposition' => $disposition, 'entity_id' => $entityId, 'event_time' => $eventTimeOverride,
            'key_version' => $forcedKeyVersion, 'location_id' => $locationId, 'metadata' => $metadata,
            'prev_hash' => $prevHash, 'related_order_ref' => $relatedOrderRef,
        ];
        $eventHash = $this->computeHash($payload, $forcedKeyVersion);
        $stmt = $this->pdo->prepare(
            'INSERT INTO epcis_events
                (entity_id, biz_step, disposition, event_time, actor_id, device_id,
                 read_point_location_id, related_order_ref, metadata, prev_hash, event_hash, key_version, created_at)
             VALUES
                (:entity_id, :biz_step, :disposition, :event_time, :actor_id, :device_id,
                 :location_id, :related_order_ref, :metadata, :prev_hash, :event_hash, :key_version, :created_at)'
        );
        $stmt->execute([
            ':entity_id' => $entityId, ':biz_step' => $bizStep, ':disposition' => $disposition,
            ':event_time' => $eventTimeOverride, ':actor_id' => $actorId, ':device_id' => $deviceId,
            ':location_id' => $locationId, ':related_order_ref' => $relatedOrderRef,
            ':metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE), ':prev_hash' => $prevHash,
            ':event_hash' => $eventHash, ':key_version' => $forcedKeyVersion,
            ':created_at' => $createdAtOverride ?? $eventTimeOverride,
        ]);
        return ['id' => (int) $this->pdo->lastInsertId(), 'event_hash' => $eventHash];
    }

    public function history(int $entityId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM epcis_events WHERE entity_id = :id ORDER BY id ASC');
        $stmt->execute([':id' => $entityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Recomputes the entire hash chain for one entity from scratch and confirms
     * every stored hash still matches what it should be. Returns true only if
     * NOTHING in the history has ever been altered.
     */
    public function verifyChain(int $entityId): bool
    {
        $expectedPrev = self::GENESIS;

        foreach ($this->history($entityId) as $row) {
            if ($row['prev_hash'] !== $expectedPrev) {
                return false;
            }

            $rowKeyVersion = $row['key_version'] !== null ? (int) $row['key_version'] : 1;
            if (!isset($this->keyRing[$rowKeyVersion])) {
                return false; // bu sürüm anahtarı elimizde yoksa doğrulanamaz — güvenli tarafta kal
            }

            $payload = [
                'actor_id' => $row['actor_id'] !== null ? (int) $row['actor_id'] : null,
                'biz_step' => $row['biz_step'],
                'device_id' => $row['device_id'] !== null ? (int) $row['device_id'] : null,
                'disposition' => $row['disposition'],
                'entity_id' => (int) $row['entity_id'],
                'event_time' => $row['event_time'],
                'key_version' => $rowKeyVersion,
                'location_id' => $row['read_point_location_id'] !== null ? (int) $row['read_point_location_id'] : null,
                'metadata' => json_decode($row['metadata'], true) ?? [],
                'prev_hash' => $row['prev_hash'],
                'related_order_ref' => $row['related_order_ref'],
            ];

            if (!hash_equals($this->computeHash($payload, $rowKeyVersion), $row['event_hash'])) {
                return false;
            }

            $expectedPrev = $row['event_hash'];
        }

        return true;
    }

    /**
     * RT8.4 DURUM 3 — Kriptografik Split-Brain'e karşı. verifyChain() SADECE
     * her satırın KENDİ döneminin anahtarıyla tutarlı olup olmadığına bakar
     * — bu, "hash teknik olarak geçerli mi" sorusudur. Bu metod FARKLI bir
     * soruyu cevaplar: "anahtar rotasyon SIRASI mantıken mümkün mü?" Bir
     * anahtar rotasyonu GERİ ALINAMAZ bir olaydır — zincirde key_version
     * hiçbir zaman GERİYE gitmemelidir. Geriye gitme, teknik olarak geçerli
     * bir hash'e sahip olsa bile SEMANTİK bir imkansızlıktır (eski, iptal
     * edilmiş bir anahtarın rotasyondan SONRA hâlâ kullanılabildiği anlamına
     * gelir — anahtar güvenliği tamamen çökmüş demektir).
     */
    public function verifyKeyLineage(int $entityId): array
    {
        $maxSeenVersion = 0;
        foreach ($this->history($entityId) as $row) {
            $v = $row['key_version'] !== null ? (int) $row['key_version'] : 1;
            if ($v < $maxSeenVersion) {
                return [
                    'consistent' => false,
                    'violation_at_event_id' => (int) $row['id'],
                    'detail' => "Event #{$row['id']}, key_version={$v} ile imzalanmış — ama zincirde daha önce zaten "
                        . "key_version={$maxSeenVersion} görülmüştü. Anahtar rotasyonu GERİ GİDEMEZ — bu, eski/iptal "
                        . 'edilmiş bir anahtarın rotasyon SONRASI hâlâ kullanılabildiğini gösterir (Split-Brain).',
                ];
            }
            $maxSeenVersion = max($maxSeenVersion, $v);
        }
        return ['consistent' => true, 'violation_at_event_id' => null, 'detail' => 'Anahtar sürümleri zincir boyunca hep aynı veya artan — rotasyon sırası mantıken tutarlı.'];
    }

    public function activateKeyVersion(int $keyVersion, ?string $atTime = null): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO key_rotation_log (key_version, activated_at) VALUES (:v, :t)');
        $stmt->execute([':v' => $keyVersion, ':t' => $atTime ?? (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
    }

    public function retireKeyVersion(int $keyVersion, ?string $atTime = null): void
    {
        $stmt = $this->pdo->prepare('UPDATE key_rotation_log SET retired_at = :t WHERE key_version = :v');
        $stmt->execute([':t' => $atTime ?? (new \DateTimeImmutable())->format('Y-m-d H:i:s'), ':v' => $keyVersion]);
    }

    /**
     * RT8.5 DARBE 3 — ANCHOR PROOF. verifyKeyLineage()'ın YEREL (tek entity'nin
     * kendi sırasına bakan) sınırını aşar: bir event'in `created_at`'i, o
     * event'in taşıdığı key_version SİSTEM GENELİNDE emekliye ayrıldıktan
     * (retired_at) SONRAYSA — bu entity'nin kendi zinciri içinde hiçbir
     * sıra ihlali olmasa BİLE — sistem çapında bağımsız bir kanıt bu event'in
     * imkansız olduğunu gösterir.
     */
    public function verifyAnchorProof(int $entityId): array
    {
        $rotationStmt = $this->pdo->query('SELECT key_version, retired_at FROM key_rotation_log WHERE retired_at IS NOT NULL');
        $retiredAt = [];
        foreach ($rotationStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $retiredAt[(int) $row['key_version']] = $row['retired_at'];
        }

        foreach ($this->history($entityId) as $row) {
            $v = $row['key_version'] !== null ? (int) $row['key_version'] : 1;
            if (isset($retiredAt[$v]) && $row['created_at'] > $retiredAt[$v]) {
                return [
                    'consistent' => false,
                    'violation_at_event_id' => (int) $row['id'],
                    'detail' => "Event #{$row['id']} key_version={$v} ile imzalı, created_at={$row['created_at']}. Ama "
                        . "key_version={$v} SİSTEM GENELİNDE {$retiredAt[$v]}'de emekliye ayrılmış — bu event, o "
                        . 'anahtar artık geçerli olmamasına RAĞMEN eklenmiş (Anchor Proof ihlali — Semantic Poisoning).',
                ];
            }
        }
        return ['consistent' => true, 'violation_at_event_id' => null, 'detail' => 'Hiçbir event, kendi anahtarının sistem-geneli emeklilik tarihinden sonra eklenmemiş.'];
    }

    private function getLastHash(int $entityId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT event_hash FROM epcis_events WHERE entity_id = :id ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':id' => $entityId]);
        $hash = $stmt->fetchColumn();
        return $hash !== false ? $hash : self::GENESIS;
    }

    /** Fixed key order (ksort) so the same logical event always hashes identically. */
    private function computeHash(array $payload, int $keyVersion): string
    {
        ksort($payload);
        $canonical = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash_hmac('sha256', $canonical, $this->keyRing[$keyVersion]);
    }
}
