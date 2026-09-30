<?php

declare(strict_types=1);

namespace Traceability\Ledger;

/**
 * EventStore::appendEvent(), (entity_id, prev_hash) üzerindeki UNIQUE
 * kısıt ihlaliyle karşılaşıp maksimum deneme sayısını (varsayılan 5)
 * aşınca fırlatılır. Bu, İKİ ŞEYDEN biri anlamına gelir:
 *   1) Aynı entity'ye AŞIRI yoğun eşzamanlı yazma var (gerçek ama nadir
 *      bir operasyonel durum — örn. çok popüler bir lot'tan aynı anda
 *      onlarca kişi sipariş hazırlıyor).
 *   2) Veritabanı bağlantısı/kilit mekanizması beklenenden çok daha
 *      yavaş yanıt veriyor (altyapı sorunu).
 * Her iki durumda da bu, LOGLANMALI ve İZLENMELİDİR — sık tekrarlanıyorsa
 * $maxAttempts artırılmalı veya entity bazında bir kuyruklama/sıralama
 * mekanizması (örn. Redis tabanlı dağıtık kilit) değerlendirilmelidir.
 */
final class ConcurrencyConflictException extends \RuntimeException
{
    public function __construct(
        public readonly int $entityId,
        public readonly int $attemptsMade,
        public readonly string $bizStep
    ) {
        parent::__construct(
            "EventStore::appendEvent() entity #{$entityId} için {$attemptsMade} denemede de "
            . "(entity_id, prev_hash) çakışmasıyla karşılaştı ve pes etti (biz_step: {$bizStep}). "
            . 'Bu, aşırı yoğun eşzamanlı yazma veya bir altyapı sorununa işaret eder — loglanmalı ve izlenmelidir.'
        );
    }
}
