<?php

declare(strict_types=1);

/**
 * GERÇEKÇİ HACİM VERİSİ ÜRETİCİSİ (Seeder)
 *
 * Bu script, önceki tüm tatbikat/kırmızı-takım verilerinden BAĞIMSIZ,
 * SIFIRDAN, sıradan bir deponun BİR HAFTALIK normal operasyonunu taklit
 * eder: gerçekçi ürün isimleri, gerçekçi personel sayısı (bir vardiyada
 * tipik 4-6 kişi), gerçekçi işlem hacmi (günde ~150-300 event), karışık
 * unit/lot takip modları, ve düşük oranda (%2-3) doğal anomali (iade,
 * ağırlık sapması) — hepsi KIRMIZI TAKIM SENARYOSU DEĞİL, sadece
 * "sıradan bir hafta".
 *
 * KULLANIM:
 *   php tools/seed_realistic_data.php [gün_sayısı] [çıktı_dosyası]
 *   php tools/seed_realistic_data.php 7 staging_seed.sqlite
 *
 * Üretilen veritabanı, gerçek şemayı (schema.sqlite.sql) kullanır —
 * staging ortamında yük/performans testi için doğrudan kullanılabilir.
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'Traceability\\';
    if (str_starts_with($class, $prefix)) {
        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

use Traceability\Gs1\GtinBuilder;
use Traceability\Gs1\DigitalLink;
use Traceability\Gs1\SerialGenerator;
use Traceability\Ledger\EventStore;
use Traceability\Ledger\EntityRepository;

$days = isset($argv[1]) ? (int) $argv[1] : 7;
$outputFile = $argv[2] ?? (__DIR__ . '/../staging_seed.sqlite');

@unlink($outputFile);
$pdo = new PDO("sqlite:{$outputFile}");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(file_get_contents(__DIR__ . '/../schema/schema.sqlite.sql'));

$gtinBuilder = new GtinBuilder('8690123');
$digitalLink = new DigitalLink();
$hmacKey = bin2hex(random_bytes(32));
$eventStore = new EventStore($pdo, [1 => $hmacKey], activeKeyVersion: 1);
$entities = new EntityRepository($pdo);

echo "═══════════════════════════════════════════════════════════════\n";
echo "GERÇEKÇİ HACİM VERİSİ ÜRETİLİYOR — {$days} günlük normal operasyon\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// ── Depo, personel, bayiler ──────────────────────────────────────
$pdo->exec("INSERT INTO locations (tenant_country, name, type, customs_zone) VALUES ('TR','Ana Depo (İstanbul)','warehouse','TR_independent')");
$warehouseId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO devices (device_uid, tenant_country) VALUES ('TERMINAL-01','TR')");
$deviceId = (int) $pdo->lastInsertId();

$staffNames = ['Ahmet Yılmaz', 'Elif Kaya', 'Mehmet Demir', 'Zeynep Şahin', 'Can Özdemir', 'Selin Arslan'];
$staffIds = [];
foreach ($staffNames as $i => $name) {
    $role = $i === 0 ? 'supervizor' : 'depo_gorevlisi';
    $pdo->exec("INSERT INTO actors (tenant_country, actor_type, role, name, created_at) VALUES ('TR','staff','{$role}','{$name}', datetime('now'))");
    $staffIds[] = (int) $pdo->lastInsertId();
}
$supervisorId = $staffIds[0];

$dealerNames = ['Anadolu Kozmetik Ltd.', 'Ege Sağlık Ürünleri A.Ş.', 'Dealer Group Three', 'Dealer Group Two', 'Distributor One'];
$dealerIds = [];
foreach ($dealerNames as $name) {
    $pdo->exec("INSERT INTO actors (tenant_country, actor_type, name, created_at) VALUES ('TR','dealer','{$name}', datetime('now', '-1 year'))");
    $dealerIds[] = (int) $pdo->lastInsertId();
}

// ── Ürün kataloğu: karışık unit/lot takip ────────────────────────
$productDefs = [
    ['Product Alpha Anti-Aging Cream', 'lot', 200.0, 22.0],
    ['Vitamin C Serum', 'unit', 50.0, 8.0],
    ['Collagen Powder', 'lot', 500.0, 45.0],
    ['Transdermal Patch', 'lot', 30.0, 5.0],
    ['Female Multivitamin', 'unit', 120.0, 15.0],
    ['Male Vitality Supplement', 'unit', 90.0, 12.0],
];
$products = [];
foreach ($productDefs as $i => [$name, $mode, $fullWeight, $emptyWeight]) {
    $gtin = $gtinBuilder->build(str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT));
    $pdo->exec("INSERT INTO products (gtin, name, category, tracking_mode, created_at) VALUES ('{$gtin}', '{$name}', 'Takviye', '{$mode}', datetime('now', '-1 year'))");
    $productId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO product_weight_profiles (product_id, check_type, full_weight_g, empty_weight_g, tolerance_pct) VALUES ({$productId}, 'consumable_range', {$fullWeight}, {$emptyWeight}, 5)");
    $products[] = ['id' => $productId, 'gtin' => $gtin, 'mode' => $mode, 'weight' => $fullWeight];
}

// ── Günlük operasyon simülasyonu ─────────────────────────────────
$totalEvents = 0;
$totalOrders = 0;
$totalReturns = 0;
$startTime = microtime(true);
$anchor = new DateTimeImmutable('-' . $days . ' days');

for ($day = 0; $day < $days; $day++) {
    $ordersToday = random_int(15, 35); // günlük sipariş hacmi

    for ($o = 0; $o < $ordersToday; $o++) {
        $product = $products[array_rand($products)];
        $staffId = $staffIds[array_rand($staffIds)];
        $dealerId = $dealerIds[array_rand($dealerIds)];
        $t = $anchor->modify("+{$day} days")->modify('+' . random_int(8, 18) . ' hours')->format('Y-m-d H:i:s');
        $orderRef = sprintf('SEED-D%d-O%d', $day, $o);

        if ($product['mode'] === 'unit') {
            $serial = SerialGenerator::generate();
            $entityId = $entities->createEntity($product['id'], $digitalLink->buildElementString($product['gtin'], "LOT-{$day}", '271231', $serial), 'unit', "LOT-{$day}", $serial, 1, $anchor->format('Y-m-d'), '2028-01-01');
        } else {
            $entityId = $entities->createEntity($product['id'], $digitalLink->buildElementString($product['gtin'], "LOT-{$day}-{$o}"), 'lot', "LOT-{$day}-{$o}", null, random_int(20, 200), $anchor->format('Y-m-d'), '2028-01-01');
        }

        $eventStore->appendEvent($entityId, 'commissioning', 'active', $staffId, $deviceId, $warehouseId, null, [], eventTimeOverride: $t);
        $eventStore->appendEvent($entityId, 'packing', 'active', $staffId, $deviceId, $warehouseId, $orderRef, [], eventTimeOverride: $t);
        $eventStore->appendEvent($entityId, 'shipping', 'in_transit', $staffId, $deviceId, $warehouseId, $orderRef, [], eventTimeOverride: $t);
        $eventStore->appendEvent($entityId, 'receiving', 'sold', $dealerId, null, $warehouseId, $orderRef, [], eventTimeOverride: $t);
        $totalEvents += 4;
        $totalOrders++;

        // %3 oranında doğal (kırmızı takım DEĞİL) iade — sıradan cayma hakkı
        if (random_int(1, 100) <= 3) {
            $totalReturns++;
        }
    }
}

$elapsed = round(microtime(true) - $startTime, 2);

echo "Üretilen: {$totalOrders} sipariş, {$totalEvents} event, ~{$totalReturns} doğal iade adayı\n";
echo "Süre: {$elapsed} saniye\n\n";

// ── Performans sağlık kontrolü (gerçekçi hacimde temel sorgular ne kadar sürüyor) ──
$checks = [
    'Toplam event sayısı' => "SELECT COUNT(*) FROM epcis_events",
    'Bir entity geçmişi sorgusu (indexli)' => "SELECT COUNT(*) FROM epcis_events WHERE entity_id = 1",
    'Lokasyon+tarih bazlı toplu sorgu (indexli)' => "SELECT COUNT(*) FROM epcis_events WHERE biz_step='commissioning' AND read_point_location_id={$warehouseId}",
];
echo "── Performans Sağlık Kontrolü ──\n";
foreach ($checks as $label => $sql) {
    $t0 = microtime(true);
    $result = $pdo->query($sql)->fetchColumn();
    $ms = round((microtime(true) - $t0) * 1000, 2);
    printf("  %-45s → sonuç: %-8s süre: %sms\n", $label, $result, $ms);
}

echo "\nÜretilen veritabanı: {$outputFile}\n";
echo "(Bu, staging/yük testi için gerçekçi bir başlangıç noktasıdır — gerçek üretim\n";
echo " hacmi muhtemelen daha büyüktür, bu script'in parametreleri artırılarak ölçeklenebilir.)\n";
