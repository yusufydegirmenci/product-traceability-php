# İnceleme Modülü 3 — MISDECLARED Lot Yaşam Döngüsü (Lot Yanlış Beyan Düzeltme)

**Dosya:** `src/Ledger/LotTraceService.php` (`reclassifyLot()`, `getAffectedShipmentsByLot()`)
**Test:** `tests/Integration/LotReclassificationTest.php` (6 test)
**İlişkili tablo:** `recall_notices`

## Ne Yapar
Yanlış ürün beyanıyla girmiş bir lot'u GEÇMİŞİ SİLMEDEN düzeltir: eski
lot `MISDECLARED` durumuna (kalan miktar 0), yeni bir lot doğru ürün
için açılır, ve o ana kadar ESKİ lot'tan kargolanmış tüm siparişler
`recall_notices` tablosunda `AWAITING_RECALL_NOTICE` olarak işaretlenir.

## Hangi Varsayımlara Dayanır
- `quantity_remaining`'in TAMAMININ yanlış beyan edilen ürüne ait
  olduğu varsayılır — yani lot'un BİR KISMI doğru, bir kısmı yanlış
  beyan edilmiş olamaz (ya hep ya hiç).
- Zaten kargolanmış birimlerin FİZİKSEL olarak geri getirilebileceği
  varsayılmaz — bu metod sadece "kime haber verilmeli" listesini
  üretir, fiziksel geri çağırmayı YÖNETMEZ.

## Bilinen Sınırlar (DÜZELTİLDİ)
- ~~`MISDECLARED` durumu GERİ ALINABİLİRDİ~~ **DÜZELTİLDİ:** Bu belge
  yazılırken test edilip DOĞRULANDI (gerçek bir boşluktu, varsayım
  değil) — `EntityRepository::transitionStatus()` artık `MISDECLARED`'ı
  `DESTROYED` ile AYNI şekilde terminal/geri döndürülemez kabul ediyor.
  Test: `tests/Integration/LotReclassificationTest.php::testMisdeclaredStatusIsPermanentLikeDestroyed`.
- `recall_notices.status` şu an SADECE `AWAITING_RECALL_NOTICE`
  değerini alıyor — bu bildirimin GERÇEKTEN müşteriye/bayiye
  iletildiğini işaretleyecek bir "sonraki adım" (örn. `NOTIFIED`,
  `RESOLVED`) YOK. Bu, kasıtlı olarak bu turun kapsamı dışında
  bırakıldı.

## İncelemeci İçin Sorular
1. `recall_notices` için bir durum makinesi (NOTIFIED → CUSTOMER_CONTACTED
   → RESOLVED gibi) ve bunu yöneten bir servis gerekiyor mu, yoksa bu
   harici bir CRM/müşteri hizmetleri sistemine mi bırakılmalı?
2. Bir lot'un SADECE BİR KISMININ yanlış beyan edilmiş olabileceği
   senaryo (örn. karışık bir palet) gerçekte olası mı? Eğer öyleyse,
   `reclassifyLot()`'un "hepsi ya da hiçbiri" varsayımı yetersiz kalır.
