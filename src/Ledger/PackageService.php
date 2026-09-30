<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use Traceability\Risk\RiskScorer;
use PDO;


/**
 * Depo görevlisinin yaşadığı iki somut sorunu çözer:
 *
 *   1) "A siparişine yapıştırılması gereken kargo etiketi B'ye,
 *      B'ninki A'ya yapıştırılıyor" — bunun kök nedeni, koli ile
 *      etiketin birbirinden BAĞIMSIZ, önceden basılmış iki ayrı nesne
 *      olması. Çözüm: openPackage() ile koli açılır, ürünler o kolinin
 *      ID'sine bağlı olarak eklenir, etiket EN SON, kutunun başında
 *      basılır (asla önceden basılıp bekletilmez) — ve yapıştırıldıktan
 *      SONRA bir kez daha okutulup (verifyLabel) doğrulanır.
 *
 *   2) "Aynı adrese giden 2 farklı sipariş tek koliye konuyor, tek kargo
 *      barkodu ikisini de kapsıyor" — bu artık package_orders tablosuyla
 *      birebir modelleniyor: bir SSCC birden fazla order_ref'e bağlanabilir.
 *
 * generateManifest(), kutunun dışına yapıştırılacak "içindekiler" listesini
 * SİSTEMİN KENDİ KAYDINDAN otomatik üretir — elle yazılmadığı için
 * yanlış yazma riski yoktur.
 */
final class PackageService
{
    public function __construct(
        private PDO $pdo,
        private ?RiskScorer $riskScorer = null,
        private ?EntityRepository $entities = null,
        private ?ScanExceptionQueue $scanQueue = null,
        private ?\Traceability\Risk\AuthorizationGuard $authGuard = null,
        private ?DwellTimeGuard $dwellGuard = null
    ) {
    }

