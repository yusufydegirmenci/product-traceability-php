<?php

declare(strict_types=1);

namespace Traceability\Tests\Unit;

use Traceability\Tests\TestCase;

/**
 * KIDEMLI İNCELEME BULGUSU: 8 exception sınıfı (UnauthorizedActionException,
 * DestroyRequestException, vb.), kendi dosyaları YERİNE "ana" sınıflarının
 * dosyasında (örn. AuthorizationGuard.php) tanımlanmıştı. Bu, PSR-4
 * otomatik yüklemenin varsayımını (dosya adı = sınıf adı) ihlal ediyordu —
 * sadece "ana" sınıf ÖNCEDEN başka bir yerde yüklenmişse fark edilmiyordu,
 * yeni bir kod yolu bu exception'ı YALNIZ BAŞINA referans ettiğinde
 * ("Class not found" hatasıyla) SESSİZCE PATLARDI.
 *
 * Bu test, src/ altındaki HER .php dosyasının içerdiği HER sınıf/arayüz
 * adının dosya adıyla EŞLEŞTİĞİNİ tarayarak doğrular — bu sınıf hatasının
 * bir daha sessizce geri gelmemesini garanti eder.
 */
final class PsrAutoloadComplianceTest extends TestCase
{
    public function testEveryClassOrInterfaceMatchesItsFilename(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $expectedName = $file->getBasename('.php');
            $content = file_get_contents($file->getPathname());

            preg_match_all('/^(?:final\s+|abstract\s+)?(?:class|interface)\s+(\w+)/m', $content, $matches);
            $declaredNames = $matches[1];

            foreach ($declaredNames as $declaredName) {
                if ($declaredName !== $expectedName) {
                    $violations[] = "{$file->getPathname()}: '{$declaredName}' adlı sınıf/arayüz, dosya adıyla ({$expectedName}.php) EŞLEŞMİYOR";
                }
            }
        }

        $this->assertCount(0, $violations, "PSR-4 ihlalleri bulundu:\n" . implode("\n", $violations));
    }
}
