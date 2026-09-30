<?php

declare(strict_types=1);

namespace Traceability\Tests;

use PDO;

/**
 * NEDEN BU DOSYA VAR: gerçek PHPUnit'i bu ortama kuramadık — Composer
 * yok, packagist.org sandbox tarafından engelli (`host_not_allowed`).
 * Bunu gizlemek yerine, PHPUnit\Framework\TestCase'in en çok kullanılan
 * API yüzeyini (setUp/tearDown/assertX/expectException) BİREBİR aynı
 * imzalarla taklit eden bu minik sınıfı yazdık.
 *
 * SONUÇ: gerçek ağ erişimi olan bir ortamda şu iki adımla tüm test
 * paketi DEĞİŞİKLİK GEREKTİRMEDEN gerçek PHPUnit altında çalışır:
 *   1) composer require --dev phpunit/phpunit
 *   2) bu dosyadaki `use Traceability\Tests\TestCase;` satırını
 *      `use PHPUnit\Framework\TestCase;` ile değiştirin (tüm test
 *      dosyalarında tek satırlık bir bul-değiştir).
 *
 * TEST İZOLASYONU: her test metodundan ÖNCE setUp() çağrılır ve TAZE,
 * BELLEK-İÇİ (:memory:) bir SQLite veritabanı kurulur — demo.php'nin
 * aksine, bir testin verisi bir SONRAKİ testi ASLA etkilemez.
 */
abstract class TestCase
{
    protected PDO $pdo;

    public function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $schemaPath = dirname(__DIR__) . '/schema/schema.sqlite.sql';
        $this->pdo->exec(file_get_contents($schemaPath));
    }

    public function tearDown(): void
    {
        // Bellek-içi DB, referans düşünce otomatik yok olur — açıkça
        // silinecek bir dosya/kaynak yok. Yine de alt sınıflar override edebilir.
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected != $actual) {
            throw new AssertionFailedException(
                $message !== '' ? $message : sprintf('Beklenen: %s, Gerçek: %s', var_export($expected, true), var_export($actual, true))
            );
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailedException(
                $message !== '' ? $message : sprintf('Beklenen (===): %s, Gerçek: %s', var_export($expected, true), var_export($actual, true))
            );
        }
    }

    protected function assertTrue(mixed $condition, string $message = ''): void
    {
        if ($condition !== true) {
            throw new AssertionFailedException($message !== '' ? $message : 'assertTrue başarısız — koşul true değil.');
        }
    }

    protected function assertFalse(mixed $condition, string $message = ''): void
    {
        if ($condition !== false) {
            throw new AssertionFailedException($message !== '' ? $message : 'assertFalse başarısız — koşul false değil.');
        }
    }

    protected function assertNull(mixed $value, string $message = ''): void
    {
        if ($value !== null) {
            throw new AssertionFailedException($message !== '' ? $message : 'assertNull başarısız.');
        }
    }

    protected function assertNotNull(mixed $value, string $message = ''): void
    {
        if ($value === null) {
            throw new AssertionFailedException($message !== '' ? $message : 'assertNotNull başarısız.');
        }
    }

    protected function assertGreaterThan(mixed $expectedLowerBound, mixed $actual, string $message = ''): void
    {
        if (!($actual > $expectedLowerBound)) {
            throw new AssertionFailedException($message !== '' ? $message : "assertGreaterThan başarısız: {$actual} > {$expectedLowerBound} değil.");
        }
    }

    protected function assertCount(int $expectedCount, array|\Countable $haystack, string $message = ''): void
    {
        $actual = count($haystack);
        if ($actual !== $expectedCount) {
            throw new AssertionFailedException($message !== '' ? $message : "assertCount başarısız: beklenen {$expectedCount}, gerçek {$actual}.");
        }
    }

    /**
     * PHPUnit'teki expectException()'ın basitleştirilmiş hali — burada
     * bir closure alır (PHPUnit'in attribute/expectException akışından
     * FARKLIDIR ama aynı amaca hizmet eder: "bu kod X istisnasını fırlatmalı").
     */
    protected function assertThrows(string $expectedExceptionClass, callable $callback, string $message = ''): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            if ($e instanceof $expectedExceptionClass) {
                return;
            }
            throw new AssertionFailedException(
                "Beklenen istisna {$expectedExceptionClass}, ama " . get_class($e) . " fırlatıldı: " . $e->getMessage()
            );
        }
        throw new AssertionFailedException($message !== '' ? $message : "Beklenen istisna ({$expectedExceptionClass}) hiç fırlatılmadı.");
    }
}

final class AssertionFailedException extends \RuntimeException {}
