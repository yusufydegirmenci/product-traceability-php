# SECURITY_REVIEW.md — İnsan İnceleme Paketi

**Durum:** İNCELENMEMİŞ (bu belge insan incelemesinin YERİNE geçmez —
sadece onu kolaylaştırmak için hazırlandı)
**Versiyon:** v1.4-prototype (bkz. `ARCHITECTURE.md` Bölüm 5 — Audit &
Security Sign-off Checklist, insan incelemesi imza bloğu buradadır)
**Kapsam:** v1.2-PRACTICAL-ZERO-TRUST-STABLE'da eklenen 4 güvenlik-kritik modül
**Detaylı dosyalar:** `docs/pr-review/00_INDEX.md`, `ARCHITECTURE.md`

---

## 1. Neden Bu Belge Var

Bu proje gerçek bir GitHub deposunda/PR sisteminde değil — yerel `git
init` ile versiyon kontrolüne alındı. Bu yüzden "ayrı PR'lara böl"
isteğinin literal karşılığını (gerçek GitHub PR'ları) veremiyoruz.
Bunun yerine, 4 güvenlik-kritik modülün her biri için ayrı, odaklı bir
inceleme dosyası hazırlandı (`docs/pr-review/`), ve bu belge onların
ÖZETİDİR — bir insan mühendisin imzalamadan önce okuması gereken tek
sayfa.

---

## 2. Refactoring Sırasında Bulunan ve Düzeltilen Gerçek Hatalar

Bu belgeler HAZIRLANIRKEN (yazma sürecinin kendisinde), varsayımla değil
gerçekten kod okunup test edilerek, **4 GERÇEK boşluk** bulundu ve
düzeltildi:

| # | Bulgu | Dosya | Düzeltme | Kanıtlayan Test |
|---|---|---|---|---|
| 1 | `DamagedItemService::markDamaged()`, `$actorId` parametresini ALIYOR ama HİÇ KULLANMIYORDU — kim işaretlediğine dair iz kalmıyordu | `DamagedItemService.php` | `risk_events`'e bilgi amaçlı bir kayıt eklendi | (dolaylı — mevcut `DamagedItemServiceTest` testleri regresyon olmadığını doğruluyor) |
| 2 | `MISDECLARED` durumu GERİ ALINABİLİYORDU — `DESTROYED` gibi kalıcı olması gerekirken `transitionStatus()` bunu engellemiyordu | `EntityRepository.php` | `MISDECLARED`, `DESTROYED` ile aynı şekilde terminal/geri döndürülemez yapıldı | `LotReclassificationTest::testMisdeclaredStatusIsPermanentLikeDestroyed` |
| 3 | `DwellTimeGuard::releaseItem()`, SADECE koliye girme akışına bağlıydı — "rafa iade" için ayrı bir çağrı noktası YOKTU | `DwellTimeGuard.php` | Açık, adlandırılmış `returnToShelf()` metodu eklendi | `DwellTimeGuardTest::testReturnToShelfClosesCustodyWithDistinctReason` |
| 4 | **KRİTİK: `EventStore::appendEvent()`, AYNI entity_id'ye eşzamanlı iki yazma olduğunda hash-chain'i ÇATALLAYABİLİYORDU** — 5 gerçek-süreç denemesinin 3'ünde ampirik olarak GERÇEKLEŞTİ (bkz. Bölüm 5) | `EventStore.php` | `(entity_id, prev_hash)` üzerinde UNIQUE kısıt + otomatik retry mantığı | `EventStoreConcurrentAppendTest::testConcurrentAppendsToSameEntityNeverForkTheChain` (10/10 çalıştırmada doğrulandı) |

Bu dördü de bu turda düzeltildi ve testle kilitlendi. Aşağıdaki Bölüm 3,
**BİLEREK DÜZELTİLMEYEN**, insan kararı gerektiren tek konuyu ele alır.

---

## 3. AÇIK İNCELEME MADDESİ — Dört-Göz Kuralı Tutarsızlığı (İnsan Kararı Gerekiyor)

### Durum
`DestroyService::confirmDestroy()`, bir ürünü kalıcı olarak imha etmek
için İKİ FARKLI kişinin (talep eden ≠ onaylayan) gerekli olduğunu
zorunlu kılar ("dört-göz" ilkesi) — bu, projede baştan beri (RT8.4'ten
önce) var olan, kanıtlanmış bir güvenlik deseni.

`DamagedItemService::scrapWithTutanak()` ise AYNI SONUCA (bir ürünün
kalıcı olarak `DESTROYED` durumuna geçmesi) varmasına rağmen, sadece
TEK bir süpervizörün rolünü kontrol eder. Talep eden ve onaylayan AYNI
kişi olabilir.

### Risk Analizi
- **Risk:** Hasarlı ürün yolu, dört-göz kuralını BİLEREK ATLAYAN bir
  "arka kapı" haline gelebilir. Kötü niyetli (veya suistimale açık) bir
  süpervizör, sağlam bir ürünü "hasarlı" diye işaretleyip TEK BAŞINA
  hurdaya ayırabilir — DestroyService'in normal yolundan GEÇMEDEN.
- **Azaltıcı faktör:** `markDamaged()` → `DAMAGED_HOLD` geçişi kendi
  başına GERİ ALINABİLİR bir durumdur (henüz kalıcı değil) ve
  `scrapWithTutanak()` zorunlu bir "Fiziki Hurda Tutanak Ref" ister —
  yani TAMAMEN izsiz değildir, ama İKİNCİ bir insanın onayı YOKTUR.
- **Kullanıcının literal isteği** ("Süpervizör tutanak Ref girmeden
  düşülemesin") teknik olarak tek-kişilik bir kural tanımlıyordu — bu
  yüzden mevcut davranış İSTENEN özelliği doğru uyguluyor, ama bu,
  projenin GENEL güvenlik felsefesiyle (dört-göz) tutarsız.

### Önerilen Uygulama Yolları (İnsan Mühendis Seçmeli)
**Seçenek A — Birleştir (SOMUTLAŞTIRILDI, henüz VARSAYILAN DEĞİL):**
`DamagedItemService::requestScrapViaFourEyes()` + `confirmScrapViaFourEyes()`
eklendi — `DestroyService`'in MEVCUT dört-göz altyapısını (talep eden ≠
onaylayan) kullanır. **Bu, `scrapWithTutanak()`'ın YERİNE GEÇMEDİ** —
ikisi de kod tabanında yan yana duruyor, ikisi de test edilmiş
(`tests/Integration/DamagedItemServiceTest.php::testFourEyesAlternativeRequiresDifferentConfirmer`
dört-göz'ün gerçekten AYNI kişiyi reddettiğini, FARKLI kişiyle
başarılı olduğunu kanıtlıyor). Bir kıdemli mühendis, hangisinin
kalıcı olacağına (ya da ikisinin de hacme göre kalması gerekip
gerekmediğine) karar vermeli ve DİĞERİNİ kod tabanından kaldırmalı.

**Seçenek B — Kabul et ve belgelensin:** Hasarlı ürün hacminin çok
yüksek olduğu (dört-göz'ün operasyonel yükü çok artıracağı) bir
depoda, mevcut tek-kişilik kural (`scrapWithTutanak()`) BİLİNÇLİ bir
risk kabulü olarak kalabilir.

**Seçenek C — Hacim eşiği:** Düşük değerli/sık hasar gören kategoriler
için tek-kişilik kural kalsın, yüksek değerli ürünler için
`requestScrapViaFourEyes()`/`confirmScrapViaFourEyes()` zorunlu olsun.

### Bu Maddenin Kapatılması İçin Gerekli
Bir kıdemli mühendis/güvenlik sorumlusu, yukarıdaki 3 seçenekten birini
seçip bu bölümü "KARAR: [seçenek] — [tarih] — [imza]" diye
güncellemeli. **Bu karar verilmeden bu modül üretime alınmamalıdır.**

---

## 5. KRİTİK BULGU — EventStore Hash-Chain Çatallanma Riski (Kıdemli İnceleme Turu)

Bir kıdemli mimari inceleme talebi sırasında, `consumeFromLot()`'ta daha
önce bulunan yarış durumuna BENZER ama çok DAHA CİDDİ bir sınıf sorun
arandı: **AYNI entity_id'ye eşzamanlı iki `appendEvent()` çağrısı, hash-chain'in
KENDİSİNİ çatallayabilir mi?**

**Kanıtlama yöntemi:** `EventStore::getLastHash()` salt-okunur bir
SELECT'tir, hiçbir satır kilidi almaz. Teorik olarak, iki eşzamanlı
süreç AYNI "son hash"i okuyup İKİSİ DE o hash'i `prev_hash` olarak
kullanarak yeni bir satır ekleyebilir — bu, tek bir doğrusal zincir
yerine bir ÇATAL (iki çocuk, aynı ebeveyne işaret eder) oluşturur.

**Ampirik sonuç:** 20 gerçek işletim sistemi süreci (pcntl_fork),
AYNI entity_id'ye eşzamanlı yazmaya çalıştırıldı. **5 denemenin 3'ünde
çatallanma GERÇEKTEN oluştu** ve `verifyChain()` bunu doğru şekilde
`false` (bozuk) olarak rapor etti — ama bu, MASUM bir eşzamanlı yazma
durumuydu, kötü niyetli bir saldırı değildi. Yani düzeltilmeden önceki
sistem, YÜKSEK hacimli bir depoda (örn. aynı popüler lot'tan aynı anda
birden fazla personel sipariş hazırlarsa) KENDİ KENDİNE, hiçbir
saldırgan olmadan, sahte bir "bütünlük ihlali" alarmı üretebilirdi.

**Düzeltme:** `(entity_id, prev_hash)` üzerinde bir UNIQUE veritabanı
kısıtı eklendi. Bu, ikinci eşzamanlı yazmayı DB SEVİYESİNDE reddeder;
`EventStore::appendEvent()` bu reddi yakalayıp `prev_hash`'i YENİDEN
okuyup otomatik olarak tekrar dener (optimistic concurrency + retry —
`consumeFromLot()`'un atomic UPDATE deseniyle AYNI felsefe, farklı bir
uygulama biçimiyle).

**Doğrulama:** Aynı 20-süreç testi düzeltme SONRASI **10/10 çalıştırmada**
sıfır çatallanma, her zaman `verifyChain()=true` üretti. Kalıcı regresyon
testi: `tests/Integration/EventStoreConcurrentAppendTest.php`.

**Neden bu önemliydi:** `consumeFromLot()`'taki yarış durumu bir SAYININ
(miktarın) yanlış hesaplanmasıyla ilgiliydi. Bu ise sistemin TEMEL
GÜVEN ARGÜMANININ (hash-chain bütünlüğü) kendisini etkiliyordu — daha
yüksek önem derecesindeydi ve daha geç (bir mimari inceleme turunda)
bulundu, bu da PERİYODİK, ODAKLANMIŞ eşzamanlılık denetimlerinin
(sadece "bir kez test edip geçtik" değil) neden gerekli olduğunu
gösteriyor.

---

## 6. Sandbox Kısıtları — Neden Gerçek PHPUnit Yok

Bu ortamda `composer` kurulu değil ve `packagist.org` sandbox ağ
politikası tarafından engellenmiş (`host_not_allowed`). Bu yüzden:

- `tests/TestCase.php`, PHPUnit'in `setUp()`/`tearDown()`/`assertX()`
  API yüzeyini BİREBİR aynı imzalarla taklit eden, bağımlılıksız bir
  temel sınıftır.
- `tests/run-tests.php`, reflection ile test sınıflarını/metotlarını
  bulup çalıştıran minimal bir runner'dır (`phpunit` CLI'nin yerini
  tutar).
- **Gerçek ağ erişimi olan bir ortamda geçiş:** `composer require --dev
  phpunit/phpunit`, ardından her test dosyasındaki
  `use Traceability\Tests\TestCase;` satırını `use
  PHPUnit\Framework\TestCase;` ile değiştirmek YETERLİDİR — test
  mantığının kendisi (assertion'lar, setUp/tearDown çağrıları) HİÇ
  değişmez.

**Şu an: 52 test, 52 başarılı, 0 başarısız, deterministik (10/10
çalıştırmada aynı sonuç — eşzamanlılık testleri dahil).**

---

---

## 6.5. İkinci Kıdemli Tur — Retry Sertleştirme, Onay Yarışı, Migration Güvenliği, PSR-4 Buldusu

Kullanıcı, "Kıdemli Yazılım Mimarı ve EventStore Uzmanı" rolüyle 4
somut madde istedi. Hepsi gerçekten uygulandı ve test edildi:

1. **Exponential backoff + `ConcurrencyConflictException`.** Retry
   mantığı artık `2^attempt × 5ms + jitter` ile bekliyor (önceden basit
   doğrusal bir bekleme vardı), varsayılan 5 deneme `config/warehouse.php`
   üzerinden ayarlanabilir, sınır aşılınca sessizce eski hatayı
   fırlatmak yerine açıklayıcı `ConcurrencyConflictException` fırlatıyor.
   Test: `EventStoreConcurrentAppendTest::testExhaustingRetriesThrowsConcurrencyConflictException`
   (60 gerçek paralel süreç, retry KAPALI, en az biri garanti çakışıyor).
2. **`confirmScrapViaFourEyes()`/`confirmDestroy()` eşzamanlı onay yarışı
   — GERÇEK bir boşluk bulundu.** `DestroyService::confirmDestroy()`'daki
   UPDATE ifadesi `WHERE id=:id` idi, `AND status='pending'` YOKTU — iki
   yetkili AYNI ANDA onaylarsa İKİSİ DE geçebilirdi. Atomik
   `WHERE id=:id AND status='pending'` + `rowCount()` kontrolüyle
   düzeltildi. Test: `ConfirmDestroyRaceTest` (2 gerçek süreç, TAM
   OLARAK biri başarılı, biri reddediliyor — 5/5 çalıştırmada doğrulandı).
3. **Migration güvenlik testleri.** `migrations/2026_add_unique_entity_prevhash.php`
   yazıldı: `--check`/`--apply`/`--rollback` modları, ÖNCEDEN oluşmuş bir
   çatallanma varsa kısıt eklemeyi REDDEDİYOR (körü körüne ALTER TABLE
   çalıştırmıyor). `MigrationSafetyTest`, hem temiz hem kasıtlı olarak
   "kirlenmiş" bir veritabanına karşı gerçekten çalıştırılarak test edildi.
4. **CI katmanlaması + GERÇEK bir PSR-4 otomatik yükleme hatası bulundu.**
   `tests/run-tests.php --group=fast|concurrency|unit|integration` eklendi.
   Bunu yaparken, **8 exception sınıfının** (`UnauthorizedActionException`,
   `DestroyRequestException`, `PackageMismatchException` vb.) kendi
   dosyaları YERİNE "ana" sınıflarının dosyasında gizli olduğu ve bu
   yüzden YALNIZ BAŞINA referans edildiklerinde autoload'ın PATLADIĞI
   ortaya çıktı (sadece "ana" sınıf önceden başka bir yerde
   yüklenmişse fark edilmiyordu). Hepsi kendi dosyalarına taşındı VE
   bu sınıf hatasının bir daha sessizce geri gelmemesi için kalıcı bir
   mimari koruma testi eklendi: `PsrAutoloadComplianceTest` (kasıtlı bir
   ihlal enjekte edilip YAKALANDIĞI doğrulandı, sonra geri alındı).
5. **Loglama altyapısı.** `src/Logging/` — PSR-3 uyumlu `Logger`,
   exception türüne göre önem seviyesi atayan `ExceptionHandler`
   (ledger tutarsızlıkları HER ZAMAN `critical`), ve gerçek Sentry
   SDK'sına (Composer gerektirir) taşınabilir `ExternalErrorReporter`
   arayüzü + `NullErrorReporter` varsayılanı. 6 test.
6. **GitHub Actions (`main.yml`).** 4 iş: hızlı testler → eşzamanlılık
   testleri → kod kalitesi → (main branch'te, insan onaylı environment
   ile) deploy. YAML sözdizimi doğrulandı, henüz GERÇEK bir GitHub
   Actions çalıştırmasında test EDİLMEDİ (bu depo yerel `git init`).
7. **EnvValidator.** Üretimde zorunlu değişkenler (HMAC anahtarı, DB
   kimlik bilgileri) eksik/zayıfsa `validateOrDie()` sistemi GÜVENLİ
   ŞEKİLDE durdurur; `maskForLogging()` hiçbir sırrı açık metin
   loglamaz. 6 test.

**Bu turun sonunda: 70 test (66 hızlı + 4 eşzamanlılık), hepsi 3
ardışık çalıştırmada başarılı, demo.php ve stres testi regresyonsuz.**

---

## 7. İmza Bloğu (İnsan Mühendis İçin)

```
İncelemeyi yapan:        _________________________
Tarih:                   _________________________
Bölüm 3 kararı (A/B/C):  _________________________
Ek notlar:               _________________________
```