    public function openPackage(string $sscc, array $orderRefs, int $locationId, int $openedByActorId): int
    {
        if ($orderRefs === []) {
            throw new \InvalidArgumentException('Bir koli en az bir siparişe bağlı olmalı.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO packages (sscc, location_id, status, opened_by_actor_id, opened_at)
             VALUES (:sscc, :location_id, :status, :actor_id, :now)'
        );
        $stmt->execute([
            ':sscc' => $sscc,
            ':location_id' => $locationId,
            ':status' => 'open',
            ':actor_id' => $openedByActorId,
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $packageId = (int) $this->pdo->lastInsertId();

        $orderStmt = $this->pdo->prepare('INSERT INTO package_orders (package_id, order_ref) VALUES (:package_id, :order_ref)');
        foreach (array_unique($orderRefs) as $orderRef) {
            $orderStmt->execute([':package_id' => $packageId, ':order_ref' => $orderRef]);
        }

        return $packageId;
    }

    /**
     * Bir ürünü bu koliye eklemeye çalışır. Ürünün paketlendiği sipariş
     * ($entityOrderRef), bu kolinin kayıtlı siparişlerinden biri DEĞİLSE
     * — yani biri A siparişinin ürününü B'nin kolisine koymaya
     * çalışıyorsa — işlem SESSİZCE geçmez, istisna fırlatılır.
     */
    /**
     * v1.1 — KESİN SKT VE LOT BLOKAJI (Hard SKT Expiry Lockout). SKT'si
     * geçmiş VEYA SKT'sine $minDaysToExpiry günden az kalmış bir ürün,
     * hiçbir override/istisna YOLU OLMADAN sipariş koliye eklenemez —
     * bu, WeightReconciler gibi "logla ve devam et" değil, KESİN bir
     * duraklamadır (Hard Lockout).
     */
    public function addEntityToPackage(int $packageId, int $entityId, string $entityOrderRef, int $minDaysToExpiry = 0): void
    {
        $pkg = $this->findPackage($packageId);
        if ($pkg === null || $pkg['status'] !== 'open') {
            throw new PackageMismatchException("Koli #{$packageId} bulunamadı veya artık açık değil (mühürlenmiş/kargolanmış olabilir).");
        }

        // v1.2 AŞAMA 2 entegrasyonu: zimmet zaman aşımı yüzünden askıya
        // alınmış bir personel, yeni bir ürünü koliye ekleyemez.
        if ($this->dwellGuard !== null && $this->dwellGuard->isSuspended((int) $pkg['opened_by_actor_id'])) {
            throw new \RuntimeException(
                "İŞLEM ASKIDA: personel #{$pkg['opened_by_actor_id']}, zimmet zaman aşımı nedeniyle askıya alınmış — "
                . 'bir süpervizör incelemeden yeni işlem yapamaz.'
            );
        }

        if ($this->entities !== null) {
            $entity = $this->entities->findById($entityId);
            if ($entity !== null && $entity['expiry_date'] !== null) {
                $threshold = (new \DateTimeImmutable())->modify("+{$minDaysToExpiry} days")->format('Y-m-d');
                if ($entity['expiry_date'] <= $threshold) {
                    throw new \RuntimeException(
                        "KESİN ENGEL: entity #{$entityId} SKT'si ({$entity['expiry_date']}) geçmiş veya {$minDaysToExpiry} "
                        . 'günden az kalmış — sipariş koliye eklenemez. Override yolu YOKTUR, ürün stoktan düşülmeli/imha sürecine alınmalıdır.'
                    );
                }
            }
        }

        $orderCheck = $this->pdo->prepare('SELECT COUNT(*) FROM package_orders WHERE package_id = :id AND order_ref = :ref');
        $orderCheck->execute([':id' => $packageId, ':ref' => $entityOrderRef]);
        if ((int) $orderCheck->fetchColumn() === 0) {
            throw new PackageMismatchException(
                "Bu ürün '{$entityOrderRef}' siparişine ait, ama koli #{$packageId} bu siparişi içermiyor — "
                . 'yanlış kutuya konmaya çalışılıyor. İşlem durduruldu.'
            );
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO package_contents (package_id, entity_id, order_ref, added_at) VALUES (:package_id, :entity_id, :order_ref, :now)'
        );
        $stmt->execute([
            ':package_id' => $packageId,
            ':entity_id' => $entityId,
            ':order_ref' => $entityOrderRef,
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        // v1.2 AŞAMA 2: ürün koliye girdi — zimmet (varsa) kapanır.
        if ($this->dwellGuard !== null) {
            $this->dwellGuard->releaseItem($entityId, 'packed');
        }
    }

    /** Kutu kapatılırken çağrılır — mühürler, kurcalamaya karşı bir mühür seri no'su üretir, dışına yapıştırılacak içerik listesini döner. */
    public function sealPackage(int $packageId, ?int $overridingActorId = null): array
    {
        $pkg = $this->findPackage($packageId);
        if ($pkg === null) {
            throw new PackageMismatchException("Koli #{$packageId} bulunamadı.");
        }

        $contentCheck = $this->pdo->prepare('SELECT COUNT(*) FROM package_contents WHERE package_id = :id');
        $contentCheck->execute([':id' => $packageId]);
        if ((int) $contentCheck->fetchColumn() === 0) {
            throw new \RuntimeException("Koli #{$packageId} boş — hiçbir ürün eklenmeden mühürlenemez.");
        }

        // DENETİM BULGUSU: bu kontrol daha önce HİÇ YOKTU — bir personel,
        // WeightReconciler tarafından FLAGGED işaretlenmiş bir tartı
        // uyuşmazlığına rağmen koliyi mühürleyebiliyordu (WeightReconciler
        // sadece loglar, kendisi hiçbir şeyi ENGELLLEMEZ). Artık bu koliye
        // ait, ÇÖZÜLMEMİŞ (override edilmemiş) bir bayraklı ağırlık kaydı
        // varsa, süpervizör/yönetici onayı OLMADAN mühürleme durur.
        $flaggedStmt = $this->pdo->prepare(
            "SELECT psw.id FROM packing_station_weights psw
             JOIN package_contents pc ON pc.entity_id = psw.entity_id
             WHERE pc.package_id = :pkg AND psw.flagged = 1 AND psw.override_by_actor_id IS NULL"
        );
        $flaggedStmt->execute([':pkg' => $packageId]);
        $unresolvedFlags = $flaggedStmt->fetchAll(PDO::FETCH_COLUMN);

        if ($unresolvedFlags !== []) {
            if ($overridingActorId === null) {
                throw new \RuntimeException(
                    "Koli #{$packageId} mühürlenemez: içindeki en az bir ürün için ÇÖZÜLMEMİŞ ağırlık "
                    . 'uyuşmazlığı var (olası çıkarılmış ürün şüphesi). Süpervizör/yönetici onayı (override) gerekli.'
                );
            }
            if ($this->authGuard !== null) {
                $this->authGuard->requireRole($overridingActorId, ['supervizor', 'yonetici']);
            }
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $placeholders = implode(',', array_fill(0, count($unresolvedFlags), '?'));
            $clearStmt = $this->pdo->prepare(
                "UPDATE packing_station_weights SET override_by_actor_id = ?, override_at = ? WHERE id IN ({$placeholders})"
            );
            $clearStmt->execute(array_merge([$overridingActorId, $now], $unresolvedFlags));

            // v1.1: her override sonrası, bu süpervizörün kısa sürede kaç
            // override onayladığına bakılır (sistemi kilitlemeden).
            if ($this->authGuard !== null) {
                $this->authGuard->checkSupervisorOverrideVelocity($overridingActorId);
            }
        }

        $sealSerial = bin2hex(random_bytes(6));
        $stmt = $this->pdo->prepare("UPDATE packages SET status = 'sealed', sealed_at = :now, seal_serial = :seal WHERE id = :id");
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt->execute([':now' => $now, ':seal' => $sealSerial, ':id' => $packageId]);

        // v1.1: her mühür, kalıcı bir tarihçe kaydı olarak da saklanır —
        // ileride iptal edilirse bile bu kayıt SONSUZA DEK durur.
        $histStmt = $this->pdo->prepare(
            'INSERT INTO package_seal_history (package_id, seal_serial, sealed_at) VALUES (:pkg, :seal, :now)'
        );
        $histStmt->execute([':pkg' => $packageId, ':seal' => $sealSerial, ':now' => $now]);

        $manifest = $this->generateManifest($packageId);
        $manifest['seal_serial'] = $sealSerial;
        return $manifest;
    }

    /**
     * v1.1 — MÜHÜR İPTALİ (Seal Rollback Protocol). Sipariş iptali veya
     * ürün değişimi gibi meşru bir gerekçeyle, mühürlenmiş bir koli
     * AÇILABİLİR — ama bu ASLA sessizce/izsiz olmaz:
     *   1) Sadece süpervizör/yönetici yapabilir.
     *   2) Bir GEREKÇE metni ZORUNLUDUR (boş geçilemez).
     *   3) Eski mühür seri no'su package_seal_history'de KALICI OLARAK
     *      "invalidated" işaretlenir — bir daha ASLA geçerli sayılmaz
     *      (reportSealIntegrityIssue() bunu otomatik olarak yansıtır,
     *      çünkü packages.seal_serial artık NULL'dur, eski değere hiçbir
     *      zaman eşit olmayacaktır).
     *   4) Koli 'open' durumuna döner — içeriği düzenlenebilir, ama
     *      GÖNDERİLEMEZ, tekrar mühürlenmesi (yeni bir seal_serial ile)
     *      gerekir.
     */
    public function voidPackage(
        int $packageId,
        int $supervisorActorId,
        string $reason,
        \Traceability\Risk\AuthorizationGuard $authGuard,
        array $damagedEntityIds = []
    ): void {
        if (trim($reason) === '') {
            throw new \RuntimeException('Mühür iptal gerekçesi zorunludur — boş bırakılamaz.');
        }
        $authGuard->requireRole($supervisorActorId, ['supervizor', 'yonetici']);

        $pkg = $this->findPackage($packageId);
        if ($pkg === null) {
            throw new PackageMismatchException("Koli #{$packageId} bulunamadı.");
        }
        if ($pkg['status'] !== 'sealed') {
            throw new \RuntimeException("Koli #{$packageId} zaten mühürlü değil (durum: {$pkg['status']}) — iptal edilecek bir mühür yok.");
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $histStmt = $this->pdo->prepare(
            "UPDATE package_seal_history SET invalidated_at = :now, invalidated_by_actor_id = :actor, void_reason = :reason
             WHERE package_id = :pkg AND seal_serial = :seal AND invalidated_at IS NULL"
        );
        $histStmt->execute([':now' => $now, ':actor' => $supervisorActorId, ':reason' => $reason, ':pkg' => $packageId, ':seal' => $pkg['seal_serial']]);

        $stmt = $this->pdo->prepare(
            "UPDATE packages SET status = 'open', sealed_at = NULL, seal_serial = NULL,
                 voided_at = :now, voided_by_actor_id = :actor, void_reason = :reason
             WHERE id = :id"
        );
        $stmt->execute([':now' => $now, ':actor' => $supervisorActorId, ':reason' => $reason, ':id' => $packageId]);

        // v1.2 AŞAMA 5: iptal edilen kolideki her ürün için — SAĞLAMSA
        // stok havuzuna (IN_WAREHOUSE) geri döner, HASARLIYSA DAMAGED_HOLD'a
        // geçer (sadece "Fiziki Hurda Tutanak Ref" ile stoktan düşülebilir —
        // çöp alanı üzerinden ürün kaçırma zaafiyeti kapatılır).
        if ($this->entities !== null) {
            $contentStmt = $this->pdo->prepare('SELECT entity_id FROM package_contents WHERE package_id = :id');
            $contentStmt->execute([':id' => $packageId]);
            foreach ($contentStmt->fetchAll(PDO::FETCH_COLUMN) as $entityId) {
                $entityId = (int) $entityId;
                $targetStatus = in_array($entityId, $damagedEntityIds, true) ? 'DAMAGED_HOLD' : 'IN_WAREHOUSE';
                try {
                    $this->entities->transitionStatus($entityId, $targetStatus);
                } catch (\RuntimeException $e) {
                    // DESTROYED gibi son bir durumdaysa sessizce geçilir.
                }
            }
        }
    }

    /**
     * AĞIRLIK KONTROLÜ KİMLİĞİ DOĞRULAMAZ, SADECE KÜTLEYİ DOĞRULAR — bir
     * ürünü çıkarıp yerine eşit ağırlıkta bir nesne (taş, metal parça)
     * koyan biri, tartıyı hiçbir zaman tetiklemez. Bunun TEK çaresi,
     * PAKETLEYENDEN BAĞIMSIZ bir kişinin kutuyu rastgele yeniden açıp
     * GÖRSEL olarak içeriği doğrulamasıdır — tartı bunu asla yakalayamaz.
     */
    public function performSpotCheck(int $packageId, int $checkedByActorId, bool $contentsMatchManifest): array
    {
        $pkg = $this->findPackage($packageId);
        if ($pkg === null) {
            throw new PackageMismatchException("Koli #{$packageId} bulunamadı.");
        }
        $independent = $checkedByActorId !== (int) $pkg['opened_by_actor_id'];

        $stmt = $this->pdo->prepare(
            "UPDATE packages SET spot_checked_by_actor_id = :actor, spot_checked_at = :now, spot_check_result = :result WHERE id = :id"
        );
        $stmt->execute([
            ':actor' => $checkedByActorId,
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ':result' => $contentsMatchManifest ? 'pass' : 'fail',
            ':id' => $packageId,
        ]);

        if ($this->riskScorer !== null) {
            if (!$independent) {
                $this->riskScorer->recordSignal($checkedByActorId, 'spot_check_not_independent', ['package_id' => $packageId]);
            }
            if (!$contentsMatchManifest) {
                $this->riskScorer->recordSignal((int) $pkg['opened_by_actor_id'], 'spot_check_content_mismatch', [
                    'package_id' => $packageId, 'checked_by' => $checkedByActorId,
                ]);
            }
        }

        // KRİTİK: spot-check başarısız olduysa, bu koliye ait TÜM ürünler
        // KARANTİNAYA alınmalı — sadece risk sinyali işleyip durumu
        // değiştirmemek, kaos günü testinde ortaya çıkan gerçek bir
        // boşluktu (ürün "temiz" statüsündeymiş gibi tekrar işleme girebiliyordu).
        if (!$contentsMatchManifest && $this->entities !== null) {
            $contentStmt = $this->pdo->prepare('SELECT entity_id FROM package_contents WHERE package_id = :id');
            $contentStmt->execute([':id' => $packageId]);
            foreach ($contentStmt->fetchAll(PDO::FETCH_COLUMN) as $entityId) {
                try {
                    $this->entities->transitionStatus((int) $entityId, 'QUARANTINED');
                } catch (\RuntimeException $e) {
                    // Zaten DESTROYED gibi bir son durumdaysa veya eşzamanlı
                    // bir değişiklik varsa, karantina denemesi sessizce geçilir —
                    // bu, birincil koruma değil, ikincil bir güvenlik ağıdır.
                }
            }

            // MÜFETTİŞ BULGUSU: bir spot-check hatası daha önce SADECE o TEK
            // koliyi etkiliyordu — aynı kişinin paketlediği DİĞER son koliler
            // hiç ek incelemeye girmiyordu. Artık bir hata bulunduğunda, aynı
            // paketleyicinin son N kolisi de manuel inceleme kuyruğuna düşer.
            if ($this->scanQueue !== null) {
                $packerId = (int) $pkg['opened_by_actor_id'];
                $recentStmt = $this->pdo->prepare(
                    "SELECT id, sscc FROM packages WHERE opened_by_actor_id = :packer AND id != :current_pkg
                     ORDER BY id DESC LIMIT 5"
                );
                $recentStmt->execute([':packer' => $packerId, ':current_pkg' => $packageId]);
                foreach ($recentStmt->fetchAll(PDO::FETCH_ASSOC) as $recentPkg) {
                    $this->scanQueue->enqueue(
                        "PAKET-{$recentPkg['sscc']}",
                        'reconciliation_mismatch',
                        actorId: $packerId
                    );
                }
            }
        }

        return ['independent' => $independent, 'passed' => $contentsMatchManifest];
    }

    /** Hedef depo/bayi/müşteri, mührün kurcalanmış/eksik olduğunu bildirdiğinde çağrılır. */
    public function reportSealIntegrityIssue(int $packageId, string $observedSealSerial): bool
    {
        $pkg = $this->findPackage($packageId);
        if ($pkg === null) {
            return false;
        }
        return hash_equals((string) $pkg['seal_serial'], $observedSealSerial);
    }

    /**
     * Etiket koliye yapıştırıldıktan SONRA bir kez daha okutulur — bu
     * ikinci okutma, "yanlış etiketi yanlış kutuya yapıştırma" hatasını
     * yakalayan SON kontrol noktasıdır.
     */
    public function verifyLabel(int $packageId, string $scannedSscc): bool
    {
        $pkg = $this->findPackage($packageId);
        if ($pkg === null) {
            return false;
        }
        $matches = hash_equals($pkg['sscc'], $scannedSscc);
        if ($matches) {
            $stmt = $this->pdo->prepare('UPDATE packages SET label_verified_at = :now WHERE id = :id');
            $stmt->execute([':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), ':id' => $packageId]);
        }
        return $matches;
    }

    /** Kutunun dışına yapıştırılacak, sistemin kendi kaydından üretilen içerik özeti. */
    public function generateManifest(int $packageId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.name as product_name, pc.order_ref, COUNT(*) as qty
             FROM package_contents pc
             JOIN trackable_entities te ON te.id = pc.entity_id
             JOIN products p ON p.id = te.product_id
             WHERE pc.package_id = :id
             GROUP BY p.name, pc.order_ref
             ORDER BY pc.order_ref, p.name"
        );
        $stmt->execute([':id' => $packageId]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $orderStmt = $this->pdo->prepare('SELECT order_ref FROM package_orders WHERE package_id = :id');
        $orderStmt->execute([':id' => $packageId]);
        $orderRefs = $orderStmt->fetchAll(PDO::FETCH_COLUMN);

        return ['order_refs' => $orderRefs, 'lines' => $lines];
    }

    public function findPackage(int $packageId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM packages WHERE id = :id');
        $stmt->execute([':id' => $packageId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }
}
