<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use Traceability\Risk\RiskScorer;
use Traceability\Risk\AuthorizationGuard;
use PDO;

/**
 * Gerçek şikayet: "iade edilen ürünün ücreti hâlâ ödenmedi" — bayi/depo
 * fiziksel iadeyi kabul etti ama ödeme departmanına hiç haber gitmedi ve
 * kimse fark etmedi. Bunun tek çözümü: iade KABULÜ ile PARA İADESİ'ni
 * veritabanında iki ayrı, birbirini referans veren adım yapmak.
 */
final class RefundService
{
    public function __construct(
        private PDO $pdo,
        private ?ReshipmentService $reshipments = null,
        private ?CustomerCommunicationService $messenger = null,
        private ?AuthorizationGuard $authGuard = null
    ) {
    }

    public function requestRefund(int $returnEventId, int $entityId, float $amount): int
    {
        $doubleCompensationRisk = $this->reshipments !== null && $this->reshipments->wasAlreadyReplaced($entityId) !== null;

        $stmt = $this->pdo->prepare(
            'INSERT INTO refunds (return_event_id, entity_id, amount, status, double_compensation_review, requested_at)
             VALUES (:return_event_id, :entity_id, :amount, :status, :review, :now)'
        );
        $stmt->execute([
            ':return_event_id' => $returnEventId,
            ':entity_id' => $entityId,
            ':amount' => $amount,
            ':status' => 'pending',
            ':review' => $doubleCompensationRisk ? 1 : 0,
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function markPaid(int $refundId, int $paidByActorId): void
    {
        if ($this->authGuard !== null) {
            $this->authGuard->requireRole($paidByActorId, ['muhasebe', 'yonetici']);
        }

        $row = $this->pdo->prepare('SELECT * FROM refunds WHERE id = :id');
        $row->execute([':id' => $refundId]);
        $refund = $row->fetch(PDO::FETCH_ASSOC);

        if ($refund !== false && (int) $refund['double_compensation_review'] === 1) {
            throw new \RuntimeException(
                "Refund #{$refundId} çift ödeme riski taşıyor (bu ürün daha önce kayıp sayılıp yenisi gönderilmişti). "
                . 'Ödeme yapılmadan önce bir insan bu kaydı manuel olarak temizlemeli (bkz. clearDoubleCompensationReview).'
            );
        }

        $stmt = $this->pdo->prepare(
            "UPDATE refunds SET status = 'paid', paid_at = :now, paid_by_actor_id = :actor WHERE id = :id"
        );
        $stmt->execute([':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), ':actor' => $paidByActorId, ':id' => $refundId]);

        if ($this->messenger !== null && $refund !== false) {
            $dealerActorId = $this->resolveDealerActorId((int) $refund['entity_id']);
            if ($dealerActorId !== null) {
                $this->messenger->notify($dealerActorId, 'refund_paid', [(string) $refund['amount']]);
            }
        }
    }

    /** İnsan çift ödeme riskini fiilen kontrol edip (kargo/depo kaydını karşılaştırıp) temizlediğinde çağrılır. */
    public function clearDoubleCompensationReview(int $refundId, int $clearedByActorId, string $note): void
    {
        $stmt = $this->pdo->prepare('UPDATE refunds SET double_compensation_review = 0 WHERE id = :id');
        $stmt->execute([':id' => $refundId]);
        // Not: production'da bu $note ve $clearedByActorId ayrı bir audit tablosuna yazılmalı —
        // burada basitlik için sadece flag temizleniyor.
    }

    private function resolveDealerActorId(int $entityId): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT metadata FROM epcis_events WHERE entity_id = :id AND disposition IN ('returned','return_expired') ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':id' => $entityId]);
        $json = $stmt->fetchColumn();
        if ($json === false) {
            return null;
        }
        $meta = json_decode((string) $json, true) ?? [];
        return isset($meta['dealer_actor_id']) ? (int) $meta['dealer_actor_id'] : null;
    }

    /**
     * KPI: kaç gündür ödenmemiş iade var. Bu, "iade kabul edildi ama parası
     * ödenmedi" şikayetlerinin sistemde SESSİZCE birikmesini engelleyen
     * temel gözetim sorgusudur — düzenli olarak (örn. günlük) çalıştırılmalı.
     */
    public function findOverduePending(int $maxDays = 3): array
    {
        $cutoff = (new \DateTimeImmutable("-{$maxDays} days"))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "SELECT * FROM refunds WHERE status = 'pending' AND requested_at < :cutoff ORDER BY requested_at ASC"
        );
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
