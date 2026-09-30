<?php

declare(strict_types=1);

namespace Traceability\Tests\Integration;

use Traceability\Tests\TestCase;
use Traceability\Ledger\EventStore;
use Traceability\Ledger\EntityRepository;
use Traceability\Ledger\ReportingService;
use Traceability\Ledger\LotTraceService;
use Traceability\Gs1\GtinBuilder;
use Traceability\Gs1\DigitalLink;

final class LotTrackingTest extends TestCase
{
    private EventStore $eventStore;
    private EntityRepository $entities;
    private ReportingService $reporting;
    private LotTraceService $lotTrace;
    private int $productId;
    private int $locationId;
    private string $gtin;

    public function setUp(): void
    {
        parent::setUp();
        $key = bin2hex(random_bytes(32));
        $this->eventStore = new EventStore($this->pdo, [1 => $key], activeKeyVersion: 1);
        $this->entities = new EntityRepository($this->pdo);
        $this->reporting = new ReportingService($this->pdo);
        $this->lotTrace = new LotTraceService($this->pdo, $this->entities, $this->eventStore);

        $this->gtin = (new GtinBuilder('8690000'))->build('00020');
        $this->pdo->exec("INSERT INTO products (gtin, name, category, tracking_mode, created_at) VALUES ('{$this->gtin}', 'Lot Test Ürünü', 'Test', 'lot', datetime('now'))");
        $this->productId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO locations (tenant_country, name, type, customs_zone) VALUES ('TR','Test Deposu','warehouse','TR_independent')");
        $this->locationId = (int) $this->pdo->lastInsertId();
    }

    private function createLot(string $lotNumber, int $qty, string $prodDate, string $expiryDate): int
    {
        $link = new DigitalLink();
        $entityId = $this->entities->createEntity($this->productId, $link->buildElementString($this->gtin, $lotNumber), 'lot', $lotNumber, null, $qty, $prodDate, $expiryDate);
        $this->eventStore->appendEvent($entityId, 'commissioning', 'active', null, null, $this->locationId, null, []);
        return $entityId;
    }

    public function testConsumeFromLotReducesRemainingQuantity(): void
    {
        $lotId = $this->createLot('LOT-A', 100, '2026-01-01', '2028-01-01');
        $this->entities->consumeFromLot($lotId, 30);
        $this->assertSame(70, (int) $this->entities->findById($lotId)['quantity_remaining']);
    }

    public function testConsumeFromLotRejectsInsufficientQuantity(): void
    {
        $lotId = $this->createLot('LOT-B', 10, '2026-01-01', '2028-01-01');
        $this->assertThrows(\RuntimeException::class, function () use ($lotId) {
            $this->entities->consumeFromLot($lotId, 9999);
        });
        $this->assertSame(10, (int) $this->entities->findById($lotId)['quantity_remaining']);
    }

    public function testConsumeFromLotMarksDepletedAtZero(): void
    {
        $lotId = $this->createLot('LOT-C', 5, '2026-01-01', '2028-01-01');
        $this->entities->consumeFromLot($lotId, 5);
        $this->assertSame('DEPLETED', $this->entities->findById($lotId)['status']);
    }

    public function testOldestAvailableLotPrefersEarlierProductionDate(): void
    {
        $this->createLot('LOT-NEW', 50, '2026-09-01', '2028-09-01');
        $oldLotId = $this->createLot('LOT-OLD', 50, '2026-01-01', '2028-01-01');
        $suggested = $this->reporting->oldestAvailableLot($this->productId, $this->locationId, 10);
        $this->assertSame($oldLotId, (int) $suggested['id']);
    }

    public function testLotTraceByNumberReturnsFullHistory(): void
    {
        $lotId = $this->createLot('LOT-TRACE', 20, '2026-01-01', '2028-01-01');
        $result = $this->lotTrace->traceByLotNumber('LOT-TRACE');
        $this->assertTrue($result['found']);
        $this->assertTrue($result['chain_verified']);
    }

    public function testLotTraceForUnknownLotReportsNotFoundHonestly(): void
    {
        $result = $this->lotTrace->traceByLotNumber('LOT-DOES-NOT-EXIST');
        $this->assertFalse($result['found']);
    }

    public function testDaysUntilExpiryComputesCorrectSign(): void
    {
        $this->createLot('LOT-EXP', 5, '2020-01-01', '2020-06-01'); // çok eski, kesin geçmiş
        $days = $this->lotTrace->daysUntilExpiry('LOT-EXP');
        $this->assertTrue($days < 0, 'Geçmiş bir SKT negatif gün döndürmeli.');
    }
}
