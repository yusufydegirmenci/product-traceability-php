<?php

declare(strict_types=1);

namespace Traceability\Tests\Integration;

use Traceability\Tests\TestCase;
use Traceability\Ledger\EntityRepository;
use Traceability\Ledger\DamagedItemService;
use Traceability\Risk\AuthorizationGuard;
use Traceability\Gs1\GtinBuilder;
use Traceability\Gs1\DigitalLink;

final class DamagedItemServiceTest extends TestCase
{
    private EntityRepository $entities;
    private DamagedItemService $damagedService;
    private int $supervisorId;
    private int $entityId;
    private int $productIdForEntity;

    public function setUp(): void
    {
        parent::setUp();
        $this->entities = new EntityRepository($this->pdo);
        $authGuard = new AuthorizationGuard($this->pdo);
        $this->damagedService = new DamagedItemService($this->pdo, $this->entities, $authGuard);

        $this->pdo->exec("INSERT INTO actors (tenant_country, actor_type, role, name) VALUES ('TR','staff','supervizor','Hurda Süpervizör')");
        $this->supervisorId = (int) $this->pdo->lastInsertId();

        $gtin = (new GtinBuilder('8690000'))->build('00060');
        $this->pdo->exec("INSERT INTO products (gtin, name, category, tracking_mode, created_at) VALUES ('{$gtin}', 'Hurda Test', 'Test', 'lot', datetime('now'))");
        $productId = (int) $this->pdo->lastInsertId();
        $this->productIdForEntity = $productId;
        $this->entityId = $this->entities->createEntity($productId, (new DigitalLink())->buildElementString($gtin, 'LOT-HURDA'), 'lot', 'LOT-HURDA', null, 3, '2027-01-01', '2028-01-01');
        $this->damagedService->markDamaged($this->entityId, $this->supervisorId);
    }

    public function testScrapRejectedWithEmptyTutanakRef(): void
    {
        $this->assertThrows(\RuntimeException::class, function () {
            $this->damagedService->scrapWithTutanak($this->entityId, $this->supervisorId, '');
        });
        $this->assertSame('DAMAGED_HOLD', $this->entities->findById($this->entityId)['status']);
    }

    public function testScrapSucceedsWithValidTutanakRef(): void
    {
        $this->damagedService->scrapWithTutanak($this->entityId, $this->supervisorId, 'HURDA-2026-001');
        $this->assertSame('DESTROYED', $this->entities->findById($this->entityId)['status']);
    }

    public function testScrapRejectedWhenEntityNotInDamagedHold(): void
    {
        $gtin = (new GtinBuilder('8690000'))->build('00061');
        $this->pdo->exec("INSERT INTO products (gtin, name, category, tracking_mode, created_at) VALUES ('{$gtin}', 'Intact', 'Test', 'lot', datetime('now'))");
        $productId = (int) $this->pdo->lastInsertId();
        $intactId = $this->entities->createEntity($productId, (new DigitalLink())->buildElementString($gtin, 'LOT-INTACT2'), 'lot', 'LOT-INTACT2', null, 3, '2027-01-01', '2028-01-01');

        $this->assertThrows(\RuntimeException::class, function () use ($intactId) {
            $this->damagedService->scrapWithTutanak($intactId, $this->supervisorId, 'HURDA-2026-002');
        });
    }

    /**
     * SECURITY_REVIEW.md Bölüm 3 — Seçenek A'nın (dört-göz entegrasyonu)
     * GERÇEKTEN çalıştığını kanıtlar. Bu, scrapWithTutanak()'ın YERİNE
     * geçmez — insan kararı bekleyen bir ALTERNATİFTİR, burada test
     * edilerek somutlaştırılmıştır.
     */
    public function testFourEyesAlternativeRequiresDifferentConfirmer(): void
    {
        $destroyService = new \Traceability\Ledger\DestroyService(
            $this->pdo,
            new \Traceability\Ledger\EventStore($this->pdo, [1 => bin2hex(random_bytes(32))], activeKeyVersion: 1),
            $this->entities,
            new \Traceability\Risk\AuthorizationGuard($this->pdo)
        );
        $requestId = $this->damagedService->requestScrapViaFourEyes($this->entityId, $this->supervisorId, 'HURDA-4EYES-001', $destroyService);

        // AYNI kişi onaylamaya çalışırsa REDDEDİLMELİ:
        $this->assertThrows(\Traceability\Ledger\DestroyRequestException::class, function () use ($requestId, $destroyService) {
            $this->damagedService->confirmScrapViaFourEyes($requestId, $this->supervisorId, $this->productIdForEntity, $destroyService);
        });

        // FARKLI bir yetkili onaylarsa BAŞARILI olmalı:
        $this->pdo->exec("INSERT INTO actors (tenant_country, actor_type, role, name) VALUES ('TR','staff','yonetici','İkinci Onaylayan')");
        $secondApprover = (int) $this->pdo->lastInsertId();
        $this->damagedService->confirmScrapViaFourEyes($requestId, $secondApprover, $this->productIdForEntity, $destroyService);
        $this->assertSame('DESTROYED', $this->entities->findById($this->entityId)['status']);
    }
}
