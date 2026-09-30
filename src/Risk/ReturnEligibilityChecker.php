<?php

declare(strict_types=1);

namespace Traceability\Risk;

use PDO;

/**
 * İki bağımsız kontrol yapar — ikisi de "gerçekten BİZİM gönderdiğimiz ürün,
 * gerçekten BİZİM gönderdiğimiz kişiden mi geri geliyor" sorusuna bakar:
 *
 *   1) ZAMAN PENCERESİ — teslimat üzerinden ürünün return_window_days'ini
 *      aşan bir süre geçmişse, ürünün fiilen kullanılmış/tüketilmiş olma
 *      ihtimali yükselir. Bu bir suç kanıtı değildir — sadece "süpervizör
 *      bir bakıversin" sinyalidir, bu yüzden supervisorOverride=true ile
 *      aşılabilir (aşıldığı da kalıcı olarak event geçmişine yazılır).
 *
 *   2) BAYİ/SİPARİŞ EŞLEŞMESİ — kod gerçek ve ürün tipi doğru olsa bile,
 *      bu SPESİFİK birim en son BAŞKA bir bayiye teslim edilmiş olabilir.
 *      Bu, ürün tipi kontrolünün yakalayamadığı bir sahtecilik yüzeyidir
 *      (örn. iki bayi ürün takas edip ikisi de "iade ediyorum" der).
 *      Bu kontrol de override edilebilir ama daha ağır bir risk sinyali
 *      bırakır, çünkü yanlışlıkla olma ihtimali zaman penceresinden düşüktür.
 */
final class ReturnEligibilityChecker
{
    public function __construct(private PDO $pdo)
    {
    }

    /** Entity'nin en son "teslim edildi" (receiving/sold) event'ini döner, yoksa null. */
    public function lastDeliveryEvent(int $entityId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM epcis_events
             WHERE entity_id = :id AND biz_step = 'receiving' AND disposition = 'sold'
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':id' => $entityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function checkWindow(int $productId, int $entityId, \DateTimeImmutable $now): array
    {
        $delivery = $this->lastDeliveryEvent($entityId);
        if ($delivery === null) {
            // Hiç teslim edilmemiş bir ürün "iade" olarak geliyor — bu ayrı,
            // daha ciddi bir sorun; ReturnService bunu zaten yakalar.
            return ['ok' => true, 'reason' => 'no_delivery_record'];
        }

        $windowDays = (int) $this->productReturnWindow($productId);
        $deliveredAt = new \DateTimeImmutable($delivery['event_time']);
        $daysElapsed = $deliveredAt->diff($now)->days;

        if ($daysElapsed > $windowDays) {
            return [
                'ok' => false,
                'reason' => 'return_window_exceeded',
                'detail' => ['days_elapsed' => $daysElapsed, 'window_days' => $windowDays],
            ];
        }
        return ['ok' => true];
    }

    public function checkDealerMatch(int $entityId, ?int $claimedDealerActorId, ?string $claimedOrderRef): array
    {
        $delivery = $this->lastDeliveryEvent($entityId);
        if ($delivery === null) {
            return ['ok' => true, 'reason' => 'no_delivery_record'];
        }

        $deliveredToActor = $delivery['actor_id'] !== null ? (int) $delivery['actor_id'] : null;
        $deliveredOrderRef = $delivery['related_order_ref'];

        $actorMismatch = $claimedDealerActorId !== null && $deliveredToActor !== null && $claimedDealerActorId !== $deliveredToActor;
        $orderMismatch = $claimedOrderRef !== null && $deliveredOrderRef !== null && $claimedOrderRef !== $deliveredOrderRef;

        if ($actorMismatch || $orderMismatch) {
            return [
                'ok' => false,
                'reason' => 'wrong_dealer_or_order_return',
                'detail' => [
                    'delivered_to_actor' => $deliveredToActor,
                    'claimed_dealer' => $claimedDealerActorId,
                    'delivered_order_ref' => $deliveredOrderRef,
                    'claimed_order_ref' => $claimedOrderRef,
                ],
            ];
        }
        return ['ok' => true];
    }

    private function productReturnWindow(int $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT return_window_days FROM products WHERE id = :id');
        $stmt->execute([':id' => $productId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (int) $val : 14;
    }

    /**
     * Belirli bir süpervizör-personel ÇİFTİNİN birlikte kaç kez override
     * yaptığını sayar. Bir çift normalden ÇOK daha sık birlikte
     * override'a çıkıyorsa, bu işbirlikli sahtekarlık (collusion)
     * paterninin somut bir göstergesidir — tek başına ne süpervizörün ne
     * de personelin davranışı şüpheli görünmeyebilir, ama BİRLİKTE
     * tekrarlayan bir eşleşme örüntüsü öyledir.
     */
    public function countOverridesByPair(int $supervisorActorId, int $staffActorId, int $days = 90): int
    {
        $cutoff = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "SELECT metadata, actor_id FROM epcis_events WHERE biz_step = 'receiving' AND event_time >= :cutoff"
        );
        $stmt->execute([':cutoff' => $cutoff]);

        $count = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $metadata = json_decode((string) $row['metadata'], true) ?? [];
            if (($metadata['approved_by_supervisor_id'] ?? null) === $supervisorActorId
                && (int) $row['actor_id'] === $staffActorId) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Bir süpervizörün son N gündeki onay sayısını döner — art niyetli bir
     * süpervizörün SÜREKLİ aynı bayi için override onaylaması gibi bir
     * işbirliği (collusion) paterni burada görünür hale gelir. Bu, bir
     * suçlama üretmez, sadece "birisi bakmalı" diyen bir rapor sorgusudur.
     */
    public function countRecentOverridesByApprover(int $supervisorActorId, int $days = 30): int
    {
        $cutoff = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "SELECT metadata FROM epcis_events WHERE biz_step = 'receiving' AND event_time >= :cutoff"
        );
        $stmt->execute([':cutoff' => $cutoff]);

        $count = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $metadataJson) {
            $metadata = json_decode((string) $metadataJson, true) ?? [];
            if (($metadata['approved_by_supervisor_id'] ?? null) === $supervisorActorId) {
                $count++;
            }
        }
        return $count;
    }
}
