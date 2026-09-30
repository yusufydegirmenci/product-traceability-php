-- SQLite mirror of schema.mysql.sql, used ONLY by demo.php so the whole
-- system can be run and verified with zero external dependencies.
-- Production deployments should use schema.mysql.sql.

CREATE TABLE products (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    gtin            TEXT NOT NULL UNIQUE,
    name            TEXT NOT NULL,
    category        TEXT,
    tracking_mode   TEXT NOT NULL DEFAULT 'lot',
    return_window_days INTEGER NOT NULL DEFAULT 14,
    created_at      TEXT NOT NULL
);

CREATE TABLE trackable_entities (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id          INTEGER NOT NULL REFERENCES products(id),
    entity_code         TEXT NOT NULL UNIQUE,
    entity_type         TEXT NOT NULL,
    lot_number          TEXT NOT NULL,
    lot_position        INTEGER,
    serial_number       TEXT,
    quantity_total      INTEGER NOT NULL DEFAULT 1,
    quantity_remaining  INTEGER NOT NULL DEFAULT 1,
    production_date     TEXT,
    expiry_date         TEXT,
    status              TEXT NOT NULL DEFAULT 'CREATED',
    created_at          TEXT NOT NULL,
    updated_at          TEXT NOT NULL
);

CREATE TABLE destroy_requests (
    id                      INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_id               INTEGER NOT NULL REFERENCES trackable_entities(id),
    reason                  TEXT NOT NULL,
    requested_by_actor_id   INTEGER NOT NULL REFERENCES actors(id),
    requested_at            TEXT NOT NULL,
    confirmed_by_actor_id   INTEGER REFERENCES actors(id),
    confirmed_at            TEXT,
    status                  TEXT NOT NULL DEFAULT 'pending'
);

CREATE TABLE intake_reconciliations (
    id                      INTEGER PRIMARY KEY AUTOINCREMENT,
    lot_number              TEXT NOT NULL,
    product_id              INTEGER NOT NULL REFERENCES products(id),
    declared_quantity       INTEGER NOT NULL,
    actual_entity_count     INTEGER NOT NULL,
    matched                 INTEGER NOT NULL,
    reconciled_by_actor_id  INTEGER NOT NULL REFERENCES actors(id),
    reconciled_at           TEXT NOT NULL
);

CREATE TABLE product_weight_profiles (
    product_id      INTEGER PRIMARY KEY REFERENCES products(id),
    check_type      TEXT NOT NULL,
    full_weight_g   REAL,
    empty_weight_g  REAL,
    unit_count      INTEGER,
    tolerance_pct   REAL NOT NULL DEFAULT 5.00
);

CREATE TABLE customer_claims (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_id           INTEGER REFERENCES trackable_entities(id),
    order_ref           TEXT,
    claim_type          TEXT NOT NULL,
    claim_text          TEXT NOT NULL,
    claimed_by_actor_id INTEGER REFERENCES actors(id),
    trust_level         TEXT NOT NULL DEFAULT 'customer_input',
    status              TEXT NOT NULL DEFAULT 'open',
    created_at          TEXT NOT NULL
);

CREATE TABLE carrier_events (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_id           INTEGER REFERENCES trackable_entities(id),
    order_ref           TEXT,
    carrier_name        TEXT NOT NULL,
    carrier_event_code  TEXT NOT NULL,
    event_time          TEXT NOT NULL,
    received_at         TEXT NOT NULL,
    proof_type          TEXT NOT NULL DEFAULT 'none',
    proof_reference     TEXT,
    raw_payload         TEXT,
    trust_level         TEXT NOT NULL DEFAULT 'carrier_api',
    disputed            INTEGER NOT NULL DEFAULT 0,
    created_at          TEXT NOT NULL
);

CREATE TABLE reshipments (
    id                      INTEGER PRIMARY KEY AUTOINCREMENT,
    original_entity_id      INTEGER NOT NULL REFERENCES trackable_entities(id),
    replacement_entity_id   INTEGER NOT NULL REFERENCES trackable_entities(id),
    claim_id                INTEGER REFERENCES customer_claims(id),
    reason                  TEXT,
    created_at              TEXT NOT NULL
);

