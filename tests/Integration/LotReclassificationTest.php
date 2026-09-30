<?php

declare(strict_types=1);

namespace Traceability\Tests\Integration;

use Traceability\Tests\TestCase;
use Traceability\Ledger\EventStore;
use Traceability\Ledger\EntityRepository;
use Traceability\Ledger\LotTraceService;
use Traceability\Risk\AuthorizationGuard;
use Traceability\Gs1\GtinBuilder;
use Traceability\Gs1\DigitalLink;

final class LotReclassificationTest extends TestCase
{
    private EventStore $eventStore;
    private EntityRepository $entities;
    private LotTraceService $lotTrace;
    private AuthorizationGuard $authGuard;
    private int $wrongProductId;
    private int $correctProductId;
    private int $supervisorId;
    private int $staffId;
    private int $dealerId;
    private int $locationId;
    private int $misdeclaredLotId;

    public function setUp(): void
    {
        parent::setUp();
        $key = bin2hex(random_bytes(32));
        $this->eventStore = new EventStore($this->pdo, [1 => $key], activeKeyVersion: 1);
        $this->entities = new EntityRepository($this->pdo);
        $this->lotTrace = new LotTraceService($this->pdo, $this->entities, $this->eventStore);
        $this->authGuard = new AuthorizationGuard($this->pdo);

        $this->pdo->exec("INSERT INTO products (gtin, name, category, tracking_mode, created_at) VALUES ('" . (new GtinBuilder('8690000'))->build('00070') . "', 'Cream (Yanlış Beyan)', 'Test', 'lot', datetime('now'))");
        $this->wrongProductId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO products (gtin, name, category, tracking_mode, created_at) VALUES ('" . (new GtinBuilder('8690000'))->build('00071') . "', 'Tonik (Doğru)', 'Test', 'lot', datetime('now'))");
        $this->correctProductId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO actors (tenant_country, actor_type, role, name) VALUES ('TR','staff','supervizor','Reclass Süpervizör')");
        $this->supervisorId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO actors (tenant_country, actor_type, role, name) VALUES ('TR','staff','depo_gorevlisi','Reclass Personel')");
        $this->staffId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO actors (tenant_country, actor_type, name) VALUES ('TR','dealer','Test Bayi')");
        $this->dealerId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO locations (tenant_country, name, type, customs_zone) VALUES ('TR','Depo','warehouse','TR_independent')");
        $this->locationId = (int) $this->pdo->lastInsertId();

        $wrongGtin = (new GtinBuilder('8690000'))->build('00070');
        $this->misdeclaredLotId = $this->entities->createEntity($this->wrongProductId, (new DigitalLink())->buildElementString($wrongGtin, 'LOT-MISDECLARED'), 'lot', 'LOT-MISDECLARED', null, 100, '2026-01-01', '2028-01-01');
        $this->eventStore->appendEvent($this->misdeclaredLotId, 'commissioning', 'active', $this->staffId, null, $this->locationId, null, []);
    }

    private function shipUnitsFromLot(int $lotId, int $count, string $orderPrefix): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->entities->consumeFromLot($lotId, 1);
            $this->eventStore->appendEvent($lotId, 'shipping', 'in_transit', $this->staffId, null, $this->locationId, "{$orderPrefix}-{$i}", []);
            $this->eventStore->appendEvent($lotId, 'receiving', 'sold', $this->dealerId, null, $this->locationId, "{$orderPrefix}-{$i}", []);
        }
    }

    public function testReclassifyRejectsUnauthorizedActor(): void
    {
        $this->assertThrows(\Traceability\Risk\UnauthorizedActionException::class, function () {
            $this->lotTrace->reclassifyLot($this->misdeclaredLotId, $this->correctProductId, 'LOT-NEW', $this->staffId, 'sebep', 'TUT-1', $this->authGuard);
        });
    }

    public function testReclassifyRejectsEmptyReasonOrTutanak(): void
    {
        $this->assertThrows(\RuntimeException::class, function () {
            $this->lotTrace->reclassifyLot($this->misdeclaredLotId, $this->correctProductId, 'LOT-NEW', $this->supervisorId, '', '', $this->authGuard);
        });
    }

    public function testReclassifyMarksOldLotMisdeclaredWithZeroRemaining(): void
    {
        $this->lotTrace->reclassifyLot($this->misdeclaredLotId, $this->correctProductId, 'LOT-NEW', $this->supervisorId, 'yanlış ürün', 'TUT-1', $this->authGuard);
        $old = $this->entities->findById($this->misdeclaredLotId);
        $this->assertSame('MISDECLARED', $old['status']);
        $this->assertSame(0, (int) $old['quantity_remaining']);
    }

    public function testReclassifyCreatesNewLotForCorrectProductWithRemainingQuantity(): void
    {
        $this->shipUnitsFromLot($this->misdeclaredLotId, 10, 'ORDER');
        $result = $this->lotTrace->reclassifyLot($this->misdeclaredLotId, $this->correctProductId, 'LOT-NEW', $this->supervisorId, 'yanlış ürün', 'TUT-1', $this->authGuard);
        $newLot = $this->entities->findById($result['new_lot_entity_id']);
        $this->assertSame($this->correctProductId, (int) $newLot['product_id']);
        $this->assertSame(90, (int) $newLot['quantity_remaining']);
    }

    public function testReclassifyDoesNotDeleteOldEventHistory(): void
    {
        $this->shipUnitsFromLot($this->misdeclaredLotId, 5, 'ORDER');
        $historyBefore = count($this->eventStore->history($this->misdeclaredLotId));
        $this->lotTrace->reclassifyLot($this->misdeclaredLotId, $this->correctProductId, 'LOT-NEW', $this->supervisorId, 'yanlış ürün', 'TUT-1', $this->authGuard);
        $historyAfter = count($this->eventStore->history($this->misdeclaredLotId));
        // Sadece EKLENDİ (reversal event) — hiçbir eski event kaybolmadı:
        $this->assertGreaterThan($historyBefore, $historyAfter);
    }

    public function testAffectedShipmentsAllMarkedAwaitingRecall(): void
    {
        $this->shipUnitsFromLot($this->misdeclaredLotId, 7, 'RECALL-ORDER');
        $result = $this->lotTrace->reclassifyLot($this->misdeclaredLotId, $this->correctProductId, 'LOT-NEW', $this->supervisorId, 'yanlış ürün', 'TUT-1', $this->authGuard);
        $this->assertCount(7, $result['affected_shipments']);

        $count = $this->pdo->query(
            "SELECT COUNT(*) FROM recall_notices WHERE lot_entity_id = {$this->misdeclaredLotId} AND status = 'AWAITING_RECALL_NOTICE'"
        )->fetchColumn();
        $this->assertSame(7, (int) $count);
    }

    public function testMisdeclaredStatusIsPermanentLikeDestroyed(): void
    {
        $this->lotTrace->reclassifyLot($this->misdeclaredLotId, $this->correctProductId, 'LOT-NEW', $this->supervisorId, 'yanlış ürün', 'TUT-1', $this->authGuard);
        $this->assertSame('MISDECLARED', $this->entities->findById($this->misdeclaredLotId)['status']);

        // İNCELEME BULGUSU (SECURITY_REVIEW.md #3): MISDECLARED, DESTROYED
        // gibi KALICI olmalı — geri alınabilir olması gerçek bir boşluktu,
        // düzeltildi. Bu test o düzeltmeyi kalıcı olarak kilitler.
        $this->assertThrows(\RuntimeException::class, function () {
            $this->entities->transitionStatus($this->misdeclaredLotId, 'IN_WAREHOUSE');
        });
    }
}
