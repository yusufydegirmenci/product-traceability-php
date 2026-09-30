<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use Traceability\Risk\RiskScorer;
use PDO;

/**
 * Gerçek şikayet: "sponsorum kayboldu, 60 üründen 50'sini iade edemiyorum,
 * destekten kimse dönmüyor." Bu sınıfın tek amacı bir bayinin KENDİ
 * durumunu — bağımsız bir insana muhtaç kalmadan — görebilmesini ve
 * kendi iddiasını doğrudan merkeze açabilmesini sağlamak.
 *
 * Sadece kendi verisini görür (dealerActorId ile filtrelenir) — başka bir
 * bayinin/müşterinin hiçbir kaydına erişemez.
 */
final class DealerPortalService
{
    public function __construct(
        private PDO $pdo,
        private ClaimService $claims,
        private RiskScorer $riskScorer
    ) {
    }

    public function myShipments(int $dealerActorId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT e.*, te.entity_code, p.name as product_name
             FROM epcis_events e
             JOIN trackable_entities te ON te.id = e.entity_id
             JOIN products p ON p.id = te.product_id
             WHERE e.actor_id = :actor AND e.biz_step = 'receiving' AND e.disposition = 'sold'
             ORDER BY e.id DESC"
        );
        $stmt->execute([':actor' => $dealerActorId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function myReturnsAndRefunds(int $dealerActorId): array
    {
        // Not: return event'inin actor_id'si İŞLEMİ YAPAN PERSONELDİR, bayi değil —
        // bu yüzden bayi eşleşmesi event metadata'sındaki dealer_actor_id üzerinden yapılır.
        $stmt = $this->pdo->prepare(
            "SELECT e.id as return_event_id, e.disposition, e.event_time, e.metadata, p.name as product_name,
                    r.status as refund_status, r.amount as refund_amount
             FROM epcis_events e
             JOIN trackable_entities te ON te.id = e.entity_id
             JOIN products p ON p.id = te.product_id
             LEFT JOIN refunds r ON r.return_event_id = e.id
             WHERE e.disposition IN ('returned','return_expired','return_flagged','return_rejected')
             ORDER BY e.id DESC"
        );
        $stmt->execute();
        $mine = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $meta = json_decode((string) $row['metadata'], true) ?? [];
            if ((int) ($meta['dealer_actor_id'] ?? 0) === $dealerActorId) {
                $mine[] = $row;
            }
        }
        return $mine;
    }

    /**
     * Bayinin kendi risk durumunu şeffafça gösterir — ama ham skoru değil,
     * "açık bir inceleme var mı, varsa açıklama ekleyebilir misin" şeklinde,
     * gereksiz alarme etmeyen bir çerçevede (bkz. "sesini duyurma hakkı").
     */
    public function myOpenReviewItems(int $dealerActorId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM risk_events WHERE actor_id = :actor AND resolved = 0 AND actor_response IS NULL ORDER BY detected_at DESC"
        );
        $stmt->execute([':actor' => $dealerActorId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function respondToReviewItem(int $riskEventId, string $response): void
    {
        $this->riskScorer->recordActorResponse($riskEventId, $response);
    }

    /** Bayi, sponsoruna/desteğe bağımlı kalmadan doğrudan merkeze bir iddia açar. */
    public function fileMyClaim(int $dealerActorId, ?int $entityId, ?string $orderRef, string $claimType, string $claimText): int
    {
        return $this->claims->fileClaim($entityId, $orderRef, $claimType, $claimText, $dealerActorId);
    }
}
