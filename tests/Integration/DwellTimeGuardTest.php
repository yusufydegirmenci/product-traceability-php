<?php

declare(strict_types=1);

namespace Traceability\Tests\Integration;

use Traceability\Tests\TestCase;
use Traceability\Ledger\EntityRepository;
use Traceability\Ledger\DwellTimeGuard;
use Traceability\Risk\AuthorizationGuard;
use Traceability\Gs1\GtinBuilder;
use Traceability\Gs1\DigitalLink;

final class DwellTimeGuardTest extends TestCase
{
    private EntityRepository $entities;
    private DwellTimeGuard $dwellGuard;
    private AuthorizationGuard $authGuard;
    private int $staffId;
    private int $supervisorId;
    private int $entityId;

    public function setUp(): void
    {
        parent::setUp();
        $this->entities = new EntityRepository($this->pdo);
        $this->dwellGuard = new DwellTimeGuard($this->pdo);
        $this->authGuard = new AuthorizationGuard($this->pdo);

        $this->pdo->exec("INSERT INTO actors (tenant_country, actor_type, role, name) VALUES ('TR','staff','depo_gorevlisi','Dwell Test')");
        $this->staffId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO actors (tenant_country, actor_type, role, name) VALUES ('TR','staff','supervizor','Dwell Süpervizör')");
        $this->supervisorId = (int) $this->pdo->lastInsertId();

        $gtin = (new GtinBuilder('8690000'))->build('00040');
        $this->pdo->exec("INSERT INTO products (gtin, name, category, tracking_mode, created_at) VALUES ('{$gtin}', 'Dwell Test', 'Test', 'lot', datetime('now'))");
        $productId = (int) $this->pdo->lastInsertId();
        $this->entityId = $this->entities->createEntity($productId, (new DigitalLink())->buildElementString($gtin, 'LOT-DWELL'), 'lot', 'LOT-DWELL', null, 5, '2027-01-01', '2028-01-01');
    }

    public function testFreshPickIsNotFlaggedImmediately(): void
    {
        $this->dwellGuard->pickItem($this->entityId, $this->staffId);
        $flagged = $this->dwellGuard->checkOverdueCustody(windowMinutes: 15);
        $this->assertCount(0, $flagged);
    }

    public function testOverdueCustodyGetsFlaggedAndStaffSuspended(): void
    {
        $custodyId = $this->dwellGuard->pickItem($this->entityId, $this->staffId);
        $this->pdo->exec("UPDATE custody_holds SET picked_at = datetime('now', '-20 minutes') WHERE id = {$custodyId}");

        $flagged = $this->dwellGuard->checkOverdueCustody(windowMinutes: 15);
        $this->assertCount(1, $flagged);
        $this->assertTrue($this->dwellGuard->isSuspended($this->staffId));
    }

    public function testReleaseItemClearsCustodyBeforeWindowCheck(): void
    {
        $custodyId = $this->dwellGuard->pickItem($this->entityId, $this->staffId);
        $this->pdo->exec("UPDATE custody_holds SET picked_at = datetime('now', '-20 minutes') WHERE id = {$custodyId}");
        $this->dwellGuard->releaseItem($this->entityId, 'packed');

        $flagged = $this->dwellGuard->checkOverdueCustody(windowMinutes: 15);
        $this->assertCount(0, $flagged);
        $this->assertFalse($this->dwellGuard->hasOpenCustody($this->staffId));
    }

    public function testSupervisorCanClearSuspension(): void
    {
        $custodyId = $this->dwellGuard->pickItem($this->entityId, $this->staffId);
        $this->pdo->exec("UPDATE custody_holds SET picked_at = datetime('now', '-20 minutes') WHERE id = {$custodyId}");
        $this->dwellGuard->checkOverdueCustody(windowMinutes: 15);
        $this->assertTrue($this->dwellGuard->isSuspended($this->staffId));

        $this->dwellGuard->clearSuspension($this->staffId, $this->supervisorId, $this->authGuard);
        $this->assertFalse($this->dwellGuard->isSuspended($this->staffId));
    }

    public function testNonSupervisorCannotClearSuspension(): void
    {
        $custodyId = $this->dwellGuard->pickItem($this->entityId, $this->staffId);
        $this->pdo->exec("UPDATE custody_holds SET picked_at = datetime('now', '-20 minutes') WHERE id = {$custodyId}");
        $this->dwellGuard->checkOverdueCustody(windowMinutes: 15);

        $this->assertThrows(\Traceability\Risk\UnauthorizedActionException::class, function () {
            $this->dwellGuard->clearSuspension($this->staffId, $this->staffId, $this->authGuard);
        });
    }

    public function testReturnToShelfClosesCustodyWithDistinctReason(): void
    {
        $this->dwellGuard->pickItem($this->entityId, $this->staffId);
        $this->assertTrue($this->dwellGuard->hasOpenCustody($this->staffId));

        $this->dwellGuard->returnToShelf($this->entityId);
        $this->assertFalse($this->dwellGuard->hasOpenCustody($this->staffId));

        $reason = $this->pdo->query("SELECT release_reason FROM custody_holds WHERE entity_id = {$this->entityId}")->fetchColumn();
        $this->assertSame('returned_to_shelf', $reason);
    }
}
