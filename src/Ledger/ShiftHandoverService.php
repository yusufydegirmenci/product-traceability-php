<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use PDO;


/**
 * MÜFETTİŞ BULGUSU: vardiya devri daha önce sadece sözlü/kağıt üzerinde
 * anlatılıyordu — hiçbir dijital kaydı yoktu. Vardiya sırasında kaybolan
 * ya da hasar gören bir ürünün sorumluluğu, iki vardiya arasında
 * belirsizliğe düşüyordu ("finger-pointing" riski).
 *
 * Bu servis, devir anındaki AÇIK KALEMLERİN (karantina, DLQ, bildirim)
 * sayısını dondurup her iki tarafın da AYRI AYRI dijital olarak
 * onaylamasını zorunlu kılar. Devreden "bunları bıraktım" der, devralan
 * "bunları gördüm ve kabul ettim" der — ikisi de aynı sayıları görür.
 */
final class ShiftHandoverService
{
    public function __construct(
        private PDO $pdo,
        private ?DwellTimeGuard $dwellGuard = null
    ) {
    }

    /**
     * v1.2 AŞAMA 3 — SIFIR MALİYETLİ VARDİYA ÇIKIŞ KİLİDİ. Bir personel,
     * üzerinde zimmetli (koliye girmemiş/rafa dönmemiş) ürün varken
     * oturumu kapatamaz — override YOKSA. Bu, "zimmetimdeki ürünü eve
     * götürüp vardiya bitince gelip alırım" gibi bir kaçış yolunu kapatır.
     */
    public function attemptLogout(int $actorId, ?int $overridingSupervisorId = null, ?\Traceability\Risk\AuthorizationGuard $authGuard = null): void
    {
        if ($this->dwellGuard === null || !$this->dwellGuard->hasOpenCustody($actorId)) {
            return; // zimmet yok, çıkış serbest
        }

        if ($overridingSupervisorId === null) {
            $count = $this->dwellGuard->openCustodyCount($actorId);
            throw new UnassignedItemLockoutException(
                "Oturum kapatılamaz: personel #{$actorId} üzerinde {$count} adet zimmetli (koliye girmemiş/rafa dönmemiş) "
                . 'ürün var. Ürünleri fiziksel olarak rafa iade edip okutun, YA DA bir süpervizör override girsin.'
            );
        }
        if ($authGuard !== null) {
            $authGuard->requireRole($overridingSupervisorId, ['supervizor', 'yonetici']);
        }
        // Override ile çıkışa izin verilir — ama zimmet KAPANMAZ, kaydı
        // olduğu gibi kalır (bir sonraki vardiyaya/soruşturmaya görünür).
    }

    public function openHandover(int $locationId, int $outgoingActorId, int $incomingActorId, ?string $notes = null): int
    {
        // GELİŞTİRİCİ İNCELEMESİ BULGUSU: bu sorgu daha önce TÜM ülkeler
        // genelindeki karantina sayısını dönüyordu — bir TR vardiya devri,
        // AZ/RO/GR'deki karantinayı da gösteriyordu, yerel süpervizör için
        // anlamsız/yanıltıcıydı. Artık entity'nin EN SON bilinen lokasyonuna
        // göre SADECE bu deponun karantinasını sayıyor.
        $openQuarantine = $this->pdo->prepare(
            "SELECT COUNT(*) FROM trackable_entities te
             WHERE te.status = 'QUARANTINED'
               AND (
                   SELECT e.read_point_location_id FROM epcis_events e
                   WHERE e.entity_id = te.id ORDER BY e.id DESC LIMIT 1
               ) = :location_id"
        );
        $openQuarantine->execute([':location_id' => $locationId]);

        $openDlqStmt = $this->pdo->prepare("SELECT COUNT(*) FROM scan_exception_queue WHERE status = 'pending' AND location_id = :location_id");
        $openDlqStmt->execute([':location_id' => $locationId]);
        $openDlq = $openDlqStmt->fetchColumn();

        // DÜRÜST SINIR: notifications tablosunda lokasyon alanı yok (related_id
        // bağlama göre aktör/bayi/cihaz olabiliyor) — bu yüzden bildirim sayısı
        // hâlâ ŞİRKET GENELİDİR, tek depoya özel değildir. Bu bilinçli bir sınır.
        $openNotifs = $this->pdo->query('SELECT COUNT(*) FROM notifications WHERE acknowledged_at IS NULL')->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO shift_handovers
                (location_id, outgoing_actor_id, incoming_actor_id, open_quarantine_count, open_dlq_count, open_notifications_count, notes, created_at)
             VALUES (:loc, :out, :in, :q, :d, :n, :notes, :now)'
        );
        $stmt->execute([
            ':loc' => $locationId, ':out' => $outgoingActorId, ':in' => $incomingActorId,
            ':q' => (int) $openQuarantine->fetchColumn(), ':d' => (int) $openDlq, ':n' => (int) $openNotifs,
            ':notes' => $notes, ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function confirmOutgoing(int $handoverId, int $actorId): void
    {
        $this->assertActorMatches($handoverId, 'outgoing_actor_id', $actorId);
        $stmt = $this->pdo->prepare('UPDATE shift_handovers SET outgoing_confirmed_at = :now WHERE id = :id');
        $stmt->execute([':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), ':id' => $handoverId]);
    }

    public function confirmIncoming(int $handoverId, int $actorId): void
    {
        $this->assertActorMatches($handoverId, 'incoming_actor_id', $actorId);
        $stmt = $this->pdo->prepare('UPDATE shift_handovers SET incoming_confirmed_at = :now WHERE id = :id');
        $stmt->execute([':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), ':id' => $handoverId]);
    }

    public function isFullyConfirmed(int $handoverId): bool
    {
        $stmt = $this->pdo->prepare('SELECT outgoing_confirmed_at, incoming_confirmed_at FROM shift_handovers WHERE id = :id');
        $stmt->execute([':id' => $handoverId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false && $row['outgoing_confirmed_at'] !== null && $row['incoming_confirmed_at'] !== null;
    }

    private function assertActorMatches(int $handoverId, string $column, int $actorId): void
    {
        $stmt = $this->pdo->prepare("SELECT {$column} FROM shift_handovers WHERE id = :id");
        $stmt->execute([':id' => $handoverId]);
        $expected = $stmt->fetchColumn();
        if ((int) $expected !== $actorId) {
            throw new \RuntimeException('Bu vardiya devrini sadece kayıttaki ilgili kişi onaylayabilir.');
        }
    }
}
