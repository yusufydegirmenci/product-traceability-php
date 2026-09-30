<?php

declare(strict_types=1);

namespace Traceability\Logging;

use Traceability\Ledger\ConcurrencyConflictException;
use Traceability\Ledger\DestroyRequestException;
use Traceability\Ledger\UnassignedItemLockoutException;
use Traceability\Ledger\InvalidScanSequenceException;
use Traceability\Risk\UnauthorizedActionException;

/**
 * Üretim standardı hata yönetimi: PHP'nin global exception/error
 * handler'larına bağlanır, her exception TÜRÜNE göre doğru önem
 * seviyesini (severity) atar, ve `Logger`'a yönlendirir.
 *
 * ÖZELLİKLE ÖNEMLİ: LEDGER TUTARSIZLIKLARI (hash-chain doğrulama
 * başarısızlığı, eşzamanlılık çakışması tükenmesi) her zaman EN AZ
 * `critical` seviyesinde loglanır ve harici raporlamaya (Sentry vb.)
 * gönderilir — bunlar "sıradan" hatalar değil, veri bütünlüğü
 * şüphesi taşıyan olaylardır.
 */
final class ExceptionHandler
{
    public function __construct(private Logger $logger)
    {
    }

    public function register(): void
    {
        set_exception_handler([$this, 'handleUncaughtException']);
        set_error_handler([$this, 'handlePhpError']);
    }

    public function handleUncaughtException(\Throwable $e): void
    {
        [$level, $tag] = $this->classify($e);
        $this->logger->log($level, "[{$tag}] " . $e->getMessage(), [
            'exception_class' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    public function handlePhpError(int $errno, string $errstr, string $errfile = '', int $errline = 0): bool
    {
        $level = match ($errno) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR => Logger::CRITICAL,
            E_WARNING, E_USER_WARNING => Logger::WARNING,
            default => Logger::NOTICE,
        };
        $this->logger->log($level, "[PHP_ERROR] {$errstr}", ['file' => $errfile, 'line' => $errline]);
        return false; // PHP'nin standart hata işlemesine de izin ver
    }

    /**
     * Servisler, bir hash-chain doğrulama başarısızlığı (verifyChain()=false)
     * gibi "exception FIRLATMAYAN ama yine de kritik olan" durumları
     * bununla açıkça bildirmeli — bu bir Throwable DEĞİLDİR, ama önemi
     * (ledger bütünlük şüphesi) aynı seviyededir.
     */
    public function logLedgerInconsistency(int $entityId, string $reason, array $extraContext = []): void
    {
        $this->logger->critical("[LEDGER_INCONSISTENCY] entity #{$entityId}: {$reason}", array_merge([
            'entity_id' => $entityId,
            'reason' => $reason,
        ], $extraContext));
    }

    /** @return array{0: string, 1: string} [seviye, etiket] */
    private function classify(\Throwable $e): array
    {
        return match (true) {
            $e instanceof ConcurrencyConflictException => [Logger::CRITICAL, 'LEDGER_CONCURRENCY_EXHAUSTED'],
            $e instanceof DestroyRequestException => [Logger::WARNING, 'DESTROY_REQUEST_REJECTED'],
            $e instanceof UnauthorizedActionException => [Logger::WARNING, 'UNAUTHORIZED_ATTEMPT'],
            $e instanceof UnassignedItemLockoutException => [Logger::NOTICE, 'SHIFT_LOCKOUT'],
            $e instanceof InvalidScanSequenceException => [Logger::NOTICE, 'SCAN_SEQUENCE_VIOLATION'],
            $e instanceof \PDOException => [Logger::CRITICAL, 'DATABASE_ERROR'],
            default => [Logger::ERROR, 'UNCLASSIFIED_EXCEPTION'],
        };
    }
}