CREATE TABLE investigation_results (
    id                          INTEGER PRIMARY KEY AUTOINCREMENT,
    claim_id                    INTEGER NOT NULL REFERENCES customer_claims(id),
    linked_event_ids            TEXT,
    linked_carrier_event_ids    TEXT,
    conclusion                  TEXT NOT NULL,
    investigated_by_actor_id    INTEGER NOT NULL REFERENCES actors(id),
    investigated_at             TEXT NOT NULL,
    notes                       TEXT,
    high_impact                 INTEGER NOT NULL DEFAULT 0,
    second_reviewer_actor_id    INTEGER REFERENCES actors(id),
    second_reviewed_at          TEXT
);

CREATE TABLE refunds (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    return_event_id     INTEGER NOT NULL REFERENCES epcis_events(id),
    entity_id           INTEGER NOT NULL REFERENCES trackable_entities(id),
    amount              REAL NOT NULL,
    status              TEXT NOT NULL DEFAULT 'pending',
    double_compensation_review INTEGER NOT NULL DEFAULT 0,
    requested_at        TEXT NOT NULL,
    paid_at             TEXT,
    paid_by_actor_id    INTEGER REFERENCES actors(id)
);

CREATE TABLE packages (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    sscc                TEXT NOT NULL UNIQUE,
    location_id         INTEGER REFERENCES locations(id),
    status              TEXT NOT NULL DEFAULT 'open',
    opened_by_actor_id  INTEGER NOT NULL REFERENCES actors(id),
    opened_at           TEXT NOT NULL,
    sealed_at           TEXT,
    seal_serial         TEXT,
    label_verified_at   TEXT,
    spot_checked_by_actor_id INTEGER REFERENCES actors(id),
    spot_checked_at     TEXT,
    spot_check_result   TEXT,
    voided_at           TEXT,
    voided_by_actor_id  INTEGER REFERENCES actors(id),
    void_reason         TEXT
);

-- v1.1: her mühürleme olayının KALICI tarihçesi — bir mühür iptal
-- edildiğinde bile burada sonsuza dek "geçersiz" olarak durur, asla
-- tekrar geçerli sayılamaz.
CREATE TABLE package_seal_history (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    package_id          INTEGER NOT NULL REFERENCES packages(id),
    seal_serial         TEXT NOT NULL,
    sealed_at           TEXT NOT NULL,
    invalidated_at      TEXT,
    invalidated_by_actor_id INTEGER REFERENCES actors(id),
    void_reason         TEXT
);

CREATE TABLE package_orders (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    package_id  INTEGER NOT NULL REFERENCES packages(id),
    order_ref   TEXT NOT NULL,
    UNIQUE (package_id, order_ref)
);

CREATE TABLE package_contents (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    package_id  INTEGER NOT NULL REFERENCES packages(id),
    entity_id   INTEGER NOT NULL REFERENCES trackable_entities(id),
    order_ref   TEXT NOT NULL,
    added_at    TEXT NOT NULL
);

CREATE TABLE warehouse_transfers (
    id                      INTEGER PRIMARY KEY AUTOINCREMENT,
    source_location_id      INTEGER NOT NULL REFERENCES locations(id),
    destination_location_id INTEGER NOT NULL REFERENCES locations(id),
    customs_declaration_ref TEXT,
    requires_customs        INTEGER NOT NULL DEFAULT 1,
    declared_quantity       INTEGER NOT NULL,
    status                  TEXT NOT NULL DEFAULT 'preparing',
    initiated_by_actor_id   INTEGER NOT NULL REFERENCES actors(id),
    initiated_at            TEXT NOT NULL,
    exported_at             TEXT,
    customs_cleared_at      TEXT,
    received_at             TEXT,
    received_by_actor_id    INTEGER REFERENCES actors(id),
    actual_received_count   INTEGER
);

CREATE TABLE transfer_contents (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    transfer_id INTEGER NOT NULL REFERENCES warehouse_transfers(id),
    entity_id   INTEGER NOT NULL REFERENCES trackable_entities(id)
);

