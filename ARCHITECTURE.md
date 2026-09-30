# ARCHITECTURE.md — Product Traceability System

**Versiyon:** v1.4-prototype
**Durum:** Test edilmiş referans mimari — insan incelemesi bekliyor (bkz. `SECURITY_REVIEW.md`)

Bu belge, sistemin mimari kararlarını, gelecek yol haritasını ve
kalan boşlukları TEK bir yerde toplar. `README.md` "ne çalışıyor"ı
anlatır; bu belge "neden böyle tasarlandı ve bundan sonra ne olacak"ı
anlatır.

---

## 1. Future Enhancements — GS1 Digital Link Resolver Yol Haritası

### Mevcut Durum
`DigitalLink::buildElementString()` ham GS1 element string'i üretir
(`(01)GTIN(10)LOT(21)SN`). Bir **resolver** (çözümleyici — örn.
`id.example.com`) HENÜZ inşa edilmedi; bu, ayrı bir web
servisi/altyapı projesidir, bu kod tabanının kapsamı DIŞINDADIR.

### Hedeflenen Mimari: Aynı QR, İki Farklı Görünüm

```
                    ┌─────────────────────────┐
                    │   Fiziksel Etiket (QR)   │
                    │  https://id.example.com/01/GTIN/10/LOT/21/SN │
                    └───────────┬─────────────┘
                                │
                    Kim okutuyor?
                 ┌──────────────┴──────────────┐
                 │                             │
        Müşteri/Bayi (tarayıcı)        Depo El Terminali (API çağrısı)
                 │                             │
                 ▼                             ▼
     ┌───────────────────────┐   ┌─────────────────────────────┐
     │   PUBLIC WEB VIEW      │   │   AUTHENTICATED API          │
     │  - Orijinallik: ✓/✗    │   │  - Tam EPCIS event geçmişi    │
     │  - Üretim/SKT tarihi   │   │  - Hangi personel, hangi     │
     │  - Genel kullanım      │   │    vardiya, hangi cihaz       │
     │    bilgisi             │   │  - risk_events, notifications │
     │  - "Zaten satılmış"    │   │  - Sadece kimlik doğrulamalı  │
     │    uyarısı (varsa)     │   │    isteklerde (JWT/API key)   │
     └───────────────────────┘   └─────────────────────────────┘
```

### Uygulama Prensibi (kodlanmadı, tasarım kararı olarak belgelendi)
Resolver servisi, gelen isteğin `Accept` header'ına VEYA bir
kimlik doğrulama token'ının varlığına bakarak yönlendirme yapmalı:

- `Accept: text/html` + token YOK → **Public Web View**: sadece
  `LotTraceService::traceByLotNumber()`'ın döndürdüğü `story` alanının
  SADECE tüketiciye açık kısmı (üretim tarihi, SKT, "orijinal ✓") —
  hiçbir `actor_id`, `device_id`, iç durum (`risk_events`,
  `notifications`) SIZDIRILMAZ.
- `Accept: application/json` + geçerli API token → **Authenticated
  API**: `EventStore::history()`'nin tam çıktısı, mevcut yetkilendirme
  katmanından (`AuthorizationGuard`) geçirilerek.

**Neden şimdi kodlanmadı:** Bu, bu depodaki PHP sınıflarının değil,
AYRI bir web-facing servisin sorumluluğudur (muhtemelen farklı bir
deployment, farklı bir güvenlik çevresi). Bu kod tabanı, resolver'ın
ihtiyaç duyacağı TÜM veriyi (`LotTraceService`, `EventStore::history()`)
zaten üretiyor — resolver bunun ÜZERİNE ince bir sunum katmanıdır.

### Bilinen Sınır: Lot-Bazlı Ürünlerde Tekil Doğrulama Yok
Kutusuz/lot-takipli ürünlerde (örn. Product Alpha) QR, TÜM partide
aynıdır — public view "bu parti gerçek mi" diyebilir, "bu SPESİFİK
kutu gerçek mi" diyemez (bkz. `LotTraceService` docblock'undaki
"DÜRÜST SINIR" notu). Bu, tekil ID maliyetinin bilinçli bir bedelidir.

