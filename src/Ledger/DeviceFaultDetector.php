<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use PDO;

/**
 * YOĞUN İŞ GÜNÜNDE + CİHAZ ARIZASI = KAOS. Bu kaos, sistemin en tehlikeli
 * anıdır — çünkü gerçek bir sabotaj, "zaten bugün her şey karışıktı" diye
 * gürültünün içine gizlenebilir, VEYA masum bir çalışan sırf o gün bozuk
 * bir okuyucu kullandığı için haksız yere suçlanabilir.
 *
 * Bu sınıfın TEK işi: bir anomalinin (bozuk kod, beklenmedik eşleşme)
 * belirli bir CİHAZ ile ilişkili olup olmadığını izlemek. Aynı cihazdan
 * kısa sürede çok sayıda anomali geliyorsa, bu muhtemelen DONANIM
 * arızasıdır — ilgili personelin risk skoruna değil, cihazın bakım
 * kuyruğuna yönlendirilmelidir. Bu ayrım yapılmazsa, kaotik bir günde
 * gerçek kötü niyet ile donanım gürültüsü aynı kefeye konur ve ikisi de
 * ya gözden kaçar ya da masum biri cezalandırılır.
 */
final class DeviceFaultDetector
{
    public function __construct(private PDO $pdo)
    {
    }

    public function recordScanAnomaly(int $deviceId, string $anomalyType, ?int $reportedByActorId = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO device_fault_events (device_id, anomaly_type, reported_by_actor_id, occurred_at)
             VALUES (:device_id, :type, :actor_id, :now)'
        );
        $stmt->execute([
            ':device_id' => $deviceId,
            ':type' => $anomalyType,
            ':actor_id' => $reportedByActorId,
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function recentAnomalyCount(int $deviceId, int $windowMinutes = 10): int
    {
        $cutoff = (new \DateTimeImmutable("-{$windowMinutes} minutes"))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM device_fault_events WHERE device_id = :device_id AND occurred_at >= :cutoff'
        );
        $stmt->execute([':device_id' => $deviceId, ':cutoff' => $cutoff]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Bir cihazdan kısa sürede $threshold'dan fazla anomali geldiyse,
     * bu anomalilerin KİŞİSEL bir risk sinyali yerine bir CİHAZ ARIZASI
     * olarak değerlendirilmesi gerektiğini söyler. ÖNEMLİ: bu insanı
     * otomatik aklamaz — sadece "önce cihazı kontrol et" der, nihai
     * kararı yine insan verir.
     */
    public function isDeviceFaultLikely(int $deviceId, int $windowMinutes = 10, int $threshold = 5): bool
    {
        return $this->recentAnomalyCount($deviceId, $windowMinutes) >= $threshold;
    }

    /**
     * MÜFETTİŞ BULGUSU: bu sınıf daha önce sadece TESPİT ediyordu, cihazı
     * hiç DURDURMUYORDU — kötü niyetli biri "cihaz arızası" kılıfı altında
     * kasıtlı olarak taramamayı sürdürüp kişisel risk sinyalinden muaf
     * kalabilirdi. Artık eşik aşıldığında cihaz GERÇEKTEN kilitlenir —
     * teknik servis onayı olmadan tekrar kullanılamaz.
     */
    public function lockDevice(int $deviceId, string $reason): void
    {
        $stmt = $this->pdo->prepare('UPDATE devices SET locked_at = :now, locked_reason = :reason WHERE id = :id');
        $stmt->execute([':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), ':reason' => $reason, ':id' => $deviceId]);
    }

    public function isLocked(int $deviceId): bool
    {
        $stmt = $this->pdo->prepare('SELECT locked_at FROM devices WHERE id = :id');
        $stmt->execute([':id' => $deviceId]);
        return $stmt->fetchColumn() !== null;
    }

    /** Sadece teknik servis/süpervizör, fiziksel muayene sonrası cihazı açabilir. */
    public function unlockDevice(int $deviceId, int $unlockedByActorId, \Traceability\Risk\AuthorizationGuard $authGuard): void
    {
        $authGuard->requireRole($unlockedByActorId, ['supervizor', 'yonetici']);
        $stmt = $this->pdo->prepare('UPDATE devices SET locked_at = NULL, locked_reason = NULL, unlocked_by_actor_id = :actor WHERE id = :id');
        $stmt->execute([':actor' => $unlockedByActorId, ':id' => $deviceId]);
    }
}