CREATE TABLE cycle_counts (
    id                          INTEGER PRIMARY KEY AUTOINCREMENT,
    location_id                 INTEGER NOT NULL REFERENCES locations(id),
    product_id                  INTEGER NOT NULL REFERENCES products(id),
    expected_quantity           INTEGER NOT NULL,
    counted_quantity             INTEGER NOT NULL,
    variance                     INTEGER NOT NULL,
    counted_by_actor_id          INTEGER NOT NULL REFERENCES actors(id),
    primary_custodian_actor_id   INTEGER REFERENCES actors(id),
    counted_at                   TEXT NOT NULL
);

CREATE TABLE device_fault_events (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    device_id           INTEGER NOT NULL REFERENCES devices(id),
    anomaly_type        TEXT NOT NULL,
    reported_by_actor_id INTEGER REFERENCES actors(id),
    occurred_at         TEXT NOT NULL
);

CREATE TABLE key_rotation_log (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    key_version     INTEGER NOT NULL UNIQUE,
    activated_at    TEXT NOT NULL,
    retired_at      TEXT
);

CREATE TABLE customer_messages (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id        INTEGER NOT NULL REFERENCES actors(id),
    entity_id       INTEGER REFERENCES trackable_entities(id),
    order_ref       TEXT,
    message_type    TEXT NOT NULL,
    channel         TEXT NOT NULL,
    message_text    TEXT NOT NULL,
    status          TEXT NOT NULL DEFAULT 'queued',
    created_at      TEXT NOT NULL,
    sent_at         TEXT
);

CREATE TABLE notifications (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    type                TEXT NOT NULL,
    severity            TEXT NOT NULL DEFAULT 'info',
    target_role         TEXT NOT NULL,
    related_id          INTEGER,
    message             TEXT NOT NULL,
    channel             TEXT NOT NULL DEFAULT 'console',
    created_at          TEXT NOT NULL,
    acknowledged_at     TEXT,
    acknowledged_by_actor_id INTEGER REFERENCES actors(id),
    escalated_at        TEXT,
    escalated_to_role   TEXT
);
CREATE INDEX idx_notif_type_related ON notifications (type, related_id, acknowledged_at);

CREATE TABLE locations (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_country  TEXT NOT NULL,
    gln             TEXT,
    name            TEXT NOT NULL,
    type            TEXT NOT NULL,
    customs_zone    TEXT,
    transit_time_hours_estimate INTEGER
);

CREATE TABLE actors (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_country  TEXT NOT NULL,
    actor_type      TEXT NOT NULL,
    role            TEXT,
    name            TEXT NOT NULL,
    external_ref    TEXT,
    beneficial_owner_ref TEXT,
    created_at      TEXT,
    suspended_at    TEXT,
    suspended_reason TEXT
);

-- AŞAMA 1: yanlış beyanla girmiş bir lot'un GEÇMİŞİ SİLİNMEDEN düzeltilmesi
CREATE TABLE recall_notices (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    lot_entity_id   INTEGER NOT NULL REFERENCES trackable_entities(id),
    order_ref       TEXT NOT NULL,
    dealer_actor_id INTEGER REFERENCES actors(id),
    status          TEXT NOT NULL DEFAULT 'AWAITING_RECALL_NOTICE',
    created_at      TEXT NOT NULL
);

-- AŞAMA 2/3: bir personelin "picked" (raftan alındı, henüz koliye/rafa
-- dönmedi) durumdaki zimmetini izler — dwell-time (bekleme süresi) kontrolü
-- ve vardiya çıkış kilidinin ortak veri kaynağı.
CREATE TABLE custody_holds (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_id       INTEGER NOT NULL REFERENCES trackable_entities(id),
    actor_id        INTEGER NOT NULL REFERENCES actors(id),
    picked_at       TEXT NOT NULL,
    released_at     TEXT,
    release_reason  TEXT
);

-- AŞAMA 4: bir cihazın SON raf/lokasyon barkodu okutma zamanı — "önce raf,
-- sonra ürün" sırasını zorunlu kılmak için.
CREATE TABLE device_scan_sequence (
    device_id           INTEGER PRIMARY KEY REFERENCES devices(id),
    last_location_id    INTEGER REFERENCES locations(id),
    last_location_scan_at TEXT
);

