<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use PDO;


/**
 * v1.2 AŞAMA 4 — YANLIŞ RAF VE KÖR TOPLAMA ENGELİ (Putaway & Blind-Pick
 * Guard). Hiçbir ekstra donanım (RFID raf etiketi, ışıklı toplama sistemi)
 * olmadan, SADECE var olan el terminaliyle iki okutmanın SIRASINI zorunlu
 * kılar: önce RAF ADRES BARKODU, sonra ÜRÜN BARKODU. Bu sıra atlanırsa
 * (doğrudan ürün okutulursa), personelin ürünü HANGİ RAFTAN aldığı/HANGİ
 * RAFA koyduğu hiçbir zaman doğrulanmaz — "kör toplama" riski oluşur.
 *
 * DÜRÜST SINIR: Bu, personelin GERÇEKTEN o rafın önünde durduğunu
 * KANITLAMAZ (birisi raf barkodunu uzaktan/ezbere de okutabilir) — sadece
 * "iki okutma arasında SIRA ve YAKIN ZAMANLI bağlantı var mı" kontrolü
 * yapar. Fiziksel bir kanıt değil, mantıksal bir tutarlılık kontrolüdür.
 */
final class ScanSequenceGuard
{
    private int $sequenceWindowSeconds;

    public function __construct(
        private PDO $pdo,
        ?int $sequenceWindowSeconds = null
    ) {
        $this->sequenceWindowSeconds = $sequenceWindowSeconds
            ?? (require dirname(__DIR__, 2) . '/config/warehouse.php')['scan_sequence']['window_seconds'];
    }

    /** Raf/lokasyon barkodu okutulduğunda çağrılır — "az önce buradaydım" damgası. */
    public function recordLocationScan(int $deviceId, int $locationId): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $existing = $this->pdo->prepare('SELECT device_id FROM device_scan_sequence WHERE device_id = :id');
        $existing->execute([':id' => $deviceId]);
        if ($existing->fetchColumn() !== false) {
            $stmt = $this->pdo->prepare('UPDATE device_scan_sequence SET last_location_id = :loc, last_location_scan_at = :now WHERE device_id = :id');
        } else {
            $stmt = $this->pdo->prepare('INSERT INTO device_scan_sequence (device_id, last_location_id, last_location_scan_at) VALUES (:id, :loc, :now)');
        }
        $stmt->execute([':loc' => $locationId, ':now' => $now, ':id' => $deviceId]);
    }

    /**
     * Ürün barkodu okutulmadan HEMEN ÖNCE çağrılmalıdır. Bu cihazdan
     * yakın zamanda (varsayılan 120 saniye) BEKLENEN lokasyon için bir
     * raf okutması yapılmadıysa, sıra ihlali fırlatır.
     */
    public function assertLocationScannedFirst(int $deviceId, ?int $expectedLocationId = null): void
    {
        $stmt = $this->pdo->prepare('SELECT last_location_id, last_location_scan_at FROM device_scan_sequence WHERE device_id = :id');
        $stmt->execute([':id' => $deviceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false || $row['last_location_scan_at'] === null) {
            throw new InvalidScanSequenceException(
                "SIRA İHLALİ: cihaz #{$deviceId} hiç raf/lokasyon barkodu okutmadan doğrudan ürün okutmaya çalıştı. "
                . 'Önce rafın barkodunu okutun.'
            );
        }

        $lastScan = new \DateTimeImmutable($row['last_location_scan_at']);
        $now = new \DateTimeImmutable();
        if (($now->getTimestamp() - $lastScan->getTimestamp()) > $this->sequenceWindowSeconds) {
            throw new InvalidScanSequenceException(
                "SIRA İHLALİ: son raf okutması çok eski (" . $row['last_location_scan_at'] . ") — {$this->sequenceWindowSeconds} "
                . 'saniyelik pencerenin dışında. Rafı tekrar okutun.'
            );
        }

        if ($expectedLocationId !== null && (int) $row['last_location_id'] !== $expectedLocationId) {
            throw new InvalidScanSequenceException(
                "SIRA İHLALİ: okutulan raf (#{$row['last_location_id']}), beklenen lokasyonla (#{$expectedLocationId}) uyuşmuyor."
            );
        }
    }
}
