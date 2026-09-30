<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use PDO;

/**
 * BLACK MIRROR DURUM 5 — Paylaşılan Kaynak Üzerinden Gizli Ağ.
 * İki kişi arasında beyan edilmiş ilişki (RelationshipGuard) yok, birbirini
 * onaylamıyorlar (countOverridesByPair sessiz), ama AYNI CİHAZ üzerinden
 * çalışıp işlemleri hep AYNI faydalanıcıya yığılıyorsa — bu, dolaylı ama
 * gerçek bir bağdır. Sadece cihaz paylaşımı TEK BAŞINA işaretlenmez (dürüst
 * çalışanlar aynı istasyonu vardiya değişiminde paylaşabilir) — İŞARETLEME
 * ANCAK aynı zamanda ortak bir faydalanıcıya yoğunlaşma varsa yapılır.
 */
final class SharedResourceCollusionDetector
{
    public function __construct(private PDO $pdo)
    {
    }

    public function sharedDeviceHiddenHub(int $minDistinctStaff = 2, int $minSharedTransactions = 3): array
    {
        // Personel (commissioning/shipping event'inin aktörü + cihazı) ile
        // o ürünün NİHAİ ALICISI (receiving event'inin aktörü — bayi) farklı
        // event satırlarındadır; entity_id üzerinden birleştirilir.
        $stmt = $this->pdo->query(
            "SELECT ship.device_id as device_id, ship.actor_id as staff_id, recv.actor_id as dealer_id
             FROM epcis_events ship
             JOIN epcis_events recv ON recv.entity_id = ship.entity_id AND recv.biz_step = 'receiving'
             WHERE ship.device_id IS NOT NULL AND ship.biz_step IN ('commissioning', 'shipping')"
        );

        $byDeviceDealer = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['device_id'] . ':' . $row['dealer_id'];
            $byDeviceDealer[$key]['device_id'] = (int) $row['device_id'];
            $byDeviceDealer[$key]['dealer_id'] = (int) $row['dealer_id'];
            $byDeviceDealer[$key]['staff'][(int) $row['staff_id']] = true;
            $byDeviceDealer[$key]['tx_count'] = ($byDeviceDealer[$key]['tx_count'] ?? 0) + 1;
        }

        $flags = [];
        foreach ($byDeviceDealer as $d) {
            $distinctStaff = count($d['staff']);
            if ($distinctStaff >= $minDistinctStaff && $d['tx_count'] >= $minSharedTransactions) {
                $dealerName = $this->pdo->prepare('SELECT name FROM actors WHERE id = :id');
                $dealerName->execute([':id' => $d['dealer_id']]);
                $flags[] = [
                    'device_id' => $d['device_id'],
                    'dealer_id' => $d['dealer_id'],
                    'dealer_name' => $dealerName->fetchColumn(),
                    'distinct_staff' => $distinctStaff,
                    'staff_ids' => array_keys($d['staff']),
                    'transaction_count' => $d['tx_count'],
                ];
            }
        }
        return $flags;
    }
}
