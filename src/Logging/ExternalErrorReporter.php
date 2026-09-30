<?php

declare(strict_types=1);

namespace Traceability\Logging;

/**
 * Sentry (veya benzeri) harici hata izleme servisleri de Composer
 * gerektirir (`sentry/sentry`) — bu sandbox'ta kurulamaz. Bu arayüz,
 * gerçek Sentry SDK'sının (`\Sentry\captureMessage()`) YERİNE
 * geçebilecek bir soyutlama sağlar: Logger, CRITICAL ve üzeri
 * seviyelerde bunu çağırır.
 *
 * GERÇEK SENTRY ENTEGRASYONU İÇİN (ağ erişimi olan bir ortamda):
 *   composer require sentry/sentry
 *   \Sentry\init(['dsn' => getenv('SENTRY_DSN')]);
 * ve bu arayüzü uygulayan bir `SentryErrorReporter` sınıfı yazıp
 * (NullErrorReporter'ın YERİNE) Logger'a enjekte edin — Logger'ın
 * kendisi HİÇ değişmez.
 */
interface ExternalErrorReporter
{
    public function report(string $level, string $message, array $context = []): void;
}
