<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use PDO;

/**
 * Bir şirket içi denetimde denetçinin soracağı üç temel soruya tek raporla
 * cevap verir:
 *
 *   1) "Kayıtlarınız değiştirilmediğini nasıl kanıtlıyorsunuz?"
 *      → verifyChainIntegritySample()
 *   2) "Kritik işlemleri (onay, ödeme, imha) gerçekten yetkili kişiler mi
 *      yapıyor?"
 *      → authorizationComplianceSummary()
 *   3) "Şu an çözülmemiş, açıkta bekleyen bir şey var mı?"
 *      → openItemsSummary()
 *
 * Bu servis hiçbir şeyi "düzeltmez" — sadece mevcut durumu şeffafça
 * raporlar, denetçiye sunulacak somut bir çıktı üretir.
 */
final class AuditReadinessService
{
    public function __construct(
        private PDO $pdo,
        private EventStore $events,
        private ReportingService $reporting
    ) {
    }

    /** Rastgele seçilen N entity'nin hash-chain bütünlüğünü doğrular. */
    public function verifyChainIntegritySample(int $sampleSize = 20): array
    {
        $stmt = $this->pdo->query(
            "SELECT id FROM trackable_entities ORDER BY RANDOM() LIMIT {$sampleSize}"
        );
        $entityIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $passed = 0;
        $failed = [];
        foreach ($entityIds as $entityId) {
            if ($this->events->verifyChain((int) $entityId)) {
                $passed++;
            } else {
                $failed[] = (int) $entityId;
            }
        }

        return [
            'sampled' => count($entityIds),
            'passed' => $passed,
            'failed' => $failed,
            'all_clean' => $failed === [],
        ];
    }

    /**
     * Override/imha/ödeme kayıtlarının GERÇEKTEN doğru rolden kişilerce
     * yapıldığını veri seviyesinde doğrular — kod bunu zaten engelliyor
     * ama denetçiye "işte veri de bunu kanıtlıyor" demek için ayrı bir
     * bağımsız kontrol.
     */
    public function authorizationComplianceSummary(): array
    {
        $badDestroys = $this->pdo->query(
            "SELECT dr.id FROM destroy_requests dr
             JOIN actors a ON a.id = dr.confirmed_by_actor_id
             WHERE dr.status = 'confirmed' AND a.role NOT IN ('supervizor','yonetici')"
        )->fetchAll(PDO::FETCH_COLUMN);

        $selfConfirmedDestroys = $this->pdo->query(
            "SELECT id FROM destroy_requests WHERE status = 'confirmed' AND requested_by_actor_id = confirmed_by_actor_id"
        )->fetchAll(PDO::FETCH_COLUMN);

        $badRefunds = $this->pdo->query(
            "SELECT r.id FROM refunds r
             JOIN actors a ON a.id = r.paid_by_actor_id
             WHERE r.status = 'paid' AND a.role NOT IN ('muhasebe','yonetici')"
        )->fetchAll(PDO::FETCH_COLUMN);

        return [
            'unauthorized_destroys' => $badDestroys,
            'self_confirmed_destroys' => $selfConfirmedDestroys,
            'unauthorized_refund_payments' => $badRefunds,
            'all_clean' => $badDestroys === [] && $selfConfirmedDestroys === [] && $badRefunds === [],
        ];
    }

    /** Denetim anında hâlâ açıkta olan, çözülmemiş her şeyin özeti. */
    public function openItemsSummary(): array
    {
        return [
            'overdue_refunds' => $this->reporting->overdueRefundsSummary(),
            'unresolved_duplicate_scans' => $this->reporting->unresolvedDuplicateScanCount(),
            'open_investigations' => $this->reporting->openInvestigationCount(),
            'disputed_carrier_events' => $this->reporting->disputedCarrierEventCount(),
            'stale_inventory_count' => count($this->reporting->staleInventory(30)),
            'quarantined_count' => (int) $this->pdo->query("SELECT COUNT(*) FROM trackable_entities WHERE status = 'QUARANTINED'")->fetchColumn(),
        ];
    }

    public function generateAuditReadinessReport(int $chainSampleSize = 20): array
    {
        return [
            'generated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'chain_integrity' => $this->verifyChainIntegritySample($chainSampleSize),
            'authorization_compliance' => $this->authorizationComplianceSummary(),
            'open_items' => $this->openItemsSummary(),
        ];
    }
}
