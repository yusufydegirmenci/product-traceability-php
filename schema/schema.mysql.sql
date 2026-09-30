-- Product Traceability System
-- Production schema (MySQL 8.0+). Adapt engine/charset settings to match
-- your existing depo stok modülü database if integrating into the same schema.

SET NAMES utf8mb4;

-- ── Kimlik katmanı ──────────────────────────────────────────────────────
CREATE TABLE products (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gtin            CHAR(14) NOT NULL UNIQUE,
    name            VARCHAR(191) NOT NULL,
    category        VARCHAR(64) NULL,
    tracking_mode   ENUM('unit','lot') NOT NULL DEFAULT 'lot',
    return_window_days INT UNSIGNED NOT NULL DEFAULT 14 COMMENT 'bu günden sonra gelen iade süpervizör onayı ister',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE trackable_entities (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id          INT UNSIGNED NOT NULL,
    entity_code         VARCHAR(128) NOT NULL UNIQUE COMMENT 'GS1 element string / Digital Link value printed on the label',
    entity_type         ENUM('unit','lot') NOT NULL,
    lot_number          VARCHAR(32) NOT NULL,
    lot_position        INT UNSIGNED NULL COMMENT 'SADECE İÇ kullanım: bu partideki 1..N sırası — dışa hiç basılmaz, parti sayımı içindir',
    serial_number       VARCHAR(32) NULL COMMENT 'DIŞA basılan seri — kasıtlı olarak rastgele, sıralı DEĞİL (tahmin edilebilir olmasın diye)',
    quantity_total      INT UNSIGNED NOT NULL DEFAULT 1,
    quantity_remaining  INT UNSIGNED NOT NULL DEFAULT 1,
    production_date     DATE NULL,
    expiry_date         DATE NULL,
    status              ENUM('CREATED','IN_WAREHOUSE','PACKED','SHIPPED','DELIVERED','RETURNED','QUARANTINED','DESTROYED')
                        NOT NULL DEFAULT 'CREATED',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id),
    INDEX idx_entity_lot (lot_number),
    INDEX idx_entity_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ürün bazlı ağırlık profili: bir iade geldiğinde TEK ürünün ağırlığının
-- makul olup olmadığını belirler. check_type ürünün doğasına göre seçilir —
-- bkz. sample_weight_profiles.csv (örnek ürünlerin önerilen sınıflandırması).
CREATE TABLE product_weight_profiles (
    product_id      INT UNSIGNED PRIMARY KEY,
    check_type      ENUM('consumable_range','fixed_match','exempt') NOT NULL,
    full_weight_g   DECIMAL(10,2) NULL COMMENT 'sıvı/krem/toz ürünlerde dolu ağırlık; sabit ürünlerde referans ağırlık',
    empty_weight_g  DECIMAL(10,2) NULL COMMENT 'sadece consumable_range için: boş kutu/şişe ağırlığı',
    unit_count      INT UNSIGNED NULL COMMENT 'adet bazlı ürünlerde (ped vb.) paket içi adet sayısı',
    tolerance_pct   DECIMAL(5,2) NOT NULL DEFAULT 5.00,
    FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── İDDİA vs SİSTEM OLAYI vs KARGO OLAYI — asla birbirine karıştırılmaz ────
-- Müşteri/bayi "böyle oldu" diyorsa bu bir İDDİA'dır, gerçek olarak kaydedilmez.
CREATE TABLE customer_claims (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_id           BIGINT UNSIGNED NULL COMMENT 'biliniyorsa; çoğu zaman iddia anında henüz bilinmeyebilir',
    order_ref           VARCHAR(64) NULL,
    claim_type          ENUM('missing_product','wrong_product','damaged_package','not_delivered','defective_health','other') NOT NULL,
    claim_text          TEXT NOT NULL,
    claimed_by_actor_id INT UNSIGNED NULL,
    trust_level         ENUM('customer_input') NOT NULL DEFAULT 'customer_input',
    status              ENUM('open','under_investigation','resolved') NOT NULL DEFAULT 'open',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (entity_id) REFERENCES trackable_entities(id),
    FOREIGN KEY (claimed_by_actor_id) REFERENCES actors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kargo firmasının bildirdiği her olay; event_time (kargonun dediği an) ile
-- received_at (bizim ne zaman öğrendiğimiz) KASITLI OLARAK ayrı tutulur —
-- "ileri tarihli kargo" gibi şikayetler bu ayrımın eksikliğinden çıkıyor.
CREATE TABLE carrier_events (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_id           BIGINT UNSIGNED NULL,
    order_ref           VARCHAR(64) NULL,
    carrier_name        VARCHAR(64) NOT NULL,
    carrier_event_code  VARCHAR(64) NOT NULL COMMENT 'örn. picked_up, in_transit, delivered, delivery_failed',
    event_time          DATETIME NOT NULL COMMENT 'kargo firmasının bildirdiği zaman — sunucu saatiyle karşılaştırılmalı',
    received_at         DATETIME NOT NULL COMMENT 'bizim webhook/API''den bu veriyi aldığımız an',
    proof_type          ENUM('otp','signature','photo','gps','none') NOT NULL DEFAULT 'none' COMMENT 'teslimat ispatı türü — sektör standardı, anlaşmazlıkta belirleyici',
    proof_reference     VARCHAR(255) NULL COMMENT 'OTP kodu/imza dosyası/fotoğraf URL/GPS koordinatı',
    raw_payload         JSON NULL COMMENT 'kargo API''sinin ham cevabı — ihtilaf çıkarsa geriye dönük kanıt',
    trust_level         ENUM('carrier_api') NOT NULL DEFAULT 'carrier_api',
    disputed            TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'event_time / received_at farkı anormalse otomatik işaretlenir',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (entity_id) REFERENCES trackable_entities(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bir iddia, ilgili sistem event'leri ve kargo event'leriyle karşılaştırılıp
-- SONUÇ üretilir. Sonuç asla "hırsızlık/suistimal" gibi kesin bir suçlama
-- değildir — investigator (insan) hangi sonuca vardığını yazar.
CREATE TABLE investigation_results (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    claim_id                BIGINT UNSIGNED NOT NULL,
    linked_event_ids        JSON NULL COMMENT 'epcis_events.id listesi',
    linked_carrier_event_ids JSON NULL,
    conclusion              ENUM('verified_claim','unverified_claim','conflicting_evidence','insufficient_evidence') NOT NULL,
    investigated_by_actor_id INT UNSIGNED NOT NULL COMMENT 'insan — sistem otomatik sonuç üretemez',
    investigated_at         DATETIME NOT NULL,
    notes                   TEXT NULL,
    high_impact             TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'maddi/itibari sonucu büyük kararlar İKİNCİ bir onay gerektirir — tek bir yozlaşmış incelemecinin karar dayatmasını önler',
    second_reviewer_actor_id INT UNSIGNED NULL COMMENT 'high_impact ise ZORUNLU, ilk incelemeciden FARKLI biri olmalı',
    second_reviewed_at       DATETIME NULL,
    FOREIGN KEY (claim_id) REFERENCES customer_claims(id),
    FOREIGN KEY (investigated_by_actor_id) REFERENCES actors(id),
    FOREIGN KEY (second_reviewer_actor_id) REFERENCES actors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kayıp/eksik ürün şikayeti sonrası yeniden gönderilen ürün ile orijinal ürün
-- BAĞLANTILI tutulur (bkz. Bigblue "never refund a reshipped order twice"
-- pratiği) — orijinal sonradan geri bulunursa çift tazminat riski önlenir.
CREATE TABLE reshipments (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    original_entity_id      BIGINT UNSIGNED NOT NULL,
    replacement_entity_id   BIGINT UNSIGNED NOT NULL,
    claim_id                BIGINT UNSIGNED NULL,
    reason                  VARCHAR(255) NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (original_entity_id) REFERENCES trackable_entities(id),
    FOREIGN KEY (replacement_entity_id) REFERENCES trackable_entities(id),
    FOREIGN KEY (claim_id) REFERENCES customer_claims(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE refunds (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    return_event_id     BIGINT UNSIGNED NOT NULL COMMENT 'epcis_events.id — iadenin kabul edildiği event',
    entity_id           BIGINT UNSIGNED NOT NULL,
    amount              DECIMAL(10,2) NOT NULL,
    status              ENUM('pending','paid','rejected') NOT NULL DEFAULT 'pending',
    double_compensation_review TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'bu ürün daha önce kayıp sayılıp yenisi gönderilmişti — ödeme öncesi insan kontrolü şart',
    requested_at        DATETIME NOT NULL,
    paid_at             DATETIME NULL,
    paid_by_actor_id    INT UNSIGNED NULL,
    FOREIGN KEY (return_event_id) REFERENCES epcis_events(id),
    FOREIGN KEY (entity_id) REFERENCES trackable_entities(id),
    FOREIGN KEY (paid_by_actor_id) REFERENCES actors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Müşteri/bayiye giden bildirimler — "siparişiniz onaylandı" tarzı işlemsel
-- mesajlar. Kasıtlı olarak PAZARLAMA içeriği taşımaz (bkz. README —
-- Türkiye'de İYS onayı sadece ticari mesajlar için gerekir, işlemsel
-- bildirimler genelde muaftır — ama şablonlar asla tanıtım/kampanya
-- metni içermemeli, yoksa bu muafiyet geçersiz kalabilir).
-- Bir KOLİ (fiziksel kutu/paket), bir veya birden fazla siparişi içerebilir
-- (aynı adrese giden 2 sipariş tek koliye konduğunda). SSCC = GS1'in
-- lojistik birimler için tanımladığı standart kod — ürün kodundan (GTIN)
-- TAMAMEN AYRI bir kimlik.
CREATE TABLE packages (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sscc            VARCHAR(18) NOT NULL UNIQUE,
    location_id     INT UNSIGNED NULL,
    status          ENUM('open','sealed','shipped','voided') NOT NULL DEFAULT 'open',
    opened_by_actor_id INT UNSIGNED NOT NULL,
    opened_at       DATETIME NOT NULL,
    sealed_at       DATETIME NULL,
    seal_serial     VARCHAR(16) NULL COMMENT 'kurcalamaya karşı bant/mühür üzerine basılan seri no',
    label_verified_at DATETIME NULL COMMENT 'etiket yapıştırıldıktan SONRA ikinci okutmanın zamanı',
    spot_checked_by_actor_id INT UNSIGNED NULL COMMENT 'bağımsız rastgele yeniden-açma kontrolünü yapan kişi — PAKETLEYENLE AYNI OLAMAZ',
    spot_checked_at DATETIME NULL,
    spot_check_result ENUM('pass','fail') NULL,
    voided_at       DATETIME NULL,
    voided_by_actor_id INT UNSIGNED NULL,
    void_reason     VARCHAR(500) NULL,
    FOREIGN KEY (location_id) REFERENCES locations(id),
    FOREIGN KEY (opened_by_actor_id) REFERENCES actors(id),
    FOREIGN KEY (spot_checked_by_actor_id) REFERENCES actors(id),
    FOREIGN KEY (voided_by_actor_id) REFERENCES actors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- v1.1: her mühürleme olayının KALICI tarihçesi — bir mühür iptal
-- edildiğinde bile burada sonsuza dek "geçersiz" olarak durur.
CREATE TABLE package_seal_history (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    package_id              BIGINT UNSIGNED NOT NULL,
    seal_serial             VARCHAR(16) NOT NULL,
    sealed_at               DATETIME NOT NULL,
    invalidated_at          DATETIME NULL,
    invalidated_by_actor_id INT UNSIGNED NULL,
    void_reason             VARCHAR(500) NULL,
    FOREIGN KEY (package_id) REFERENCES packages(id),
    FOREIGN KEY (invalidated_by_actor_id) REFERENCES actors(id),
    INDEX idx_seal_history_serial (seal_serial)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bir koliye bağlı sipariş referansları — BİRDEN FAZLA olabilir (ortak koli).
CREATE TABLE package_orders (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    package_id  BIGINT UNSIGNED NOT NULL,
    order_ref   VARCHAR(64) NOT NULL,
    FOREIGN KEY (package_id) REFERENCES packages(id),
    UNIQUE KEY uniq_package_order (package_id, order_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bir koliye fiilen eklenen ürünler — otomatik içerik etiketinin/manifestonun kaynağı.
CREATE TABLE package_contents (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    package_id  BIGINT UNSIGNED NOT NULL,
    entity_id   BIGINT UNSIGNED NOT NULL,
    order_ref   VARCHAR(64) NOT NULL,
    added_at    DATETIME NOT NULL,
    FOREIGN KEY (package_id) REFERENCES packages(id),
    FOREIGN KEY (entity_id) REFERENCES trackable_entities(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Depolar arası (özellikle yurt dışı) transfer — kaynak/hedef depo,
-- gümrük beyanname referansı ve aşama (hazırlanıyor→çıktı→gümrükte→teslim
-- alındı) tek kayıtta izlenir. Varışta miktar mutabakatı, uluslararası
-- taşımada en sık kaybın olduğu noktayı (çıkış-varış arası) yakalar.
CREATE TABLE warehouse_transfers (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_location_id      INT UNSIGNED NOT NULL,
    destination_location_id INT UNSIGNED NOT NULL,
    customs_declaration_ref VARCHAR(64) NULL,
    requires_customs        TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'aynı gümrük bölgesi içi transferlerde otomatik 0 olur',
    declared_quantity       INT UNSIGNED NOT NULL,
    status                  ENUM('preparing','exported','customs_cleared','received') NOT NULL DEFAULT 'preparing',
    initiated_by_actor_id    INT UNSIGNED NOT NULL,
    initiated_at            DATETIME NOT NULL,
    exported_at             DATETIME NULL,
    customs_cleared_at      DATETIME NULL,
    received_at             DATETIME NULL,
    received_by_actor_id    INT UNSIGNED NULL,
    actual_received_count   INT UNSIGNED NULL,
    FOREIGN KEY (source_location_id) REFERENCES locations(id),
    FOREIGN KEY (destination_location_id) REFERENCES locations(id),
    FOREIGN KEY (initiated_by_actor_id) REFERENCES actors(id),
    FOREIGN KEY (received_by_actor_id) REFERENCES actors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE transfer_contents (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transfer_id BIGINT UNSIGNED NOT NULL,
    entity_id   BIGINT UNSIGNED NOT NULL,
    FOREIGN KEY (transfer_id) REFERENCES warehouse_transfers(id),
    FOREIGN KEY (entity_id) REFERENCES trackable_entities(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Depoda fiziksel olarak sessizce (hiçbir event tetiklenmeden) ürün
-- kaybının TEK yakalama yolu: düzenli, BAĞIMSIZ kişi tarafından yapılan
-- fiziksel sayım. Sistemin "olması gereken" miktarı (event-sourcing'den
-- türetilir) ile fiilen sayılan miktar karşılaştırılır.
CREATE TABLE cycle_counts (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    location_id             INT UNSIGNED NOT NULL,
    product_id              INT UNSIGNED NOT NULL,
    expected_quantity       INT NOT NULL,
    counted_quantity        INT NOT NULL,
    variance                INT NOT NULL,
    counted_by_actor_id     INT UNSIGNED NOT NULL,
    primary_custodian_actor_id INT UNSIGNED NULL COMMENT 'bu bölgeden normalde sorumlu kişi — sayımı yapan bu kişiyle AYNIYSA bağımsızlık ilkesi ihlal edilir',
    counted_at              DATETIME NOT NULL,
    FOREIGN KEY (location_id) REFERENCES locations(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (counted_by_actor_id) REFERENCES actors(id),
    FOREIGN KEY (primary_custodian_actor_id) REFERENCES actors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- YOĞUN İŞ GÜNÜNDE CİHAZ ARIZASI vs KÖTÜ NİYET AYRIMI: bir okuyucu/yazıcı
-- kısa sürede anormal sayıda bozuk/tutarsız okuma üretirse, bu muhtemelen
-- DONANIM arızasıdır — ilgili personeli suçlamak yerine cihazı işaretler.
-- Bu ayrım olmadan, bir kaos gününde (yoğunluk + arıza) gerçek bir
-- sabotaj, donanım gürültüsünün içinde kolayca kaybolabilir.
CREATE TABLE device_fault_events (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id       INT UNSIGNED NOT NULL,
    anomaly_type    VARCHAR(64) NOT NULL COMMENT 'malformed_code|unexpected_entity|checksum_fail',
    reported_by_actor_id INT UNSIGNED NULL,
    occurred_at     DATETIME NOT NULL,
    FOREIGN KEY (device_id) REFERENCES devices(id),
    FOREIGN KEY (reported_by_actor_id) REFERENCES actors(id),
    INDEX idx_device_fault_time (device_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- RT8.5 DARBE 3 (Anchor Proof) — verifyKeyLineage() sadece TEK BİR entity'nin
-- kendi zincirindeki key_version sırasının geriye gitmediğini kontrol eder.
-- Ama bir saldırgan, TÜM SİSTEM ÇAPINDA çoktan emekliye ayrılmış bir anahtarı
-- kullanıp, o anahtarın HİÇ dokunulmamış bir entity'nin zincirine "ilk ve tek"
-- event olarak eklerse, o entity'nin YEREL sırası hiç bozulmaz (1,1,1... hep
-- aynı) — key_rotation_log, anahtarın SİSTEM GENELİNDE ne zaman emekliye
-- ayrıldığını bağımsız, sabit bir referans noktası (immutable anchor) olarak
-- tutar; bu, tek bir entity'nin göreceli sırasından bağımsızdır.
CREATE TABLE key_rotation_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    key_version     INT UNSIGNED NOT NULL UNIQUE,
    activated_at    DATETIME NOT NULL,
    retired_at      DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE customer_messages (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id        INT UNSIGNED NOT NULL,
    entity_id       BIGINT UNSIGNED NULL,
    order_ref       VARCHAR(64) NULL,
    message_type    VARCHAR(64) NOT NULL COMMENT 'payment_confirmed|sent_to_warehouse|packed|shipped|delivered|return_accepted|return_rejected|refund_paid|claim_received|investigation_resolved',
    channel         VARCHAR(32) NOT NULL COMMENT 'sms|email|backoffice_inbox',
    message_text    VARCHAR(500) NOT NULL,
    status          ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
    created_at      DATETIME NOT NULL,
    sent_at         DATETIME NULL,
    FOREIGN KEY (actor_id) REFERENCES actors(id),
    FOREIGN KEY (entity_id) REFERENCES trackable_entities(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sistemin tespit ettiği ama henüz kimsenin bakmadığı her durum için tek
-- kuyruk. Rapor/risk/iade servislerinin ürettiği sinyaller burada toplanıp
-- ilgili role dağıtılır; onaylanmazsa belirli sürede üst role eskalasyon olur.
CREATE TABLE notifications (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type                VARCHAR(64) NOT NULL COMMENT 'overdue_refund|high_risk_actor|disputed_carrier_event|duplicate_scan|unresolved_investigation',
    severity            ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
    target_role         VARCHAR(32) NOT NULL COMMENT 'supervisor|accounting|manager',
    related_id          BIGINT UNSIGNED NULL COMMENT 'ilgili kaydın id''si (refund/actor/carrier_event/...)',
    message             VARCHAR(500) NOT NULL,
    channel             VARCHAR(32) NOT NULL DEFAULT 'console' COMMENT 'production''da email/sms/slack olur',
    created_at          DATETIME NOT NULL,
    acknowledged_at     DATETIME NULL,
    acknowledged_by_actor_id INT UNSIGNED NULL,
    escalated_at        DATETIME NULL,
    escalated_to_role   VARCHAR(32) NULL,
    FOREIGN KEY (acknowledged_by_actor_id) REFERENCES actors(id),
    INDEX idx_notif_type_related (type, related_id, acknowledged_at) COMMENT 'MÜFETTİŞ BULGUSU (200 gün simülasyonu): alreadyOpen() her tarama döngüsünde bu üçlüyle sorgu atıyordu, hiç index yoktu — 200 günlük birikimde tam tablo taraması olurdu'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE locations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_country  CHAR(2) NOT NULL COMMENT 'TR, AZ, NL, ...',
    gln             VARCHAR(20) NULL COMMENT 'GS1 Global Location Number, if assigned',
    name            VARCHAR(191) NOT NULL,
    type            ENUM('warehouse','dealer','region') NOT NULL,
    customs_zone    VARCHAR(32) NULL COMMENT 'örn. EU, EAEU, independent — aynı bölge içi transferde gümrük adımı atlanabilir',
    transit_time_hours_estimate INT UNSIGNED NULL COMMENT 'bu depoya tipik sevkiyat süresi — kargo teyidi bekleme eşiğini buna göre ayarla'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- İmha GERİ ALINAMAZ olduğu için tek kişinin fat-finger hatasıyla yanlış
-- ürünü imha etmesini önlemek üzere iki farklı yetkili kişinin onayını
-- gerektirir ("dört göz" ilkesi — banka havalesi onayına benzer).
CREATE TABLE destroy_requests (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_id           BIGINT UNSIGNED NOT NULL,
    reason              VARCHAR(255) NOT NULL,
    requested_by_actor_id INT UNSIGNED NOT NULL,
    requested_at        DATETIME NOT NULL,
    confirmed_by_actor_id INT UNSIGNED NULL,
    confirmed_at        DATETIME NULL,
    status              ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
    FOREIGN KEY (entity_id) REFERENCES trackable_entities(id),
    FOREIGN KEY (requested_by_actor_id) REFERENCES actors(id),
    FOREIGN KEY (confirmed_by_actor_id) REFERENCES actors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tedarikçiden/üretimden "1000 adet geldi" diye beyan edilen miktar ile
-- sistemde gerçekte oluşturulan entity sayısının mutabakatı. Uyuşmazlık,
-- ya bir sayım hatasını ya da kasıtlı bir eksik-beyanı yakalar.
CREATE TABLE intake_reconciliations (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lot_number          VARCHAR(32) NOT NULL,
    product_id          INT UNSIGNED NOT NULL,
    declared_quantity   INT UNSIGNED NOT NULL COMMENT 'tedarikçi irsaliyesinde yazan miktar',
    actual_entity_count INT UNSIGNED NOT NULL COMMENT 'sistemde gerçekte oluşturulan kayıt sayısı',
    matched             TINYINT(1) NOT NULL,
    reconciled_by_actor_id INT UNSIGNED NOT NULL,
    reconciled_at       DATETIME NOT NULL,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (reconciled_by_actor_id) REFERENCES actors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE actors (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_country  CHAR(2) NOT NULL,
    actor_type      ENUM('staff','dealer','customer') NOT NULL,
    role            VARCHAR(32) NULL COMMENT 'sadece staff için: depo_gorevlisi|supervizor|muhasebe|yonetici — kritik işlemler için yetki kontrolünde kullanılır',
    name            VARCHAR(191) NOT NULL,
    external_ref    VARCHAR(64) NULL COMMENT 'ID in the existing CRM/backoffice, if applicable',
    beneficial_owner_ref VARCHAR(64) NULL COMMENT 'RT8.3 DURUM: Entity Splitting saldırısına karşı — vergi no/ortak banka hesabı/UBO referansı. Birden fazla dealer_id AYNI bu alana sahipse, aslında TEK bir gerçek kişi/holding demektir.',
    created_at      DATETIME NULL COMMENT 'yeni/şüpheli hızlı hareket eden hesap tespiti için (bkz. newDealerVelocityAnomaly)',
    suspended_at    DATETIME NULL COMMENT 'v1.2 AŞAMA 2: Dwell-Time Guard tarafından askıya alınmış personel',
    suspended_reason VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- v1.2 AŞAMA 1: yanlış beyanla girmiş bir lot'un GEÇMİŞİ SİLİNMEDEN düzeltilmesi
CREATE TABLE recall_notices (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lot_entity_id   BIGINT UNSIGNED NOT NULL,
    order_ref       VARCHAR(64) NOT NULL,
    dealer_actor_id INT UNSIGNED NULL,
    status          VARCHAR(32) NOT NULL DEFAULT 'AWAITING_RECALL_NOTICE',
    created_at      DATETIME NOT NULL,
    FOREIGN KEY (lot_entity_id) REFERENCES trackable_entities(id),
    FOREIGN KEY (dealer_actor_id) REFERENCES actors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- v1.2 AŞAMA 2/3: "picked" (raftan alındı, henüz koliye/rafa dönmedi)
-- durumdaki zimmet — dwell-time kontrolü ve vardiya çıkış kilidi ortak kaynağı.
CREATE TABLE custody_holds (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_id       BIGINT UNSIGNED NOT NULL,
    actor_id        INT UNSIGNED NOT NULL,
    picked_at       DATETIME NOT NULL,
    released_at     DATETIME NULL,
    release_reason  VARCHAR(255) NULL,
    FOREIGN KEY (entity_id) REFERENCES trackable_entities(id),
    FOREIGN KEY (actor_id) REFERENCES actors(id),
    INDEX idx_custody_actor_open (actor_id, released_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- v1.2 AŞAMA 4: bir cihazın SON raf/lokasyon barkodu okutma zamanı.
CREATE TABLE device_scan_sequence (
    device_id               INT UNSIGNED PRIMARY KEY,
    last_location_id        INT UNSIGNED NULL,
    last_location_scan_at   DATETIME NULL,
    FOREIGN KEY (device_id) REFERENCES devices(id),
    FOREIGN KEY (last_location_id) REFERENCES locations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kişisel/duygusal ilişki beyanı — bir süpervizör/yönetici, KENDİ ilişkili
-- olduğu birinin işlemini onaylayamaz/soruşturamaz (çıkar çatışması).
CREATE TABLE actor_relationships (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id_1          INT UNSIGNED NOT NULL,
    actor_id_2          INT UNSIGNED NOT NULL,
    relationship_type   VARCHAR(32) NOT NULL COMMENT 'romantic|family|other',
    declared_at         DATETIME NOT NULL,
    FOREIGN KEY (actor_id_1) REFERENCES actors(id),
    FOREIGN KEY (actor_id_2) REFERENCES actors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE devices (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_uid          VARCHAR(64) NOT NULL UNIQUE,
    tenant_country      CHAR(2) NOT NULL,
    cert_fingerprint    VARCHAR(128) NULL COMMENT 'zero-trust device attestation',
    last_seen_at        DATETIME NULL,
    locked_at           DATETIME NULL COMMENT 'müfettiş bulgusu: art arda cihaz arızası artık sadece bildirim değil, cihazı fiilen kilitler',
    locked_reason       VARCHAR(255) NULL,
    unlocked_by_actor_id INT UNSIGNED NULL,
    FOREIGN KEY (unlocked_by_actor_id) REFERENCES actors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Müfettiş bulgusu: vardiya devri sadece sözlü/kağıt üzerindeydi, hiç dijital
-- kaydı yoktu — devreden ve devralan artık açık kalemleri dijital onaylıyor.
CREATE TABLE shift_handovers (
    id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    location_id               INT UNSIGNED NOT NULL,
    outgoing_actor_id         INT UNSIGNED NOT NULL,
    incoming_actor_id         INT UNSIGNED NOT NULL,
    open_quarantine_count     INT NOT NULL,
    open_dlq_count            INT NOT NULL,
    open_notifications_count INT NOT NULL,
    notes                     VARCHAR(1000) NULL,
    outgoing_confirmed_at     DATETIME NULL,
    incoming_confirmed_at     DATETIME NULL,
    created_at                DATETIME NOT NULL,
    FOREIGN KEY (location_id) REFERENCES locations(id),
    FOREIGN KEY (outgoing_actor_id) REFERENCES actors(id),
    FOREIGN KEY (incoming_actor_id) REFERENCES actors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Olay katmanı: hash-chained, DB seviyesinde INSERT-only ────────────
CREATE TABLE epcis_events (
    id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_id                   BIGINT UNSIGNED NOT NULL,
    biz_step                    VARCHAR(32) NOT NULL COMMENT 'commissioning|packing|shipping|receiving|decommissioning|destroyed',
    disposition                 VARCHAR(32) NOT NULL COMMENT 'active|in_transit|sold|returned|recalled|destroyed',
    event_time                  DATETIME(6) NOT NULL,
    actor_id                    INT UNSIGNED NULL,
    device_id                   INT UNSIGNED NULL,
    read_point_location_id      INT UNSIGNED NULL,
    related_order_ref           VARCHAR(64) NULL,
    metadata                    JSON NULL,
    prev_hash                   CHAR(64) NOT NULL,
    event_hash                  CHAR(64) NOT NULL UNIQUE,
    key_version                 INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'RT8.4: HMAC anahtar rotasyon sürümü — verifyKeyLineage() ile geriye gitmediği doğrulanır',
    idempotency_key              CHAR(36) NULL UNIQUE COMMENT 'v1.1: el terminali ağ kopması/gecikmesinde aynı isteği tekrar gönderirse (retry storm), UUID v4 sayesinde İKİNCİ kez işlenmez',
    created_at                  DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    FOREIGN KEY (entity_id) REFERENCES trackable_entities(id),
    FOREIGN KEY (actor_id) REFERENCES actors(id),
    FOREIGN KEY (device_id) REFERENCES devices(id),
    FOREIGN KEY (read_point_location_id) REFERENCES locations(id),
    INDEX idx_events_entity (entity_id, id),
    -- KIDEMLI İNCELEME BULGUSU: aynı entity_id'ye eşzamanlı yazma,
    -- hash-chain'i ÇATALLAYABİLİR (ampirik olarak kanıtlandı — bkz.
    -- chain_race_test.php, 5 denemenin 3'ünde gerçekleşti). Bu UNIQUE
    -- kısıt, ikinci eşzamanlı yazmayı DB seviyesinde reddeder;
    -- EventStore::appendEvent() bunu yakalayıp retry eder.
    UNIQUE KEY idx_events_no_fork (entity_id, prev_hash),
    INDEX idx_events_time (event_time),
    INDEX idx_events_bizstep_loc_time (biz_step, read_point_location_id, event_time) COMMENT 'capacityDriftAnalysis()/systemWideAggregateDrift() gibi lokasyon+tarih toplu sorguları için — daha önce sadece event_time tekil indexliydi'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- harici bir gÃ¶zden geÃ§iren önerisi DURUM 3/5: bir tarama cihaz arızası/ağ kopması yüzünden
-- ANINDA işlenemezse, izole bir Dead Letter Queue'ya düşer — süpervizör
-- fiziksel kontrol sonrası manuel çözer. Aynı mekanizma offline senkronizasyon
-- kuyruğunu da temsil eder (yapısal olarak aynı durum: "hemen işlenemedi").
CREATE TABLE scan_exception_queue (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    raw_scanned_code    VARCHAR(255) NOT NULL,
    device_id           INT UNSIGNED NULL,
    location_id         INT UNSIGNED NULL,
    actor_id            INT UNSIGNED NULL,
    exception_type      VARCHAR(32) NOT NULL COMMENT 'device_malformed|network_offline|reconciliation_mismatch',
    submitted_at        DATETIME NOT NULL,
    status              ENUM('pending','resolved','discarded') NOT NULL DEFAULT 'pending',
    resolved_by_actor_id INT UNSIGNED NULL,
    resolved_at         DATETIME NULL,
    resolution_note     VARCHAR(500) NULL,
    FOREIGN KEY (device_id) REFERENCES devices(id),
    FOREIGN KEY (location_id) REFERENCES locations(id),
    FOREIGN KEY (actor_id) REFERENCES actors(id),
    FOREIGN KEY (resolved_by_actor_id) REFERENCES actors(id),
    INDEX idx_scan_queue_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DELIMITER $$

CREATE TRIGGER trg_epcis_events_no_update
BEFORE UPDATE ON epcis_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'epcis_events is append-only: correct mistakes with a new event, never edit history.';
END$$

CREATE TRIGGER trg_epcis_events_no_delete
BEFORE DELETE ON epcis_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'epcis_events is append-only: rows can never be deleted.';
END$$

DELIMITER ;

-- ── Güvenlik / operasyon katmanı ────────────────────────────────────────
CREATE TABLE printer_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id       INT UNSIGNED NOT NULL,
    entity_id       BIGINT UNSIGNED NULL,
    printed_at      DATETIME NOT NULL,
    label_count     INT UNSIGNED NOT NULL DEFAULT 1,
    FOREIGN KEY (device_id) REFERENCES devices(id),
    FOREIGN KEY (entity_id) REFERENCES trackable_entities(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE duplicate_scan_flags (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_id           BIGINT UNSIGNED NOT NULL,
    first_event_id      BIGINT UNSIGNED NOT NULL,
    second_event_id     BIGINT UNSIGNED NULL,
    actor_id            INT UNSIGNED NULL,
    flagged_at          DATETIME NOT NULL,
    resolved            TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (entity_id) REFERENCES trackable_entities(id),
    FOREIGN KEY (first_event_id) REFERENCES epcis_events(id),
    FOREIGN KEY (second_event_id) REFERENCES epcis_events(id),
    FOREIGN KEY (actor_id) REFERENCES actors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE packing_station_weights (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_ref           VARCHAR(64) NOT NULL,
    entity_id           BIGINT UNSIGNED NOT NULL,
    expected_weight_g   DECIMAL(10,2) NOT NULL,
    measured_weight_g   DECIMAL(10,2) NOT NULL,
    variance_pct        DECIMAL(6,2) NOT NULL,
    flagged             TINYINT(1) NOT NULL DEFAULT 0,
    override_by_actor_id INT UNSIGNED NULL COMMENT 'DENETİM BULGUSU: sealPackage() daha önce bu bayrağı HİÇ KONTROL ETMİYORDU — bir tartı uyuşmazlığı olsa bile koli mühürlenebiliyordu',
    override_at         DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (entity_id) REFERENCES trackable_entities(id),
    FOREIGN KEY (override_by_actor_id) REFERENCES actors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE risk_events (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id        INT UNSIGNED NOT NULL,
    event_type      VARCHAR(64) NOT NULL,
    severity        SMALLINT UNSIGNED NOT NULL,
    is_first_occurrence TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'true ise ağırlık zaten düşürülmüş şekilde kaydedildi',
    detail          JSON NULL,
    detected_at     DATETIME NOT NULL,
    resolved        TINYINT(1) NOT NULL DEFAULT 0,
    actor_response      TEXT NULL COMMENT 'flag''lenen kişinin kendi açıklaması — insan incelemesinden ÖNCE eklenebilir',
    actor_response_at   DATETIME NULL,
    FOREIGN KEY (actor_id) REFERENCES actors(id),
    INDEX idx_risk_actor_time (actor_id, detected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- KANIT KORUMASI: risk_events'in KANIT alanları (severity, event_type,
-- detected_at, actor_id, detail) hiçbir zaman değiştirilemez veya
-- silinemez — bir yönetici/süpervizör bile kendi veya başkasının
-- kaydını "temizleyemez". actor_response/actor_response_at ve resolved
-- (insan incelemesi sonrası) alanları hâlâ güncellenebilir — bunlar
-- meşru kullanım alanlarıdır, kanıtın kendisi değildir.
DELIMITER $$

CREATE TRIGGER trg_risk_events_protect_evidence
BEFORE UPDATE ON risk_events
FOR EACH ROW
BEGIN
    IF NEW.severity <> OLD.severity OR NEW.event_type <> OLD.event_type
       OR NEW.detected_at <> OLD.detected_at OR NEW.actor_id <> OLD.actor_id
    THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'risk_events kanıt alanları (severity, event_type, detected_at, actor_id) değiştirilemez.';
    END IF;
END$$

CREATE TRIGGER trg_risk_events_no_delete
BEFORE DELETE ON risk_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'risk_events kayıtları asla silinemez — en yetkili rol dahil.';
END$$

DELIMITER ;

CREATE TABLE actor_risk_score (
    actor_id            INT UNSIGNED PRIMARY KEY,
    rolling_score       DECIMAL(8,2) NOT NULL DEFAULT 0,
    last_calculated_at  DATETIME NOT NULL,
    FOREIGN KEY (actor_id) REFERENCES actors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
