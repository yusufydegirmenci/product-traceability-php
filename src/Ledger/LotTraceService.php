<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use PDO;

/**
 * GEMİNİ SENARYOSU: "Patron çöpten kutusuz/ID'siz bir ürün bulursa" — elinde
 * sadece üretimde basılmış Lot No varsa (tekil seri no YOK, barkod
 * OKUNAMIYOR/kaybolmuş), sistemin YİNE DE o ürünün TÜM hayatını
 * anlatabilmesi gerekir.
 *
 * Bu servis, EntityRepository::findByLotNumber() + EventStore::history()'i
 * birleştirip, kod/tarih/ID dolu ham bir tabloyu, bir İNSANIN okuyabileceği
 * bir HİKAYEYE çevirir — aktör ve lokasyon adlarını çözerek.
 *
 * DÜRÜST SINIR: Bu, o LOT'UN toplu hikayesini anlatır (kaç adet depoya
 * girdi, hangi siparişlere ne kadarı gitti) — eğer lot içindeki ürünlere
 * tekil seri no basılmamışsa, "bu SPESİFİK fiziksel birim" ile "aynı
 * lot'tan başka bir birim" arasında sistem ayrım YAPAMAZ. Bu, tekil
 * ID'nin sağladığı kesinliğin bilinçli bir bedelidir — lot takibi, tekil
 * takipten daha ucuzdur ama daha az kesindir.
 */
final class LotTraceService
{
    public function __construct(
        private PDO $pdo,
        private EntityRepository $entities,
        private EventStore $eventStore
    ) {
    }

    public function traceByLotNumber(string $lotNumber): array
    {
        $lot = $this->entities->findByLotNumber($lotNumber);
        if ($lot === null) {
            return ['found' => false, 'story' => "'{$lotNumber}' numaralı bir lot bulunamadı."];
        }

        $productStmt = $this->pdo->prepare('SELECT name FROM products WHERE id = :id');
        $productStmt->execute([':id' => $lot['product_id']]);
        $productName = $productStmt->fetchColumn();

        $history = $this->eventStore->history((int) $lot['id']);
        $storyLines = [];
        $storyLines[] = sprintf(
            "%s (Lot: %s) — %d adet, %s tarihinde üretildi, SKT: %s.",
            $productName, $lot['lot_number'], $lot['quantity_total'], $lot['production_date'] ?? 'bilinmiyor', $lot['expiry_date'] ?? 'bilinmiyor'
        );

        foreach ($history as $event) {
            $actorName = null;
            if ($event['actor_id'] !== null) {
                $actorStmt = $this->pdo->prepare('SELECT name FROM actors WHERE id = :id');
                $actorStmt->execute([':id' => $event['actor_id']]);
                $actorName = $actorStmt->fetchColumn() ?: "aktör #{$event['actor_id']}";
            }
            $locationName = null;
            if ($event['read_point_location_id'] !== null) {
                $locStmt = $this->pdo->prepare('SELECT name FROM locations WHERE id = :id');
                $locStmt->execute([':id' => $event['read_point_location_id']]);
                $locationName = $locStmt->fetchColumn() ?: "lokasyon #{$event['read_point_location_id']}";
            }

            $stepLabel = match ($event['biz_step']) {
                'commissioning' => 'Depoya Giriş',
                'packing' => 'Paketleme',
                'shipping' => 'Sevkiyat',
                'receiving' => $event['disposition'] === 'returned' ? 'İade Alındı' : 'Teslim Edildi',
                'destroyed' => 'İmha Edildi',
                default => $event['biz_step'],
            };

            $line = "{$event['event_time']} — {$stepLabel}";
            if ($actorName !== null) { $line .= " ({$actorName})"; }
            if ($locationName !== null) { $line .= " — {$locationName}"; }
            if ($event['related_order_ref'] !== null) { $line .= " — Sipariş: {$event['related_order_ref']}"; }
            $storyLines[] = $line;
        }

        $storyLines[] = sprintf('Kalan miktar: %d / %d.', $lot['quantity_remaining'], $lot['quantity_total']);
        $chainOk = $this->eventStore->verifyChain((int) $lot['id']);
        $storyLines[] = $chainOk
            ? 'Bu kayıtların hiçbiri sonradan değiştirilmemiş (hash-chain doğrulandı).'
            : 'UYARI: bu kayıt zincirinde bütünlük sorunu tespit edildi.';

        return ['found' => true, 'lot' => $lot, 'product_name' => $productName, 'story' => implode("\n", $storyLines), 'chain_verified' => $chainOk];
    }

    /**
     * v1.1 — KESİN SKT BLOKAJI'nın görünürlük tarafı: bu lot'un SKT'sine
     * kaç gün kaldığını (veya kaçtı geçtiğini, negatif olarak) döner.
     * Gerçek ENGELLEME PackageService::addEntityToPackage()'da yapılır —
     * bu metod sadece raporlama/görünürlük amaçlıdır.
     */
    public function daysUntilExpiry(string $lotNumber): ?int
    {
        $lot = $this->entities->findByLotNumber($lotNumber);
        if ($lot === null || $lot['expiry_date'] === null) {
            return null;
        }
        $expiry = new \DateTimeImmutable($lot['expiry_date']);
        $now = new \DateTimeImmutable();
        return (int) $now->diff($expiry)->format('%r%a');
    }

