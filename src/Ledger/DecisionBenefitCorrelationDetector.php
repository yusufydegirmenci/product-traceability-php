<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use PDO;

/**
 * RED TEAM 7 (Gölge Ağ) tatbikatının kanıtladığı TEK gerçek boşluk için
 * yazıldı — ve SADECE o boşluk için. Mevcut altı dedektör
 * (countOverridesByPair, approverHubAnomaly, staffServingMultipleApprovers,
 * crossCountryRiskOverview, newDealerVelocityAnomaly, staffDamageRateAnomaly)
 * hepsi KİŞİ veya İKİLİ bazında çalışır. Gölge Ağ saldırısı tam olarak bunu
 * istismar etti: her kişi farklı, her ikili tek seferlik, her onay ayrı bir
 * yetkiliden, aylara yayılmış — hiçbiri eşiği tek başına geçmedi.
 *
 * Bu sınıf KİŞİYİ değil, NİHAİ FAYDALANICIYI (dealer_actor_id) izler: aynı
 * bayi/faydalanıcıya, UZUN bir pencerede, ÇOK SAYIDA FARKLI ülkeden, FARKLI
 * personelden, FARKLI onaylayıcılardan gelen istisna/override akışı
 * varsa — kimin ne yaptığından bağımsız olarak — bu bir ŞEBEKE sinyalidir.
 *
 * KASITLI TASARIM SINIRI: bu sınıf HİÇBİR BİREYSEL risk skoruna dokunmaz.
 * Sadece bir "organized_network_suspected" bulgusu üretir, bunu bir KİŞİYE
 * değil bir FAYDALANICIYA (dealer) bağlar — masum katılımcıların (İpek,
 * Kemal, Gül gibi, her biri tek, meşru görünen bir işlem yaptı) yanlışlıkla
 * damgalanmaması bu ayrımla garanti edilir.
 */
final class DecisionBenefitCorrelationDetector
{
    public function __construct(private PDO $pdo)
    {
    }

    public function sharedBeneficiaryConcentration(
        int $windowDays = 180,
        int $minDistinctCountries = 3,
        int $minDistinctStaff = 4,
        int $minDistinctApprovers = 4
    ): array {
        $cutoff = (new \DateTimeImmutable("-{$windowDays} days"))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "SELECT e.metadata, e.actor_id, a.tenant_country
             FROM epcis_events e
             JOIN actors a ON a.id = e.actor_id
             WHERE e.biz_step = 'receiving'
               AND e.disposition IN ('returned','return_expired','return_flagged')
               AND e.event_time >= :cutoff"
        );
        $stmt->execute([':cutoff' => $cutoff]);

