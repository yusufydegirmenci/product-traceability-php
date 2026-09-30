# PRODUCTION_CHECKLIST.md — Canlıya Alım Kontrol Listesi

**Bu belge tamamlanmadan hiçbir ortamda üretime geçiş yapılmamalıdır.**
Her madde işaretlenmeli, sorumlu kişi/tarih not edilmelidir.

---

## 0. ÖN KOŞUL — İnsan İncelemesi (ATLANAMAZ)

- [ ] `SECURITY_REVIEW.md` bir kıdemli mühendis tarafından okundu ve
      imzalandı.
- [ ] `SECURITY_REVIEW.md` Bölüm 3'teki (Dört-Göz Kuralı Tutarsızlığı)
      kararı (A/B/C) resmi olarak verildi ve belgeye yazıldı.
- [ ] `docs/pr-review/` altındaki 4 modülün TAMAMI incelendi, açık
      sorular yanıtlandı.

Bu bölüm işaretlenmeden aşağıdaki hiçbir adıma geçilmemelidir.

---

## 1. Veritabanı Geçişi (Database Migration)

- [ ] Hedef veritabanı **MySQL**'dir — `schema.sqlite.sql` sadece yerel
      geliştirme/test içindir, üretimde KULLANILMAZ.
- [ ] `schema/schema.mysql.sql` bir migration aracıyla (Doctrine
      Migrations, Phinx, ya da elle sıralı `.sql` dosyaları) hedef
      veritabanına uygulandı.
- [ ] Şemadaki TÜM trigger'lar (`trg_epcis_events_no_update`,
      `trg_epcis_events_no_delete`, `trg_risk_events_protect_evidence`,
      `trg_risk_events_no_delete`) gerçekten oluşturuldu — bazı MySQL
      yönetilen servisleri (örn. kısıtlı RDS izinleri) trigger
      oluşturmayı engelleyebilir, bunu ÖNCEDEN doğrulayın:
      ```sql
      SHOW TRIGGERS LIKE 'epcis_events';
      SHOW TRIGGERS LIKE 'risk_events';
      ```
- [ ] Uygulama veritabanı kullanıcısına `epcis_events` tablosunda
      **SADECE** `SELECT, INSERT` yetkisi verildi — `UPDATE, DELETE`
      yetkisi KESİNLİKLE verilmedi:
      ```sql
      GRANT SELECT, INSERT ON traceability.epcis_events TO 'app_user'@'%';
      REVOKE UPDATE, DELETE ON traceability.epcis_events FROM 'app_user'@'%';
      ```
- [ ] `sample_weight_profiles.csv` dosyasındaki ürünler için
      GERÇEK dolu/boş gram değerleri spec sheet'lerden dolduruldu;
      "TEYİT GEREKİR" notlu satırlar (cihaz, aksesuar ve belirsiz ürün tipleri) ürün ekibiyle netleştirildi.
- [ ] Her ürün için `products.return_window_days` gerçek iade
      politikasına göre ayarlandı (varsayılan: 14 gün — kontrol edin).
- [ ] Boş bir veritabanına karşı `php tools/seed_realistic_data.php 7`
      çalıştırılıp şemanın GERÇEKTEN sorunsuz kurulduğu doğrulandı.

---

## 2. Ortam Değişkenleri Doğrulaması (.env)

- [ ] `.env.example` → `.env` olarak kopyalandı, TÜM değerler gözden
      geçirildi (körü körüne varsayılanlar kabul EDİLMEDİ).
- [ ] `.env`, `.gitignore`'da — versiyon kontrolüne KESİNLİKLE
      girmedi. Kontrol: `git check-ignore .env` boş dönmemeli.
- [ ] Aşağıdaki her parametre, gerçek saha verisiyle (en az 2-4 haftalık
      gözlem) kalibre edildi — hiçbiri "makul görünen varsayılan"
      olarak üretime bırakılmadı:

  | Parametre | Varsayılan | Kalibre Edildi mi? |
  |---|---|---|
  | `DWELL_TIME_WINDOW_MINUTES` | 15 | [ ] |
  | `SCAN_SEQUENCE_WINDOW_SECONDS` | 120 | [ ] |
  | `SUPERVISOR_VELOCITY_WINDOW_MINUTES` / `_THRESHOLD` | 60 / 5 | [ ] |
  | `DEVICE_FAULT_WINDOW_MINUTES` / `_THRESHOLD` | 10 / 5 | [ ] |
  | `WEIGHT_TOLERANCE_FRACTION` | 0.10 | [ ] |
  | `RISK_THRESHOLD_HIGH` / `_MEDIUM` / `_WINDOW_DAYS` | 60 / 25 / 90 | [ ] |
  | `EXPIRY_LOCKOUT_DEFAULT_MIN_DAYS` | 0 | [ ] |
  | `CARRIER_CONFIRMATION_HOURS` | 48 | [ ] |

- [ ] `config/warehouse.php`'nin GERÇEKTEN `.env`'i okuduğu doğrulandı:
      ```bash
      php tests/run-tests.php  # ConfigOverrideTest sınıfı bunu otomatik doğrular
      ```

