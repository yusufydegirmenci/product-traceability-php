<?php

declare(strict_types=1);

// TODO: Refactor ReportingService into domain-specific sub-services.
// DENETİM BULGUSU (üç-gözlü inceleme, geliştirici bakışı): bu sınıf artık
// 22 metotlu ve en az 4 farklı sorumluluğu (temel KPI/dashboard raporlama,
// sahtecilik/işbirliği tespiti [approverHubAnomaly, staffServingMultipleApprovers,
// newDealerVelocityAnomaly, staffDamageRateAnomaly], zaman/kayma analizi
// [capacityDriftAnalysis, systemWideAggregateDrift, shiftHandoverConcentration],
// ve FIFO/operasyonel yardımcı [oldestAvailableLot, actorDeviceFirstUse]) tek
// çatı altında topluyor — klasik bir "god class" riski. ŞU AN ÇALIŞIYOR ve
// test kapsamı tam, ama ileride bölünmelidir (örn. FraudDetectionReportingService,
// CapacityAnalysisService, OperationalQueryService). Bu, 50+ demo bölümündeki
// çağrı noktalarını riske atmadan, AYRI ve dikkatli bir refactor turu
// gerektirir — bilinçli olarak bu turda ERTELENDİ, unutulmadı.

namespace Traceability\Ledger;

use PDO;

/**
 * Sistem zaten her şeyi kaydediyor — bu sınıf o kayıtları yönetimin
 * karar verebileceği sayılara dönüştürür. Hiçbir yeni veri üretmez,
 * sadece var olanı özetler.
 */
