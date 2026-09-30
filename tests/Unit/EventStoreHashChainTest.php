<?php

declare(strict_types=1);

namespace Traceability\Tests\Unit;

use Traceability\Tests\TestCase;
use Traceability\Ledger\EventStore;
use Traceability\Ledger\EntityRepository;
use Traceability\Gs1\GtinBuilder;
use Traceability\Gs1\DigitalLink;

final class EventStoreHashChainTest extends TestCase
{
    private EventStore $eventStore;
    private EntityRepository $entities;
    private int $productId;
    private int $entityId;

    public function setUp(): void
    {
        parent::setUp();
        $key = bin2hex(random_bytes(32));
        $this->eventStore = new EventStore($this->pdo, [1 => $key], activeKeyVersion: 1);
        $this->entities = new EntityRepository($this->pdo);

        $gtin = (new GtinBuilder('8690000'))->build('00010');
        $this->pdo->exec("INSERT INTO products (gtin, name, category, tracking_mode, created_at) VALUES ('{$gtin}', 'Test Ürünü', 'Test', 'unit', datetime('now'))");
        $this->productId = (int) $this->pdo->lastInsertId();
        $this->entityId = $this->entities->createEntity($this->productId, (new DigitalLink())->buildElementString($gtin, 'LOT-1'), 'unit', 'LOT-1', 'SN1', 1, '2027-01-01', '2028-01-01');
        $this->eventStore->appendEvent($this->entityId, 'commissioning', 'active', null, null, null, null, []);
    }

    public function testFreshChainVerifies(): void
    {
        $this->assertTrue($this->eventStore->verifyChain($this->entityId));
    }

    public function testTamperingWithAnEventBreaksVerification(): void
    {
        // Append-only trigger DB seviyesinde bunu zaten engeller (iki
        // bağımsız katmandan biri) — hash-chain'in KENDİ BAŞINA da
        // tamperingi yakaladığını test etmek için trigger'ı BİLEREK
        // devre dışı bırakıp (gerçek bir saldırganın dosya seviyesinde
        // erişimi olduğu senaryoyu simüle ederek) doğrudan tamper ediyoruz.
        $this->pdo->exec('DROP TRIGGER trg_epcis_events_no_update');
        $this->pdo->exec("UPDATE epcis_events SET actor_id = 9999 WHERE entity_id = {$this->entityId}");
        $this->assertFalse($this->eventStore->verifyChain($this->entityId));
    }

    public function testKeyLineageDetectsBackwardRotation(): void
    {
        $key1 = bin2hex(random_bytes(32));
        $key2 = bin2hex(random_bytes(32));
        $store = new EventStore($this->pdo, [1 => $key1, 2 => $key2], activeKeyVersion: 1);
        $gtin = (new GtinBuilder('8690000'))->build('00011');
        $this->pdo->exec("INSERT INTO products (gtin, name, category, tracking_mode, created_at) VALUES ('{$gtin}', 'Key Test', 'Test', 'unit', datetime('now'))");
        $pid = (int) $this->pdo->lastInsertId();
        $eid = $this->entities->createEntity($pid, (new DigitalLink())->buildElementString($gtin, 'LOT-KEY'), 'unit', 'LOT-KEY', null, 1, '2027-01-01', '2028-01-01');

        $store->appendEvent($eid, 'commissioning', 'active', null, null, null, null, []);
        $storeV2 = new EventStore($this->pdo, [1 => $key1, 2 => $key2], activeKeyVersion: 2);
        $storeV2->appendEvent($eid, 'shipping', 'in_transit', null, null, null, null, []);
        $storeV1Again = new EventStore($this->pdo, [1 => $key1, 2 => $key2], activeKeyVersion: 1);
        $storeV1Again->appendEventForSimulatedKeyAttack($eid, 'destroyed', 'destroyed', null, null, null, null, [], (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'), forcedKeyVersion: 1);

        $lineage = $storeV1Again->verifyKeyLineage($eid);
        $this->assertFalse($lineage['consistent']);
    }
}
