<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use PDO;

/**
 * Creates and looks up trackable_entities — the physical units or lots
 * being followed through the chain. An entity's code is NEVER reused, even
 * after a return or cancellation (see README §"İade/iptal ve ID'nin
 * kalıcılığı") — only DESTROYED is a true terminal state.
 */

final class EntityRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function createEntity(
        int $productId,
        string $entityCode,      // the GS1 element string or Digital Link value printed on the label
        string $entityType,      // 'unit' | 'lot'
        string $lotNumber,
        ?string $serialNumber,
        int $quantityTotal,
        ?string $productionDate,
        ?string $expiryDate,
        ?int $lotPosition = null // SADECE İÇ kullanım: bu partideki 1..N sırası, dışa hiç basılmaz
    ): int {
        // Depo görevlisinin yorgunlukla/dikkatsizlikle SKT'yi yanlış girmesi
        // (örn. 2027 yerine 2037) hiçbir şekilde yakalanmıyordu. Basit bir
        // mantıksal denetim: SKT üretim tarihinden önce olamaz, ve makul
        // bir raf ömrü aralığının (10 yıl) dışına çıkamaz.
        if ($productionDate !== null && $expiryDate !== null) {
            $prod = new \DateTimeImmutable($productionDate);
            $exp = new \DateTimeImmutable($expiryDate);
            if ($exp <= $prod) {
                throw new \InvalidArgumentException(
                    "SKT ({$expiryDate}) üretim tarihinden ({$productionDate}) önce veya aynı olamaz — giriş kontrol edilmeli."
                );
            }
            if ($exp->diff($prod)->y > 10) {
                throw new \InvalidArgumentException(
                    "SKT ile üretim tarihi arası 10 yıldan fazla ({$productionDate} → {$expiryDate}) — muhtemelen bir yazım hatası, giriş kontrol edilmeli."
                );
            }
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO trackable_entities
                (product_id, entity_code, entity_type, lot_number, lot_position, serial_number,
                 quantity_total, quantity_remaining, production_date, expiry_date, status, created_at, updated_at)
             VALUES
                (:product_id, :entity_code, :entity_type, :lot_number, :lot_position, :serial_number,
                 :qty_total, :qty_total, :production_date, :expiry_date, :status, :now, :now)'
        );
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt->execute([
            ':product_id' => $productId,
            ':entity_code' => $entityCode,
            ':entity_type' => $entityType,
            ':lot_number' => $lotNumber,
            ':lot_position' => $lotPosition,
            ':serial_number' => $serialNumber,
            ':qty_total' => $quantityTotal,
            ':production_date' => $productionDate,
            ':expiry_date' => $expiryDate,
            ':status' => 'CREATED',
            ':now' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Bir partide gerçekte kaç entity oluşturulmuş — giriş mutabakatı için. */
    public function countByLot(string $lotNumber): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM trackable_entities WHERE lot_number = :lot');
        $stmt->execute([':lot' => $lotNumber]);
        return (int) $stmt->fetchColumn();
    }

    public function findByCode(string $entityCode): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM trackable_entities WHERE entity_code = :code');
        $stmt->execute([':code' => $entityCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * KUTUSUZ/TEKİL-ID'SİZ ÜRÜN SENARYOSU: bir müşteri/patron elinde sadece
     * ürünün üzerine üretimde basılmış Lot No'yu bulursa (barkod, seri no
     * yok), bunu arayabilmesi gerekir. entity_code her zaman tam GS1
     * barkod dizisidir — ama lot_number tek başına insan tarafından
     * okunabilir/yazılabilir bir değerdir. Bu metod SADECE lot_number ile
     * arar.
     */
    public function findByLotNumber(string $lotNumber): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM trackable_entities WHERE lot_number = :lot AND entity_type = 'lot'");
        $stmt->execute([':lot' => $lotNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Tekil seri numarası BASILAMAYAN ürünler için — harici bir gÃ¶zden geÃ§iren'nin önerdiği
     * "Lot (Parti) No + Yazılım Logu" modeli. Bir LOT entity'si (quantity_total
     * birden fazla fiziksel ürünü TEMSİL EDER, her birinin kendi entity_id'si
     * YOKTUR) bir siparişe/bayiye tahsis edildiğinde bu çağrılır.
     *
     * DENETİM BULGUSU: quantity_remaining alanı şemada 54 bölümdür VARDI
     * ama hiçbir kod onu GERÇEKTEN azaltmıyordu — sadece oluşturulduğunda
     * bir kez yazılıyordu. Bu metod onu gerçek işlevine kavuşturuyor.
     */
    public function consumeFromLot(int $lotEntityId, int $quantityToConsume): void
    {
        $lot = $this->findById($lotEntityId);
        if ($lot === null || $lot['entity_type'] !== 'lot') {
            throw new \RuntimeException("Entity #{$lotEntityId} bir LOT değil — consumeFromLot() sadece lot-tipi entity'lerde kullanılır.");
        }

        // GELİŞTİRİCİ İNCELEMESİ BULGUSU: önceki sürüm "oku, hesapla, yaz"
        // deseniyle yazılmıştı — İKİ eşzamanlı çağrı aynı lot'tan aynı anda
        // düşerse, biri diğerinin güncellemesini SESSİZCE KAYBEDEBİLİRDİ
        // (klasik lost-update yarış durumu). transitionStatus()'ta zaten
        // kullanılan atomic compare-and-swap deseni burada da uygulanıyor:
        // azaltma VE yeterlilik kontrolü TEK bir atomik UPDATE'te yapılıyor.
        $stmt = $this->pdo->prepare(
            "UPDATE trackable_entities
             SET quantity_remaining = quantity_remaining - :qty,
                 status = CASE WHEN quantity_remaining - :qty2 <= 0 THEN 'DEPLETED' ELSE status END,
                 updated_at = :now
             WHERE id = :id AND quantity_remaining >= :qty3"
        );
        $stmt->execute([
            ':qty' => $quantityToConsume, ':qty2' => $quantityToConsume, ':qty3' => $quantityToConsume,
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), ':id' => $lotEntityId,
        ]);

        if ($stmt->rowCount() === 0) {
            $current = $this->findById($lotEntityId)['quantity_remaining'] ?? 0;
            throw new \RuntimeException(
                "Lot '{$lot['lot_number']}' içinde yeterli miktar yok: kalan {$current}, istenen {$quantityToConsume}."
            );
        }
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM trackable_entities WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Updates the entity's current-state projection. This is a convenience
     * cache for fast lookups ("where is this unit right now?") — the
     * epcis_events history remains the single source of truth and is never
     * overwritten. DESTROYED is the one status that can never transition
     * further; the caller (see PackingService/ReturnService) must enforce that.
     */
    /**
     * Updates the entity's current-state projection. This is a convenience
     * cache for fast lookups ("where is this unit right now?") — the
     * epcis_events history remains the single source of truth and is never
     * overwritten. DESTROYED is the one status that can never transition
     * further; the caller (see PackingService/ReturnService) must enforce that.
     *
     * EŞZAMANLILIK KORUMASI: geçiş, WHERE koşuluna "eski durum buysa"
     * şartını da ekleyerek ATOMİK yapılır (compare-and-swap). İki işlem
     * aynı anda aynı entity'yi değiştirmeye çalışırsa, sadece biri
     * başarılı olur — diğeri ConcurrentModificationException alır ve
     * baştan kontrol etmeye zorlanır. Bu, "iki farklı yerden aynı anda
     * iade edilme" gibi bir yarış durumunun çift işlem yapmasını engeller.
     */
    public function transitionStatus(int $entityId, string $newStatus): void
    {
        $current = $this->findById($entityId);
        if ($current === null) {
            throw new \RuntimeException("Entity #{$entityId} bulunamadı.");
        }
        if ($current['status'] === 'DESTROYED') {
            throw new \RuntimeException(
                "Entity #{$entityId} is DESTROYED — it can never be re-activated. "
                . 'A scan attempting this should be treated as a high-severity fraud signal.'
            );
        }
        if ($current['status'] === 'MISDECLARED') {
            throw new \RuntimeException(
                "Entity #{$entityId} is MISDECLARED (v1.2 AŞAMA 1 — yanlış ürün beyanıyla girmiş, reclassifyLot() ile "
                . 'düzeltilmiş bir lot). DESTROYED gibi kalıcı ve geri döndürülemez bir durumdur — bu kod bir daha ASLA '
                . 'normal akışa sokulamaz. Doğru ürün için açılan YENİ lot kullanılmalıdır.'
            );
        }

        $stmt = $this->pdo->prepare(
            'UPDATE trackable_entities SET status = :status, updated_at = :now WHERE id = :id AND status = :expected_status'
        );
        $stmt->execute([
            ':status' => $newStatus,
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ':id' => $entityId,
            ':expected_status' => $current['status'],
        ]);

        if ($stmt->rowCount() === 0) {
            throw new ConcurrentModificationException(
                "Entity #{$entityId} durumu bu işlem sırasında başka bir işlem tarafından değiştirildi — "
                . 'muhtemelen aynı ürün aynı anda iki yerden işlendi. İşlemi baştan kontrol et.'
            );
        }
    }

    /**
     * Reddedilen/şüpheli bir iade, bir insan (süpervizör) inceleyip temiz
     * bulana kadar karantinada kalır. MÜFETTİŞ BULGUSU: bu işlem daha önce
     * TEK bir kişinin onayıyla yapılabiliyordu ("tek nokta başarısızlığı") —
     * artık DestroyService'teki dört-göz deseniyle aynı mantıkla, talep
     * eden ve onaylayan FARKLI iki kişi olmak zorunda.
     */
    public function clearQuarantine(
        int $entityId,
        int $requestingActorId,
        int $confirmingActorId,
        \Traceability\Risk\AuthorizationGuard $authGuard,
        string $newStatus = 'IN_WAREHOUSE',
        ?RuleConflictResolver $ruleResolver = null,
        ?int $associatedDealerId = null
    ): void {
        $authGuard->requireRole($requestingActorId, ['supervizor', 'yonetici']);
        $authGuard->requireRole($confirmingActorId, ['supervizor', 'yonetici']);
        if ($requestingActorId === $confirmingActorId) {
            throw new \RuntimeException('Karantina temizleme dört-göz gerektirir: talep eden ve onaylayan AYNI kişi olamaz.');
        }

        if ($ruleResolver !== null) {
            $check = $ruleResolver->canClearQuarantine($entityId, $associatedDealerId);
            if (!$check['allowed']) {
                throw new \RuntimeException("Karantina temizlenemedi: {$check['reason']}");
            }
        }

        $this->transitionStatus($entityId, $newStatus);
    }
}
