# İnceleme Modülü 1 — ScanSequenceGuard (Raf/Ürün Sıralı Okutma)

**Dosya:** `src/Ledger/ScanSequenceGuard.php`
**Test:** `tests/Integration/ScanSequenceGuardTest.php` (4 test)
**Bağımlı şema:** `device_scan_sequence` tablosu

## Ne Yapar
Bir el terminalinin (device_id), ürün barkodu okutmadan ÖNCE bir raf/
lokasyon barkodu okutup okutmadığını, ve bu okutmanın YAKIN ZAMANLI
(varsayılan 120 sn, `config/warehouse.php`) olup olmadığını kontrol eder.

## Hangi Varsayımlara Dayanır
- Her el terminalinin (device_id) TEK bir kişi tarafından, TEK bir anda
  kullanıldığı varsayılır — aynı cihazı paylaşan iki kişi olursa
  (örn. vardiya değişiminde cihaz elden ele geçerse), ikinci kişinin
  okutması BİRİNCİ kişinin raf okutmasını "meşru" gibi kullanabilir.
- 120 saniyelik pencere, "bir kişinin rafın önünden ürünü alıp
  okutmasına yetecek kadar" olarak seçildi — GERÇEK VERİYLE
  DOĞRULANMADI.

## Bilinen Sınırlar (Kod İçinde de Belgelendi)
- Personelin GERÇEKTEN o rafın FİZİKSEL önünde durduğunu KANITLAMAZ —
  biri raf barkodunu ezbere/uzaktan da okutabilir. Bu, RFID/konum
  doğrulaması OLMADAN ulaşılabilecek en yüksek güvence seviyesidir.
- `expectedLocationId` kontrolü opsiyoneldir — çağıran kod bunu
  vermezse, HERHANGİ bir raf okutması "doğru" sayılır.

## İncelemeci İçin Sorular
1. 120 saniyelik pencere, gerçek depo yürüme mesafeleri için makul mü?
2. Cihaz paylaşımı senaryosu (vardiya değişiminde) operasyonel olarak
   ne sıklıkla oluyor — bunun için ek bir kontrol (örn. cihaz + aktör
   ikilisi) gerekli mi?
3. `expectedLocationId` her çağrı noktasında GERÇEKTEN veriliyor mu,
   yoksa bazı yerlerde atlanıp bu kontrolü etkisiz mi bırakıyor?
