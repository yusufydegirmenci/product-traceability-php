<?php

declare(strict_types=1);

namespace Traceability\Risk;

use PDO;

/**
 * Catches Senaryo 3 from the plan: a packed box whose measured weight
 * doesn't match what the order's contents should weigh — a possible sign
 * a product was removed before shipping.
 */
final class WeightReconciler
{
    public function __construct(
        private PDO $pdo,
        private float $toleranceFraction = 0.10 // 10% variance allowed before flagging
    ) {
    }

    /** Returns true if the weight is WITHIN tolerance (i.e. no problem found). */
    public function check(string $orderRef, int $entityId, float $expectedGrams, float $measuredGrams): bool
    {
        $variance = $expectedGrams > 0.0
            ? abs($measuredGrams - $expectedGrams) / $expectedGrams
            : 1.0;
        $flagged = $variance > $this->toleranceFraction;

        $stmt = $this->pdo->prepare(
            'INSERT INTO packing_station_weights
                (order_ref, entity_id, expected_weight_g, measured_weight_g, variance_pct, flagged, created_at)
             VALUES (:order_ref, :entity_id, :expected, :measured, :variance_pct, :flagged, :now)'
        );
        $stmt->execute([
            ':order_ref' => $orderRef,
            ':entity_id' => $entityId,
            ':expected' => $expectedGrams,
            ':measured' => $measuredGrams,
            ':variance_pct' => round($variance * 100, 2),
            ':flagged' => $flagged ? 1 : 0,
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return !$flagged;
    }
}
