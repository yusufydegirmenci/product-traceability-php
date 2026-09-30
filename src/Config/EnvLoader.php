<?php

declare(strict_types=1);

namespace Traceability\Config;

/**
 * NEDEN BU DOSYA VAR: Standart çözüm (vlucas/phpdotenv) Composer
 * gerektirir — bu sandbox'ta packagist.org erişilemez (host_not_allowed).
 * Bu, `KEY=VALUE` formatındaki bir .env dosyasını okuyup
 * getenv()/$_ENV'e yükleyen, bağımlılıksız minimal bir muadildir.
 * Gerçek ağ erişimi olan bir ortamda, bu dosya silinip
 * `composer require vlucas/phpdotenv` ile birebir değiştirilebilir —
 * çağıran kod (bkz. config/warehouse.php) hiç değişmez çünkü ikisi de
 * sonuçta getenv() ile okunan aynı ortam değişkenlerini üretir.
 */
final class EnvLoader
{
    private static bool $loaded = false;

    public static function load(string $envFilePath): void
    {
        if (self::$loaded || !is_file($envFilePath)) {
            return;
        }
        foreach (file($envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if (getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        return $value === false ? $default : $value;
    }

    public static function getInt(string $key, int $default): int
    {
        $value = getenv($key);
        return $value === false ? $default : (int) $value;
    }

    public static function getFloat(string $key, float $default): float
    {
        $value = getenv($key);
        return $value === false ? $default : (float) $value;
    }
}