final class ReportingService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function overdueRefundsSummary(): array
    {
        $row = $this->pdo->query(
            "SELECT COUNT(*) as cnt, COALESCE(AVG(julianday('now') - julianday(requested_at)),0) as avg_days
             FROM refunds WHERE status = 'pending'"
        )->fetch(PDO::FETCH_ASSOC);
        return ['count' => (int) $row['cnt'], 'avg_days_pending' => round((float) $row['avg_days'], 1)];
    }

    /** actor_risk_score'u üç seviyeye (normal/orta/yüksek) dağıtır. */
    public function riskDistribution(): array
    {
        $rows = $this->pdo->query('SELECT rolling_score FROM actor_risk_score')->fetchAll(PDO::FETCH_COLUMN);
        $dist = ['normal' => 0, 'orta' => 0, 'yuksek' => 0];
        foreach ($rows as $score) {
            $score = (float) $score;
            if ($score >= 60) $dist['yuksek']++;
            elseif ($score >= 25) $dist['orta']++;
            else $dist['normal']++;
        }
        return $dist;
    }

    /** epcis_events.metadata içindeki return_reason_category'yi sayar. */
    public function returnReasonBreakdown(): array
    {
        $stmt = $this->pdo->query(
            "SELECT metadata FROM epcis_events WHERE disposition IN ('returned','return_expired')"
        );
        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $meta = json_decode((string) $json, true) ?? [];
            $reason = $meta['return_reason_category'] ?? 'bilinmiyor';
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }
        return $counts;
    }

    public function disputedCarrierEventCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM carrier_events WHERE disputed = 1')->fetchColumn();
    }

    public function openInvestigationCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM customer_claims WHERE status != 'resolved'")->fetchColumn();
    }

    public function unresolvedDuplicateScanCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM duplicate_scan_flags WHERE resolved = 0')->fetchColumn();
    }

    /** Depoya girmiş ama X gündür hiçbir aşamaya ilerlememiş ürünler — sessiz kayıp/çalıntı riski. */
    public function staleInventory(int $days = 30): array
    {
        $cutoff = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "SELECT te.*, p.name as product_name FROM trackable_entities te
             JOIN products p ON p.id = te.product_id
             WHERE te.status IN ('CREATED','IN_WAREHOUSE') AND te.updated_at < :cutoff"
        );
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** İçeride "kargolandı" işaretlenmiş ama kargo firmasından hiç teslim alma teyidi gelmemiş kayıtlar. */
    public function shippedWithoutCarrierConfirmation(int $hoursThreshold = 48): array
    {
        $cutoff = (new \DateTimeImmutable("-{$hoursThreshold} hours"))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "SELECT e.* FROM epcis_events e
             WHERE e.biz_step = 'shipping' AND e.event_time < :cutoff
               AND NOT EXISTS (
                   SELECT 1 FROM carrier_events c WHERE c.entity_id = e.entity_id
               )"
        );
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** En çok iade edilen ürünleri döner. */
    public function topReturnedProducts(int $limit = 5): array
    {
        $stmt = $this->pdo->query(
            "SELECT p.name, COUNT(*) as cnt
             FROM epcis_events e
             JOIN trackable_entities te ON te.id = e.entity_id
             JOIN products p ON p.id = te.product_id
             WHERE e.disposition IN ('returned','return_expired','return_flagged')
             GROUP BY p.name ORDER BY cnt DESC LIMIT {$limit}"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * çok ülkeli yapı için: bölge sorumlusunun TEK ekranda tüm ülkelerdeki
     * risk yoğunlaşmasını görmesi. Hangi ülkede anormal bir birikme varsa
     * anında fark edilir — tek tek her depoya girmeye gerek kalmaz.
     */
    public function crossCountryRiskOverview(): array
    {
        $stmt = $this->pdo->query(
            "SELECT a.tenant_country, COUNT(*) as signal_count, COALESCE(SUM(re.severity), 0) as total_severity
             FROM risk_events re
             JOIN actors a ON a.id = re.actor_id
             WHERE re.resolved = 0
             GROUP BY a.tenant_country
             ORDER BY total_severity DESC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Kanıtla çürütülmüş (unverified_claim) bir iddiayı kabul etmeyip
     * TEKRAR TEKRAR benzer şikayet açan bayi/müşteri — normal sahtecilikten
     * FARKLI bir motivasyon paterni (itibar saldırısı riski). Sistem bunu
     * bir SUÇLAMA olarak değil, "bu kişiyle ilişki yönetimi insan eliyle
     * konuşulmalı" sinyali olarak üretir.
     */
    /**
     * Belirli bir personelle sürekli eşleşen bir onaylayıcı yerine, TEK
     * bir onaylayıcının ÇOK SAYIDA FARKLI personel/ülke üzerinde
     * override onayladığı bir "merkez" (hub) paterni — bu, en yetkili
     * rolün (yonetici) birden fazla ülkede birden fazla kişiyi
     * kullanarak organize ettiği bir komployu yakalamak için tasarlandı.
     * countOverridesByPair() İKİLİ eşleşmeye bakar, bu metod TEK KİŞİNİN
     * TOPLAM YAYILIMINA bakar.
     */
    public function approverHubAnomaly(int $minDistinctStaff = 3): array
    {
        $stmt = $this->pdo->query(
            "SELECT metadata, actor_id, read_point_location_id FROM epcis_events WHERE biz_step = 'receiving'"
        );
        $byApprover = []; // approver_id => ['staff' => Set, 'countries' => Set]
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $meta = json_decode((string) $row['metadata'], true) ?? [];
            $approver = $meta['approved_by_supervisor_id'] ?? null;
            if ($approver === null) {
                continue;
            }
            $byApprover[$approver]['staff'][(int) $row['actor_id']] = true;
        }

        $anomalies = [];
        foreach ($byApprover as $approverId => $data) {
            $distinctStaff = count($data['staff']);
            if ($distinctStaff >= $minDistinctStaff) {
                $roleStmt = $this->pdo->prepare('SELECT name, role FROM actors WHERE id = :id');
                $roleStmt->execute([':id' => $approverId]);
                $actorInfo = $roleStmt->fetch(PDO::FETCH_ASSOC);
                $anomalies[] = [
                    'approver_id' => (int) $approverId,
                    'approver_name' => $actorInfo['name'] ?? null,
                    'approver_role' => $actorInfo['role'] ?? null,
                    'distinct_staff_count' => $distinctStaff,
                ];
            }
        }
        return $anomalies;
    }

    /**
     * Bir personelin İKİ FARKLI onaylayıcı (örn. iki farklı ülkenin
     * müdürü) tarafından onaylandığı ÇİFT SADAKAT paterni — approverHubAnomaly
     * TEK bir onaylayıcının yayılımına bakar, bu metod TERSİNE bakar:
     * TEK bir personelin kaç FARKLI onaylayıcıya hizmet ettiğine. Bu,
     * "bir çalışan hem ana şirket müdürüne hem başka bir ülkenin
     * müdürüne birden bağlı" gibi çapraz ihanet/çifte ajanlık paternini
     * yakalamak için tasarlandı.
     */
    /**
     * BLACK MIRROR DURUM 10 — Karşı-Adli Delil Zehirleme'ye karşı ZAYIF ama
     * gerçek bir düzeltici sinyal: bu, hash-chain'in KANITLAYAMADIĞI şeyi
     * (verinin BAŞTAN doğru aktöre ait olup olmadığını) kısmen telafi eder.
     * Bir aktör, kritik bir olayda (imha, override onayı) daha önce HİÇ
     * kullanmadığı bir cihaz/lokasyon ile eşleşiyorsa, bu ek doğrulama
     * gerektiren bir "ilk kullanım" işaretidir — KESİN kanıt değildir.
     */
    public function actorDeviceFirstUse(int $actorId, ?int $deviceId): bool
    {
        if ($deviceId === null) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM epcis_events WHERE actor_id = :actor AND device_id = :device'
        );
        $stmt->execute([':actor' => $actorId, ':device' => $deviceId]);
        return ((int) $stmt->fetchColumn()) <= 1; // <=1 çünkü sorgulanan olayın kendisi de sayılıyor olabilir
    }

    /**
     * BLACK MIRROR DURUM 11 — Vardiya Değişimi Sabotajı. Olayların vardiya
     * değişim dakikalarına (varsayılan 07:45/15:45/23:45) orantısız
     * yoğunlaştığını tespit eder. $lotPattern verilirse (örn. 'RT8-'), sadece
     * o desene uyan lot'lardaki olaylar taranır — genel demo gürültüsüne
     * karışmasın diye.
     */
    public function shiftHandoverConcentration(array $handoverTimes = ['07:45', '15:45', '23:45'], int $windowMinutes = 20, ?string $lotPattern = null): array
    {
        $sql = "SELECT e.event_time FROM epcis_events e";
        $params = [];
        if ($lotPattern !== null) {
            $sql .= " JOIN trackable_entities te ON te.id = e.entity_id WHERE te.lot_number LIKE :lot";
            $params[':lot'] = $lotPattern . '%';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $total = 0; $nearHandover = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $total++;
            $hm = substr((string) $t, 11, 5);
            if ($hm === '') { continue; }
            $hmSeconds = ((int) substr($hm, 0, 2)) * 3600 + ((int) substr($hm, 3, 2)) * 60;
            foreach ($handoverTimes as $hp) {
                $hpSeconds = ((int) substr($hp, 0, 2)) * 3600 + ((int) substr($hp, 3, 2)) * 60;
                if (abs($hmSeconds - $hpSeconds) <= $windowMinutes * 60) {
                    $nearHandover++;
                    break;
                }
            }
        }

        $expectedRatio = (count($handoverTimes) * $windowMinutes * 2) / (24 * 60);
        $actualRatio = $total > 0 ? $nearHandover / $total : 0;
        return [
            'total' => $total,
            'near_handover' => $nearHandover,
            'actual_ratio' => round($actualRatio, 3),
            'expected_ratio' => round($expectedRatio, 3),
            'concentration_factor' => $expectedRatio > 0 ? round($actualRatio / $expectedRatio, 2) : 0.0,
        ];
    }

    /**
     * RT8.3 DURUM: ASİMETRİK YAVAŞ KAYMA / ROLLING BASELINE POISONING.
     * "Depo kapasitesi" bizim şemamızda literal bir alan değil — bunun
     * gerçekçi bir vekili (proxy) olarak, bu lokasyonda GÜNLÜK
     * commissioning event sayısını (günlük işlenen ürün hacmi) kullanıyoruz.
     * Saldırgan günden güne küçük, dalgalı (bazen +, bazen -) değişiklikler
     * yaparsa, bir "dün-bugün" karşılaştırması hiçbir şey yakalamaz — ama
     * SABİT bir eski "altın standart" dönemle (örn. ilk 30 gün) şimdiki
     * kısa pencere ortalamasını kıyaslamak kaymayı ortaya çıkarır.
     */
    public function capacityDriftAnalysis(int $locationId, string $goldStartDate, string $goldEndDate, string $recentStartDate, string $recentEndDate): array
    {
        $goldStmt = $this->pdo->prepare(
            "SELECT COUNT(*) as cnt, COUNT(DISTINCT date(event_time)) as days
             FROM epcis_events WHERE read_point_location_id = :loc AND biz_step = 'commissioning'
               AND event_time BETWEEN :start AND :end"
        );
        $goldStmt->execute([':loc' => $locationId, ':start' => $goldStartDate, ':end' => $goldEndDate]);
        $gold = $goldStmt->fetch(PDO::FETCH_ASSOC);
        $goldDailyAvg = ((int) $gold['days']) > 0 ? ((int) $gold['cnt']) / ((int) $gold['days']) : 0;

        $recentStmt = $this->pdo->prepare(
            "SELECT COUNT(*) as cnt, COUNT(DISTINCT date(event_time)) as days
             FROM epcis_events WHERE read_point_location_id = :loc AND biz_step = 'commissioning'
               AND event_time BETWEEN :start AND :end"
        );
        $recentStmt->execute([':loc' => $locationId, ':start' => $recentStartDate, ':end' => $recentEndDate]);
        $recent = $recentStmt->fetch(PDO::FETCH_ASSOC);
        $recentDailyAvg = ((int) $recent['days']) > 0 ? ((int) $recent['cnt']) / ((int) $recent['days']) : 0;

        $driftPct = $goldDailyAvg > 0 ? (($goldDailyAvg - $recentDailyAvg) / $goldDailyAvg) * 100 : 0;
        return [
            'gold_daily_avg' => round($goldDailyAvg, 1),
            'recent_daily_avg' => round($recentDailyAvg, 1),
            'drift_pct' => round($driftPct, 1),
        ];
    }

    /**
     * RT8.5 DARBE 4 — ASİMETRİK UBO/DRIFT PARÇALANMASI'na karşı. Tek bir
     * lokasyonun/UBO'nun kaymasına bakan capacityDriftAnalysis() ve
     * sharedBeneficiaryConcentrationByOwner(), her biri kendi eşiğinin
     * ALTINDA kalacak şekilde 100 parçaya bölünmüş bir saldırıyı KAÇIRIR.
     * Bu metod TEK TEK değil, TÜM lokasyonların TOPLAMINA bakar — %0.1'lik
     * 100 parça, sistem genelinde gerçek bir %10 kayıp üretiyorsa bunu
     * yakalar, hangi lokasyonun/kimin sorumlu olduğunu söylemez (bu bir
     * KİŞİ/LOKASYON sinyali değil, SİSTEM SAĞLIĞI sinyalidir).
     */
    public function systemWideAggregateDrift(string $goldStartDate, string $goldEndDate, string $recentStartDate, string $recentEndDate): array
    {
        $goldStmt = $this->pdo->prepare(
            "SELECT COUNT(*) as cnt, COUNT(DISTINCT date(event_time)) as days
             FROM epcis_events WHERE biz_step = 'commissioning' AND event_time BETWEEN :start AND :end"
        );
        $goldStmt->execute([':start' => $goldStartDate, ':end' => $goldEndDate]);
        $gold = $goldStmt->fetch(PDO::FETCH_ASSOC);
        $goldDailyAvg = ((int) $gold['days']) > 0 ? ((int) $gold['cnt']) / ((int) $gold['days']) : 0;

        $recentStmt = $this->pdo->prepare(
            "SELECT COUNT(*) as cnt, COUNT(DISTINCT date(event_time)) as days
             FROM epcis_events WHERE biz_step = 'commissioning' AND event_time BETWEEN :start AND :end"
        );
        $recentStmt->execute([':start' => $recentStartDate, ':end' => $recentEndDate]);
        $recent = $recentStmt->fetch(PDO::FETCH_ASSOC);
        $recentDailyAvg = ((int) $recent['days']) > 0 ? ((int) $recent['cnt']) / ((int) $recent['days']) : 0;

        $driftPct = $goldDailyAvg > 0 ? (($goldDailyAvg - $recentDailyAvg) / $goldDailyAvg) * 100 : 0;
        return ['gold_daily_avg' => round($goldDailyAvg, 1), 'recent_daily_avg' => round($recentDailyAvg, 1), 'drift_pct' => round($driftPct, 1)];
    }

    /**
     * harici bir gÃ¶zden geÃ§iren'nin önerdiği "FIFO Zorunluluğu": tekil ID'si olmayan (lot-bazlı)
     * ürünlerde, personel HANGİ lot'un en eski olduğunu manuel takip etmek
     * zorunda kalmasın — sistem otomatik olarak en eski (üretim tarihi en
     * eski, kalan miktarı yeterli) lot'u önerir. Bu, hem doğal stok rotasyonu
     * sağlar hem de "çöpten çıkan ürünün tarihini tahmin etme" işini daha
     * kolay/doğru hale getirir (her zaman en eski lot tüketildiği için).
     */
    public function oldestAvailableLot(int $productId, int $locationId, int $quantityNeeded): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT te.* FROM trackable_entities te
             WHERE te.product_id = :product_id
               AND te.entity_type = 'lot'
               AND te.quantity_remaining >= :qty
               AND te.status IN ('CREATED', 'IN_WAREHOUSE')
               AND (
                   SELECT e.read_point_location_id FROM epcis_events e
                   WHERE e.entity_id = te.id ORDER BY e.id DESC LIMIT 1
               ) = :location_id
             ORDER BY te.production_date ASC, te.id ASC
             LIMIT 1"
        );
        $stmt->execute([':product_id' => $productId, ':qty' => $quantityNeeded, ':location_id' => $locationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function staffServingMultipleApprovers(int $minDistinctApprovers = 2): array
    {
        $stmt = $this->pdo->query(
            "SELECT metadata, actor_id FROM epcis_events WHERE biz_step = 'receiving'"
        );
        $byStaff = []; // staff_id => Set of approver_ids
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $meta = json_decode((string) $row['metadata'], true) ?? [];
            $approver = $meta['approved_by_supervisor_id'] ?? null;
            if ($approver === null) {
                continue;
            }
            $byStaff[(int) $row['actor_id']]['approvers'][$approver] = true;
        }

        $anomalies = [];
        foreach ($byStaff as $staffId => $data) {
            $distinctApprovers = count($data['approvers']);
            if ($distinctApprovers >= $minDistinctApprovers) {
                $roleStmt = $this->pdo->prepare('SELECT name, tenant_country FROM actors WHERE id = :id');
                $roleStmt->execute([':id' => $staffId]);
                $staffInfo = $roleStmt->fetch(PDO::FETCH_ASSOC);
                $anomalies[] = [
                    'staff_id' => (int) $staffId,
                    'staff_name' => $staffInfo['name'] ?? null,
                    'staff_country' => $staffInfo['tenant_country'] ?? null,
                    'distinct_approver_count' => $distinctApprovers,
                    'approver_ids' => array_keys($data['approvers']),
                ];
            }
        }
        return $anomalies;
    }

    public function repeatUnverifiedClaimants(int $minCount = 2): array
    {
        $stmt = $this->pdo->query(
            "SELECT cc.claimed_by_actor_id, a.name, COUNT(*) as unverified_count
             FROM investigation_results ir
             JOIN customer_claims cc ON cc.id = ir.claim_id
             JOIN actors a ON a.id = cc.claimed_by_actor_id
             WHERE ir.conclusion = 'unverified_claim'
             GROUP BY cc.claimed_by_actor_id, a.name
             HAVING COUNT(*) >= {$minCount}
             ORDER BY unverified_count DESC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Dış firmalarla işbirliği yapan personelin ürettiği "sahte bayi"
     * paterni: YENİ oluşturulmuş bir bayi hesabının, kısa süre içinde
     * anormal derecede yüksek işlem hacmine ulaşması. Gerçek bayiler
     * genelde zamanla organik olarak büyür — bir hesabın ilk günden
     * itibaren yüksek hacimli olması şüphelidir.
     */
    public function newDealerVelocityAnomaly(int $withinDays = 7, int $minTransactions = 3): array
    {
        $stmt = $this->pdo->query(
            "SELECT a.id, a.name, a.created_at, COUNT(e.id) as transaction_count
             FROM actors a
             JOIN epcis_events e ON e.actor_id = a.id
             WHERE a.actor_type = 'dealer' AND a.created_at IS NOT NULL
             GROUP BY a.id, a.name, a.created_at
             HAVING transaction_count >= {$minTransactions}"
        );
        $anomalies = [];
        $now = new \DateTimeImmutable();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $createdAt = new \DateTimeImmutable($row['created_at']);
            $ageDays = $now->diff($createdAt)->days;
            if ($ageDays <= $withinDays) {
                $row['age_days'] = $ageDays;
                $anomalies[] = $row;
            }
        }
        return $anomalies;
    }

    /**
     * Belirli bir personelin paketlediği kutuların, diğer personele göre
     * ORANTISIZ yüksek oranda "hasarlı geldi" şikayeti alması — kasıtlı
     * sabotaj (belirli/büyük bayilere bilerek yırtık kutu gönderme) paterni.
     */
    public function staffDamageRateAnomaly(int $minPacked = 3): array
    {
        $packedStmt = $this->pdo->query(
            "SELECT actor_id, COUNT(*) as packed_count FROM epcis_events WHERE biz_step = 'packing' GROUP BY actor_id"
        );
        $packed = [];
        foreach ($packedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $packed[(int) $row['actor_id']] = (int) $row['packed_count'];
        }

        $damagedStmt = $this->pdo->query(
            "SELECT te_events.actor_id, COUNT(*) as damaged_count FROM epcis_events te_events
             JOIN trackable_entities te ON te.id = te_events.entity_id
             WHERE te_events.biz_step = 'packing' AND te.id IN (
                 SELECT entity_id FROM epcis_events WHERE metadata LIKE '%\"box_damaged\":true%'
             )
             GROUP BY te_events.actor_id"
        );
        $damaged = [];
        foreach ($damagedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $damaged[(int) $row['actor_id']] = (int) $row['damaged_count'];
        }

        $anomalies = [];
        foreach ($packed as $actorId => $packedCount) {
            if ($packedCount < $minPacked) {
                continue;
            }
            $damagedCount = $damaged[$actorId] ?? 0;
            $rate = $damagedCount / $packedCount;
            if ($rate > 0.2) { // %20'den fazla hasar oranı — makul bir başlangıç eşiği, gerçek veriyle kalibre edilmeli
                $anomalies[] = ['actor_id' => $actorId, 'packed_count' => $packedCount, 'damaged_count' => $damagedCount, 'rate' => round($rate, 2)];
            }
        }
        return $anomalies;
    }

    /** Tek ekranda özet — bir yönetim panosunun besleyeceği tüm veri. */
    public function dashboardSnapshot(): array
    {
        return [
            'overdue_refunds' => $this->overdueRefundsSummary(),
            'risk_distribution' => $this->riskDistribution(),
            'return_reasons' => $this->returnReasonBreakdown(),
            'disputed_carrier_events' => $this->disputedCarrierEventCount(),
            'open_investigations' => $this->openInvestigationCount(),
            'unresolved_duplicate_scans' => $this->unresolvedDuplicateScanCount(),
            'top_returned_products' => $this->topReturnedProducts(),
            'generated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];
    }
}
