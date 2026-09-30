<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use Traceability\Gs1\CheckDigit;
use Traceability\Gs1\DigitalLink;
use Traceability\Risk\ReturnVerifier;
use Traceability\Risk\ReturnEligibilityChecker;
use Traceability\Risk\RiskScorer;
use Traceability\Risk\AuthorizationGuard;
use Traceability\Risk\RelationshipGuard;

/**
 * Bir iade geldiğinde depo görevlisinin izlediği TEK akış budur — sırayla:
 *
 *   1) Kodun formatı ve GS1 check digit'i geçerli mi?
 *   2) Bu kod veritabanında gerçekten var mı?
 *   3) Kod zaten İMHA EDİLMİŞ mi?
 *   4) Kod zaten daha önce İADE EDİLMİŞ mi? (çifte iade — override YOK)
 *   4.5) Hiç teslim edilmemiş mi? (override YOK)
 *   5) İade edilen FİZİKSEL ürünle sistemdeki kayıtlı ürün AYNI mı?
 *      (reddedilirse ürün KARANTİNAYA düşer — eski durumunda kalıp
 *      yanlışlıkla satılabilir rafa geri konulmasını önler)
 *   6) Bu ürün GERÇEKTEN bu bayiye/bu siparişe mi teslim edilmişti?
 *      (override edilebilir, ama iz kalıcı olarak yazılır)
 *   7) Teslimattan bu yana geçen süre ürünün iade penceresi içinde mi?
 *      (override edilebilir, ama iz kalıcı olarak yazılır)
 *   8) Ürünün tipine göre ağırlık/durum kontrolü tutarlı mı?
 *      (reddedilirse KARANTİNAYA düşer; bu da override edilebilir —
 *      tartı arızası gibi masum bir hata yüzünden müşteri mağdur olmasın)
 *   9) SKT'si geçmiş mi? (reddetmez ama asla yeniden satılabilir stoğa dönmez)
 *
 * $supervisorOverride, adım 6/7/8'i aşmak için süpervizörün elle onayladığı
 * anlamına gelir — sistem buna izin verir ama ASLA sessizce yapmaz: override
 * kullanıldığı her seferde bu, olay geçmişine ve risk skoruna kalıcı olarak işlenir.
 *
 * $photoReference: kutu hasarlı olduğu iddia edildiğinde veya sağlık/ayıplı-mal
 * şikayeti kategorisinde ZORUNLUDUR — "kutu sağlamdı" gibi bir beyan asla
 * kanıtsız kabul edilmez (personel-bayi işbirlikli yalan beyanına karşı).
 */
final class ReturnService
{
    public function __construct(
        private EntityRepository $entities,
        private EventStore $events,
        private ReturnVerifier $weightVerifier,
        private ReturnEligibilityChecker $eligibility,
        private RiskScorer $riskScorer,
        private ?CustomerCommunicationService $messenger = null,
        private ?AuthorizationGuard $authGuard = null,
        private ?RelationshipGuard $relationshipGuard = null,
        private ?DeviceFaultDetector $deviceFaultDetector = null,
        private ?CausalIntegrityGuard $causalGuard = null
    ) {
    }

