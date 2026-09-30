<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use Traceability\Risk\AuthorizationGuard;
use PDO;

/**
 * harici bir gÃ¶zden geÃ§iren'nin önerisi (DURUM 3 — Dead Letter Queue, DURUM 5 — offline
 * destek) doğruydu: şimdiye kadar bir tarama cihaz arızası ya da ağ
 * kopması yüzünden anında işlenemezse HİÇBİR YERDE tutulmuyordu — sadece
 * "istasyon yerel kuyrukta bekletir" diye ANLATILMIŞTI, hiç KODLANMAMIŞTI.
 *
 * Bu sınıf ikisini de TEK bir mekanizmayla çözer, çünkü yapısal olarak
 * aynı durumdurlar: "bu tarama şu an işlenemedi, kayıp olmasın, sıraya
 * gir." Süpervizör daha sonra fiziksel kontrol yapıp ya gerçek bir
 * işleme dönüştürür ya da (yanlış okutma ise) sessizce siler.
 */
final class ScanExceptionQueue
{
    public function __construct(
        private PDO $pdo,
        private AuthorizationGuard $authGuard
    ) {
    }

    public function enqueue(
        string $rawScannedCode,
        string $exceptionType, // 'device_malformed' | 'network_offline' | 'reconciliation_mismatch'
        ?int $deviceId = null,
        ?int $locationId = null,
        ?int $actorId = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO scan_exception_queue (raw_scanned_code, device_id, location_id, actor_id, exception_type, submitted_at, status)
             VALUES (:code, :device, :location, :actor, :type, :now, :status)'
        );
        $stmt->execute([
            ':code' => $rawScannedCode,
            ':device' => $deviceId,
            ':location' => $locationId,
            ':actor' => $actorId,
            ':type' => $exceptionType,
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ':status' => 'pending',
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function pendingItems(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM scan_exception_queue WHERE status = 'pending' ORDER BY submitted_at ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Süpervizör, fiziksel kontrol sonrası (ürünü elle bulup gerçek kodunu
     * teyit ederek) bu kaydı ya çözülmüş işaretler ya da tamamen yanlış bir
     * okutmaysa siler ($discard=true). Bu metod KENDİSİ hiçbir event
     * üretmez — çözüm notunu kaydeder, gerçek işlemi (örn. doğru kodla
     * yeniden tarama) süpervizör AYRICA normal akıştan yapar.
     */
    public function resolve(int $queueId, int $resolvedByActorId, string $resolutionNote, bool $discard = false): void
    {
        $this->authGuard->requireRole($resolvedByActorId, ['supervizor', 'yonetici']);

        $stmt = $this->pdo->prepare(
            "UPDATE scan_exception_queue SET status = :status, resolved_by_actor_id = :actor, resolved_at = :now, resolution_note = :note WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute([
            ':status' => $discard ? 'discarded' : 'resolved',
            ':actor' => $resolvedByActorId,
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ':note' => $resolutionNote,
            ':id' => $queueId,
        ]);

        // v1.1: her DLQ çözümü sonrası da aynı hız kontrolü çalışır.
        $this->authGuard->checkSupervisorOverrideVelocity($resolvedByActorId);
    }

    public function pendingCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM scan_exception_queue WHERE status = 'pending'")->fetchColumn();
    }
}
