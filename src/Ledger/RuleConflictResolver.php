<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use PDO;

/**
 * RT8.4 DURUM 2 — Kural Motorunun Kendine Saldırması / Sonsuz Döngü.
 * Sistemde OTONOM, birbiriyle yarışan "kuralcı" motorlar YOKTUR — her aksiyon
 * (karantina, imha, silme) zaten insan onaylı, açık bir kod yolundan geçer.
 * Bu, saldırının varsaydığı senaryonun (3 arka plan motorunun aynı anda
 * çakışması) mimaride HİÇ VAR OLMADIĞI anlamına gelir — DÜRÜSTÇE belirtiyoruz.
 *
 * Ama GERÇEK bir çakışma noktası var: bir entity KARANTİNADA iken, o entity
 * ile ilişkili bayi/aktörün YASAL SAKLAMA (legal hold) altında olması. Bu
 * sınıf, bu spesifik çakışmayı ÖNCELİK SIRASIYLA çözer: YASAL SAKLAMA HER
 * ZAMAN karantina temizlemenin ÖNÜNE GEÇER — bir soruşturma sürerken kanıt
 * durumundaki bir kaydı "operasyonel akış" gerekçesiyle temizlemek asla
 * doğru değildir.
 */
final class RuleConflictResolver
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{allowed: bool, reason: string}
     */
    public function canClearQuarantine(int $entityId, ?int $associatedDealerId): array
    {
        if ($associatedDealerId !== null) {
            $openClaim = $this->pdo->prepare(
                "SELECT COUNT(*) FROM customer_claims WHERE claimed_by_actor_id = :id AND status != 'resolved'"
            );
            $openClaim->execute([':id' => $associatedDealerId]);
            if (((int) $openClaim->fetchColumn()) > 0) {
                return ['allowed' => false, 'reason' => 'YASAL_SAKLAMA_ONCELIKLI: bu entity ile ilişkili bayinin açık bir talebi var — karantina, operasyonel gerekçeyle temizlenemez, önce soruşturma kapanmalı.'];
            }
            $openRisk = $this->pdo->prepare('SELECT COUNT(*) FROM risk_events WHERE actor_id = :id AND resolved = 0');
            $openRisk->execute([':id' => $associatedDealerId]);
            if (((int) $openRisk->fetchColumn()) > 0) {
                return ['allowed' => false, 'reason' => 'YASAL_SAKLAMA_ONCELIKLI: ilişkili aktörün çözülmemiş bir risk kaydı var — aynı öncelik kuralı geçerli.'];
            }
        }
        return ['allowed' => true, 'reason' => 'ENGEL_YOK: yasal saklama koşulu bulunamadı, operasyonel temizlik ilerleyebilir.'];
    }
}
