<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use Traceability\Risk\RiskScorer;
use PDO;

/**
 * Yurt dışı depoya (veya herhangi bir depo-depo) transferi tek bir kayıtta
 * yönetir: hazırlanıyor → çıktı → gümrükte → teslim alındı. Uluslararası
 * taşımada ürünün en çok "kaybolduğu" nokta çıkış ile varış arasıdır —
 * bu yüzden receiveAtDestination() beyan edilen miktarla hedef depoda
 * fiilen okutulan miktarı karşılaştırır (IntakeReconciliationService'in
 * aynı mantığı, ama tedarikçiden değil KENDİ başka bir deponuzdan gelen
 * mal için).
 */
final class WarehouseTransferService
{
    public function __construct(
        private PDO $pdo,
        private EventStore $events,
        private RiskScorer $riskScorer
    ) {
    }

    public function createTransfer(int $sourceLocationId, int $destinationLocationId, int $initiatedByActorId, ?string $customsRef = null): int
    {
        $requiresCustoms = $this->requiresCustoms($sourceLocationId, $destinationLocationId);

        $stmt = $this->pdo->prepare(
            'INSERT INTO warehouse_transfers
                (source_location_id, destination_location_id, customs_declaration_ref, requires_customs, declared_quantity, status, initiated_by_actor_id, initiated_at)
             VALUES (:source, :dest, :customs, :requires_customs, 0, :status, :actor, :now)'
        );
        $stmt->execute([
            ':source' => $sourceLocationId,
            ':dest' => $destinationLocationId,
            ':customs' => $customsRef,
            ':requires_customs' => $requiresCustoms ? 1 : 0,
            ':status' => 'preparing',
            ':actor' => $initiatedByActorId,
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * İki depo AYNI gümrük bölgesindeyse (örn. ikisi de AB üyesi) aralarında
     * gümrük işlemi gerekmez — A→B (aynı gümrük bölgesi) gibi. Farklı bölgedeyse
     * (örn. C→D (farklı gümrük bölgesi), ya da merkezden herhangi bir yere)
     * gümrük şarttır.
     */
    public function requiresCustoms(int $sourceLocationId, int $destinationLocationId): bool
    {
        $source = $this->findLocationZone($sourceLocationId);
        $dest = $this->findLocationZone($destinationLocationId);
        if ($source === null || $dest === null) {
            return true; // bölge bilinmiyorsa güvenli tarafta kal, gümrük gerektir
        }
        return $source !== $dest;
    }

    /** Hedef depoya tipik sevkiyat süresine göre "kargo teyidi bekleme eşiği" — Uzak bir ülkeye giden bir sevkiyatı 48 saatte "kayboldu" sanmamak için. */
    public function carrierConfirmationThresholdHours(int $destinationLocationId): int
    {
        $stmt = $this->pdo->prepare('SELECT transit_time_hours_estimate FROM locations WHERE id = :id');
        $stmt->execute([':id' => $destinationLocationId]);
        $val = $stmt->fetchColumn();
        return $val !== false && $val !== null ? ((int) $val) + 24 : 48; // tahmini süre + 24 saat tampon, yoksa varsayılan 48
    }

    private function findLocationZone(int $locationId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT customs_zone FROM locations WHERE id = :id');
        $stmt->execute([':id' => $locationId]);
        $zone = $stmt->fetchColumn();
        return $zone !== false && $zone !== null ? $zone : null;
    }

    public function addEntity(int $transferId, int $entityId, int $actorId): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO transfer_contents (transfer_id, entity_id) VALUES (:t, :e)');
        $stmt->execute([':t' => $transferId, ':e' => $entityId]);

        $upd = $this->pdo->prepare('UPDATE warehouse_transfers SET declared_quantity = declared_quantity + 1 WHERE id = :id');
        $upd->execute([':id' => $transferId]);

        $transfer = $this->findTransfer($transferId);
        $this->events->appendEvent($entityId, 'shipping', 'in_transit', $actorId, null, (int) $transfer['source_location_id'], "TRANSFER-{$transferId}", [
            'transfer_id' => $transferId,
            'destination_location_id' => (int) $transfer['destination_location_id'],
        ]);
    }

    public function markExported(int $transferId, ?string $customsRef = null): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE warehouse_transfers SET status = 'exported', exported_at = :now, customs_declaration_ref = COALESCE(:customs, customs_declaration_ref) WHERE id = :id"
        );
        $stmt->execute([':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), ':customs' => $customsRef, ':id' => $transferId]);
    }

    public function markCustomsCleared(int $transferId): void
    {
        $stmt = $this->pdo->prepare("UPDATE warehouse_transfers SET status = 'customs_cleared', customs_cleared_at = :now WHERE id = :id");
        $stmt->execute([':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), ':id' => $transferId]);
    }

    /**
     * Hedef depo, teslim aldığı ürünleri tek tek okutur. $scannedEntityIds
     * bu okutmaların listesidir. Beyan edilen (declared_quantity) ile
     * gerçekte okutulan sayı uyuşmazsa risk sinyali işlenir — bu, taşıma
     * sırasında kayıp/çalıntı olup olmadığının TEK somut kanıtıdır.
     */
    public function receiveAtDestination(int $transferId, int $receivedByActorId, array $scannedEntityIds): array
    {
        $transfer = $this->findTransfer($transferId);
        if ($transfer === null) {
            throw new \RuntimeException("Transfer #{$transferId} bulunamadı.");
        }

        foreach ($scannedEntityIds as $entityId) {
            $this->events->appendEvent($entityId, 'receiving', 'active', $receivedByActorId, null, (int) $transfer['destination_location_id'], "TRANSFER-{$transferId}", [
                'transfer_id' => $transferId,
            ]);
        }

        $actualCount = count($scannedEntityIds);
        $declaredCount = (int) $transfer['declared_quantity'];
        $matched = $actualCount === $declaredCount;

        $stmt = $this->pdo->prepare(
            "UPDATE warehouse_transfers SET status = 'received', received_at = :now, received_by_actor_id = :actor, actual_received_count = :actual WHERE id = :id"
        );
        $stmt->execute([
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ':actor' => $receivedByActorId,
            ':actual' => $actualCount,
            ':id' => $transferId,
        ]);

        if (!$matched) {
            $this->riskScorer->recordSignal((int) $transfer['initiated_by_actor_id'], 'transfer_quantity_mismatch', [
                'transfer_id' => $transferId,
                'declared' => $declaredCount,
                'actual' => $actualCount,
                'difference' => $declaredCount - $actualCount,
            ]);
        }

        return ['matched' => $matched, 'declared' => $declaredCount, 'actual' => $actualCount];
    }

    public function findTransfer(int $transferId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM warehouse_transfers WHERE id = :id');
        $stmt->execute([':id' => $transferId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }
}