CREATE TABLE actor_relationships (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id_1          INTEGER NOT NULL REFERENCES actors(id),
    actor_id_2          INTEGER NOT NULL REFERENCES actors(id),
    relationship_type   TEXT NOT NULL,
    declared_at         TEXT NOT NULL
);

CREATE TABLE devices (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    device_uid          TEXT NOT NULL UNIQUE,
    tenant_country      TEXT NOT NULL,
    cert_fingerprint    TEXT,
    last_seen_at        TEXT,
    locked_at           TEXT,
    locked_reason       TEXT,
    unlocked_by_actor_id INTEGER REFERENCES actors(id)
);

-- Müfettiş bulgusu: vardiya devri sadece sözlü/kağıt üzerindeydi, hiç dijital
-- kaydı yoktu — devreden ve devralan artık açık kalemleri (karantina, DLQ,
-- bildirim) dijital olarak görüp AYRI AYRI onaylıyor.
CREATE TABLE shift_handovers (
    id                        INTEGER PRIMARY KEY AUTOINCREMENT,
    location_id               INTEGER NOT NULL REFERENCES locations(id),
    outgoing_actor_id         INTEGER NOT NULL REFERENCES actors(id),
    incoming_actor_id         INTEGER NOT NULL REFERENCES actors(id),
    open_quarantine_count     INTEGER NOT NULL,
    open_dlq_count            INTEGER NOT NULL,
    open_notifications_count INTEGER NOT NULL,
    notes                     TEXT,
    outgoing_confirmed_at     TEXT,
    incoming_confirmed_at     TEXT,
    created_at                TEXT NOT NULL
);

CREATE TABLE epcis_events (
    id                          INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_id                   INTEGER NOT NULL REFERENCES trackable_entities(id),
    biz_step                    TEXT NOT NULL,
    disposition                 TEXT NOT NULL,
    event_time                  TEXT NOT NULL,
    actor_id                    INTEGER REFERENCES actors(id),
    device_id                   INTEGER REFERENCES devices(id),
    read_point_location_id      INTEGER REFERENCES locations(id),
    related_order_ref           TEXT,
    metadata                    TEXT,
    prev_hash                   TEXT NOT NULL,
    event_hash                  TEXT NOT NULL UNIQUE,
    key_version                 INTEGER NOT NULL DEFAULT 1,
    idempotency_key              TEXT UNIQUE,
    created_at                  TEXT NOT NULL
);

