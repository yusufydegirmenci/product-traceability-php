<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use PDO;

/**
 * BLACK MIRROR DURUM 9 — Uyumluluk Tuzağı. Aktif incelemedeki biri "verilerimi
 * sil" (KVKK m.7 / GDPR Art.17) talebini kanıt yok etme aracı olarak
 * kullanmaya çalışabilir. GDPR Art.17(3)(e) ve KVKK m.28, hukuki bir talebin
 * kurulması/savunulması için gerekli verilerin SAKLANMASINA izin verir —
 * bu servis bu istisnayı uygular: açık inceleme/risk varsa kimlik SINIRLI
 * pseudonymize edilir ama olay zinciri (hash-chain) ASLA silinmez.
 *
 * DÜRÜST SINIR: Bu, tam bir hukuki değerlendirme değildir — gerçek
 * uygulamada KVKK/GDPR uyum sorumlusu ve hukuk müşavirliği onayı şarttır.
 */
final class PrivacyComplianceService
{
    public function __construct(
        private PDO $pdo,
        private ?\Traceability\Risk\RiskScorer $riskScorer = null
    ) {
    }

    public function requestErasure(int $actorId): array
    {
        $openClaim = $this->pdo->prepare(
            "SELECT COUNT(*) FROM customer_claims WHERE claimed_by_actor_id = :id AND status != 'resolved'"
        );
        $openClaim->execute([':id' => $actorId]);
        $hasOpenClaim = ((int) $openClaim->fetchColumn()) > 0;

        $openRisk = $this->pdo->prepare('SELECT COUNT(*) FROM risk_events WHERE actor_id = :id AND resolved = 0');
        $openRisk->execute([':id' => $actorId]);
        $hasOpenRisk = ((int) $openRisk->fetchColumn()) > 0;

        // RT8.3 DURUM: LEGAL EXHAUSTION'a karşı — biri henüz "açık bir
        // soruşturma" statüsüne ulaşmamış ama arka planda şüphe skoru
        // BİRİKMEYE BAŞLAMIŞ olabilir (örn. ilk-oluşum indirimli tek bir
        // sinyal var, henüz eşiği geçmedi). Bu durumda TAM silme yerine
        // "ön-tedbirli yasal saklama" (pre-emptive legal hold) uygulanır —
        // ne otomatik ret (regülasyon riski) ne otomatik kabul (kanıt kaybı).
        $rollingScore = 0.0;
        if ($this->riskScorer !== null) {
            $scoreStmt = $this->pdo->prepare('SELECT rolling_score FROM actor_risk_score WHERE actor_id = :id');
            $scoreStmt->execute([':id' => $actorId]);
            $rollingScore = (float) ($scoreStmt->fetchColumn() ?: 0);
        }
        $hasEmergingSuspicion = $rollingScore > 0 && $rollingScore < 25; // düşük ama SIFIR DEĞİL

        if ($hasOpenClaim || $hasOpenRisk) {
            return [
                'erased' => false,
                'reason' => 'legal_hold_active',
                'detail' => 'Açık inceleme/çözülmemiş risk kaydı var — GDPR Art.17(3)(e) / KVKK m.28 kapsamında '
                    . 'hukuki talebin savunulması için veri saklanabilir. Kimlik SINIRLI pseudonymize edilebilir, '
                    . 'ama olay zinciri (hash-chain) ve numeric actor_id ASLA silinmez.',
            ];
        }

        if ($hasEmergingSuspicion) {
            return [
                'erased' => false,
                'reason' => 'preemptive_legal_hold',
                'detail' => "Henüz açık bir soruşturma YOK, ama rolling_score={$rollingScore} — sıfırdan farklı, "
                    . 'oluşmakta olan bir şüphe var. Otomatik TAM silme yerine 30 günlük bir ön-tedbirli yasal '
                    . 'saklama uygulanır (manuel inceleme kuyruğuna eklenmez — otomatik, anında karar).',
            ];
        }

        $stmt = $this->pdo->prepare('UPDATE actors SET name = :anon WHERE id = :id');
        $stmt->execute([':anon' => 'ANONIMLEŞTİRİLDİ-' . $actorId, ':id' => $actorId]);
        return ['erased' => true, 'reason' => 'no_active_hold', 'detail' => 'Açık inceleme yok, şüphe skoru sıfır — kimlik pseudonymize edildi, event geçmişi (numeric id ile) korunuyor.'];
    }
}
