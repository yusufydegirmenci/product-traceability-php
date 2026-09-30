<?php

declare(strict_types=1);

/**
 * MIGRATION: UNIQUE (entity_id, prev_hash) kısıtını epcis_events'e ekler.
 *
 * NEDEN BU AYRI BİR SCRIPT: schema.mysql.sql/schema.sqlite.sql SIFIRDAN
 * kurulum içindir. Eğer sistem ZATEN üretimde çalışıyorsa ve bu kısıt
 * SONRADAN ekleniyorsa, mevcut veride (geçmişte, düzeltmeden ÖNCE oluşmuş
 * bir çatallanmadan kalma) ZATEN duplicate (entity_id, prev_hash) çiftleri
 * olabilir — bu durumda kısıt eklemek DOĞRUDAN BAŞARISIZ OLUR. Bu script,
 * körü körüne "ALTER TABLE" çalıştırmaz — önce KONTROL eder.
 *
 * KULLANIM:
 *   php migrations/2026_add_unique_entity_prevhash.php --check   (sadece kontrol, değişiklik yapmaz)
 *   php migrations/2026_add_unique_entity_prevhash.php --apply   (kontrol + uygula)
 *   php migrations/2026_add_unique_entity_prevhash.php --rollback (kısıtı kaldır)
 *
 * Varsayılan DB: schema.sqlite.sql'in oluşturduğu yerel dosya. Üretimde,
 * bu script'in mantığını MySQL'e (bkz. altındaki $mysqlSql) uyarlayın.
 */

$mode = $argv[1] ?? '--check';
$dbFile = $argv[2] ?? (__DIR__ . '/../demo.sqlite');

if (!is_file($dbFile)) {
    fwrite(STDERR, "Veritabanı dosyası bulunamadı: {$dbFile}\n");
    exit(1);
}

$pdo = new PDO("sqlite:{$dbFile}");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function checkForExistingDuplicates(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT entity_id, prev_hash, COUNT(*) as cnt, GROUP_CONCAT(id) as conflicting_ids
         FROM epcis_events GROUP BY entity_id, prev_hash HAVING cnt > 1"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "MIGRATION: UNIQUE (entity_id, prev_hash) — {$mode}\n";
echo "Hedef: {$dbFile}\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

if ($mode === '--rollback') {
    echo "Kısıt kaldırılıyor...\n";
    try {
        $pdo->exec('DROP INDEX idx_events_no_fork');
        echo "TAMAMLANDI ✓ — kısıt kaldırıldı. (NOT: bu, geriye dönük olarak\n";
        echo "oluşmuş olabilecek herhangi bir çatallanmayı DÜZELTMEZ, sadece\n";
        echo "yeni çatallanmalara karşı korumayı KALDIRIR.)\n";
    } catch (\PDOException $e) {
        echo "Kısıt zaten yok veya kaldırılamadı: " . $e->getMessage() . "\n";
    }
    exit(0);
}

echo "1. Adım: Mevcut veride ÖNCEDEN oluşmuş bir çatallanma (duplicate\n";
echo "   entity_id+prev_hash çifti) var mı kontrol ediliyor...\n\n";

$duplicates = checkForExistingDuplicates($pdo);

if (count($duplicates) > 0) {
    echo "❌ DURDURULDU: " . count($duplicates) . " adet ÖNCEDEN OLUŞMUŞ çatallanma bulundu!\n\n";
    foreach ($duplicates as $d) {
        echo "  entity_id={$d['entity_id']}, prev_hash=" . substr($d['prev_hash'], 0, 16) . "..., "
           . "{$d['cnt']} çakışan satır (id'ler: {$d['conflicting_ids']})\n";
    }
    echo "\nBu kısıt, bu veri TEMİZLENMEDEN eklenemez — ALTER TABLE başarısız olur.\n";
    echo "ÖNERİLEN TEMİZLEME YOLU (elle, DİKKATLE, HER GRUBU İNCELEYEREK):\n";
    echo "  1) Her çatallanmış grup için, hangi satırın 'gerçek' (ilk yazılan,\n";
    echo "     doğru iş akışını temsil eden) olduğunu belirleyin — genellikle\n";
    echo "     en KÜÇÜK id'li satır budur, ama bunu doğrulayın.\n";
    echo "  2) SAHTE/çatallanmış satırları SİLMEYİN (append-only felsefesine\n";
    echo "     aykırı) — bunun yerine bir 'fork_resolution_events' tablosuna\n";
    echo "     taşıyıp epcis_events'ten çıkarın, VE bu kararı ayrıca loglayın.\n";
    echo "  3) Temizlik sonrası bu script'i --check ile TEKRAR çalıştırıp\n";
    echo "     sıfır duplicate kaldığını doğrulayın, SONRA --apply kullanın.\n";
    exit(1);
}

echo "✓ Mevcut veride hiç çatallanma yok — kısıt güvenle eklenebilir.\n\n";

if ($mode === '--check') {
    echo "Sadece kontrol modu (--check) — hiçbir değişiklik yapılmadı.\n";
    exit(0);
}

if ($mode === '--apply') {
    echo "2. Adım: Kısıt ekleniyor...\n";
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_events_no_fork ON epcis_events (entity_id, prev_hash)');
    echo "TAMAMLANDI ✓ — UNIQUE (entity_id, prev_hash) kısıtı eklendi.\n";
    exit(0);
}

fwrite(STDERR, "Bilinmeyen mod: {$mode}. Kullanım: --check | --apply | --rollback\n");
exit(1);
