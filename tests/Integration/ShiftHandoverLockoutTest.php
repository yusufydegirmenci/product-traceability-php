<?php

declare(strict_types=1);

namespace Traceability\Tests\Integration;

use Traceability\Tests\TestCase;
use Traceability\Ledger\EntityRepository;
use Traceability\Ledger\DwellTimeGuard;
use Traceability\Ledger\ShiftHandoverService;
use Traceability\Ledger\UnassignedItemLockoutException;
use Traceability\Risk\AuthorizationGuard;
use Traceability\Gs1\GtinBuilder;
use Traceability\Gs1\DigitalLink;

final class ShiftHandoverLockoutTest extends TestCase
{
    private DwellTimeGuard $dwellGuard;
    private ShiftHandoverService $shiftService;
    private AuthorizationGuard $authGuard;
    private int $staffId;
    private int $supervisorId;
    private int $entityId;

    public function setUp(): void
    {
        parent::setUp();
        $entities = new EntityRepository($this->pdo);
        $this->dwellGuard = new DwellTimeGuard($this->pdo);
        $this->authGuard = new AuthorizationGuard($this->pdo);
        $this->shiftService = new ShiftHandoverService($this->pdo, $this->dwellGuard);

        $this->pdo->exec("INSERT INTO actors (tenant_country, actor_type, role, name) VALUES ('TR','staff','depo_gorevlisi','Logout Test')");
        $this->staffId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO actors (tenant_country, actor_type, role, name) VALUES ('TR','staff','supervizor','Logout Süpervizör')");
        $this->supervisorId = (int) $this->pdo->lastInsertId();

        $gtin = (new GtinBuilder('8690000'))->build('00050');
        $this->pdo->exec("INSERT INTO products (gtin, name, category, tracking_mode, created_at) VALUES ('{$gtin}', 'Logout Test Ürünü', 'Test', 'lot', datetime('now'))");
        $productId = (int) $this->pdo->lastInsertId();
        $this->entityId = $entities->createEntity($productId, (new DigitalLink())->buildElementString($gtin, 'LOT-LOGOUT'), 'lot', 'LOT-LOGOUT', null, 3, '2027-01-01', '2028-01-01');
    }

    public function testLogoutSucceedsWithNoOpenCustody(): void
    {
        $this->shiftService->attemptLogout($this->staffId);
        $this->assertTrue(true); // exception atmadıysa geçti
    }

    public function testLogoutBlockedWithOpenCustody(): void
    {
        $this->dwellGuard->pickItem($this->entityId, $this->staffId);
        $this->assertThrows(UnassignedItemLockoutException::class, function () {
            $this->shiftService->attemptLogout($this->staffId);
        });
    }

    public function testSupervisorOverrideAllowsLogoutWithoutClearingCustody(): void
    {
        $this->dwellGuard->pickItem($this->entityId, $this->staffId);
        $this->shiftService->attemptLogout($this->staffId, overridingSupervisorId: $this->supervisorId, authGuard: $this->authGuard);
        // Override çıkışa izin verir ama zimmet kaydı SİLİNMEMELİDİR:
        $this->assertTrue($this->dwellGuard->hasOpenCustody($this->staffId));
    }

    public function testNonSupervisorOverrideIsRejected(): void
    {
        $this->dwellGuard->pickItem($this->entityId, $this->staffId);
        $this->assertThrows(\Traceability\Risk\UnauthorizedActionException::class, function () {
            $this->shiftService->attemptLogout($this->staffId, overridingSupervisorId: $this->staffId, authGuard: $this->authGuard);
        });
    }
}
