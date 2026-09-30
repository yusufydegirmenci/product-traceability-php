<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use PDO;

/**
 * Şimdiye kadar kurduğumuz her şey (bekleyen iadeler, riskli aktörler,
 * anlaşmazlıklı kargo olayları) veritabanında SESSİZCE bekliyordu — kimse
 * bakmazsa hiçbir şey olmuyordu. Bu sınıf düzenli çalıştırıldığında
 * (örn. saatlik bir zamanlanmış görevle) bu durumları tarar, bildirim
 * kaydı oluşturur ve belirli süre onaylanmazsa üst role eskalasyon yapar.
 *
 * Bu ASLA otomatik bir aksiyon almaz (para ödemez, hesap kapatmaz) —
 * sadece "birisi buna bakmalı" der ve doğru role yönlendirir.
 */
final class NotificationService
{
    public function __construct(
        private PDO $pdo,
        private RefundService $refunds,
        private ?ReportingService $reporting = null,
        private ?DeviceFaultDetector $deviceFaultDetector = null,
        private ?DecisionBenefitCorrelationDetector $networkDetector = null,
        private ?SharedResourceCollusionDetector $sharedResourceDetector = null
    ) {
    }

    /** Tüm kontrolleri çalıştırır, yeni bulunan her durum için bir bildirim açar. Zaten açık/onaylanmamış bir bildirim varsa tekrar oluşturmaz. */
    public function scanAndDispatch(): array
    {
        $created = [];
        $created = array_merge($created, $this->checkOverdueRefunds());
        $created = array_merge($created, $this->checkHighRiskActors());
        $created = array_merge($created, $this->checkDisputedCarrierEvents());
        $created = array_merge($created, $this->checkUnresolvedDuplicateFlags());
        $created = array_merge($created, $this->checkStaleInventory());
        $created = array_merge($created, $this->checkShippedWithoutConfirmation());
        $created = array_merge($created, $this->checkDeviceFaults());
        $created = array_merge($created, $this->checkOrganizedNetworks());
        $created = array_merge($created, $this->checkSharedResourceCollusion());
        return $created;
    }

    /**
     * DENETİM BULGUSU (kod incelemesi sırasında bulundu): SharedResourceCollusionDetector
     * (RT8.2 DURUM 5) daha önce sadece demo.php'de ayrı çağrılıyordu, gerçek
     * bildirim taramasına hiç bağlı değildi — üretimde bu paterni kimse
     * otomatik göremezdi. Artık diğer ağ-bazlı bulgular gibi taranıyor.
     */
    private function checkSharedResourceCollusion(): array
    {
        if ($this->sharedResourceDetector === null) {
            return [];
        }
        $created = [];
        foreach ($this->sharedResourceDetector->sharedDeviceHiddenHub() as $flag) {
            $relatedId = $flag['device_id'] * 100000 + $flag['dealer_id']; // cihaz+bayi ikilisini tekilleştiren birleşik anahtar
            if ($this->alreadyOpen('shared_resource_hidden_hub', $relatedId)) {
                continue;
            }
            $created[] = $this->create(
                'shared_resource_hidden_hub',
                'critical',
                'sirket_sahibi',
                $relatedId,
                sprintf(
                    "Cihaz #%d üzerinden %d farklı personel, '%s' bayisine %d işlemlik ortak yoğunlaşma gösteriyor — gizli ağ şüphesi (kişi değil, kaynak bulgusu).",
                    $flag['device_id'], $flag['distinct_staff'], $flag['dealer_name'], $flag['transaction_count']
                )
            );
        }
        return $created;
    }

