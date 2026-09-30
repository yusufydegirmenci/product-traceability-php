<?php

declare(strict_types=1);

namespace Traceability\Risk;

use PDO;

/**
 * Bir iade geldiğinde ürünün TEK BAŞINA tartılmasını doğrular — paketleme
 * anındaki kutu tartısından (WeightReconciler) FARKLI bir kontrol: burada
 * "bu 400ml şampuan gerçekten dolu mu, yoksa boşaltılıp mı gönderildi"
 * sorusuna bakılır.
 *
 * Her ürünün product_weight_profiles tablosunda bir profili vardır:
 *
 *   - 'exempt'          → ağırlık kontrolü anlamsız (örn. yakıt kartları).
 *                         Hiçbir şey ölçülmez, iade doğrudan geçer.
 *   - 'fixed_match'      → tüketilmeyen, sabit ağırlıklı ürün (shaker, cihaz,
 *                         ajanda). Ölçülen ağırlık HER ZAMAN aynı aralıkta
 *                         olmalı — herhangi bir sapma (parça eksik/değişmiş)
 *                         şüphelidir.
 *   - 'consumable_range' → sıvı/krem/toz/kapsül gibi kullanıldıkça hafifleyen
 *                         ürün. "Dolu" ile "bitmiş" arasındaki HER ağırlık
 *                         normaldir — ama iade "hiç açılmadı" diye geldiyse
 *                         ağırlık dolu ürüne yakın olmalı; bunun dışına
 *                         çıkması veya fiziksel olarak imkansız bir ağırlık
 *                         (boş kutudan bile hafif, ya da dolu kutudan bile
 *                         ağır) ölçülmesi sahtecilik/manipülasyon işaretidir.
 */
final class ReturnVerifier
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param string $claimedCondition 'sealed' (hiç açılmamış/kullanılmamış iddiası) | 'used' (kısmen kullanılmış/hasarlı iddiası)
     * @param bool $bypassSealedRequirement Sağlık/ayıplı-mal şikayetlerinde true geçilir — paketin açılmış/kullanılmış
     *        olması BEKLENEN VE MEŞRU bir durumdur (bkz. TKHK md.48, cayma hakkı ile ayıplı mal şikayeti ayrımı),
     *        bu yüzden "sealed" tutarlılık kontrolleri ve kutu hasarı çelişkisi bu durumda uygulanmaz.
     */
    public function verify(
        int $productId,
        float $measuredWeightG,
        string $claimedCondition,
        bool $boxDamaged,
        bool $bypassSealedRequirement = false
    ): array {
        $profile = $this->loadProfile($productId);

        if ($profile === null || $profile['check_type'] === 'exempt') {
            return ['ok' => true, 'reason' => 'weight_check_not_applicable'];
        }

        $tolerance = ((float) $profile['tolerance_pct']) / 100.0;

        if ($profile['check_type'] === 'fixed_match') {
            return $this->checkFixedMatch($profile, $measuredWeightG, $tolerance);
        }

        return $this->checkConsumableRange($profile, $measuredWeightG, $claimedCondition, $boxDamaged, $tolerance, $bypassSealedRequirement);
    }

    private function checkFixedMatch(array $profile, float $measured, float $tolerance): array
    {
        $expected = (float) $profile['full_weight_g'];
        if ($expected <= 0.0) {
            return ['ok' => true, 'reason' => 'no_reference_weight_configured'];
        }

        $diff = abs($measured - $expected) / $expected;
        if ($diff > $tolerance) {
            return [
                'ok' => false,
                'reason' => 'fixed_item_weight_mismatch',
                'detail' => ['expected_g' => $expected, 'measured_g' => $measured, 'diff_pct' => round($diff * 100, 1)],
            ];
        }
        return ['ok' => true];
    }

    private function checkConsumableRange(array $profile, float $measured, string $claimedCondition, bool $boxDamaged, float $tolerance, bool $bypassSealedRequirement): array
    {
        $full = (float) $profile['full_weight_g'];
        $empty = (float) $profile['empty_weight_g'];

        if ($full <= 0.0 || $empty < 0.0 || $empty >= $full) {
            return ['ok' => true, 'reason' => 'no_reference_weight_configured'];
        }

        $physicalLowerBound = $empty * (1 - $tolerance);
        $physicalUpperBound = $full * (1 + $tolerance);

        // Fiziksel olarak imkansız ağırlıklar — bunlar HER ZAMAN kontrol edilir,
        // sağlık/ayıplı-mal şikayeti olsa bile (boştan hafif ürün olamaz).
        if ($measured < $physicalLowerBound) {
            return ['ok' => false, 'reason' => 'below_empty_weight', 'detail' => ['lower_bound_g' => $physicalLowerBound, 'measured_g' => $measured]];
        }
        if ($measured > $physicalUpperBound) {
            return ['ok' => false, 'reason' => 'above_full_weight', 'detail' => ['upper_bound_g' => $physicalUpperBound, 'measured_g' => $measured]];
        }

        // "Sealed" tutarlılığı ve kutu-hasarı çelişkisi SADECE cayma hakkı
        // (sebepsiz iade) senaryosunda uygulanır — sağlık/ayıplı-mal
        // şikayetinde paketin açılmış/kullanılmış olması beklenen bir kanıttır.
        if (!$bypassSealedRequirement && $claimedCondition === 'sealed') {
            $sealedLowerBound = $full * (1 - $tolerance);
            if ($measured < $sealedLowerBound) {
                return [
                    'ok' => false,
                    'reason' => 'claim_weight_mismatch',
                    'detail' => ['claimed' => 'sealed', 'expected_min_g' => $sealedLowerBound, 'measured_g' => $measured],
                ];
            }
            if ($boxDamaged) {
                return ['ok' => false, 'reason' => 'condition_claim_mismatch', 'detail' => ['claimed' => 'sealed', 'box_damaged' => true]];
            }
        }

        return ['ok' => true];
    }

    private function loadProfile(int $productId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_weight_profiles WHERE product_id = :id');
        $stmt->execute([':id' => $productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }
}
