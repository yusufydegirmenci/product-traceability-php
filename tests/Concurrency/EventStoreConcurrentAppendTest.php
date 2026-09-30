<?php

declare(strict_types=1);

namespace Traceability\Tests\Concurrency;

use Traceability\Tests\TestCase;
use Traceability\Ledger\EntityRepository;
use Traceability\Gs1\GtinBuilder;
use Traceability\Gs1\DigitalLink;

/**
 * KIDEMLI İNCELEME BULGUSU (ampirik olarak kanıtlandı): AYNI entity_id'ye
 * eşzamanlı iki appendEvent() çağrısı, hash-chain'i ÇATALLAYABİLİRDİ —
 * 5 gerçek-süreç denemesinin 3'ünde gerçekleşti. (entity_id, prev_hash)
 * üzerindeki UNIQUE kısıt + retry mantığı eklendikten sonra 10/10
 * denemede çatallanma SIFIRA indi. Bu test o düzeltmeyi kalıcı olarak
 * kilitler — :memory: SQLite paylaşılamadığı için (bkz.
 * ConsumeFromLotConcurrencyTest) kendi dosya tabanlı DB'sini yönetir.
 */
final class EventStoreConcurrentAppendTest extends TestCase
{
    private string $dbFile;

    public function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/chain_fork_test_' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->pdo = new \PDO("sqlite:{$this->dbFile}");
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(file_get_contents(dirname(__DIR__, 2) . '/schema/schema.sqlite.sql'));
    }

    public function tearDown(): void
    {
        @unlink($this->dbFile);
    }

    public function testConcurrentAppendsToSameEntityNeverForkTheChain(): void
    {
        if (!function_exists('pcntl_fork')) {
            echo '  (pcntl mevcut değil, bu ortamda atlandı) ';
            return;
        }

        $entities = new EntityRepository($this->pdo);
        $gtin = (new GtinBuilder('8690000'))->build('00096');
        $this->pdo->exec("INSERT INTO products (gtin, name, category, tracking_mode, created_at) VALUES ('{$gtin}', 'Fork Test', 'Test', 'lot', datetime('now'))");
        $productId = (int) $this->pdo->lastInsertId();
        $entityId = $entities->createEntity($productId, (new DigitalLink())->buildElementString($gtin, 'LOT-FORK'), 'lot', 'LOT-FORK', null, 1000, '2027-01-01', '2028-01-01');
        $key = bin2hex(random_bytes(32));

        $workers = 15;
        $pids = [];
        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $childPdo = new \PDO("sqlite:{$this->dbFile}");
                $childPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $childPdo->setAttribute(\PDO::ATTR_TIMEOUT, 30);
                $childStore = new \Traceability\Ledger\EventStore($childPdo, [1 => $key], activeKeyVersion: 1);
                $childStore->appendEvent($entityId, 'shipping', 'in_transit', null, null, null, "FORK-ORDER-{$i}", []);
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $finalStore = new \Traceability\Ledger\EventStore($this->pdo, [1 => $key], activeKeyVersion: 1);
        $this->assertTrue($finalStore->verifyChain($entityId), '15 eşzamanlı süreç sonrası hash-chain BOZULDU — çatallanma koruması başarısız oldu.');

        $collisions = $this->pdo->query(
            "SELECT COUNT(*) FROM (SELECT prev_hash FROM epcis_events WHERE entity_id = {$entityId} GROUP BY prev_hash HAVING COUNT(*) > 1)"
        )->fetchColumn();
        $this->assertSame(0, (int) $collisions, 'Aynı prev_hash\'i paylaşan çatallanmış event grubu bulundu.');

        $shippingCount = (int) $this->pdo->query("SELECT COUNT(*) FROM epcis_events WHERE entity_id = {$entityId} AND biz_step = 'shipping'")->fetchColumn();
        $this->assertSame($workers, $shippingCount, 'Retry mekanizması bazı yazmaları KAYBETMİŞ olabilir — hepsi sonunda başarılı olmalıydı.');
    }

    /**
     * KULLANICI İSTEĞİ: "maksimum 3-5 deneme sınırı ... sınır aşıldığında
     * açıklayıcı bir ConcurrencyConflictException fırlat." Bunu
     * DETERMİNİSTİK olarak (tek süreçte) zorlamak mümkün değil — autoincrement
     * id sıralaması yüzünden, tek bir süreçte her "son hash" okuması
     * DAİMA henüz kimsenin talep etmediği taze bir değerdir. Bu yüzden
     * GERÇEK eşzamanlılık kullanılıyor, ama retry TAMAMEN kapatılarak
     * (maxAppendRetryAttempts=1) çakışma olasılığı maksimize ediliyor:
     * yeterli sayıda paralel süreçte, en az BİRİNİN kaybetmesi neredeyse kesindir.
     */
    public function testExhaustingRetriesThrowsConcurrencyConflictException(): void
    {
        $entities = new EntityRepository($this->pdo);
        $gtin = (new GtinBuilder('8690000'))->build('00097');
        $this->pdo->exec("INSERT INTO products (gtin, name, category, tracking_mode, created_at) VALUES ('{$gtin}', 'Retry Limit Test', 'Test', 'lot', datetime('now'))");
        $productId = (int) $this->pdo->lastInsertId();
        $entityId = $entities->createEntity($productId, (new DigitalLink())->buildElementString($gtin, 'LOT-RETRYLIMIT'), 'lot', 'LOT-RETRYLIMIT', null, 10, '2027-01-01', '2028-01-01');
        $key = bin2hex(random_bytes(32));

        $resultsFile = sys_get_temp_dir() . '/retry_limit_results_' . bin2hex(random_bytes(4)) . '.log';
        @unlink($resultsFile);

        $workers = 60;
        $pids = [];
        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $childPdo = new \PDO("sqlite:{$this->dbFile}");
                $childPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $childPdo->setAttribute(\PDO::ATTR_TIMEOUT, 30);
                // KASITLI OLARAK retry KAPALI (maxAppendRetryAttempts=1) —
                // gerçek üretimde bu 5 olurdu, burada çakışmayı GÖRÜNÜR
                // kılmak için bilerek 1'e düşürüldü.
                $childStore = new \Traceability\Ledger\EventStore($childPdo, [1 => $key], activeKeyVersion: 1, maxAppendRetryAttempts: 1);
                try {
                    $childStore->appendEvent($entityId, 'shipping', 'in_transit', null, null, null, "RETRYLIMIT-{$i}", []);
                    file_put_contents($resultsFile, "OK\n", FILE_APPEND);
                } catch (\Traceability\Ledger\ConcurrencyConflictException $e) {
                    file_put_contents($resultsFile, "CONFLICT\n", FILE_APPEND);
                }
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $results = is_file($resultsFile) ? array_filter(explode("\n", file_get_contents($resultsFile))) : [];
        @unlink($resultsFile);
        $conflictCount = count(array_filter($results, fn($r) => $r === 'CONFLICT'));

        $this->assertGreaterThan(
            0,
            $conflictCount,
            "retry KAPALIYKEN (max=1), {$workers} eşzamanlı süreçten HİÇBİRİ ConcurrencyConflictException almadı — "
            . 'ya çakışma hiç oluşmadı (şüpheli) ya da exception mekanizması çalışmıyor.'
        );
    }
}
