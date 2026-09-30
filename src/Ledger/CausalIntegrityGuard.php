<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use PDO;

/**
 * RT8.4 DURUM 1 — NTP Desenkronizasyonu / Nedensellik Paradoksu. Sistem
 * event_time alanına GÜVENİR ama bunun MANTIKEN mümkün olup olmadığını hiç
 * sormaz. Bir "iade" event'i, aynı ürünün "depoya giriş" event'inden ÖNCE
 * bir zaman damgasıyla gelirse (ürün henüz var olmadan iade edilmiş gibi),
 * bu bir nedensellik paradoksudur — hash-chain bunu YAKALAMAZ (her satır
 * kendi içinde hâlâ geçerli bir hash'e sahiptir, chain sırası da bozulmaz,
 * çünkü chain sırası event_time'a değil INSERT sırasına dayanır).
 *
 * DÜRÜST SINIR: Bu KESİN bir kanıt değildir — bazı meşru senaryolarda
 * (örn. geç senkronize olan bir mobil cihazın saatinin birkaç dakika kayması)
 * küçük bir paradoks olabilir. Bu yüzden askıya alma (suspend) önerilir,
 * otomatik red değil — insan karar verir.
 */
final class CausalIntegrityGuard
{
    private const STAGE_ORDER = ['commissioning' => 0, 'packing' => 1, 'shipping' => 2, 'receiving' => 3, 'destroyed' => 4];

    /**
     * RT8.5 DARBE 1 — WORKFLOW STATE CAUSALITY PARADOX'a karşı eklendi.
     * detectParadox() SADECE var olan iki kaydın zaman sırasını kıyaslar —
     * ama bir kayıt hiç YOKSA (örn. 'RETURNED_TO_DEALER' basılmış ama
     * 'IN_TRANSIT'/'receiving-sold' hiç yoksa), kıyaslanacak bir şey
     * olmadığı için sessizce geçer! Bu, ReturnService::processReturn()
     * akışında zaten lastDeliveryEvent() kontrolüyle kapatılmıştı — ama
     * biri EventStore::appendEvent()'i DOĞRUDAN çağırırsa (ReturnService'i
     * atlayarak — örn. bozulmuş bir entegrasyon/backfill yolu), bu koruma
     * devre dışı kalır. Bu metod, iş akışının GEREKLİ ÖN AŞAMASININ
     * VAR OLUP OLMADIĞINI (sadece sırasını değil) doğrudan kontrol eder.
     */
    private const REQUIRED_PREDECESSOR = [
        'receiving' => ['commissioning'], // teslim/iade kaydı için en az bir depoya-giriş kaydı şart
        'destroyed' => ['commissioning'],
    ];

    public function detectMissingPrerequisite(int $entityId, string $newBizStep): ?string
    {
        $required = self::REQUIRED_PREDECESSOR[$newBizStep] ?? null;
        if ($required === null) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT DISTINCT biz_step FROM epcis_events WHERE entity_id = :id');
        $stmt->execute([':id' => $entityId]);
        $existingSteps = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $missing = array_diff($required, $existingSteps);
        if ($missing !== []) {
            return sprintf(
                "STATE_TRANSITION_LINEAGE İHLALİ: '%s' event'i eklenmeye çalışılıyor, ama bu entity'nin geçmişinde "
                    . "gerekli ön aşama(lar) HİÇ YOK: %s. Zaman damgaları geçerli/sayısal olarak tutarlı olsa bile, "
                    . 'durum makinesi (state machine) nedenselliği bozuktur.',
                $newBizStep, implode(', ', $missing)
            );
        }
        return null;
    }

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Bir entity'ye YENİ bir event eklenmeden ÖNCE çağrılır. Yeni event'in
     * biz_step'i, mevcut geçmişteki DAHA SONRAKİ bir aşamadan (yüksek rank)
     * daha erken bir zaman damgası taşıyorsa paradoks bulunur.
     */
    public function detectParadox(int $entityId, string $newBizStep, string $newEventTime): ?string
    {
        $newRank = self::STAGE_ORDER[$newBizStep] ?? null;
        if ($newRank === null) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT biz_step, event_time FROM epcis_events WHERE entity_id = :id');
        $stmt->execute([':id' => $entityId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existingRank = self::STAGE_ORDER[$row['biz_step']] ?? null;
            if ($existingRank === null) {
                continue;
            }
            if ($newRank > $existingRank && $newEventTime < $row['event_time']) {
                return sprintf(
                    "PARADOKS: '%s' (aşama %d) zaman damgası (%s), '%s' (aşama %d) zaman damgasından (%s) ÖNCE geliyor — "
                        . 'bu, mantıken imkansız bir sıra (ürün henüz var olmadan sonraki bir aşamaya geçmiş gibi).',
                    $newBizStep, $newRank, $newEventTime, $row['biz_step'], $existingRank, $row['event_time']
                );
            }
        }
        return null;
    }
}
