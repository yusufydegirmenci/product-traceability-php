<?php

declare(strict_types=1);

namespace Traceability\Risk;

use PDO;

/**
 * Exception-Based-Reporting-style scoring: every anomaly signal adds points
 * to the actor (staff or dealer) it's tied to; the rolling score over the
 * last 90 days decides whether a human should take a closer look.
 *
 * This NEVER produces an automatic verdict or punishment — it only produces
 * an investigation lead. Wiring this to any automatic account suspension or
 * disciplinary action is explicitly out of scope; a person must review the
 * underlying events first.
 */
final class RiskScorer
{
    private const SEVERITY_WEIGHTS = [
        'duplicate_scan' => 40,
        'weight_mismatch' => 25,
        'wrong_product_return' => 30,
        'off_hours_activity' => 10,
        'malformed_code' => 45,
        'unknown_return_code' => 45,
        'return_of_destroyed_code' => 50,
        'return_weight_anomaly' => 35,
        'wrong_dealer_or_order_return' => 45,
        'return_window_exceeded' => 15,
        'return_window_exceeded_override' => 10,
        'duplicate_return_attempt' => 50,
        'return_of_undelivered_item' => 55,
        'concurrent_return_conflict' => 40,
        'intake_quantity_mismatch' => 50,
        'stale_inventory' => 20,
        'shipped_without_carrier_confirmation' => 35,
        'return_weight_anomaly_override' => 15,
        'transfer_quantity_mismatch' => 50,
        'cycle_count_not_independent' => 20,
        'inventory_shrinkage_detected' => 55,
        'spot_check_not_independent' => 15,
        'spot_check_content_mismatch' => 60,
        'seal_integrity_violation' => 55,
        'relationship_conflict_detected' => 45,
        'override_pair_collusion_pattern' => 50,
        'new_dealer_velocity_anomaly' => 45,
        'staff_damage_rate_anomaly' => 50,
        'negligent_oversight' => 35,
        'active_collusion' => 65,
        'fake_external_order' => 60,
        'deliberate_shortage' => 55,
        'deliberate_sabotage_damage' => 60,
        'director_hub_collusion_pattern' => 70,
        'return_of_quarantined_item' => 60,
        'staff_double_loyalty_pattern' => 55,
        'causal_integrity_violation' => 55,
    ];

    private const WINDOW_DAYS = 90;

    /**
     * İlk kez görülen bir anomali "kesin suç" değil, "dikkat çekici" demektir.
     * Bu yüzden ilk oluşumda ağırlık düşürülür — sadece AYNI tür sinyal
     * TEKRARLANIRSA tam ağırlığa çıkar. Bu, "5 işlemden 2'sinde hata =
     * problemli çalışan" gibi istatistiksel olarak yanlış bir sonuca
     * sistemin kendisinin varmasını engeller.
     */
    private const FIRST_OCCURRENCE_DAMPENING = 0.4;

    public function __construct(private PDO $pdo)
    {
    }

    public function recordSignal(int $actorId, string $eventType, array $detail = []): float
    {
        $baseSeverity = self::SEVERITY_WEIGHTS[$eventType] ?? 10;
        $cutoff = (new \DateTimeImmutable('-' . self::WINDOW_DAYS . ' days'))->format('Y-m-d H:i:s');

        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM risk_events WHERE actor_id = :actor_id AND event_type = :event_type AND detected_at >= :cutoff'
        );
        $countStmt->execute([':actor_id' => $actorId, ':event_type' => $eventType, ':cutoff' => $cutoff]);
        $priorCount = (int) $countStmt->fetchColumn();

        $isFirstOccurrence = $priorCount === 0;
        $severity = $isFirstOccurrence
            ? (int) round($baseSeverity * self::FIRST_OCCURRENCE_DAMPENING)
            : $baseSeverity;

        $stmt = $this->pdo->prepare(
            'INSERT INTO risk_events (actor_id, event_type, severity, is_first_occurrence, detail, detected_at, resolved)
             VALUES (:actor_id, :event_type, :severity, :is_first, :detail, :now, 0)'
        );
        $stmt->execute([
            ':actor_id' => $actorId,
            ':event_type' => $eventType,
            ':severity' => $severity,
            ':is_first' => $isFirstOccurrence ? 1 : 0,
            ':detail' => json_encode($detail, JSON_UNESCAPED_UNICODE),
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $this->recalculate($actorId);
    }

    /**
     * Flag'lenen kişinin (personel/bayi) kendi açıklamasını ekleme hakkı.
     * Bu, insan incelemesi başlamadan ÖNCE sisteme girer — "önce suçla,
     * sonra sor" değil, "önce dinle" ilkesini uygular. Açıklama eklenmiş
     * bir sinyal otomatik olarak "çözülmüş" sayılmaz — insan hâlâ karar
     * verir — ama karar vermeden önce ilgili kişinin sesini duyar.
     */
    public function recordActorResponse(int $riskEventId, string $response): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE risk_events SET actor_response = :response, actor_response_at = :now WHERE id = :id'
        );
        $stmt->execute([
            ':response' => $response,
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ':id' => $riskEventId,
        ]);
    }

    public function recalculate(int $actorId): float
    {
        $cutoff = (new \DateTimeImmutable('-' . self::WINDOW_DAYS . ' days'))->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(severity), 0) FROM risk_events
             WHERE actor_id = :actor_id AND resolved = 0 AND detected_at >= :cutoff'
        );
        $stmt->execute([':actor_id' => $actorId, ':cutoff' => $cutoff]);
        $score = (float) $stmt->fetchColumn();

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $update = $this->pdo->prepare(
            'UPDATE actor_risk_score SET rolling_score = :score, last_calculated_at = :now WHERE actor_id = :actor_id'
        );
        $update->execute([':score' => $score, ':now' => $now, ':actor_id' => $actorId]);

        if ($update->rowCount() === 0) {
            $insert = $this->pdo->prepare(
                'INSERT INTO actor_risk_score (actor_id, rolling_score, last_calculated_at) VALUES (:actor_id, :score, :now)'
            );
            $insert->execute([':actor_id' => $actorId, ':score' => $score, ':now' => $now]);
        }

        return $score;
    }

    /**
     * harici bir gÃ¶zden geÃ§iren önerisi DURUM 4 — Dinamik Risk Eşiği (TEK somut örnek).
     * Yoğun dönemde (örn. kampanya günleri), sabit bir "60 = yüksek risk"
     * eşiği false-positive oranını artırabilir — daha fazla MEŞRU işlem
     * hacmi, istatistiksel olarak daha fazla sıradan istisnaya yol açar.
     * Bu metod eşiği GÜVENLİK FERAGATİ OLMADAN, sadece belirli bir bantta
     * (1.0x-1.5x) hacme göre esnetir — asla temel eşiğin ALTINA düşmez.
     */
    private const HIGH_RISK_THRESHOLD = 60.0;
    private const MEDIUM_RISK_THRESHOLD = 25.0;

    public function dynamicHighRiskThreshold(float $recentVolumeRatio): float
    {
        $multiplier = min(1.5, max(1.0, $recentVolumeRatio));
        return round(self::HIGH_RISK_THRESHOLD * $multiplier, 1);
    }

    public function level(float $score, ?float $highRiskThreshold = null): string
    {
        $threshold = $highRiskThreshold ?? self::HIGH_RISK_THRESHOLD;
        return match (true) {
            $score >= $threshold => 'YÜKSEK RİSK — incelemeye al',
            $score >= self::MEDIUM_RISK_THRESHOLD => 'ORTA RİSK — izlemeye devam',
            default => 'NORMAL',
        };
    }
}
