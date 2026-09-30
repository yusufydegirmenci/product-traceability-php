<?php

declare(strict_types=1);

/**
 * ══════════════════════════════════════════════════════════════════
 * DEPO OPERASYON PARAMETRELERİ — BU DOSYADAKİ HER DEĞER VARSAYILANDIR.
 * ══════════════════════════════════════════════════════════════════
 *
 * Bu sayılar (15 dakika, 120 saniye, saatte 5 override vb.) daha önce
 * ilgili sınıfların İÇİNE gömülmüştü (hardcoded). Buraya taşındılar
 * ki:
 *   1) Gerçek saha verisi toplanınca KOD DEĞİŞTİRMEDEN kalibre
 *      edilebilsinler (.env dosyasından override edilerek).
 *   2) Farklı depolar/ülkeler farklı eşiklere ihtiyaç duyarsa (örn.
 *      Uzak bir depo merkezden daha uzun bir dwell-time toleransı
 *      isteyebilir), bu TEK dosyadan yönetilebilsin.
 *
 * ⚠️  UYARI: AŞAĞIDAKİ DEĞERLERİN HİÇBİRİ GERÇEK SAHA VERİSİYLE
 *     KALİBRE EDİLMEMİŞTİR. Bunlar, tatbikatlar sırasında "makul
 *     görünen" başlangıç noktalarıdır — üretime geçmeden önce gerçek
 *     operasyonel veriyle (bkz. PRODUCTION_CHECKLIST.md) doğrulanmalı
 *     ve muhtemelen değiştirilmelidir. Örneğin 15 dakikalık dwell-time
 *     penceresi, yüksek hacimli bir depoda çok kısa, düşük hacimli bir
 *     depoda çok uzun kalabilir.
 *
 * Kullanım: require bu dosyayı, dönen array'den ilgili anahtarı oku.
 * .env dosyasında bir değişken TANIMLIYSA, o değer varsayılanın
 * yerine geçer (bkz. src/Config/EnvLoader.php).
 */

use Traceability\Config\EnvLoader;

EnvLoader::load(dirname(__DIR__) . '/.env');

return [

    // ── AŞAMA 2: Zimmet Zaman Aşımı (Dwell-Time Guard) ──────────────
    'dwell_time' => [
        // Bir ürün raftan alınıp kaç dakika içinde koliye/rafa dönmezse
        // "zimmet şüphesi" sayılır. VARSAYILAN: 15 dakika.
        'window_minutes' => EnvLoader::getInt('DWELL_TIME_WINDOW_MINUTES', 15),
    ],

    // ── AŞAMA 3/4: Raf/Ürün Sıralı Okutma (Scan Sequence Guard) ─────
    'scan_sequence' => [
        // Bir raf/lokasyon okutmasının "hâlâ geçerli" sayılacağı süre
        // (saniye). Bu pencere dışında bir ürün okutulursa sıra ihlali
        // sayılır. VARSAYILAN: 120 saniye.
        'window_seconds' => EnvLoader::getInt('SCAN_SEQUENCE_WINDOW_SECONDS', 120),
    ],

    // ── v1.1 AŞAMA 2: Süpervizör Suistimal Önleme Eşiği ─────────────
    'supervisor_override_velocity' => [
        // Bir süpervizörün kaç dakikalık pencerede kaç override'dan
        // fazlasını onaylarsa SYSTEM_RISK_FLAG üretilir.
        // VARSAYILAN: 60 dakikada 5'ten fazla.
        'window_minutes' => EnvLoader::getInt('SUPERVISOR_VELOCITY_WINDOW_MINUTES', 60),
        'threshold' => EnvLoader::getInt('SUPERVISOR_VELOCITY_THRESHOLD', 5),
    ],

    // ── Cihaz Arıza Tespiti (Device Fault Detector) ─────────────────
    'device_fault' => [
        'window_minutes' => EnvLoader::getInt('DEVICE_FAULT_WINDOW_MINUTES', 10),
        'threshold' => EnvLoader::getInt('DEVICE_FAULT_THRESHOLD', 5),
    ],

    // ── Ağırlık Toleransı (Weight Reconciler) ───────────────────────
    'weight_tolerance' => [
        // İzin verilen ağırlık sapma oranı (0.10 = %10) — bunun
        // üzerindeki her sapma flag'lenir.
        'fraction' => EnvLoader::getFloat('WEIGHT_TOLERANCE_FRACTION', 0.10),
    ],

    // ── Risk Skorlama Eşikleri (RiskScorer) ─────────────────────────
    'risk_thresholds' => [
        'high_risk' => EnvLoader::getFloat('RISK_THRESHOLD_HIGH', 60.0),
        'medium_risk' => EnvLoader::getFloat('RISK_THRESHOLD_MEDIUM', 25.0),
        // Risk penceresi (gün) — bir sinyalin "ilk oluşum" sayılıp
        // sayılmayacağı bu pencereye göre belirlenir.
        'window_days' => EnvLoader::getInt('RISK_WINDOW_DAYS', 90),
    ],

    // ── Kesin SKT Blokajı (Hard Expiry Lockout) ─────────────────────
    'expiry_lockout' => [
        // Varsayılan minimum "SKT'ye kalan gün" eşiği — bu günden az
        // kalmış ürünler siparişe eklenemez. Ürün kategorisine göre
        // override edilebilir (bkz. addEntityToPackage($minDaysToExpiry)).
        'default_min_days' => EnvLoader::getInt('EXPIRY_LOCKOUT_DEFAULT_MIN_DAYS', 0),
    ],

    // ── Spot-Check / Cycle Count Sıklığı ─────────────────────────────
    'inspection' => [
        // Kargo teyidi bekleme eşiği (saat) — bundan uzun süre teyitsiz
        // kalan sevkiyat işaretlenir.
        'carrier_confirmation_hours' => EnvLoader::getInt('CARRIER_CONFIRMATION_HOURS', 48),
    ],

    // ── EventStore: Eşzamanlılık Çakışması Retry Ayarları ───────────
    'event_store' => [
        // (entity_id, prev_hash) UNIQUE kısıt çakışmasında kaç kez tekrar
        // denenir. Kullanıcı isteği: "3-5 deneme sınırı". VARSAYILAN: 5.
        'max_append_retry_attempts' => EnvLoader::getInt('EVENT_STORE_MAX_RETRY_ATTEMPTS', 5),
    ],

];
