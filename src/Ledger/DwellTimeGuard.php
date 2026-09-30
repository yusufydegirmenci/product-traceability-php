<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use PDO;

/**
 * v1.2 AŞAMA 2 — ZİMMET ZAMAN AŞIMI (Dwell-Time Guard). Hiçbir ekstra
 * donanım (RFID, X-Ray) olmadan, SADECE el terminalinin "raftan aldım"
 * (pickItem) ve "koliye koydum / rafa iade ettim" (releaseItem) okutmaları
 * arasındaki SÜREYİ izleyerek iç hırsızlık şüphesini yakalar: bir ürün
 * raftan alınıp uzun süre hiçbir yere GİRMEZSE (ne koliye ne rafa), bu
 * "üzerimde duruyor" şüphesidir.
 *
 * DÜRÜST SINIR: Bu KESİN bir kanıt değildir — meşru bir sebep de olabilir
 * (mola, dikkat dağınıklığı, sistemsel bir gecikme). Bu yüzden personel
 * SİLİNMEZ/kovulmaz — sadece YENİ işlem yapması askıya alınır, bir
 * süpervizör inceleyip ya temizler ya da gerçek bir soruşturma başlatır.
 */
final class DwellTimeGuard
{
    public function __construct(private PDO $pdo)
    {
    }

    public function pickItem(int $entityId, int $actorId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO custody_holds (entity_id, actor_id, picked_at) VALUES (:entity, :actor, :now)'
        );
        $stmt->execute([':entity' => $entityId, ':actor' => $actorId, ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Ürün koliye girdiğinde (packing) veya rafa iade edildiğinde çağrılır — zimmeti kapatır. */
    public function releaseItem(int $entityId, string $reason = 'packed'): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE custody_holds SET released_at = :now, release_reason = :reason
             WHERE entity_id = :entity AND released_at IS NULL"
        );
        $stmt->execute([':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), ':reason' => $reason, ':entity' => $entityId]);
    }

    /**
     * İNCELEME BULGUSU (SECURITY_REVIEW.md #4): releaseItem() daha önce
     * SADECE PackageService::addEntityToPackage() içinde ("koliye
     * girdi") çağrılıyordu — personel bir siparişten VAZGEÇİP ürünü
     * fiziksel olarak rafa geri koyduğunda zimmeti kapatacak AYRI bir
     * çağrı noktası YOKTU. Bu, açık, adlandırılmış bir metotla kapatıldı.
     */
    public function returnToShelf(int $entityId): void
    {
        $this->releaseItem($entityId, 'returned_to_shelf');
    }

    public function hasOpenCustody(int $actorId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM custody_holds WHERE actor_id = :actor AND released_at IS NULL');
        $stmt->execute([':actor' => $actorId]);
        return ((int) $stmt->fetchColumn()) > 0;
    }

    public function openCustodyCount(int $actorId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM custody_holds WHERE actor_id = :actor AND released_at IS NULL');
        $stmt->execute([':actor' => $actorId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Süresi dolmuş zimmetleri tarar. Her aktör için EN FAZLA bir kez
     * bayrak/askıya alma yapar (aynı aktörün 5 farklı gecikmiş ürünü
     * varsa bile TEK bir olay + TEK bir askıya alma yeter).
     */
    public function checkOverdueCustody(?int $windowMinutes = null): array
    {
        $windowMinutes ??= (require dirname(__DIR__, 2) . '/config/warehouse.php')['dwell_time']['window_minutes'];
        $cutoff = (new \DateTimeImmutable("-{$windowMinutes} minutes"))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT actor_id FROM custody_holds WHERE released_at IS NULL AND picked_at <= :cutoff'
        );
        $stmt->execute([':cutoff' => $cutoff]);
        $overdueActors = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $flagged = [];
        foreach ($overdueActors as $actorId) {
            $actorId = (int) $actorId;
            $countStmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM custody_holds WHERE actor_id = :actor AND released_at IS NULL AND picked_at <= :cutoff'
            );
            $countStmt->execute([':actor' => $actorId, ':cutoff' => $cutoff]);
            $overdueCount = (int) $countStmt->fetchColumn();

            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $riskStmt = $this->pdo->prepare(
                'INSERT INTO risk_events (actor_id, event_type, severity, is_first_occurrence, detail, detected_at, resolved)
                 VALUES (:actor, :type, 55, 0, :detail, :now, 0)'
            );
            $riskStmt->execute([
                ':actor' => $actorId, ':type' => 'internal_shrinkage_suspicion',
                ':detail' => json_encode(['overdue_items' => $overdueCount, 'window_minutes' => $windowMinutes], JSON_UNESCAPED_UNICODE),
                ':now' => $now,
            ]);

            $notifStmt = $this->pdo->prepare(
                "INSERT INTO notifications (type, severity, target_role, related_id, message, channel, created_at)
                 VALUES ('internal_shrinkage_suspicion', 'critical', 'supervisor', :actor, :message, 'console', :now)"
            );
            $notifStmt->execute([
                ':actor' => $actorId,
                ':message' => "Personel #{$actorId} üzerinde {$overdueCount} adet ürün {$windowMinutes} dakikadır koliye girmemiş/rafa dönmemiş — zimmet şüphesi.",
                ':now' => $now,
            ]);

            $suspendStmt = $this->pdo->prepare('UPDATE actors SET suspended_at = :now, suspended_reason = :reason WHERE id = :id');
            $suspendStmt->execute([':now' => $now, ':reason' => "Zimmet zaman aşımı ({$overdueCount} ürün, {$windowMinutes}+ dk)", ':id' => $actorId]);

            $flagged[] = ['actor_id' => $actorId, 'overdue_count' => $overdueCount];
        }
        return $flagged;
    }

    public function isSuspended(int $actorId): bool
    {
        $stmt = $this->pdo->prepare('SELECT suspended_at FROM actors WHERE id = :id');
        $stmt->execute([':id' => $actorId]);
        $value = $stmt->fetchColumn();
        return $value !== null && $value !== false;
    }

    /** Süpervizör inceleyip temiz bulursa veya durumu çözerse askıyı kaldırır. */
    public function clearSuspension(int $actorId, int $clearedByActorId, \Traceability\Risk\AuthorizationGuard $authGuard): void
    {
        $authGuard->requireRole($clearedByActorId, ['supervizor', 'yonetici']);
        $stmt = $this->pdo->prepare('UPDATE actors SET suspended_at = NULL, suspended_reason = NULL WHERE id = :id');
        $stmt->execute([':id' => $actorId]);
    }
}
