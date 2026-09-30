<?php

declare(strict_types=1);

namespace Traceability\Tests\Unit;

use Traceability\Tests\TestCase;

/**
 * Bu test, config/warehouse.php + EnvLoader'ın SADECE var olduğunu
 * değil, GERÇEKTEN .env dosyasındaki bir değeri okuyup varsayılanın
 * yerine geçirdiğini kanıtlar. Kendi geçici .env dosyasını yazıp siler
 * — proje kökündeki gerçek .env'e DOKUNMAZ.
 */
final class ConfigOverrideTest extends TestCase
{
    private string $projectRoot;
    private string $envPath;
    private bool $envAlreadyExisted;
    private string $originalEnvContent = '';

    public function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = dirname(__DIR__, 2);
        $this->envPath = $this->projectRoot . '/.env';
        $this->envAlreadyExisted = is_file($this->envPath);
        if ($this->envAlreadyExisted) {
            $this->originalEnvContent = file_get_contents($this->envPath);
        }
    }

    public function tearDown(): void
    {
        parent::tearDown();
        if ($this->envAlreadyExisted) {
            file_put_contents($this->envPath, $this->originalEnvContent);
        } else {
            @unlink($this->envPath);
        }
        // EnvLoader statik $loaded bayrağını sıfırlamak için reflection kullan —
        // aksi halde bir sonraki test eski (bu testte yüklenmiş) değerleri görür.
        $reflection = new \ReflectionClass(\Traceability\Config\EnvLoader::class);
        $prop = $reflection->getProperty('loaded');
        $prop->setAccessible(true);
        $prop->setValue(null, false);
        putenv('DWELL_TIME_WINDOW_MINUTES');
        putenv('SUPERVISOR_VELOCITY_THRESHOLD');
    }

    public function testEnvOverridesDwellTimeDefault(): void
    {
        file_put_contents($this->envPath, "DWELL_TIME_WINDOW_MINUTES=7\n");
        $config = require $this->projectRoot . '/config/warehouse.php';
        $this->assertSame(7, $config['dwell_time']['window_minutes']);
    }

    public function testEnvOverridesSupervisorVelocityThreshold(): void
    {
        file_put_contents($this->envPath, "SUPERVISOR_VELOCITY_THRESHOLD=99\n");
        $config = require $this->projectRoot . '/config/warehouse.php';
        $this->assertSame(99, $config['supervisor_override_velocity']['threshold']);
    }

    public function testMissingEnvFileFallsBackToDocumentedDefaults(): void
    {
        @unlink($this->envPath);
        $reflection = new \ReflectionClass(\Traceability\Config\EnvLoader::class);
        $prop = $reflection->getProperty('loaded');
        $prop->setAccessible(true);
        $prop->setValue(null, false);

        $config = require $this->projectRoot . '/config/warehouse.php';
        $this->assertSame(15, $config['dwell_time']['window_minutes']);
        $this->assertSame(120, $config['scan_sequence']['window_seconds']);
        $this->assertSame(5, $config['supervisor_override_velocity']['threshold']);
    }

    /**
     * GERÇEK ÇALIŞMA ZAMANI DAVRANIŞINI kanıtlar: sadece config array'i
     * değil, DwellTimeGuard'ın KENDİSİ de .env override'ını gerçekten
     * kullanıyor mu?
     */
    public function testDwellTimeGuardActuallyUsesEnvOverrideAtRuntime(): void
    {
        file_put_contents($this->envPath, "DWELL_TIME_WINDOW_MINUTES=2\n");

        $entities = new \Traceability\Ledger\EntityRepository($this->pdo);
        $dwellGuard = new \Traceability\Ledger\DwellTimeGuard($this->pdo);
        $gtin = (new \Traceability\Gs1\GtinBuilder('8690000'))->build('00090');
        $this->pdo->exec("INSERT INTO products (gtin, name, category, tracking_mode, created_at) VALUES ('{$gtin}', 'Config Test', 'Test', 'lot', datetime('now'))");
        $productId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO actors (tenant_country, actor_type, role, name) VALUES ('TR','staff','depo_gorevlisi','Config Test Personel')");
        $staffId = (int) $this->pdo->lastInsertId();
        $entityId = $entities->createEntity($productId, (new \Traceability\Gs1\DigitalLink())->buildElementString($gtin, 'LOT-CFG'), 'lot', 'LOT-CFG', null, 3, '2027-01-01', '2028-01-01');

        $custodyId = $dwellGuard->pickItem($entityId, $staffId);
        // Sadece 3 dakika bekletildi — VARSAYILAN (15 dk) penceresinde
        // YAKALANMAZDI, ama .env'in 2 dakikalık override'ında YAKALANMALI.
        $this->pdo->exec("UPDATE custody_holds SET picked_at = datetime('now', '-3 minutes') WHERE id = {$custodyId}");

        $flagged = $dwellGuard->checkOverdueCustody(); // windowMinutes verilmedi — config'ten OKUMALI
        $this->assertCount(1, $flagged, '.env DWELL_TIME_WINDOW_MINUTES=2 override edilmiş olsa bile 3 dakikalık bekleme yakalanmadı — config gerçekten okunmuyor olabilir.');
    }
}
