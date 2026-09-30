<?php

declare(strict_types=1);

// GERÇEK eşzamanlılık testi — 50 AYRI İŞLETİM SİSTEMİ SÜRECİ (pcntl_fork),
// her biri KENDİ PDO bağlantısıyla, AYNI SQLite dosyasına, AYNI ANDA
// yazmaya çalışıyor. Bu, PHP'nin tek-thread'li demo.php'sinde asla
// gerçekten test edilemeyecek bir şeydir — sıralı çağrılar hiçbir zaman
// yarış durumunu ortaya çıkaramaz, çünkü her çağrı bir öncekinin tamamen
// bitmesini bekler. Burada GERÇEKTEN 50 ayrı süreç aynı anda yarışıyor.

spl_autoload_register(function (string $class): void {
    $prefix = 'Traceability\\';
    if (str_starts_with($class, $prefix)) {
        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

use Traceability\Ledger\EntityRepository;
use Traceability\Gs1\GtinBuilder;
use Traceability\Gs1\DigitalLink;

$dbFile = __DIR__ . '/stress_test.sqlite';
$errFile = __DIR__ . '/stress_errors.log';
@unlink($dbFile);
@unlink($errFile);

$pdo = new PDO("sqlite:{$dbFile}");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(file_get_contents(__DIR__ . '/schema/schema.sqlite.sql'));

$gtinBuilder = new GtinBuilder('8690000');
$digitalLink = new DigitalLink();
$entities = new EntityRepository($pdo);

$gtin = $gtinBuilder->build('01001');
$pdo->exec("INSERT INTO products (gtin, name, category, tracking_mode, created_at) VALUES ('{$gtin}', 'Product Alpha Anti-Aging Cream', 'Kozmetik', 'lot', datetime('now'))");
$productId = (int) $pdo->lastInsertId();

$lotEntityId = $entities->createEntity(
    $productId,
    $digitalLink->buildElementString($gtin, 'LOT-2026-AN01'),
    'lot',
    'LOT-2026-AN01',
    null,
    4000,
    '2026-09-13',
    '2028-09-13'
);
echo "Başlangıç: LOT-2026-AN01, 4000 adet, entity #{$lotEntityId}\n";
echo "50 SANAL PAKETLEYİCİ (gerçek, ayrı işletim sistemi süreci) aynı anda 1'er adet düşmeye çalışıyor...\n\n";

$pdo = null; // fork öncesi bağlantıyı kapat — her çocuk kendi bağlantısını açacak

$numWorkers = 50;
$pids = [];
for ($i = 0; $i < $numWorkers; $i++) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        fwrite(STDERR, "fork başarısız (worker {$i})\n");
        exit(1);
    }
    if ($pid === 0) {
        // ÇOCUK SÜREÇ
        try {
            $childPdo = new PDO("sqlite:{$dbFile}");
            $childPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $childPdo->setAttribute(PDO::ATTR_TIMEOUT, 30); // kilit varsa bekle, hata verme
            $childEntities = new EntityRepository($childPdo);
            $childEntities->consumeFromLot($lotEntityId, 1);
        } catch (\Throwable $e) {
            file_put_contents($errFile, "Worker {$i}: " . $e->getMessage() . "\n", FILE_APPEND);
        }
        exit(0);
    }
    $pids[] = $pid;
}

foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
}

$finalPdo = new PDO("sqlite:{$dbFile}");
$remaining = (int) $finalPdo->query("SELECT quantity_remaining FROM trackable_entities WHERE id = {$lotEntityId}")->fetchColumn();
$expected = 4000 - $numWorkers;

echo "SONUÇ:\n";
echo "  Beklenen kalan miktar: {$expected}\n";
echo "  Gerçek kalan miktar:   {$remaining}\n";
echo "  Sonuç: " . ($remaining === $expected ? "DOĞRU ✓ — 50 eşzamanlı süreç, TEK BİR birim bile kaybolmadan/çift sayılmadan işlendi" : "YANLIŞ ✗ — VERİ KAYBI VAR, yarış durumu hâlâ mevcut") . "\n";

if (file_exists($errFile)) {
    echo "\n  Not: bazı worker'lar hata aldı (muhtemelen SQLite kilit zaman aşımı, veri kaybı DEĞİL):\n";
    echo "  " . str_replace("\n", "\n  ", trim(file_get_contents($errFile))) . "\n";
} else {
    echo "  Hiçbir worker hata almadan tamamladı.\n";
}

@unlink($dbFile);
@unlink($errFile);