    /**
     * KİŞİ bazlı değil, FAYDALANICI (dealer/ağ) bazlı bildirim — Gölge Ağ
     * tatbikatının kanıtladığı boşluğu kapatır. Hedef doğrudan
     * 'sirket_sahibi'dir — çünkü çok-ülkeli organize bir şebeke bulgusu,
     * tek bir ülke supervizörünün münhasır sorumluluğu değildir.
     */
    private function checkOrganizedNetworks(): array
    {
        if ($this->networkDetector === null) {
            return [];
        }
        $created = [];
        foreach ($this->networkDetector->sharedBeneficiaryConcentration() as $flag) {
            if ($this->alreadyOpen('organized_network_suspected', $flag['dealer_id'])) {
                continue;
            }
            $created[] = $this->create(
                'organized_network_suspected',
                'critical',
                'sirket_sahibi',
                $flag['dealer_id'],
                sprintf(
                    "Faydalanıcı '%s': %d ülke, %d farklı personel, %d farklı onaylayıcı üzerinden %d işlem — organize şebeke şüphesi (kişi değil, ağ bulgusu).",
                    $flag['dealer_name'], $flag['distinct_countries'], $flag['distinct_staff'], $flag['distinct_approvers'], $flag['transaction_count']
                )
            );
        }
        return $created;
    }

