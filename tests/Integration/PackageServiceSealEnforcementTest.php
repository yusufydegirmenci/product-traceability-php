<?php

declare(strict_types=1);

namespace Traceability\Tests\Integration;

use Traceability\Tests\TestCase;
use Traceability\Ledger\EventStore;
use Traceability\Ledger\EntityRepository;
use Traceability\Ledger\PackageService;
use Traceability\Risk\AuthorizationGuard;
use Traceability\Risk\WeightReconciler;
use Traceability\Gs1\GtinBuilder;
use Traceability\Gs1\DigitalLink;
use Traceability\Gs1\SsccBuilder;

final class PackageServiceSealEnforcementTest extends TestCase
{
    private EventStore $eventStore;
    private EntityRepository $entities;
    private PackageService $packages;
    private AuthorizationGuard $authGuard;
    private WeightReconciler $weightCheck;
    private int $productId;
    private int $locationId;
    private int $supervisorId;
    private int $staffId;

    public function setUp(): void
    {
        parent::setUp();
        $key = bin2hex(random_bytes(32));
        $this->eventStore = new EventStore($this->pdo, [1 => $key], activeKeyVersion: 1);
        $this->entities = new EntityRepository($this->pdo);
        $this->authGuard = new AuthorizationGuard($this->pdo);
        $this->weightCheck = new WeightReconciler($this->pdo);
        $this->packages = new PackageService($this->pdo, null, $this->entities, null, $this->authGuard);

        $gtin = (new GtinBuilder('8690000'))->build('00030');
        $this->pdo->exec("INSERT INTO products (gtin, name, category, tracking_mode, created_at) VALUES ('{$gtin}', 'Seal Test', 'Test', 'unit', datetime('now'))");
        $this->productId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO locations (tenant_country, name, type, customs_zone) VALUES ('TR','Depo','warehouse','TR_independent')");
        $this->locationId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO actors (tenant_country, actor_type, role, name) VALUES ('TR','staff','supervizor','Test Süpervizör')");
        $this->supervisorId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO actors (tenant_country, actor_type, role, name) VALUES ('TR','staff','depo_gorevlisi','Test Personel')");
        $this->staffId = (int) $this->pdo->lastInsertId();
    }

    private function openPackageWithOneEntity(): array
    {
        $gtin = (new GtinBuilder('8690000'))->build('00030');
        $link = new DigitalLink();
        $entityId = $this->entities->createEntity($this->productId, $link->buildElementString($gtin, 'LOT-SEAL'), 'unit', 'LOT-SEAL', 'SN-SEAL', 1, '2027-01-01', '2028-01-01');
        $sscc = (new SsccBuilder(gs1CompanyPrefix: '8690001', extensionDigit: '0'))->build(SsccBuilder::randomSerialReference(9));
        $pkgId = $this->packages->openPackage($sscc, ['ORDER-1'], $this->locationId, $this->staffId);
        $this->packages->addEntityToPackage($pkgId, $entityId, 'ORDER-1');
        return [$pkgId, $entityId];
    }

    public function testSealSucceedsWithoutAnyWeightFlag(): void
    {
        [$pkgId] = $this->openPackageWithOneEntity();
        $result = $this->packages->sealPackage($pkgId);
        $this->assertNotNull($result['seal_serial']);
    }

    public function testSealRejectedWhenUnresolvedWeightFlagExists(): void
    {
        [$pkgId, $entityId] = $this->openPackageWithOneEntity();
        $this->weightCheck->check(orderRef: 'ORDER-1', entityId: $entityId, expectedGrams: 500.0, measuredGrams: 100.0);
        $this->assertThrows(\RuntimeException::class, function () use ($pkgId) {
            $this->packages->sealPackage($pkgId);
        });
    }

    public function testSealSucceedsWithSupervisorOverrideAndRecordsApprover(): void
    {
        [$pkgId, $entityId] = $this->openPackageWithOneEntity();
        $this->weightCheck->check(orderRef: 'ORDER-1', entityId: $entityId, expectedGrams: 500.0, measuredGrams: 100.0);
        $result = $this->packages->sealPackage($pkgId, overridingActorId: $this->supervisorId);
        $this->assertNotNull($result['seal_serial']);
        $recorded = $this->pdo->query("SELECT override_by_actor_id FROM packing_station_weights WHERE entity_id = {$entityId}")->fetchColumn();
        $this->assertSame($this->supervisorId, (int) $recorded);
    }

    public function testVoidPackageRequiresNonEmptyReason(): void
    {
        [$pkgId] = $this->openPackageWithOneEntity();
        $this->packages->sealPackage($pkgId);
        $this->assertThrows(\RuntimeException::class, function () use ($pkgId) {
            $this->packages->voidPackage($pkgId, $this->supervisorId, '', $this->authGuard);
        });
    }

    public function testVoidPackagePermanentlyInvalidatesOldSeal(): void
    {
        [$pkgId] = $this->openPackageWithOneEntity();
        $sealResult = $this->packages->sealPackage($pkgId);
        $this->packages->voidPackage($pkgId, $this->supervisorId, 'Sipariş iptal edildi', $this->authGuard);

        $history = $this->pdo->query(
            "SELECT invalidated_at FROM package_seal_history WHERE package_id = {$pkgId} AND seal_serial = '{$sealResult['seal_serial']}'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotNull($history['invalidated_at']);

        $reopened = $this->packages->findPackage($pkgId);
        $this->assertSame('open', $reopened['status']);
        $this->assertNull($reopened['seal_serial']);
    }

    public function testVoidPackageReleasesIntactItemsAndHoldsDamagedOnes(): void
    {
        [$pkgId, $entityId] = $this->openPackageWithOneEntity();
        $this->packages->sealPackage($pkgId);
        $this->packages->voidPackage($pkgId, $this->supervisorId, 'Hasar tespit edildi', $this->authGuard, damagedEntityIds: [$entityId]);
        $this->assertSame('DAMAGED_HOLD', $this->entities->findById($entityId)['status']);
    }
}
