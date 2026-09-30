# Güvenlik-Kritik Modüller — İnsan İnceleme Endeksi

**DÜRÜST NOT:** Bu proje şu an gerçek bir GitHub deposunda değil (yerel
`git init` ile versiyon kontrolüne alındı, uzak bir repo/PR sistemi yok).
Bu yüzden "ayrı PR'lar" isteğinin literal karşılığını veremiyorum — bunun
yerine, her güvenlik-kritik modülü BAĞIMSIZ, odaklı bir inceleme
dosyasına ayırdım. Gerçek bir GitHub deposuna taşındığında, her biri
doğrudan bir PR açıklamasına dönüştürülebilir (dosya adları ve içerik
bilinçli olarak bu amaç için yazıldı).

**Bu belge, insan incelemesinin YERİNE geçmez** — sadece incelemeyi
kolaylaştırmak için hazırlanmıştır. Aşağıdaki 4 modülün HİÇBİRİ ikinci
bir insan mühendis tarafından gözden geçirilmeden üretime alınmamalıdır.

## İncelenmesi Gereken Modüller

| # | Modül | Dosya | Risk Yüzeyi | Detay |
|---|---|---|---|---|
| 1 | Raf/Ürün Sıralı Okutma | `src/Ledger/ScanSequenceGuard.php` | Fiziksel varlığı KANITLAMAZ, sadece mantıksal sıra kontrolü | [01_scan_sequence_guard.md](01_scan_sequence_guard.md) |
| 2 | Hasarlı Ürün İmha | `src/Ledger/DamagedItemService.php` | Dört-göz kuralını UYGULAMAZ (tek süpervizör yeterli) | [02_damaged_item_service.md](02_damaged_item_service.md) |
| 3 | Lot Yanlış Beyan Düzeltme | `src/Ledger/LotTraceService.php` (`reclassifyLot`) | Stok değerini kalıcı olarak değiştirir, geri alınamaz | [03_lot_misdeclaration.md](03_lot_misdeclaration.md) |
| 4 | Zimmet Zaman Aşımı İzolasyonu | `src/Ledger/DwellTimeGuard.php` | Personeli otomatik askıya alır — yanlış pozitif riski var | [04_dwell_time_isolation.md](04_dwell_time_isolation.md) |

Her dosya şu formatı takip eder: **Ne yapar → Hangi varsayımlara
dayanır → Bilinen sınırlar → İncelemeci için sorular.**
