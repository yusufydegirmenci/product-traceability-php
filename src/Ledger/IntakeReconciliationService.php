<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use Traceability\Risk\RiskScorer;
use PDO;

/**
 * Gerçek senaryo: "1000 adet şampuan geldi" deniyor. Sistem gerçekten
 * 1000 tane trackable_entity oluşturdu mu, yoksa 950 mi? Bu sınıf bu
 * mutabakatı yapar ve uyuşmazlığı KAYIT ALTINA alır — daha önce bu
 * kontrol hiç yoktu, yani "1000 dedim, 950 kaydettim, 50'sini cebe
 * attım" tarzı bir açık tamamen kapalıydı.
 */
final class IntakeReconciliationService
{
    public function __construct(
        private PDO $pdo,
        private EntityRepository $entities,
        private RiskScorer $riskScorer
    ) {
    }

    public function reconcile(
        string $lotNumber,
        int $productId,
        int $declaredQuantity,
        int $reconciledByActorId
    ): array {
        $actualCount = $this->entities->countByLot($lotNumber);
        $matched = $actualCount === $declaredQuantity;

        $stmt = $this->pdo->prepare(
            'INSERT INTO intake_reconciliations
                (lot_number, product_id, declared_quantity, actual_entity_count, matched, reconciled_by_actor_id, reconciled_at)
             VALUES (:lot, :product_id, :declared, :actual, :matched, :actor, :now)'
        );
        $stmt->execute([
            ':lot' => $lotNumber,
            ':product_id' => $productId,
            ':declared' => $declaredQuantity,
            ':actual' => $actualCount,
            ':matched' => $matched ? 1 : 0,
            ':actor' => $reconciledByActorId,
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        if (!$matched) {
            $this->riskScorer->recordSignal($reconciledByActorId, 'intake_quantity_mismatch', [
                'lot_number' => $lotNumber,
                'declared' => $declaredQuantity,
                'actual' => $actualCount,
                'difference' => $declaredQuantity - $actualCount,
            ]);
        }

        return ['matched' => $matched, 'declared' => $declaredQuantity, 'actual' => $actualCount];
    }
}