    /**
     * v1.2 AŞAMA 1 — GERİYE DÖNÜK LOT SINIFLANDIRMA VE GERİ ÇAĞIRMA. Bir
     * lot YANLIŞ ürün beyanıyla sisteme girmişse (örn. gerçekte Tonik
     * olan bir parti, Cream olarak kaydedilmiş), bu GEÇMİŞİ SİLMEDEN
     * düzeltilir:
     *   1) Eski (yanlış) lot'a bir STOCK_REVERSAL_EVENT event'i eklenir
     *      ve kalan miktarı MISDECLARED durumuna alınır (bir daha
     *      kullanılamaz, ama kaydı SİLİNMEZ).
     *   2) Doğru ürün için YENİ bir lot (STOCK_RECLASSIFIED_ENTRY)
     *      oluşturulur.
     *   3) Süpervizör onayı + Düzeltme Gerekçesi + Tutanak Ref No
     *      ZORUNLUDUR.
     */
    public function reclassifyLot(
        int $oldLotEntityId,
        int $correctProductId,
        string $newLotNumber,
        int $supervisorActorId,
        string $reason,
        string $tutanakRef,
        \Traceability\Risk\AuthorizationGuard $authGuard
    ): array {
        if (trim($reason) === '' || trim($tutanakRef) === '') {
            throw new \RuntimeException('Düzeltme Gerekçesi ve Tutanak Ref No ZORUNLUDUR — boş bırakılamaz.');
        }
        $authGuard->requireRole($supervisorActorId, ['supervizor', 'yonetici']);

        $oldLot = $this->entities->findById($oldLotEntityId);
        if ($oldLot === null || $oldLot['entity_type'] !== 'lot') {
            throw new \RuntimeException("Entity #{$oldLotEntityId} bir LOT değil — reclassifyLot() sadece lot-tipi entity'lerde kullanılır.");
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $remaining = (int) $oldLot['quantity_remaining'];

        // 1) Eski lot'a TERS KAYIT — geçmiş SİLİNMEZ, yeni bir event eklenir.
        $this->eventStore->appendEvent(
            $oldLotEntityId, 'destroyed', 'destroyed', $supervisorActorId, null, null, null,
            ['event_type' => 'STOCK_REVERSAL_EVENT', 'reason' => $reason, 'tutanak_ref' => $tutanakRef, 'reversed_quantity' => $remaining]
        );
        $this->pdo->prepare("UPDATE trackable_entities SET status = 'MISDECLARED', quantity_remaining = 0, updated_at = :now WHERE id = :id")
            ->execute([':now' => $now, ':id' => $oldLotEntityId]);

        // 2) Doğru ürün için YENİ lot girişi.
        $gtinStmt = $this->pdo->prepare('SELECT gtin FROM products WHERE id = :id');
        $gtinStmt->execute([':id' => $correctProductId]);
        $correctGtin = $gtinStmt->fetchColumn();
        $digitalLink = new \Traceability\Gs1\DigitalLink();
        $newCode = $digitalLink->buildElementString($correctGtin, $newLotNumber);
        $newLotEntityId = $this->entities->createEntity(
            $correctProductId, $newCode, 'lot', $newLotNumber, null, $remaining, $now, $oldLot['expiry_date']
        );
        $this->eventStore->appendEvent(
            $newLotEntityId, 'commissioning', 'active', $supervisorActorId, null, null, null,
            ['event_type' => 'STOCK_RECLASSIFIED_ENTRY', 'source_lot_entity_id' => $oldLotEntityId, 'reason' => $reason, 'tutanak_ref' => $tutanakRef]
        );

        // 3) Bu tarihe kadar ESKİ lot'tan zaten kargolanmış siparişleri bul.
        $affected = $this->getAffectedShipmentsByLot($oldLotEntityId);

        return ['old_lot_entity_id' => $oldLotEntityId, 'new_lot_entity_id' => $newLotEntityId, 'reversed_quantity' => $remaining, 'affected_shipments' => $affected];
    }

    /**
     * Bir lot'tan (düzeltmeden ÖNCE) zaten kargolanmış/teslim edilmiş
     * siparişleri bulur ve her birini AWAITING_RECALL_NOTICE olarak
     * işaretler.
     */
    public function getAffectedShipmentsByLot(int $lotEntityId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT related_order_ref, actor_id FROM epcis_events
             WHERE entity_id = :id AND biz_step = 'receiving' AND disposition = 'sold'"
        );
        $stmt->execute([':id' => $lotEntityId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $affected = [];
        foreach ($rows as $row) {
            $exists = $this->pdo->prepare('SELECT id FROM recall_notices WHERE lot_entity_id = :lot AND order_ref = :ref');
            $exists->execute([':lot' => $lotEntityId, ':ref' => $row['related_order_ref']]);
            if ($exists->fetchColumn() === false) {
                $insert = $this->pdo->prepare(
                    "INSERT INTO recall_notices (lot_entity_id, order_ref, dealer_actor_id, status, created_at)
                     VALUES (:lot, :ref, :dealer, 'AWAITING_RECALL_NOTICE', :now)"
                );
                $insert->execute([
                    ':lot' => $lotEntityId, ':ref' => $row['related_order_ref'], ':dealer' => $row['actor_id'],
                    ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]);
            }
            $affected[] = ['order_ref' => $row['related_order_ref'], 'dealer_actor_id' => (int) $row['actor_id'], 'status' => 'AWAITING_RECALL_NOTICE'];
        }
        return $affected;
    }
}
