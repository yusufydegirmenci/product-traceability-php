# İnceleme Modülü 2 — DamagedItemService (Hasarlı Ürün İmha)

**Dosya:** `src/Ledger/DamagedItemService.php`
**Test:** `tests/Integration/DamagedItemServiceTest.php` (3 test)
**İlişkili:** `src/Ledger/DestroyService.php` (dört-göz deseni burada VAR ama bu sınıfta YOK)

## Ne Yapar
Hasarlı işaretlenen bir ürünü `DAMAGED_HOLD` durumuna alır; stoktan
KESİN düşürülmesi (imha) için süpervizör rolü + zorunlu "Fiziki Hurda
Tutanak Ref" ister.

## ⚠️ BİLİNEN, KASITLI OLARAK GEVŞETİLMİŞ KURAL (Seçenek A artık MEVCUT)
`DestroyService::confirmDestroy()`, imha için İKİ FARKLI kişinin (talep
eden + onaylayan) olmasını zorunlu kılar (dört-göz). `scrapWithTutanak()`
ise SADECE TEK bir süpervizörün rolünü kontrol eder — hâlâ kod tabanında
DURUYOR (geriye dönük uyumluluk için).

**Güncelleme:** `requestScrapViaFourEyes()` / `confirmScrapViaFourEyes()`
eklendi — `DestroyService`'in dört-göz akışını kullanan, test edilmiş bir
ALTERNATİF. İkisi de kod tabanında yan yana duruyor; insan mühendis
hangisinin kalıcı olacağına `SECURITY_REVIEW.md` Bölüm 3'te karar vermeli.

## İncelemeci İçin Sorular
1. **`scrapWithTutanak()` mi yoksa `requestScrapViaFourEyes()`/`confirmScrapViaFourEyes()`
   mi kalıcı olmalı — yoksa hacme göre ikisi de mi kalmalı (Seçenek C)?**
2. Hasarlı ürünün fiziksel olarak GERÇEKTEN hasarlı olduğunu doğrulayan
   bir ikinci kontrol (örn. fotoğraf kanıtı) eklenmeli mi?
3. `markDamaged()` şu an sadece bilgi amaçlı bir risk_events kaydı
   düşüyor, rol kontrolü yapmıyor — bu kasıtlı mı (hızlı raporlama
   için) yoksa bir boşluk mu?
