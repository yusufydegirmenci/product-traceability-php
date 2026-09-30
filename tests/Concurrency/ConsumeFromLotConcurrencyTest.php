<?php

declare(strict_types=1);

namespace Traceability\Tests\Concurrency;

use Traceability\Tests\TestCase;
use Traceability\Ledger\EntityRepository;
use Traceability\Gs1\GtinBuilder;
use Traceability\Gs1\DigitalLink;

/**
 * :memory: SQLite, ayrı işletim sistemi süreçleri arasında PAYLAŞILAMAZ —
 * bu yüzden bu test, TestCase'in normal bellek-içi izolasyonunu
 * OVERRIDE eder ve kendi geçici DOSYA tabanlı veritabanını yönetir.
 * pcntl uzantısı yoksa test KENDİSİNİ atlar (skip) — CI/sandbox
 * ortamına göre esnek davranır.
 */
final class ConsumeFromLotConcurrencyTest extends TestCase
{
    private string $dbFile;

    public function setUp(): void
    {
        // Kasıtlı olarak parent::setUp() ÇAĞRILMIYOR — :memory: burada işe yaramaz.
        $this->dbFile = sys_get_temp_dir() . '/concurrency_test_' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->pdo = new \PDO("sqlite:{$this->dbFile}");
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(file_get_contents(dirname(__DIR__, 2) . '/schema/schema.sqlite.sql'));
    }

    public function tearDown(): void
    {
        @unlink($this->dbFile);
    }

    public function testFiftyConcurrentProcessesNeverLoseAUnit(): void
    {
        if (!function_exists('pcntl_fork')) {
            echo '  (pcntl mevcut değil, eşzamanlılık testi bu ortamda atlandı — gerçek CI/sunucuda çalıştırılmalı) ';
            return;
        }

        $entities = new EntityRepository($this->pdo);
        $gtin = (new GtinBuilder('8690000'))->build('00080');
        $this->pdo->exec("INSERT INTO products (gtin, name, category, tracking_mode, created_at) VALUES ('{$gtin}', 'Concurrency Test', 'Test', 'lot', datetime('now'))");
        $productId = (int) $this->pdo->lastInsertId();
        $lotId = $entities->createEntity($productId, (new DigitalLink())->buildElementString($gtin, 'LOT-CONCURRENCY'), 'lot', 'LOT-CONCURRENCY', null, 1000, '2027-01-01', '2028-01-01');

        // Not: $this->pdo tipi (PDO, nullable değil) null atamaya izin
        // vermez — bu yüzden parent bağlantıyı basitçe bir daha
        // kullanmayarak "kapatıyoruz" (child'lar zaten kendi bağlantısını açar).

        $workers = 50;
        $pids = [];
        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $childPdo = new \PDO("sqlite:{$this->dbFile}");
                $childPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $childPdo->setAttribute(\PDO::ATTR_TIMEOUT, 30);
                (new EntityRepository($childPdo))->consumeFromLot($lotId, 1);
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $finalPdo = new \PDO("sqlite:{$this->dbFile}");
        $remaining = (int) $finalPdo->query("SELECT quantity_remaining FROM trackable_entities WHERE id = {$lotId}")->fetchColumn();
        $this->assertSame(1000 - $workers, $remaining, "50 gerçek paralel süreç sonrası kalan miktar {$remaining} — beklenen " . (1000 - $workers));
    }
}
