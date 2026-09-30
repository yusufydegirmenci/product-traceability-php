<?php

declare(strict_types=1);

namespace Traceability\Risk;

use PDO;


/**
 * Şimdiye kadar kritik işlemleri (süpervizör onayı, para ödeme, imha,
 * inceleme sonucu kaydetme) kimin yapabileceğine dair HİÇBİR kontrol
 * yoktu — herhangi bir actor_id, herhangi bir işlemi tetikleyebilirdi.
 * Bu sınıf, actors.role alanına bakarak minimum bir yetki kontrolü
 * uygular. Tam bir RBAC sistemi değil — ama en hassas işlemleri
 * kilitliyor.
 */
final class AuthorizationGuard
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @param string[] $allowedRoles */
    public function requireRole(int $actorId, array $allowedRoles): void
    {
        $stmt = $this->pdo->prepare('SELECT role FROM actors WHERE id = :id');
        $stmt->execute([':id' => $actorId]);
        $role = $stmt->fetchColumn();

        if ($role === false || !in_array($role, $allowedRoles, true)) {
            throw new UnauthorizedActionException(sprintf(
                'Actor #%d bu işlem için yetkisiz — gerekli rol(ler): %s, mevcut rol: %s',
                $actorId,
                implode(', ', $allowedRoles),
                $role !== false && $role !== null ? $role : 'tanımsız'
            ));
        }
    }

    /**
     * v1.1 — SÜPERVİZÖR SUİSTİMAL ÖNLEME EŞİĞİ (Rogue Supervisor Anomaly
     * Threshold). Bir süpervizörün TEK BAŞINA, kısa bir zaman diliminde
     * anormal sayıda override onaylaması (ağırlık uyuşmazlığı VEYA DLQ
     * çözümü), tek başına kanıt değildir ama İNSANIN bakması gereken bir
     * hızlanma paternidir. SİSTEMİ KİLİTLEMEZ (işlemler devam eder) —
     * sadece bir SYSTEM_RISK_FLAG olayı üretir ve bağımsız gözetime
     * bildirir. Bu metod her başarılı override sonrası çağrılmalıdır.
     */
    public function checkSupervisorOverrideVelocity(int $supervisorActorId, ?int $windowMinutes = null, ?int $threshold = null): void
    {
        $config = (require dirname(__DIR__, 2) . '/config/warehouse.php')['supervisor_override_velocity'];
        $windowMinutes ??= $config['window_minutes'];
        $threshold ??= $config['threshold'];
        $cutoff = (new \DateTimeImmutable("-{$windowMinutes} minutes"))->format('Y-m-d H:i:s');

        $weightStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM packing_station_weights WHERE override_by_actor_id = :actor AND override_at >= :cutoff'
        );
        $weightStmt->execute([':actor' => $supervisorActorId, ':cutoff' => $cutoff]);
        $weightCount = (int) $weightStmt->fetchColumn();

        $dlqStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM scan_exception_queue WHERE resolved_by_actor_id = :actor AND resolved_at >= :cutoff"
        );
        $dlqStmt->execute([':actor' => $supervisorActorId, ':cutoff' => $cutoff]);
        $dlqCount = (int) $dlqStmt->fetchColumn();

        $total = $weightCount + $dlqCount;
        if ($total <= $threshold) {
            return;
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $detail = json_encode([
            'window_minutes' => $windowMinutes, 'threshold' => $threshold,
            'weight_overrides' => $weightCount, 'dlq_resolutions' => $dlqCount, 'total' => $total,
        ], JSON_UNESCAPED_UNICODE);

        // SYSTEM_RISK_FLAG — mevcut risk_events tablosuna, mevcut şemayla yazılır
        // (RiskScorer'ın kendisiyle bağ kurmadan, kendi kendine yeten bir kayıt).
        $riskStmt = $this->pdo->prepare(
            'INSERT INTO risk_events (actor_id, event_type, severity, is_first_occurrence, detail, detected_at, resolved)
             VALUES (:actor_id, :event_type, :severity, 0, :detail, :now, 0)'
        );
        $riskStmt->execute([
            ':actor_id' => $supervisorActorId, ':event_type' => 'rogue_supervisor_override_velocity',
            ':severity' => 70, ':detail' => $detail, ':now' => $now,
        ]);

        $notifStmt = $this->pdo->prepare(
            "INSERT INTO notifications (type, severity, target_role, related_id, message, channel, created_at)
             VALUES ('system_risk_flag', 'critical', 'sirket_sahibi', :actor_id, :message, 'console', :now)"
        );
        $notifStmt->execute([
            ':actor_id' => $supervisorActorId,
            ':message' => "SYSTEM_RISK_FLAG: Süpervizör #{$supervisorActorId}, son {$windowMinutes} dakikada {$total} override onayladı (eşik: {$threshold}) — kilitlenmedi, ama bağımsız inceleme önerilir.",
            ':now' => $now,
        ]);
    }
}