---

## 3. Sır Yönetimi (Secrets)

- [ ] `EventStore`'a verilen HMAC anahtarı(ları) rastgele üretildi
      (`random_bytes(32)`), bir secrets manager'da (AWS Secrets Manager,
      HashiCorp Vault, veya en az şifreli bir env değişkeni) saklanıyor
      — kod içinde veya `.env`'de düz metin YOK.
  ```php
  // demo.php'deki gibi rastgele üretmeyin — gerçek üretimde secrets
  // manager'dan okuyun:
  $hmacKey = getenv('EVENT_STORE_HMAC_KEY_V1'); // örnek
  ```
- [ ] Anahtar rotasyon politikası belirlendi (`key_rotation_log`
      tablosu buna hazır — `EventStore::activateKeyVersion()`/
      `retireKeyVersion()` kullanılacak).

---

## 4. Barkod/Donanım Entegrasyonu

- [ ] Barkod/QR üretimi için gerçek bir kütüphane (`picqer/php-barcode-generator`
      veya benzeri) `composer require` ile eklendi — bu proje sadece
      `DigitalLink::buildElementString()` ile VERİ ÜRETİR, fiziksel
      sembol basmaz.
- [ ] Paketleme istasyonu tartısının `WeightReconciler::check()`'i
      GERÇEKTEN tetiklediği (donanım entegrasyonu) doğrulandı — bu kod
      tarafı hazır, veriyi bekliyor.
- [ ] El terminali uygulaması, `ScanSequenceGuard::recordLocationScan()`'ı
      GERÇEKTEN her raf barkodu okutmasında çağırıyor mu doğrulandı.

---

## 5. Test Paketi (CI/CD Entegrasyonu)

- [ ] `php tests/run-tests.php` CI pipeline'ına eklendi, HER deploy
      öncesi otomatik çalışıyor, 0 başarısız test şart koşuluyor.
- [ ] **Gerçek ağ erişimi varsa:** `composer require --dev
      phpunit/phpunit` çalıştırıldı, her test dosyasındaki
      `use Traceability\Tests\TestCase;` satırı `use
      PHPUnit\Framework\TestCase;` ile değiştirildi, test paketi
      gerçek PHPUnit altında da yeşil.
- [ ] `php stress_test_concurrency.php` (veya `tests/Integration/ConsumeFromLotConcurrencyTest.php`)
      staging ortamında GERÇEK MySQL'e karşı (SQLite değil) çalıştırıldı
      — MySQL'in kilitleme davranışı SQLite'dan farklıdır, bu ayrıca
      doğrulanmalıdır.
- [ ] `php tools/seed_realistic_data.php 90` ile üretilen hacimde temel
      sorguların (dashboard, raporlama) yanıt süresi kabul edilebilir
      seviyede (< 200ms) olduğu doğrulandı.

---

## 6. Dağıtım Sonrası Duman Testleri (Post-Deployment Smoke Tests)

Dağıtımdan HEMEN sonra, gerçek (ama düşük riskli) verilerle:

- [ ] Bir ürün depoya girildi, GTIN + Lot No doğru kaydedildi.
- [ ] Bir koli açılıp mühürlendi, `seal_serial` üretildi.
- [ ] `EventStore::verifyChain()` bu entity için `true` döndü.
- [ ] Bir iade işlendi, sistem doğru şekilde kabul/red kararı verdi.
- [ ] Bir bildirim (`notifications`) oluşturuldu ve `acknowledge()` ile
      kapatılabildi.
- [ ] Bir vardiya devri açılıp her iki tarafça onaylandı.
- [ ] `AuditReadinessService::generateAuditReadinessReport()` hatasız
      çalıştı ve makul bir sonuç döndürdü.

---

## 7. İzleme ve Uyarı (Monitoring)

- [ ] `notifications` tablosundaki `critical` seviyeli kayıtlar
      (özellikle `system_risk_flag`, `internal_shrinkage_suspicion`,
      `organized_network_suspected`) gerçek zamanlı bir uyarı kanalına
      (Slack, e-posta, PagerDuty) bağlandı — şu an sadece veritabanında
      duruyorlar, aktif olarak KİMSEYE bildirilmiyor.
- [ ] `device_fault_events` ve kilitli cihaz sayısı için bir dashboard
      widget'ı eklendi.

---

## 8. Geri Alma Planı (Rollback)

- [ ] Migration'ların geri alınabilir (`down()`) versiyonları hazır.
- [ ] Hash-chain'in append-only doğası nedeniyle, bir geri alma
      SIRASINDA `epcis_events`'e YAZILMIŞ hiçbir kayıt SİLİNEMEZ —
      rollback planı bunu hesaba katmalı (yeni bir "rollback event"
      eklemek, veri silmek DEĞİL).

---

## Son Onay

```
Onaylayan (Mühendislik):     _________________________  Tarih: _______
Onaylayan (Güvenlik/Risk):   _________________________  Tarih: _______
Onaylayan (Operasyon):       _________________________  Tarih: _______
```
