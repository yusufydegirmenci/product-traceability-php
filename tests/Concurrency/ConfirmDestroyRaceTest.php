<?php

declare(strict_types=1);

namespace Traceability\Tests\Concurrency;

use Traceability\Tests\TestCase;
use Traceability\Ledger\EntityRepository;
use Traceability\Gs1\GtinBuilder;
use Traceability\Gs1\DigitalLink;

/**
 * KIDEMLI İNCELEME BULGUSU: DestroyService::confirmDestroy() (ve onu
 * kullanan DamagedItemService::confirmScrapViaFourEyes()), iki FARKLI
 * yetkilinin AYNI ANDA aynı destroy_request'i onaylamaya çalışmasına
 * karşı korumasızdı — UPDATE ifadesi "status='pending'" kontrolü
 * YAPMIYORDU. Bu, gerçek OS süreçleriyle test edilir — :memory: SQLite
 * paylaşılamadığı için kendi dosya tabanlı DB'sini yönetir.
 */
final class ConfirmDestroyRaceTest extends TestCase
{
    private string $dbFile;

    public function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/confirm_destroy_race_' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->pdo = new \PDO("sqlite:{$this->dbFile}");
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(file_get_contents(dirname(__DIR__, 2) . '/schema/schema.sqlite.sql'));
    }

    public function tearDown(): void
    {
        @unlink($this->dbFile);
    }

    public function testOnlyOneOfTwoSimultaneousConfirmationsSucceeds(): void
    {
        if (!function_exists('pcntl_fork')) {
            echo '  (pcntl mevcut değil, atlandı) ';
            return;
        }

        $entities = new EntityRepository($this->pdo);
        $key = bin2hex(random_bytes(32));
        $eventStore = new \Traceability\Ledger\EventStore($this->pdo, [1 => $key], activeKeyVersion: 1);
        $authGuard = new \Traceability\Risk\AuthorizationGuard($this->pdo);

        $gtin = (new GtinBuilder('8690000'))->build('00098');
        $this->pdo->exec("INSERT INTO products (gtin, name, category, tracking_mode, created_at) VALUES ('{$gtin}', 'Race Confirm Test', 'Test', 'lot', datetime('now'))");
        $productId = (int) $this->pdo->lastInsertId();
        $entityId = $entities->createEntity($productId, (new DigitalLink())->buildElementString($gtin, 'LOT-CONFIRMRACE'), 'lot', 'LOT-CONFIRMRACE', null, 5, '2027-01-01', '2028-01-01');
        $eventStore->appendEvent($entityId, 'commissioning', 'active', null, null, null, null, []);

        $this->pdo->exec("INSERT INTO actors (tenant_country, actor_type, role, name) VALUES ('TR','staff','supervizor','Talep Eden')");
        $requesterId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO actors (tenant_country, actor_type, role, name) VALUES ('TR','staff','yonetici','Onaylayan A')");
        $approverA = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO actors (tenant_country, actor_type, role, name) VALUES ('TR','staff','yonetici','Onaylayan B')");
        $approverB = (int) $this->pdo->lastInsertId();

        $destroyService = new \Traceability\Ledger\DestroyService($this->pdo, $eventStore, $entities, $authGuard);
        $requestId = $destroyService->requestDestroy($entityId, $requesterId, 'Test: eşzamanlı onay yarışı');

        $resultsFile = sys_get_temp_dir() . '/confirm_race_results_' . bin2hex(random_bytes(4)) . '.log';
        @unlink($resultsFile);

        $approvers = [$approverA, $approverB];
        $pids = [];
        foreach ($approvers as $approverId) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $childPdo = new \PDO("sqlite:{$this->dbFile}");
                $childPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $childPdo->setAttribute(\PDO::ATTR_TIMEOUT, 30);
                $childEvents = new \Traceability\Ledger\EventStore($childPdo, [1 => $key], activeKeyVersion: 1);
                $childEntities = new EntityRepository($childPdo);
                $childAuthGuard = new \Traceability\Risk\AuthorizationGuard($childPdo);
                $childDestroy = new \Traceability\Ledger\DestroyService($childPdo, $childEvents, $childEntities, $childAuthGuard);
                try {
                    $childDestroy->confirmDestroy($requestId, $approverId, $productId);
                    file_put_contents($resultsFile, "SUCCESS:{$approverId}\n", FILE_APPEND);
                } catch (\Traceability\Ledger\DestroyRequestException $e) {
                    file_put_contents($resultsFile, "REJECTED:{$approverId}\n", FILE_APPEND);
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
        $successCount = count(array_filter($results, fn($r) => str_starts_with($r, 'SUCCESS')));
        $rejectedCount = count(array_filter($results, fn($r) => str_starts_with($r, 'REJECTED')));

        $this->assertSame(1, $successCount, "TAM OLARAK bir onay başarılı olmalıydı, {$successCount} oldu.");
        $this->assertSame(1, $rejectedCount, "TAM OLARAK bir onay reddedilmeliydi, {$rejectedCount} oldu.");

        $finalStatus = $this->pdo->query("SELECT status FROM destroy_requests WHERE id = {$requestId}")->fetchColumn();
        $this->assertSame('confirmed', $finalStatus);
    }
}
