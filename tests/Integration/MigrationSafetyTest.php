<?php

declare(strict_types=1);

namespace Traceability\Tests\Integration;

use Traceability\Tests\TestCase;
use Traceability\Ledger\EntityRepository;
use Traceability\Gs1\GtinBuilder;
use Traceability\Gs1\DigitalLink;

/**
 * migrations/2026_add_unique_entity_prevhash.php script'ini GERÇEKTEN
 * çalıştırarak (shell_exec ile) hem temiz hem "kirli" (önceden
 * çatallanmış) veritabanlarına karşı doğru davrandığını kanıtlar.
 */
final class MigrationSafetyTest extends TestCase
{
    private string $dbFile;
    private string $migrationScript;

    public function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/migration_test_' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->migrationScript = dirname(__DIR__, 2) . '/migrations/2026_add_unique_entity_prevhash.php';

        // NOT: bu veritabanı KASITLI OLARAK idx_events_no_fork kısıtı
        // OLMADAN kuruluyor — schema.sqlite.sql'i alıp o satırı çıkarıyoruz,
        // böylece "kısıt henüz eklenmemiş eski bir üretim veritabanı"nı
        // gerçekçi olarak taklit ediyoruz.
        $schema = file_get_contents(dirname(__DIR__, 2) . '/schema/schema.sqlite.sql');
        $schemaWithoutConstraint = str_replace(
            'CREATE UNIQUE INDEX idx_events_no_fork ON epcis_events (entity_id, prev_hash);',
            '-- (kısıt bilerek çıkarıldı, migration script tarafından eklenecek)',
            $schema
        );
        $this->pdo = new \PDO("sqlite:{$this->dbFile}");
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec($schemaWithoutConstraint);
    }

    public function tearDown(): void
    {
        @unlink($this->dbFile);
    }

    private function runMigration(string $mode): array
    {
        $cmd = sprintf('php %s %s %s 2>&1', escapeshellarg($this->migrationScript), escapeshellarg($mode), escapeshellarg($this->dbFile));
        exec($cmd, $output, $exitCode);
        return ['output' => implode("\n", $output), 'exitCode' => $exitCode];
    }

    public function testCleanDatabasePassesCheckAndApplySucceeds(): void
    {
        $entities = new EntityRepository($this->pdo);
        $gtin = (new GtinBuilder('8690000'))->build('00099');
        $this->pdo->exec("INSERT INTO products (gtin, name, category, tracking_mode, created_at) VALUES ('{$gtin}', 'Clean Migration Test', 'Test', 'lot', datetime('now'))");
        $productId = (int) $this->pdo->lastInsertId();
        $entities->createEntity($productId, (new DigitalLink())->buildElementString($gtin, 'LOT-CLEAN'), 'lot', 'LOT-CLEAN', null, 5, '2027-01-01', '2028-01-01');

        $check = $this->runMigration('--check');
        $this->assertSame(0, $check['exitCode'], "Temiz veritabanında --check BAŞARISIZ oldu: {$check['output']}");

        $apply = $this->runMigration('--apply');
        $this->assertSame(0, $apply['exitCode'], "Temiz veritabanında --apply BAŞARISIZ oldu: {$apply['output']}");

        // Kısıt GERÇEKTEN eklendi mi doğrula:
        $indexExists = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND name='idx_events_no_fork'")->fetchColumn();
        $this->assertNotNull($indexExists === false ? null : $indexExists);
    }

    public function testDirtyDatabaseWithPreexistingForkIsRejected(): void
    {
        $entities = new EntityRepository($this->pdo);
        $gtin = (new GtinBuilder('8690000'))->build('00100');
        $this->pdo->exec("INSERT INTO products (gtin, name, category, tracking_mode, created_at) VALUES ('{$gtin}', 'Dirty Migration Test', 'Test', 'lot', datetime('now'))");
        $productId = (int) $this->pdo->lastInsertId();
        $entityId = $entities->createEntity($productId, (new DigitalLink())->buildElementString($gtin, 'LOT-DIRTY'), 'lot', 'LOT-DIRTY', null, 5, '2027-01-01', '2028-01-01');

        // GEÇMİŞTE (düzeltmeden ÖNCE) oluşmuş bir çatallanmayı elle simüle et:
        // AYNI entity_id + AYNI prev_hash'e sahip İKİ satır.
        $this->pdo->exec(
            "INSERT INTO epcis_events (entity_id, biz_step, disposition, event_time, prev_hash, event_hash, key_version, created_at)
             VALUES ({$entityId}, 'shipping', 'in_transit', datetime('now'), 'SHARED-PREV-HASH', 'HASH-A', 1, datetime('now'))"
        );
        $this->pdo->exec(
            "INSERT INTO epcis_events (entity_id, biz_step, disposition, event_time, prev_hash, event_hash, key_version, created_at)
             VALUES ({$entityId}, 'shipping', 'in_transit', datetime('now'), 'SHARED-PREV-HASH', 'HASH-B', 1, datetime('now'))"
        );

        $check = $this->runMigration('--check');
        $this->assertSame(1, $check['exitCode'], 'Kirli (çatallanmış) veritabanında --check BAŞARILI dönmemeliydi.');
        $this->assertTrue(str_contains($check['output'], 'DURDURULDU'), 'Çıktı, tespit edilen çatallanmayı AÇIKÇA belirtmeliydi.');

        $apply = $this->runMigration('--apply');
        $this->assertSame(1, $apply['exitCode'], 'Kirli veritabanında --apply da BAŞARISIZ olmalıydı (check\'i tekrar çalıştırır).');

        // Kısıt GERÇEKTEN eklenmedi mi doğrula (güvenlik ağı çalıştı mı):
        $indexExists = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND name='idx_events_no_fork'")->fetchColumn();
        $this->assertFalse($indexExists, 'Kirli veride kısıt YİNE DE eklenmiş — güvenlik kontrolü baypas edildi!');
    }

    public function testRollbackRemovesConstraint(): void
    {
        $this->pdo->exec('CREATE UNIQUE INDEX idx_events_no_fork ON epcis_events (entity_id, prev_hash)');
        $rollback = $this->runMigration('--rollback');
        $this->assertSame(0, $rollback['exitCode']);

        $indexExists = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND name='idx_events_no_fork'")->fetchColumn();
        $this->assertFalse($indexExists);
    }
}
