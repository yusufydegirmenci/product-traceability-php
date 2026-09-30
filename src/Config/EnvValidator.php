<?php

declare(strict_types=1);

namespace Traceability\Config;

/**
 * ÜRETİM SERTLEŞTİRME KATMANI. Kullanıcı isteği: "Hassas verilerin kod
 * içinde sızmasını engelleyecek, eksik env tanımlarında sistemi güvenli
 * şekilde durduracak katı bir validation ve hardening katmanı."
 *
 * İKİ SORUMLULUK:
 *   1) ZORUNLU ortam değişkenleri eksikse, sistem SESSİZCE varsayılan
 *      bir değerle DEVAM ETMEZ — açık bir hata ile DURUR (fail-safe,
 *      fail-silent DEĞİL).
 *   2) Hassas anahtarların (HMAC anahtarı, DB şifresi vb.) DEĞERLERİ,
 *      hiçbir log/hata mesajında/dump çıktısında AÇIK METİN olarak
 *      görünmez — sadece "tanımlı/tanımsız" ve son birkaç karakteri
 *      (teşhis için) gösterilir.
 */
final class EnvValidator
{
    /**
     * Üretimde MUTLAKA tanımlı olması gereken değişkenler. Format:
     * anahtar => ['required' => bool, 'sensitive' => bool, 'min_length' => int|null]
     */
    private const REQUIRED_IN_PRODUCTION = [
        'EVENT_STORE_HMAC_KEY_V1' => ['sensitive' => true, 'min_length' => 32],
        'DB_HOST' => ['sensitive' => false, 'min_length' => null],
        'DB_DATABASE' => ['sensitive' => false, 'min_length' => null],
        'DB_USERNAME' => ['sensitive' => false, 'min_length' => null],
        'DB_PASSWORD' => ['sensitive' => true, 'min_length' => null],
    ];

    /**
     * @param string $environment 'production' | 'staging' | 'local' | 'testing'
     * @return string[] Bulunan sorunların listesi (boşsa her şey yolunda demektir)
     */
    public static function validate(string $environment = 'production'): array
    {
        if ($environment !== 'production') {
            return []; // local/testing ortamında zorunlu değişken kontrolü UYGULANMAZ
        }

        $problems = [];
        foreach (self::REQUIRED_IN_PRODUCTION as $key => $rules) {
            $value = EnvLoader::get($key);

            if ($value === null || $value === '') {
                $problems[] = "ZORUNLU ortam değişkeni eksik: {$key}";
                continue;
            }
            if ($rules['min_length'] !== null && strlen((string) $value) < $rules['min_length']) {
                $problems[] = "{$key} çok kısa (en az {$rules['min_length']} karakter olmalı — güvensiz bir anahtar/şifre kullanılıyor olabilir)";
            }
        }
        return $problems;
    }

    /**
     * Üretim ortamında, doğrulama BAŞARISIZ olursa, sistemi GÜVENLİ
     * ŞEKİLDE (açık, anlaşılır bir mesajla) DURDURUR. "Güvenli" demek:
     * sessizce bir varsayılana düşüp devam etmek YERİNE, net bir hatayla
     * hemen durmak — belirsiz bir yapılandırmayla üretimde çalışmaktan
     * daha güvenlidir.
     */
    public static function validateOrDie(string $environment = 'production'): void
    {
        $problems = self::validate($environment);
        if ($problems === []) {
            return;
        }

        fwrite(STDERR, "❌ ORTAM DOĞRULAMASI BAŞARISIZ — sistem GÜVENLİ ŞEKİLDE durduruluyor:\n\n");
        foreach ($problems as $problem) {
            fwrite(STDERR, "  - {$problem}\n");
        }
        fwrite(STDERR, "\nBu, bir güvenlik önlemidir: eksik/zayıf yapılandırmayla üretimde\n");
        fwrite(STDERR, "çalışmak, sessizce devam etmekten HER ZAMAN daha risklidir.\n");
        fwrite(STDERR, "Eksik değişkenleri .env dosyanıza ekleyin (bkz. .env.example).\n");
        exit(1);
    }

    /**
     * Bir değeri LOG/DEBUG çıktısında GÖSTERMEK için güvenli hale getirir
     * — asıl değeri asla açığa çıkarmaz, sadece "tanımlı mı" ve teşhis
     * için son 4 karakteri gösterir (örn. bir anahtarın YANLIŞ .env'den
     * okunup okunmadığını anlamaya yeter, ama anahtarın kendisini
     * sızdırmaz).
     */
    public static function maskForLogging(?string $value): string
    {
        if ($value === null || $value === '') {
            return '(tanımsız)';
        }
        if (strlen($value) <= 4) {
            return '****';
        }
        return str_repeat('*', strlen($value) - 4) . substr($value, -4);
    }
}
