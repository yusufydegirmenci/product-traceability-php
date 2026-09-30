# İnceleme Modülü 4 — Zimmet Zaman Aşımı İzolasyonu (Dwell-Time Guard)

**Dosya:** `src/Ledger/DwellTimeGuard.php`
**Test:** `tests/Integration/DwellTimeGuardTest.php` (5 test), `tests/Integration/ShiftHandoverLockoutTest.php` (4 test)
**İlişkili tablo:** `custody_holds`, `actors.suspended_at`

## Ne Yapar
Bir ürün raftan alınıp (`pickItem`) belirli bir süre (varsayılan 15 dk,
`config/warehouse.php`) içinde koliye/rafa dönmezse (`releaseItem`
çağrılmazsa), personeli otomatik olarak askıya alır — yeni işlem
yapmasını ve vardiyadan çıkmasını (override olmadan) engeller.

## Hangi Varsayımlara Dayanır
- Bir ürünün "koliye girdi" sayılması, `PackageService::addEntityToPackage()`
  çağrılmasına bağlıdır — eğer bir iş akışı bu metodu ATLAYIP ürünü
  başka bir yoldan işlerse (örn. doğrudan bir SQL güncellemesi, ya da
  gelecekte eklenecek başka bir "ürünü işle" yolu), zimmet kaydı ASLA
  kapanmaz ve personel HAKSIZ yere askıya alınabilir.
- 15 dakika, "normal bir toplama-paketleme süresi" olarak varsayıldı —
  GERÇEK SAHA VERİSİYLE DOĞRULANMADI. Yoğun/karmaşık siparişlerde
  (örn. 50 kalemlik bir sipariş) bu süre yetersiz kalabilir.

## Bilinen Sınırlar — YANLIŞ POZİTİF RİSKİ (1 DÜZELTİLDİ)
Bu, 4 modül arasında **en yüksek yanlış-pozitif riski** taşıyandır:
- Personel mola/dikkat dağınıklığı yaşarsa (meşru bir sebeple) askıya
  alınır — kovulmaz, sadece süpervizör inceleyene kadar bekler, ama
  bu GEREKSİZ operasyonel sürtünme yaratabilir.
- ~~`releaseItem()` sadece `addEntityToPackage()`'da çağrılıyor, "rafa
  iade" için çağrı noktası yok~~ **DÜZELTİLDİ:** bu belge yazılırken
  test edilip DOĞRULANDI — `DwellTimeGuard::returnToShelf()` adlı,
  açık bir metot eklendi. Test:
  `tests/Integration/DwellTimeGuardTest.php::testReturnToShelfClosesCustodyWithDistinctReason`.

## İncelemeci İçin Sorular
1. 15 dakikalık pencere sabit mi kalmalı, yoksa sipariş büyüklüğüne
   (kalem sayısına) göre DİNAMİK mi olmalı?
2. Bir personel YANLIŞLIKLA askıya alındığında, bunu düzeltmek için
   `clearSuspension()` yeterli mi, yoksa otomatik bir "ilk seferde
   uyar, ikinci seferde askıya al" kademeli bir yaklaşım mı olmalı
   (RiskScorer'ın "ilk oluşum indirimi" felsefesiyle tutarlı olurdu)?
3. `returnToShelf()` şu an el terminalinin GERÇEKTEN rafa iade
   olayını bu metoda bağladığı varsayılıyor — saha entegrasyonunda bu
   çağrı noktası fiilen eklenmeli.
