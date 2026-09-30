<?php

declare(strict_types=1);

namespace Traceability\Tests\Unit;

use Traceability\Tests\TestCase;
use Traceability\Config\EnvValidator;

final class EnvValidatorTest extends TestCase
{
    private array $envKeysToClean = [
        'EVENT_STORE_HMAC_KEY_V1', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
    ];

    public function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->envKeysToClean as $key) {
            putenv($key);
        }
    }

    public function testLocalEnvironmentSkipsValidationEntirely(): void
    {
        // Hiçbir env değişkeni ayarlanmadı — production'da bu BAŞARISIZ olurdu.
        $problems = EnvValidator::validate('local');
        $this->assertCount(0, $problems);
    }

    public function testProductionWithAllVarsMissingReportsEveryOne(): void
    {
        $problems = EnvValidator::validate('production');
        $this->assertGreaterThan(0, count($problems));
        $this->assertTrue(
            (bool) array_filter($problems, fn($p) => str_contains($p, 'EVENT_STORE_HMAC_KEY_V1')),
            'HMAC anahtarı eksikliği raporlanmadı.'
        );
    }

    public function testProductionWithShortHmacKeyIsRejected(): void
    {
        putenv('EVENT_STORE_HMAC_KEY_V1=cok-kisa'); // 32 karakterden az
        putenv('DB_HOST=localhost');
        putenv('DB_DATABASE=traceability');
        putenv('DB_USERNAME=app');
        putenv('DB_PASSWORD=gizli');

        $problems = EnvValidator::validate('production');
        $this->assertTrue(
            (bool) array_filter($problems, fn($p) => str_contains($p, 'çok kısa')),
            'Kısa/güvensiz HMAC anahtarı YAKALANMADI.'
        );
    }

    public function testProductionWithAllValidVarsPassesCleanly(): void
    {
        putenv('EVENT_STORE_HMAC_KEY_V1=' . bin2hex(random_bytes(32)));
        putenv('DB_HOST=localhost');
        putenv('DB_DATABASE=traceability');
        putenv('DB_USERNAME=app');
        putenv('DB_PASSWORD=gercekten-guclu-bir-sifre');

        $problems = EnvValidator::validate('production');
        $this->assertCount(0, $problems);
    }

    public function testMaskForLoggingNeverExposesFullSecret(): void
    {
        $secret = 'super-gizli-hmac-anahtari-1234567890';
        $masked = EnvValidator::maskForLogging($secret);

        $this->assertFalse(str_contains($masked, $secret), 'Maskelenmiş değer, HALA orijinal sırrı içeriyor!');
        $this->assertTrue(str_ends_with($masked, substr($secret, -4)), 'Teşhis için son 4 karakter görünür olmalıydı.');
    }

    public function testMaskForLoggingHandlesNullAndEmpty(): void
    {
        $this->assertSame('(tanımsız)', EnvValidator::maskForLogging(null));
        $this->assertSame('(tanımsız)', EnvValidator::maskForLogging(''));
    }
}
