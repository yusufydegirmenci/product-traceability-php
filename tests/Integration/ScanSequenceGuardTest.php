<?php

declare(strict_types=1);

namespace Traceability\Tests\Integration;

use Traceability\Tests\TestCase;
use Traceability\Ledger\ScanSequenceGuard;
use Traceability\Ledger\InvalidScanSequenceException;

final class ScanSequenceGuardTest extends TestCase
{
    private ScanSequenceGuard $guard;
    private int $deviceId;
    private int $locationId;

    public function setUp(): void
    {
        parent::setUp();
        $this->guard = new ScanSequenceGuard($this->pdo, sequenceWindowSeconds: 120);
        $this->pdo->exec("INSERT INTO devices (device_uid, tenant_country) VALUES ('SEQ-TEST-DEVICE','TR')");
        $this->deviceId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO locations (tenant_country, name, type, customs_zone) VALUES ('TR','Raf A1','warehouse','TR_independent')");
        $this->locationId = (int) $this->pdo->lastInsertId();
    }

    public function testProductScanWithoutPriorLocationScanIsRejected(): void
    {
        $this->assertThrows(InvalidScanSequenceException::class, function () {
            $this->guard->assertLocationScannedFirst($this->deviceId);
        });
    }

    public function testProductScanAfterLocationScanIsAccepted(): void
    {
        $this->guard->recordLocationScan($this->deviceId, $this->locationId);
        // exception atmazsa test geçer
        $this->guard->assertLocationScannedFirst($this->deviceId, expectedLocationId: $this->locationId);
        $this->assertTrue(true);
    }

    public function testStaleLocationScanOutsideWindowIsRejected(): void
    {
        $this->guard->recordLocationScan($this->deviceId, $this->locationId);
        $this->pdo->exec("UPDATE device_scan_sequence SET last_location_scan_at = datetime('now', '-5 minutes') WHERE device_id = {$this->deviceId}");
        $this->assertThrows(InvalidScanSequenceException::class, function () {
            $this->guard->assertLocationScannedFirst($this->deviceId);
        });
    }

    public function testMismatchedExpectedLocationIsRejected(): void
    {
        $this->guard->recordLocationScan($this->deviceId, $this->locationId);
        $this->assertThrows(InvalidScanSequenceException::class, function () {
            $this->guard->assertLocationScannedFirst($this->deviceId, expectedLocationId: 999999);
        });
    }
}
