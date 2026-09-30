<?php

declare(strict_types=1);

// Gerçek `vendor/bin/phpunit` yerine — Composer/packagist.org bu sandbox'ta
// erişilemez (host_not_allowed) olduğu için elle yazılmış minimal bir
// çalıştırıcı. Gerçek PHPUnit kurulunca bu dosyanın YERİNE
// `vendor/bin/phpunit tests/` çalıştırılır, test dosyalarının HİÇBİRİ
// değişmez (bkz. tests/TestCase.php başlığı).
//
// CI KATMANLAMASI: KULLANIM:
//   php tests/run-tests.php              → hepsini çalıştırır (Unit+Integration+Concurrency)
//   php tests/run-tests.php --group=fast → SADECE Unit+Integration (milisaniye seviyesi, HER commit'te)
//   php tests/run-tests.php --group=concurrency → SADECE Concurrency (gerçek süreç çatallama,
//                                                   saniyeler sürer, PR/nightly'de)

spl_autoload_register(function (string $class): void {
    $prefix = 'Traceability\\';
    if (str_starts_with($class, $prefix)) {
        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
            return;
        }
        // tests/ altındaki sınıflar için de aynı namespace kökünü kullan
        $testPath = __DIR__ . '/' . str_replace('\\', '/', substr($relative, strlen('Tests\\'))) . '.php';
    }
});

// tests/ dizinini kendi namespace'i için ayrıca autoload'a ekle
spl_autoload_register(function (string $class): void {
    $prefix = 'Traceability\\Tests\\';
    if (str_starts_with($class, $prefix)) {
        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

$group = 'all';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--group=')) {
        $group = substr($arg, strlen('--group='));
    }
}

$groupDirs = match ($group) {
    'fast' => ['Unit', 'Integration'],
    'concurrency' => ['Concurrency'],
    'unit' => ['Unit'],
    'integration' => ['Integration'],
    default => ['Unit', 'Integration', 'Concurrency'],
};

$testFiles = [];
foreach ($groupDirs as $dir) {
    $testFiles = array_merge($testFiles, glob(__DIR__ . "/{$dir}/*Test.php"));
}

$totalTests = 0;
$totalFailures = 0;
$failureDetails = [];
$startTime = microtime(true);

echo "Çalıştırılan grup: {$group} (" . implode(', ', $groupDirs) . ")\n\n";

foreach ($testFiles as $file) {
    require_once $file;
    $dirName = basename(dirname($file));
    $className = "Traceability\\Tests\\{$dirName}\\" . basename($file, '.php');

    if (!class_exists($className)) {
        continue;
    }

    $reflection = new ReflectionClass($className);
    if ($reflection->isAbstract()) {
        continue;
    }

    $methods = array_filter($reflection->getMethods(ReflectionMethod::IS_PUBLIC), fn($m) => str_starts_with($m->getName(), 'test'));

    foreach ($methods as $method) {
        $totalTests++;
        $instance = $reflection->newInstance();
        try {
            $instance->setUp();
            $instance->{$method->getName()}();
            echo "  ✓ {$reflection->getShortName()}::{$method->getName()}\n";
        } catch (\Throwable $e) {
            $totalFailures++;
            $failureDetails[] = "{$reflection->getShortName()}::{$method->getName()} → " . $e->getMessage();
            echo "  ✗ {$reflection->getShortName()}::{$method->getName()} — " . $e->getMessage() . "\n";
        } finally {
            // BULGU: tearDown() daha önce SADECE başarılı testlerde
            // çağrılıyordu — başarısız bir test, kendi temizliğini (örn.
            // geçici dosyalar) hiç yapmadan bir sonrakini kirletebilirdi.
            try {
                $instance->tearDown();
            } catch (\Throwable $e) {
                // tearDown'ın kendisi patlarsa bile test sonucunu etkilemesin.
            }
        }
    }
}

$elapsed = round(microtime(true) - $startTime, 3);
echo "\n" . str_repeat('─', 70) . "\n";
printf("Toplam: %d test, %d başarısız, süre: %ss\n", $totalTests, $totalFailures, $elapsed);

if ($totalFailures > 0) {
    echo "\nBAŞARISIZ TESTLER:\n";
    foreach ($failureDetails as $detail) {
        echo "  - {$detail}\n";
    }
    exit(1);
}

echo "TÜM TESTLER BAŞARILI ✓\n";
exit(0);
