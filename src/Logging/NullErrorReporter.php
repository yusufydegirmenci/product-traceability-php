<?php

declare(strict_types=1);

namespace Traceability\Logging;

/**
 * Varsayılan, hiçbir dış bağımlılık gerektirmeyen uygulama — sadece
 * yerel bir "harici raporlama kuyruğu" dosyasına yazar. Böylece Sentry
 * DSN'i henüz ayarlanmamış bir ortamda bile CRITICAL olaylar KAYBOLMAZ,
 * sadece "gönderilmeyi bekleyen" bir dosyada birikir.
 */
final class NullErrorReporter implements ExternalErrorReporter
{
    public function __construct(private string $queueFilePath)
    {
        $dir = dirname($this->queueFilePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    public function report(string $level, string $message, array $context = []): void
    {
        $entry = [
            'queued_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'note' => 'SENTRY_DSN ayarlanmadığı için bu olay sadece yerel kuyrukta bekliyor — üretimde gerçek Sentry SDK\'sına bağlanmalı.',
        ];
        file_put_contents($this->queueFilePath, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }
}