---

## 2. ID Management & Traceability — Mimari Karar Kaydı (ADR)

### ADR-001: Seri Numaraları Asla Yeniden Kullanılmaz

**Durum:** Kabul edildi
**Bağlam:** Kullanıcı, SKT'si dolmuş yüksek hacimli bir lot'un (10.000
adet Product Alpha Cream) ID/seri alanını yeni bir partiye "devretmenin"
maliyet/verimlilik avantajı sağlayıp sağlamayacağını sordu.

**Değerlendirilen Seçenek:** Tükenen bir seri numarası havuzunu yeni
üretime devretmek.

**Reddedilme Gerekçesi:**
1. **Hash-chain kirliliği:** `EventStore` append-only'dir — aynı entity_id
   3 yıl sonra "yeniden doğarsa", eski `DESTROYED`/tüketim event'leri ile
   yeni `commissioning` event'leri AYNI zincirde karışır. Bu,
   `EntityRepository::transitionStatus()`'un `DESTROYED`/`MISDECLARED`
   için uyguladığı "asla geri dönüşü yok" ilkesiyle doğrudan çelişir.
2. **GS1 standardı (doğrulandı, web araması ile teyit edildi):**
   GS1 General Specification, **Ocak 2019 revizyonuyla**, GTIN yeniden
   kullanımını TAMAMEN ortadan kaldırdı — eski "48 ay bekle" gibi
   kurallar artık geçerli değil, kural "asla" haline geldi. (Bu, ilk
   tartışmada öne sürülen "en az 6 yıl" rakamından daha KATI bir
   kuraldır — düzeltme web aramasıyla doğrulanmıştır.)
3. **Tüketici güvenliği:** Bir müşteri QR okuttuğunda, eski (tükenmiş)
   parti mi yeni parti mi göründüğü belirsizleşir — yanlış SKT/sahte
   ürün algısı yaratır.
4. **Sahtecilik tespiti kaybı:** Çöpten bulunan eski bir kod, "bu artık
   geçersiz/tüketilmiş" diye KESİN olarak işaretli kalmalı — yeniden
   kullanılırsa bu sinyal kaybolur.

**Kabul Edilen Çözüm:** `SerialGenerator` (ZATEN mevcut, bu tartışma
İÇİN yeni yazılmadı) — 32 karakterlik alfanümerik alfabe (karışabilecek
0/O, 1/I çıkarılmış), 8 haneli, kriptografik rastgelelik
(`random_int`), ~1.1 trilyon kombinasyon. Pratikte tükenme riski yok.
Lot-bazlı ürünlerde zaten TEK bir Lot ID 1000'lerce fiziksel birimi
temsil ettiği için (bkz. `LotTraceService`), ID maliyeti/israfı
endişesi büyük ölçüde zaten çözülmüş durumdaydı.

**Sonuç:** Kod değişikliği YAPILMADI — mevcut `SerialGenerator` zaten
doğru yaklaşımı uyguluyordu. Bu ADR, gelecekte aynı soru tekrar
sorulduğunda (bir başka mühendis/AI tarafından) cevabın kod içinde
hazır durmasını sağlar.

---

## 3. Saha Verisi Bekleyen Eşik Değerleri (Dürüst Liste)

Aşağıdaki HİÇBİR değer gerçek operasyonel veriyle kalibre EDİLMEDİ —
hepsi "makul başlangıç noktası" olarak seçildi. Hepsi `config/warehouse.php`
+ `.env` üzerinden kod değiştirmeden ayarlanabilir.

