<?php

declare(strict_types=1);

namespace Traceability\Tests\Unit;

use Traceability\Tests\TestCase;
use Traceability\Logging\Logger;
use Traceability\Logging\ExceptionHandler;
use Traceability\Logging\NullErrorReporter;

final class LoggerTest extends TestCase
{
    private string $logFile;
    private string $queueFile;

    public function setUp(): void
    {
        parent::setUp();
        $this->logFile = sys_get_temp_dir() . '/logger_test_' . bin2hex(random_bytes(6)) . '.log';
        $this->queueFile = sys_get_temp_dir() . '/reporter_queue_' . bin2hex(random_bytes(6)) . '.log';
    }

    public function tearDown(): void
    {
        parent::tearDown();
        @unlink($this->logFile);
        @unlink($this->queueFile);
    }

    private function readLastLogEntry(): array
    {
        $lines = array_filter(explode("\n", file_get_contents($this->logFile)));
        $last = end($lines);
        return json_decode($last, true);
    }

    public function testInfoLevelWritesValidJsonLine(): void
    {
        $logger = new Logger($this->logFile);
        $logger->info('Test mesajı', ['foo' => 'bar']);

        $entry = $this->readLastLogEntry();
        $this->assertSame('INFO', $entry['level']);
        $this->assertSame('Test mesajı', $entry['message']);
        $this->assertSame('bar', $entry['context']['foo']);
        $this->assertNotNull($entry['timestamp']);
    }

    public function testCriticalLevelTriggersExternalReporter(): void
    {
        $reporter = new NullErrorReporter($this->queueFile);
        $logger = new Logger($this->logFile, 'test', $reporter);
        $logger->critical('Kritik bir olay', ['entity_id' => 42]);

        $this->assertTrue(is_file($this->queueFile), 'CRITICAL seviye, harici raportöre YAZILMADI.');
        $queued = json_decode(trim(file_get_contents($this->queueFile)), true);
        $this->assertSame('critical', $queued['level']);
        $this->assertSame(42, $queued['context']['entity_id']);
    }

    public function testInfoLevelDoesNotTriggerExternalReporter(): void
    {
        $reporter = new NullErrorReporter($this->queueFile);
        $logger = new Logger($this->logFile, 'test', $reporter);
        $logger->info('Sıradan bir bilgi mesajı');

        $this->assertFalse(is_file($this->queueFile), 'INFO seviyesi harici raportöre YAZILMAMALIYDI (gürültü olur).');
    }

    public function testExceptionHandlerClassifiesConcurrencyConflictAsCritical(): void
    {
        $logger = new Logger($this->logFile);
        $handler = new ExceptionHandler($logger);
        $handler->handleUncaughtException(new \Traceability\Ledger\ConcurrencyConflictException(7, 5, 'shipping'));

        $entry = $this->readLastLogEntry();
        $this->assertSame('CRITICAL', $entry['level']);
        $this->assertTrue(str_contains($entry['message'], 'LEDGER_CONCURRENCY_EXHAUSTED'));
    }

    public function testExceptionHandlerClassifiesUnauthorizedAsWarning(): void
    {
        $logger = new Logger($this->logFile);
        $handler = new ExceptionHandler($logger);
        $handler->handleUncaughtException(new \Traceability\Risk\UnauthorizedActionException('test'));

        $entry = $this->readLastLogEntry();
        $this->assertSame('WARNING', $entry['level']);
    }

    public function testLedgerInconsistencyIsAlwaysLoggedAsCritical(): void
    {
        $reporter = new NullErrorReporter($this->queueFile);
        $logger = new Logger($this->logFile, 'test', $reporter);
        $handler = new ExceptionHandler($logger);
        $handler->logLedgerInconsistency(99, 'verifyChain() false döndü');

        $entry = $this->readLastLogEntry();
        $this->assertSame('CRITICAL', $entry['level']);
        $this->assertSame(99, $entry['context']['entity_id']);
        $this->assertTrue(is_file($this->queueFile), 'Ledger tutarsızlığı harici raporlamaya İLETİLMELİYDİ.');
    }
}
