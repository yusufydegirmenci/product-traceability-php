<?php

declare(strict_types=1);

namespace Traceability\Logging;

/**
 * NEDEN BU DOSYA VAR: gerçek Monolog (veya PSR-3 uyumlu başka bir logger)
 * Composer gerektirir — packagist.org bu sandbox'ta erişilemez
 * (`host_not_allowed`, bkz. SECURITY_REVIEW.md Bölüm 6). Bu sınıf,
 * PSR-3 LoggerInterface'in method imzalarını (emergency/alert/critical/
 * error/warning/notice/info/debug + log()) BİREBİR aynı şekilde
 * uygular — gerçek ağ erişimi olan bir ortamda:
 *   composer require monolog/monolog
 * kurulup, bu sınıfın YERİNE `new Monolog\Logger(...)` geçirilebilir;
 * ÇAĞIRAN KOD (ExceptionHandler, servisler) HİÇ DEĞİŞMEZ çünkü ikisi de
 * aynı 8 metodu sunar.
 *
 * Loglar hem bir dosyaya (JSON-lines formatında, makine tarafından
 * kolayca ayrıştırılabilir) hem de (CRITICAL ve üzeri için) stderr'e yazılır.
 */
final class Logger
{
    public const EMERGENCY = 'emergency';
    public const ALERT = 'alert';
    public const CRITICAL = 'critical';
    public const ERROR = 'error';
    public const WARNING = 'warning';
    public const NOTICE = 'notice';
    public const INFO = 'info';
    public const DEBUG = 'debug';

    private const LEVEL_SEVERITY = [
        self::DEBUG => 0, self::INFO => 1, self::NOTICE => 2, self::WARNING => 3,
        self::ERROR => 4, self::CRITICAL => 5, self::ALERT => 6, self::EMERGENCY => 7,
    ];

    public function __construct(
        private string $logFilePath,
        private string $channel = 'traceability',
        private ?ExternalErrorReporter $externalReporter = null
    ) {
        $dir = dirname($this->logFilePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    public function emergency(string $message, array $context = []): void { $this->log(self::EMERGENCY, $message, $context); }
    public function alert(string $message, array $context = []): void { $this->log(self::ALERT, $message, $context); }
    public function critical(string $message, array $context = []): void { $this->log(self::CRITICAL, $message, $context); }
    public function error(string $message, array $context = []): void { $this->log(self::ERROR, $message, $context); }
    public function warning(string $message, array $context = []): void { $this->log(self::WARNING, $message, $context); }
    public function notice(string $message, array $context = []): void { $this->log(self::NOTICE, $message, $context); }
    public function info(string $message, array $context = []): void { $this->log(self::INFO, $message, $context); }
    public function debug(string $message, array $context = []): void { $this->log(self::DEBUG, $message, $context); }

    public function log(string $level, string $message, array $context = []): void
    {
        $entry = [
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'channel' => $this->channel,
            'level' => strtoupper($level),
            'message' => $message,
            'context' => $context,
        ];
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents($this->logFilePath, $line, FILE_APPEND | LOCK_EX);

        $severity = self::LEVEL_SEVERITY[$level] ?? 0;
        if ($severity >= self::LEVEL_SEVERITY[self::CRITICAL]) {
            fwrite(STDERR, "[{$entry['timestamp']}] {$entry['level']}: {$message}\n");
            $this->externalReporter?->report($level, $message, $context);
        }
    }
}