| Parametre | Şu anki varsayılan | Neye göre kalibre edilmeli |
|---|---|---|
| `DWELL_TIME_WINDOW_MINUTES` | 15 dk | Gerçek ortalama toplama-paketleme süresi (sipariş büyüklüğüne göre değişebilir) |
| `SCAN_SEQUENCE_WINDOW_SECONDS` | 120 sn | Gerçek raf-arası yürüme mesafesi/süresi |
| `SUPERVISOR_VELOCITY_WINDOW_MINUTES` / `_THRESHOLD` | 60 dk / 5 | Normal bir süpervizörün saatlik ortalama override sayısı |
| `DEVICE_FAULT_WINDOW_MINUTES` / `_THRESHOLD` | 10 dk / 5 | Cihazların gerçek arıza/okuma hatası oranı |
| `WEIGHT_TOLERANCE_FRACTION` | %10 | Ürün ambalaj/dolum varyansının gerçek dağılımı |
| `RISK_THRESHOLD_HIGH` / `_MEDIUM` | 60 / 25 | Gerçek false-positive/false-negative oranları gözlemlenerek |
| `EVENT_STORE_MAX_RETRY_ATTEMPTS` | 5 | Gerçek eşzamanlı yazma yoğunluğu (şu an SADECE test/tahminle seçildi) |

## 4. Donanım Entegrasyon Noktaları (Kodlanmadı, Veri Bekliyor)

Bu kod tabanı MANTIK katmanıdır — hiçbir fiziksel donanımla test
EDİLMEDİ:

- **Barkod/QR yazıcı:** `DigitalLink::buildElementString()` veri üretir,
  fiziksel sembolü basmaz (bkz. `PRODUCTION_CHECKLIST.md` madde 4).
- **Paketleme istasyonu tartısı:** `WeightReconciler::check()` bir
  donanım sinyali (ölçülen gram) bekler — bu sinyal HENÜZ hiçbir
  gerçek tartıdan gelmiyor, demo/test'te elle veriliyor.
- **El terminali tarayıcı:** `ScanSequenceGuard::recordLocationScan()`
  gerçek bir el terminali uygulamasından çağrılmayı bekliyor.
- **GS1 Digital Link Resolver:** Bölüm 1'de anlatılan servis HENÜZ İNŞA
  EDİLMEDİ, ayrı bir proje.

---

## 5. Audit & Security Sign-off Checklist (BOŞ — İkinci İnsan Gözü İçin)

Bu bölüm KASITLI OLARAK boş bırakılmıştır. Claude (bu sistemi yazan AI),
kendi kendini denetleyemez — aşağıdaki her satır, sistemi hiç görmemiş
BAĞIMSIZ bir insan mühendis/güvenlik uzmanı tarafından doldurulmalıdır.

| # | Kontrol Maddesi | İncelendi mi? | İnceleyen | Tarih | Not |
|---|---|---|---|---|---|
| 1 | `SECURITY_REVIEW.md` Bölüm 3 (dört-göz kararı: A/B/C) | ☐ | | | |
| 2 | `SECURITY_REVIEW.md` Bölüm 5 (hash-chain çatallanma düzeltmesi) bağımsız olarak doğrulandı mı | ☐ | | | |
| 3 | `docs/pr-review/` altındaki 4 modülün TAMAMI okundu mu | ☐ | | | |
| 4 | Tüm eşik değerleri (Bölüm 3, yukarıda) gerçek/simüle saha verisiyle karşılaştırıldı mı | ☐ | | | |
| 5 | `phpstan.neon` (Level 8) gerçek bir ortamda çalıştırılıp sıfır hata verdi mi | ☐ | | | |
| 6 | Gerçek PHPUnit'e geçiş yapıldı mı (bkz. `SECURITY_REVIEW.md` Bölüm 6) | ☐ | | | |
| 7 | `PRODUCTION_CHECKLIST.md`'nin TAMAMI tamamlandı mı | ☐ | | | |
| 8 | Bağımsız bir penetrasyon testi/kod denetimi yapıldı mı | ☐ | | | |

**Bu tablo TAMAMEN doldurulmadan proje üretime alınmamalıdır.**

```
Baş Denetleyen:          _________________________  Tarih: _______
İkinci Onay (varsa):     _________________________  Tarih: _______
```