        $byDealer = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $meta = json_decode((string) $row['metadata'], true) ?? [];
            $dealerId = $meta['dealer_actor_id'] ?? null;
            if ($dealerId === null) {
                continue;
            }
            $approver = $meta['approved_by_supervisor_id'] ?? null;
            $byDealer[$dealerId]['countries'][$row['tenant_country']] = true;
            $byDealer[$dealerId]['staff'][(int) $row['actor_id']] = true;
            if ($approver !== null) {
                $byDealer[$dealerId]['approvers'][$approver] = true;
            }
            $byDealer[$dealerId]['tx_count'] = ($byDealer[$dealerId]['tx_count'] ?? 0) + 1;
        }

        $flags = [];
        foreach ($byDealer as $dealerId => $d) {
            $countryCount = count($d['countries']);
            $staffCount = count($d['staff']);
            $approverCount = count($d['approvers'] ?? []);
            if ($countryCount >= $minDistinctCountries && $staffCount >= $minDistinctStaff && $approverCount >= $minDistinctApprovers) {
                $nameStmt = $this->pdo->prepare('SELECT name FROM actors WHERE id = :id');
                $nameStmt->execute([':id' => $dealerId]);
                $flags[] = [
                    'dealer_id' => (int) $dealerId,
                    'dealer_name' => $nameStmt->fetchColumn(),
                    'distinct_countries' => $countryCount,
                    'distinct_staff' => $staffCount,
                    'distinct_approvers' => $approverCount,
                    'transaction_count' => $d['tx_count'],
                    'countries' => array_keys($d['countries']),
                ];
            }
        }
        return $flags;
    }

    /**
     * RT8.3 DURUM: ENTITY SPLITTING'e karşı. Yukarıdaki metod TEK bir
     * dealer_id'ye bakar — saldırgan 10 farklı hayalet dealer_id açıp her
     * birini eşiğin altında tutarsa (her biri "bağımsız, meşru, düşük
     * hacimli" görünür) o metod HİÇBİRİNİ yakalayamaz. Bu metod dealer_id
     * YERİNE beneficial_owner_ref (ortak vergi no/banka hesabı) düzeyinde
     * gruplar — birden fazla dealer_id AYNI UBO'ya bağlıysa, hepsi TEK bir
     * varlık olarak birleştirilip aynı eşiklere tabi tutulur.
     *
     * DÜRÜST SINIR: bu, SADECE beneficial_owner_ref alanı DOLDURULMUŞSA
     * çalışır — yani şirketin KYC/müşteri kabul sürecinde bu bilginin
     * gerçekten toplanması ŞARTTIR. Alan boşsa (mevcut durumda olduğu gibi)
     * sistem yine kördür — bu kod tek başına KYC sürecinin yerini tutmaz.
     */
    public function sharedBeneficiaryConcentrationByOwner(
        int $windowDays = 180,
        int $minDistinctCountries = 3,
        int $minDistinctStaff = 4,
        int $minDistinctApprovers = 4,
        int $minDistinctDealerIds = 2
    ): array {
        $cutoff = (new \DateTimeImmutable("-{$windowDays} days"))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "SELECT e.metadata, e.actor_id, a.tenant_country
             FROM epcis_events e
             JOIN actors a ON a.id = e.actor_id
             WHERE e.biz_step = 'receiving'
               AND e.disposition IN ('returned','return_expired','return_flagged')
               AND e.event_time >= :cutoff"
        );
        $stmt->execute([':cutoff' => $cutoff]);

        $byOwner = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $meta = json_decode((string) $row['metadata'], true) ?? [];
            $dealerId = $meta['dealer_actor_id'] ?? null;
            if ($dealerId === null) {
                continue;
            }
            $ownerStmt = $this->pdo->prepare('SELECT beneficial_owner_ref FROM actors WHERE id = :id');
            $ownerStmt->execute([':id' => $dealerId]);
            $owner = $ownerStmt->fetchColumn();
            if ($owner === false || $owner === null || $owner === '') {
                continue; // UBO bilgisi yoksa bu metodun kapsamı dışında — dürüstçe atlanıyor
            }
            $approver = $meta['approved_by_supervisor_id'] ?? null;
            $byOwner[$owner]['countries'][$row['tenant_country']] = true;
            $byOwner[$owner]['staff'][(int) $row['actor_id']] = true;
            $byOwner[$owner]['dealer_ids'][(int) $dealerId] = true;
            if ($approver !== null) {
                $byOwner[$owner]['approvers'][$approver] = true;
            }
            $byOwner[$owner]['tx_count'] = ($byOwner[$owner]['tx_count'] ?? 0) + 1;
        }

        $flags = [];
        foreach ($byOwner as $owner => $d) {
            $countryCount = count($d['countries']);
            $staffCount = count($d['staff']);
            $approverCount = count($d['approvers'] ?? []);
            $dealerCount = count($d['dealer_ids']);
            if ($countryCount >= $minDistinctCountries && $staffCount >= $minDistinctStaff
                && $approverCount >= $minDistinctApprovers && $dealerCount >= $minDistinctDealerIds) {
                $flags[] = [
                    'beneficial_owner_ref' => $owner,
                    'distinct_dealer_ids' => $dealerCount,
                    'dealer_ids' => array_keys($d['dealer_ids']),
                    'distinct_countries' => $countryCount,
                    'distinct_staff' => $staffCount,
                    'distinct_approvers' => $approverCount,
                    'transaction_count' => $d['tx_count'],
                ];
            }
        }
        return $flags;
    }
}