-- DENETİM BULGUSU: bu indexler daha önce SQLite şemasında hiç yoktu (sadece
-- örtük birincil anahtar indexi vardı) — MySQL şemasıyla paritesi için eklendi.
CREATE INDEX idx_events_entity ON epcis_events (entity_id, id);
-- KIDEMLI İNCELEME BULGUSU: AYNI entity_id'ye eşzamanlı iki appendEvent()
-- çağrısı, İKİSİ DE aynı prev_hash'i (son event'in hash'i) okuyup TİKİ
-- de o prev_hash ile yeni bir satır ekleyebilir — bu, hash-chain'i
-- ÇATALLAR (iki child, aynı parent'a işaret eder). Ampirik olarak
-- kanıtlandı (5 denemenin 3'ünde gerçekleşti — bkz. chain_race_test.php).
-- Bu UNIQUE kısıt, İKİNCİ eşzamanlı yazmayı DB SEVİYESİNDE reddeder;
-- EventStore::appendEvent() bunu yakalayıp otomatik olarak retry eder.
CREATE UNIQUE INDEX idx_events_no_fork ON epcis_events (entity_id, prev_hash);
CREATE INDEX idx_events_time ON epcis_events (event_time);
CREATE INDEX idx_events_bizstep_loc_time ON epcis_events (biz_step, read_point_location_id, event_time);

-- harici bir gÃ¶zden geÃ§iren önerisi DURUM 3/5: bir tarama cihaz arızası/ağ kopması yüzünden
-- ANINDA işlenemezse (barkod okunamadı, kargo tartısı yanıt vermedi, vb.),
-- bu ASLA sessizce kaybolmamalı — izole bir bekleme alanına (Dead Letter
-- Queue) düşer, süpervizör fiziksel kontrol sonrası manuel çözer. Aynı
-- mekanizma, istasyonun offline biriktirdiği taramaları da (bağlantı geri
-- gelince) temsil eder — ikisi de yapısal olarak "hemen işlenemedi, sıraya
-- girdi" durumudur.
CREATE TABLE scan_exception_queue (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    raw_scanned_code    TEXT NOT NULL,
    device_id           INTEGER REFERENCES devices(id),
    location_id         INTEGER REFERENCES locations(id),
    actor_id            INTEGER REFERENCES actors(id),
    exception_type      TEXT NOT NULL,
    submitted_at        TEXT NOT NULL,
    status              TEXT NOT NULL DEFAULT 'pending',
    resolved_by_actor_id INTEGER REFERENCES actors(id),
    resolved_at         TEXT,
    resolution_note     TEXT
);

-- Same append-only enforcement as production, expressed in SQLite trigger syntax.
CREATE TRIGGER trg_epcis_events_no_update
BEFORE UPDATE ON epcis_events
BEGIN
    SELECT RAISE(ABORT, 'epcis_events is append-only: correct mistakes with a new event, never edit history.');
END;

CREATE TRIGGER trg_epcis_events_no_delete
BEFORE DELETE ON epcis_events
BEGIN
    SELECT RAISE(ABORT, 'epcis_events is append-only: rows can never be deleted.');
END;

CREATE TABLE printer_log (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    device_id       INTEGER NOT NULL REFERENCES devices(id),
    entity_id       INTEGER REFERENCES trackable_entities(id),
    printed_at      TEXT NOT NULL,
    label_count     INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE duplicate_scan_flags (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_id           INTEGER NOT NULL REFERENCES trackable_entities(id),
    first_event_id      INTEGER NOT NULL REFERENCES epcis_events(id),
    second_event_id     INTEGER REFERENCES epcis_events(id),
    actor_id            INTEGER REFERENCES actors(id),
    flagged_at          TEXT NOT NULL,
    resolved            INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE packing_station_weights (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    order_ref           TEXT NOT NULL,
    entity_id           INTEGER NOT NULL REFERENCES trackable_entities(id),
    expected_weight_g   REAL NOT NULL,
    measured_weight_g   REAL NOT NULL,
    variance_pct        REAL NOT NULL,
    flagged             INTEGER NOT NULL DEFAULT 0,
    override_by_actor_id INTEGER REFERENCES actors(id),
    override_at         TEXT,
    created_at          TEXT NOT NULL
);

CREATE TABLE risk_events (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id        INTEGER NOT NULL REFERENCES actors(id),
    event_type      TEXT NOT NULL,
    severity        INTEGER NOT NULL,
    is_first_occurrence INTEGER NOT NULL DEFAULT 0,
    detail          TEXT,
    detected_at     TEXT NOT NULL,
    resolved        INTEGER NOT NULL DEFAULT 0,
    actor_response      TEXT,
    actor_response_at   TEXT
);

-- KANIT KORUMASI: kanıt alanları (severity, event_type, detected_at,
-- actor_id) hiçbir zaman değiştirilemez/silinemez — en yetkili rol dahil.
-- actor_response/resolved güncellenebilir (meşru kullanım).
CREATE TRIGGER trg_risk_events_protect_evidence
BEFORE UPDATE ON risk_events
WHEN NEW.severity <> OLD.severity OR NEW.event_type <> OLD.event_type
     OR NEW.detected_at <> OLD.detected_at OR NEW.actor_id <> OLD.actor_id
BEGIN
    SELECT RAISE(ABORT, 'risk_events kanıt alanları değiştirilemez.');
END;

CREATE TRIGGER trg_risk_events_no_delete
BEFORE DELETE ON risk_events
BEGIN
    SELECT RAISE(ABORT, 'risk_events kayıtları asla silinemez — en yetkili rol dahil.');
END;

CREATE TABLE actor_risk_score (
    actor_id            INTEGER PRIMARY KEY REFERENCES actors(id),
    rolling_score       REAL NOT NULL DEFAULT 0,
    last_calculated_at  TEXT NOT NULL
);