    /**
     * Cihaz arızası, KİŞİSEL bir risk sinyali DEĞİLDİR — bu yüzden ayrı bir
     * kanaldan ("teknik_servis" rolüne) bildirilir. Bu ayrım, kaotik/yoğun
     * bir günde donanım gürültüsünün personel risk kuyruğunu tıkayıp
     * gerçek sinyalleri gizlemesini önler.
     */
    private function checkDeviceFaults(): array
    {
        if ($this->deviceFaultDetector === null) {
            return [];
        }
        $created = [];
        $stmt = $this->pdo->query('SELECT DISTINCT device_id FROM device_fault_events');
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $deviceId) {
            $deviceId = (int) $deviceId;
            if (!$this->deviceFaultDetector->isDeviceFaultLikely($deviceId)) {
                continue;
            }
            if ($this->alreadyOpen('device_malfunction_suspected', $deviceId)) {
                continue;
            }
            $count = $this->deviceFaultDetector->recentAnomalyCount($deviceId);
            $created[] = $this->create(
                'device_malfunction_suspected',
                'warning',
                'teknik_servis',
                $deviceId,
                sprintf('Cihaz #%d son 10 dakikada %d bozuk okuma üretti — muhtemelen donanım arızası, bakım kontrolü gerekli.', $deviceId, $count)
            );
        }
        return $created;
    }

    private function checkStaleInventory(): array
    {
        if ($this->reporting === null) {
            return [];
        }
        $created = [];
        foreach ($this->reporting->staleInventory(30) as $row) {
            if ($this->alreadyOpen('stale_inventory', (int) $row['id'])) {
                continue;
            }
            $created[] = $this->create(
                'stale_inventory',
                'warning',
                'supervisor',
                (int) $row['id'],
                sprintf('%s (entity #%d) 30 günden uzun süredir depoda hareketsiz.', $row['product_name'], $row['id'])
            );
        }
        return $created;
    }

    private function checkShippedWithoutConfirmation(): array
    {
        if ($this->reporting === null) {
            return [];
        }
        $created = [];
        foreach ($this->reporting->shippedWithoutCarrierConfirmation(48) as $row) {
            if ($this->alreadyOpen('shipped_without_carrier_confirmation', (int) $row['id'])) {
                continue;
            }
            $created[] = $this->create(
                'shipped_without_carrier_confirmation',
                'critical',
                'supervisor',
                (int) $row['id'],
                sprintf('Event #%d "kargolandı" olarak işaretli ama 48 saattir kargo firmasından teyit gelmedi.', $row['id'])
            );
        }
        return $created;
    }

    private function checkOverdueRefunds(): array
    {
        $created = [];
        foreach ($this->refunds->findOverduePending(maxDays: 3) as $refund) {
            if ($this->alreadyOpen('overdue_refund', (int) $refund['id'])) {
                continue;
            }
            $created[] = $this->create(
                'overdue_refund',
                'warning',
                'accounting',
                (int) $refund['id'],
                sprintf('Refund #%d, %s TL, 3 günden uzun süredir ödenmeyi bekliyor.', $refund['id'], $refund['amount'])
            );
        }
        return $created;
    }

    private function checkHighRiskActors(): array
    {
        $created = [];
        $stmt = $this->pdo->query("SELECT * FROM actor_risk_score WHERE rolling_score >= 60");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($this->alreadyOpen('high_risk_actor', (int) $row['actor_id'])) {
                continue;
            }
            $created[] = $this->create(
                'high_risk_actor',
                'critical',
                'supervisor',
                (int) $row['actor_id'],
                sprintf('Aktör #%d risk skoru %.0f — incelemeye alınmalı.', $row['actor_id'], $row['rolling_score'])
            );
        }
        return $created;
    }

    private function checkDisputedCarrierEvents(): array
    {
        $created = [];
        $stmt = $this->pdo->query("SELECT * FROM carrier_events WHERE disputed = 1");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($this->alreadyOpen('disputed_carrier_event', (int) $row['id'])) {
                continue;
            }
            $created[] = $this->create(
                'disputed_carrier_event',
                'warning',
                'supervisor',
                (int) $row['id'],
                sprintf('Kargo olayı #%d: bildirilen zaman ile alınan zaman arasında anormal fark var.', $row['id'])
            );
        }
        return $created;
    }

    private function checkUnresolvedDuplicateFlags(): array
    {
        $created = [];
        $stmt = $this->pdo->query("SELECT * FROM duplicate_scan_flags WHERE resolved = 0");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($this->alreadyOpen('duplicate_scan', (int) $row['id'])) {
                continue;
            }
            $created[] = $this->create(
                'duplicate_scan',
                'critical',
                'supervisor',
                (int) $row['id'],
                sprintf('Kopya kod şüphesi #%d — henüz incelenmedi.', $row['id'])
            );
        }
        return $created;
    }

    private function alreadyOpen(string $type, int $relatedId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM notifications WHERE type = :type AND related_id = :related_id AND acknowledged_at IS NULL'
        );
        $stmt->execute([':type' => $type, ':related_id' => $relatedId]);
        return ((int) $stmt->fetchColumn()) > 0;
    }

    private function create(string $type, string $severity, string $targetRole, ?int $relatedId, string $message): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO notifications (type, severity, target_role, related_id, message, channel, created_at)
             VALUES (:type, :severity, :role, :related_id, :message, :channel, :now)'
        );
        $stmt->execute([
            ':type' => $type,
            ':severity' => $severity,
            ':role' => $targetRole,
            ':related_id' => $relatedId,
            ':message' => $message,
            ':channel' => 'console', // production'da: email/sms/slack adaptörüne değişir
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function acknowledge(int $notificationId, int $actorId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE notifications SET acknowledged_at = :now, acknowledged_by_actor_id = :actor WHERE id = :id'
        );
        $stmt->execute([':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), ':actor' => $actorId, ':id' => $notificationId]);
    }

    /**
     * Belirtilen saatten uzun süredir onaylanmamış bildirimleri üst role
     * eskalasyon yapar. ÖNEMLİ: eskalasyon zinciri 'yonetici'de DURMAZ —
     * en yetkili operasyonel rolün KENDİSİ bozuksa (bu senaryoda olduğu
     * gibi), eskalasyonun ona geri dönmesi hiçbir işe yaramaz. Bu yüzden
     * zincirin son durağı, operasyonel hiyerarşinin TAMAMEN DIŞINDA olan
     * 'sirket_sahibi' (veya bağımsız denetim kurulu) rolüdür.
     */
    public function escalateUnacknowledged(int $hours = 24): array
    {
        $cutoff = (new \DateTimeImmutable("-{$hours} hours"))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "SELECT * FROM notifications
             WHERE acknowledged_at IS NULL
               AND COALESCE(escalated_at, created_at) <= :cutoff
               AND COALESCE(escalated_to_role, '') != 'sirket_sahibi'"
        );
        $stmt->execute([':cutoff' => $cutoff]);

        $escalated = [];
        $nextRole = ['supervisor' => 'manager', 'accounting' => 'manager', 'manager' => 'yonetici', 'yonetici' => 'sirket_sahibi'];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $currentRole = $row['escalated_to_role'] ?? $row['target_role'];
            $to = $nextRole[$currentRole] ?? 'sirket_sahibi';
            $upd = $this->pdo->prepare(
                'UPDATE notifications SET escalated_at = :now, escalated_to_role = :to WHERE id = :id'
            );
            $upd->execute([':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), ':to' => $to, ':id' => $row['id']]);
            $escalated[] = (int) $row['id'];
        }
        return $escalated;
    }
}
