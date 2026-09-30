<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use Traceability\Risk\RiskScorer;
use PDO;

/**
 * ŞİMDİYE KADAR KURDUĞUMUZ HER KONTROL "BİRİ BİR ŞEYİ OKUTTUĞUNDA" tetiklenir.
 * Biri hiç okutmadan, sessizce bir ürünü alıp çıkarsa (çantasında, aracında)
 * hiçbir event hiç oluşmaz — sistemin buna karşı TEK çaresi düzenli,
 * BAĞIMSIZ bir kişinin yaptığı fiziksel sayımdır.
 *
 * ÖNEMLİ VE DÜRÜST BİR SINIR: Bu servis hırsızlığı ENGELLEMEZ, sadece
 * onu KISA SÜREDE, SOMUT VERİYLE ORTAYA ÇIKARIR. Fiziksel engelleme
 * (çıkışta üst arama, tek gözetimli çıkış kapısı, kamera) bu kodun
 * kapsamı dışında bir GÜVENLİK/OPERASYON kararıdır — sistem bunu
 * ikame edemez, sadece onunla birlikte çalışır.
 */
final class CycleCountService
{
    public function __construct(
        private PDO $pdo,
        private RiskScorer $riskScorer
    ) {
    }

    /** Event-sourcing'den "bu depoda, bu üründen kaç tane olması gerekiyor" hesabı. */
    public function expectedQuantity(int $locationId, int $productId): int
    {
        // Bu ürüne ait, hâlâ depoda sayılan (satılmamış/iade/imha olmamış)
        // her entity için EN SON event'inin lokasyonunu bul, bu depoya
        // ait olanları say.
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM trackable_entities te
             WHERE te.product_id = :product_id
               AND te.status IN ('CREATED','IN_WAREHOUSE')
               AND (
                   SELECT e.read_point_location_id FROM epcis_events e
                   WHERE e.entity_id = te.id ORDER BY e.id DESC LIMIT 1
               ) = :location_id"
        );
        $stmt->execute([':product_id' => $productId, ':location_id' => $locationId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param int|null $primaryCustodianActorId Bu bölgeden normalde sorumlu kişi.
     *        Sayımı yapan ($countedByActorId) bu kişiyle AYNIYSA, sayımın
     *        kendisi bağımsız sayılmaz — bu ayrı bir sinyal üretir, çünkü
     *        "kendi sayımını kendi yapan" bir kontrol hiçbir şeyi kanıtlamaz.
     */
    public function performCount(
        int $locationId,
        int $productId,
        int $countedQuantity,
        int $countedByActorId,
        ?int $primaryCustodianActorId = null
    ): array {
        $expected = $this->expectedQuantity($locationId, $productId);
        $variance = $countedQuantity - $expected;

        $stmt = $this->pdo->prepare(
            'INSERT INTO cycle_counts (location_id, product_id, expected_quantity, counted_quantity, variance, counted_by_actor_id, primary_custodian_actor_id, counted_at)
             VALUES (:loc, :prod, :expected, :counted, :variance, :counter, :custodian, :now)'
        );
        $stmt->execute([
            ':loc' => $locationId,
            ':prod' => $productId,
            ':expected' => $expected,
            ':counted' => $countedQuantity,
            ':variance' => $variance,
            ':counter' => $countedByActorId,
            ':custodian' => $primaryCustodianActorId,
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $countId = (int) $this->pdo->lastInsertId();

        $notIndependent = $primaryCustodianActorId !== null && $primaryCustodianActorId === $countedByActorId;
        if ($notIndependent) {
            $this->riskScorer->recordSignal($countedByActorId, 'cycle_count_not_independent', [
                'location_id' => $locationId, 'product_id' => $productId,
            ]);
        }

        if ($variance < 0) {
            // Eksik çıktı — sistemin "olmalı" dediğinden az. Bu bölgeden
            // sorumlu kişiye (belirtilmişse) veya sayımı yapana değil,
            // AÇIKÇA bölgenin sorumlusuna işlenir — çünkü kayıp, sayanın
            // değil, bölgeyi elinde tutanın sorumluluğundadır.
            $flagTarget = $primaryCustodianActorId ?? $countedByActorId;
            $this->riskScorer->recordSignal($flagTarget, 'inventory_shrinkage_detected', [
                'location_id' => $locationId,
                'product_id' => $productId,
                'expected' => $expected,
                'counted' => $countedQuantity,
                'missing' => abs($variance),
                'independent_count' => !$notIndependent,
            ]);
        }

        return [
            'expected' => $expected,
            'counted' => $countedQuantity,
            'variance' => $variance,
            'independent' => !$notIndependent,
            'count_id' => $countId,
        ];
    }

    /** Belirli bir eşiğin üzerinde eksik çıkan, henüz gözden geçirilmemiş sayımlar. */
    public function significantShortages(int $minMissing = 1): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cycle_counts WHERE variance <= :threshold ORDER BY counted_at DESC');
        $stmt->execute([':threshold' => -$minMissing]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