    public function processReturn(
        string $entityCode,
        int $claimedProductId,
        float $measuredWeightG,
        string $claimedCondition,
        bool $boxDamaged,
        ?int $dealerActorId,
        ?int $staffActorId,
        ?int $locationId,
        ?string $claimedOrderRef = null,
        bool $supervisorOverride = false,
        ?int $supervisorActorId = null, // override kullanılıyorsa ZORUNLU — kim onayladığı hesap verebilirliği için kalıcı kaydedilir
        ?\DateTimeImmutable $now = null,
        string $returnReasonCategory = 'diger', // 'cayma_hakki' | 'ayipli_mal_saglik' | 'hasarli_teslim' | 'yanlis_urun' | 'diger'
        ?string $photoReference = null,
        ?int $deviceId = null // yoğun gün + arızalı cihaz ayrımı için — malformed_code'un cihaza mı, kişiye mi ait olduğunu ayırt eder
    ): array {
        if ($supervisorOverride && $supervisorActorId === null) {
            throw new \InvalidArgumentException(
                'Süpervizör override kullanılıyorsa onaylayan supervisorActorId ZORUNLUDUR — '
                . 'bu hem art niyetli onayları yakalamak hem de dürüst süpervizörü kayıtla korumak içindir.'
            );
        }
        if ($supervisorOverride && $this->authGuard !== null) {
            $this->authGuard->requireRole($supervisorActorId, ['supervizor', 'yonetici']);
        }
        if ($supervisorOverride && $this->relationshipGuard !== null) {
            $this->relationshipGuard->assertNoConflict($supervisorActorId, [$staffActorId, $dealerActorId]);
        }

        $requiresPhoto = $boxDamaged || in_array($returnReasonCategory, ['ayipli_mal_saglik', 'hasarli_teslim'], true);
        if ($requiresPhoto && $photoReference === null) {
            throw new \InvalidArgumentException(
                'Kutu hasarlı beyanı veya sağlık/ayıplı-mal şikayeti fotoğraf kanıtı OLMADAN kabul edilemez — '
                . 'bu, personel-bayi işbirlikli yanlış beyanına karşı zorunlu bir koruma.'
            );
        }

        $now ??= new \DateTimeImmutable();
        // Sağlık/ayıplı-mal ve hasarlı-teslim şikayetlerinde paketin açık/
        // kullanılmış olması BEKLENEN bir durumdur — TKHK md.48 kapsamındaki
        // "cayma hakkı" (sebepsiz iade) kuralı bunlara uygulanamaz.
        $bypassSealedRequirement = in_array($returnReasonCategory, ['ayipli_mal_saglik', 'hasarli_teslim'], true);

        // 0) MÜFETTİŞ BULGUSU: kilitli bir cihaz artık HİÇ kullanılamaz —
        // sadece bozuk kod ürettiğinde değil, HER işlemde en baştan reddedilir.
        if ($deviceId !== null && $this->deviceFaultDetector !== null && $this->deviceFaultDetector->isLocked($deviceId)) {
            return ['accepted' => false, 'reason' => 'device_locked', 'device_locked' => true];
        }

        // 1) Format + check digit
        $gtin = DigitalLink::extractGtin($entityCode);
        if ($gtin === null || !CheckDigit::validate($gtin)) {
            if ($deviceId !== null && $this->deviceFaultDetector !== null) {
                $this->deviceFaultDetector->recordScanAnomaly($deviceId, 'malformed_code', $dealerActorId ?? $staffActorId);
                if ($this->deviceFaultDetector->isDeviceFaultLikely($deviceId)) {
                    // MÜFETTİŞ BULGUSU: artık sadece bildirim değil, cihaz GERÇEKTEN kilitleniyor —
                    // teknik servis onayı olmadan bu cihazdan başka tarama işlenemez.
                    if (!$this->deviceFaultDetector->isLocked($deviceId)) {
                        $this->deviceFaultDetector->lockDevice($deviceId, 'Kısa sürede art arda bozuk okuma — otomatik kilitlendi');
                    }
                    return ['accepted' => false, 'reason' => 'malformed_code', 'device_fault_suspected' => true, 'device_locked' => true];
                }
            }
            $this->flag($dealerActorId, 'malformed_code', ['entity_code' => $entityCode]);
            return ['accepted' => false, 'reason' => 'malformed_code'];
        }

        // 2) Kod gerçekten var mı?
        $entity = $this->entities->findByCode($entityCode);
        if ($entity === null) {
            $this->flag($dealerActorId, 'unknown_return_code', ['entity_code' => $entityCode]);
            return ['accepted' => false, 'reason' => 'unknown_code'];
        }
        $entityId = (int) $entity['id'];

        // 2.5) DENETİM BULGUSU (RT8.5 Darbe 1): CausalIntegrityGuard daha önce
        // sadece demo.php'de ayrı test ediliyordu — burada, gerçek akışta,
        // hiç 'commissioning' kaydı olmayan bir entity'nin iade/imha
        // edilmeye çalışılması (state-transition lineage ihlali) kontrol
        // ediliyor. Bu, lastDeliveryEvent() kontrolünden FARKLIDIR — o "bu
        // ürün bu bayiye teslim edildi mi" sorusuna, bu ise "bu ürün hiç
        // depoya girdi mi" sorusuna bakar.
        if ($this->causalGuard !== null) {
            $missingPrereq = $this->causalGuard->detectMissingPrerequisite($entityId, 'receiving');
            if ($missingPrereq !== null) {
                $this->flag($dealerActorId, 'causal_integrity_violation', ['entity_id' => $entityId, 'detail' => $missingPrereq]);
                return ['accepted' => false, 'reason' => 'causal_integrity_violation'];
            }
        }

        // 3) İmha edilmiş kod bir daha asla aktive edilemez
        if ($entity['status'] === 'DESTROYED') {
            $this->flag($dealerActorId, 'return_of_destroyed_code', ['entity_id' => $entityId]);
            return ['accepted' => false, 'reason' => 'code_already_destroyed'];
        }

        // 4) Çifte iade — override YOK, bu her zaman durur
        if ($entity['status'] === 'RETURNED') {
            $this->flag($dealerActorId, 'duplicate_return_attempt', ['entity_id' => $entityId]);
            return ['accepted' => false, 'reason' => 'already_returned'];
        }

        // 4.4) KAOS GÜNÜ TESTİNDE BULUNAN GERÇEK BOŞLUK: karantinadaki bir
        // ürün (spot-check'te taş çıkmış, henüz süpervizör temizlememiş)
        // ikinci kez "iade" olarak işlenebiliyordu — override YOK, bu da
        // RETURNED/DESTROYED gibi kalıcı olarak durur, sadece
        // EntityRepository::clearQuarantine() ile temizlenebilir.
        if ($entity['status'] === 'QUARANTINED') {
            $this->flag($dealerActorId, 'return_of_quarantined_item', ['entity_id' => $entityId]);
            return ['accepted' => false, 'reason' => 'entity_is_quarantined'];
        }

        // 4.5) GÜVENLİK AÇIĞI KAPATILDI: hiç teslim edilmemiş bir ürün için
        // "iade" kabul edilemez — aksi halde kargoya bile çıkmamış bir ürün
        // için sahte iade/tazminat talep edilebilirdi. Override YOKTUR —
        // bu, fiziksel olarak imkansız bir iddia, süpervizör onayı bile
        // bunu meşru kılmaz.
        $deliveryRecord = $this->eligibility->lastDeliveryEvent($entityId);
        if ($deliveryRecord === null) {
            $this->flag($dealerActorId, 'return_of_undelivered_item', ['entity_id' => $entityId]);
            return ['accepted' => false, 'reason' => 'return_of_undelivered_item'];
        }

        // 5) Fiziksel ürün ile kayıtlı ürün aynı mı?
        if ((int) $entity['product_id'] !== $claimedProductId) {
            $this->flag($dealerActorId, 'wrong_product_return', ['entity_id' => $entityId, 'claimed_product_id' => $claimedProductId]);
            $this->events->appendEvent($entityId, 'receiving', 'return_rejected', $staffActorId, null, $locationId, $claimedOrderRef, [
                'flag_reason' => 'wrong_product_return',
                'claimed_product_id' => $claimedProductId,
                'photo_reference' => $photoReference,
            ]);
            $this->quarantine($entityId);
            $this->notify($dealerActorId, 'return_rejected', [$claimedOrderRef ?? (string) $entityId]);
            return ['accepted' => false, 'reason' => 'wrong_product_return'];
        }

        // 6) Bu ürün gerçekten bu bayiye/bu siparişe mi teslim edilmişti?
        $dealerCheck = $this->eligibility->checkDealerMatch($entityId, $dealerActorId, $claimedOrderRef);
        if (!$dealerCheck['ok'] && !$supervisorOverride) {
            $this->flag($dealerActorId, 'wrong_dealer_or_order_return', $dealerCheck['detail'] ?? []);
            return ['accepted' => false, 'reason' => 'wrong_dealer_or_order_return', 'requires_supervisor_override' => true];
        }
        $dealerOverrideUsed = !$dealerCheck['ok'] && $supervisorOverride;

        // 7) İade zaman penceresi
        $windowCheck = $this->eligibility->checkWindow($claimedProductId, $entityId, $now);
        if (!$windowCheck['ok'] && !$supervisorOverride) {
            $this->flag($dealerActorId, 'return_window_exceeded', $windowCheck['detail'] ?? []);
            return ['accepted' => false, 'reason' => 'return_window_exceeded', 'requires_supervisor_override' => true];
        }
        $windowOverrideUsed = !$windowCheck['ok'] && $supervisorOverride;

        // 8) Ürün tipine göre ağırlık/durum kontrolü — TARTI ARIZASI gibi
        // masum bir hata yüzünden müşteri mağdur olmasın diye bu da
        // süpervizör onayıyla aşılabilir (diğer override'lar gibi kalıcı iz bırakır).
        $weightResult = $this->weightVerifier->verify($claimedProductId, $measuredWeightG, $claimedCondition, $boxDamaged, $bypassSealedRequirement);
        $weightOverrideUsed = false;
        if (!$weightResult['ok']) {
            if (!$supervisorOverride) {
                $this->flag($dealerActorId, 'return_weight_anomaly', [
                    'entity_id' => $entityId,
                    'reason' => $weightResult['reason'],
                    'detail' => $weightResult['detail'] ?? null,
                ]);
                $this->events->appendEvent($entityId, 'receiving', 'return_flagged', $staffActorId, null, $locationId, $claimedOrderRef, [
                    'claimed_condition' => $claimedCondition,
                    'measured_weight_g' => $measuredWeightG,
                    'box_damaged' => $boxDamaged,
                    'photo_reference' => $photoReference,
                    'flag_reason' => $weightResult['reason'],
                ]);
                $this->quarantine($entityId);
                return ['accepted' => false, 'reason' => $weightResult['reason'], 'requires_supervisor_override' => true];
            }
            // Override kullanıldı — kayıt kalıcı olarak işleniyor, sessizce geçmiyor.
            $weightOverrideUsed = true;
            $this->flag($dealerActorId, 'return_weight_anomaly_override', [
                'entity_id' => $entityId,
                'reason' => $weightResult['reason'],
                'approved_by' => $supervisorActorId,
            ]);
        }

        if ($dealerOverrideUsed) {
            $this->flag($dealerActorId, 'return_window_exceeded_override', $dealerCheck['detail'] ?? []);
        }
        if ($windowOverrideUsed) {
            $this->flag($dealerActorId, 'return_window_exceeded_override', $windowCheck['detail'] ?? []);
        }

        // 9) SKT kontrolü — reddetmez, ama sonucu değiştirir: asla yeniden
        // satılabilir stoğa dönmez, doğrudan imha bekleyen duruma alınır.
        $expired = $entity['expiry_date'] !== null && $entity['expiry_date'] < $now->format('Y-m-d');

        $returnEvent = $this->events->appendEvent($entityId, 'receiving', $expired ? 'return_expired' : 'returned', $staffActorId, null, $locationId, $claimedOrderRef, [
            'claimed_condition' => $claimedCondition,
            'measured_weight_g' => $measuredWeightG,
            'box_damaged' => $boxDamaged,
            'photo_reference' => $photoReference,
            'return_reason_category' => $returnReasonCategory,
            'dealer_actor_id' => $dealerActorId,
            'dealer_override_used' => $dealerOverrideUsed,
            'window_override_used' => $windowOverrideUsed,
            'weight_override_used' => $weightOverrideUsed,
            'approved_by_supervisor_id' => ($dealerOverrideUsed || $windowOverrideUsed || $weightOverrideUsed) ? $supervisorActorId : null,
            'expired' => $expired,
        ]);
        try {
            $this->entities->transitionStatus($entityId, $expired ? 'DESTROYED' : 'RETURNED');
        } catch (ConcurrentModificationException $e) {
            // Event zaten hash-chain'e yazıldı (bu asla silinmez/geri alınmaz),
            // ama durum geçişi çakıştı — aynı ürün aynı anda başka bir
            // işlemden de işlenmiş demektir. Yüksek öncelikli inceleme sinyali.
            $this->flag($dealerActorId, 'concurrent_return_conflict', ['entity_id' => $entityId, 'detail' => $e->getMessage()]);
            return ['accepted' => false, 'reason' => 'concurrent_modification', 'return_event_id' => $returnEvent['id']];
        }
        $this->notify($dealerActorId, 'return_accepted', [$claimedOrderRef ?? (string) $entityId]);

        return [
            'accepted' => true,
            'expired' => $expired,
            'dealer_override_used' => $dealerOverrideUsed,
            'window_override_used' => $windowOverrideUsed,
            'weight_override_used' => $weightOverrideUsed,
            'return_event_id' => $returnEvent['id'],
            'entity_id' => $entityId,
        ];
    }

    private function quarantine(int $entityId): void
    {
        try {
            $this->entities->transitionStatus($entityId, 'QUARANTINED');
        } catch (ConcurrentModificationException) {
            // Zaten başka bir işlem tarafından değiştirilmiş — bu durumda
            // karantina işaretlemesi ikincil önemde, sessizce geç.
        }
    }

    private function flag(?int $actorId, string $eventType, array $detail): void
    {
        if ($actorId !== null) {
            $this->riskScorer->recordSignal($actorId, $eventType, $detail);
        }
    }

    private function notify(?int $actorId, string $messageType, array $params): void
    {
        if ($actorId !== null && $this->messenger !== null) {
            $this->messenger->notify($actorId, $messageType, $params, 'backoffice_inbox');
        }
    }
}
