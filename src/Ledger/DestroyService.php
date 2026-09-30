<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use Traceability\Risk\AuthorizationGuard;
use Traceability\Risk\RelationshipGuard;
use PDO;


/**
 * İmha GERİ ALINAMAZ — bir süpervizörün fat-finger hatasıyla YANLIŞ ürünü
 * taratıp imha etmesi, tek kişilik bir onayla önlenemeyen bir risktir.
 * Bu yüzden imha artık İKİ AŞAMALI: bir kişi talep açar (requestDestroy),
 * FARKLI bir yetkili kişi onaylar (confirmDestroy) — banka havalesi
 * onayına benzer "dört göz" ilkesi. Sadece confirmDestroy çağrıldığında
 * gerçek imha event'i düşer.
 */
final class DestroyService
{
    public function __construct(
        private PDO $pdo,
        private EventStore $events,
        private EntityRepository $entities,
        private AuthorizationGuard $authGuard,
        private ?RelationshipGuard $relationshipGuard = null
    ) {
    }

    public function requestDestroy(int $entityId, int $requestedByActorId, string $reason): int
    {
        $this->authGuard->requireRole($requestedByActorId, ['supervizor', 'yonetici']);

        $stmt = $this->pdo->prepare(
            'INSERT INTO destroy_requests (entity_id, reason, requested_by_actor_id, requested_at, status)
             VALUES (:entity_id, :reason, :actor_id, :now, :status)'
        );
        $stmt->execute([
            ':entity_id' => $entityId,
            ':reason' => $reason,
            ':actor_id' => $requestedByActorId,
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ':status' => 'pending',
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param int $expectedProductId Onaylayan kişinin "imha ettiğimi düşündüğüm ürün budur"
     *        diye teyit ettiği product_id — gerçek entity'nin ürünüyle uyuşmazsa
     *        (fat-finger/yanlış talep) işlem durur, bu ekstra bir güvenlik katmanı.
     */
    public function confirmDestroy(int $requestId, int $confirmingActorId, int $expectedProductId): void
    {
        $this->authGuard->requireRole($confirmingActorId, ['supervizor', 'yonetici']);

        $stmt = $this->pdo->prepare('SELECT * FROM destroy_requests WHERE id = :id');
        $stmt->execute([':id' => $requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($request === false || $request['status'] !== 'pending') {
            throw new DestroyRequestException("Destroy request #{$requestId} bulunamadı veya zaten işlenmiş.");
        }
        if ((int) $request['requested_by_actor_id'] === $confirmingActorId) {
            throw new DestroyRequestException(
                'Talebi açan kişi kendi talebini onaylayamaz — dört göz ilkesi ihlal edilir. Farklı bir yetkili gerekli.'
            );
        }
        if ($this->relationshipGuard !== null) {
            $this->relationshipGuard->assertNoConflict($confirmingActorId, [(int) $request['requested_by_actor_id']]);
        }

        $entity = $this->entities->findById((int) $request['entity_id']);
        if ($entity === null || (int) $entity['product_id'] !== $expectedProductId) {
            throw new DestroyRequestException(
                "Onaylayanın beklediği ürün (product_id={$expectedProductId}) ile entity'nin gerçek ürünü uyuşmuyor — "
                . 'muhtemelen yanlış entity seçildi (fat-finger). İşlem durduruldu.'
            );
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        // KIDEMLI İNCELEME BULGUSU: bu UPDATE daha önce SADECE "WHERE id=:id"
        // idi — status kontrolü YOKTU. İki yetkili AYNI ANDA confirmDestroy()
        // çağırırsa, İKİSİ DE yukarıdaki SELECT'te status='pending' görür
        // (henüz kimse güncellemeden), İKİSİ DE bu UPDATE'i çalıştırır,
        // İKİSİ DE imha event'i ekleyip entity'yi DESTROYED yapmaya çalışır —
        // dört-göz'ün ÖNLEMEYE çalıştığı "tek onayla iki kez imha" riskini
        // FARKLI bir yoldan (eşzamanlı ikili onay) yeniden açar. Artık
        // "AND status='pending'" ile ATOMİK hale getirildi — kaybeden taraf
        // (0 satır etkilenirse) net bir hata alır, sessizce devam ETMEZ.
        $upd = $this->pdo->prepare(
            "UPDATE destroy_requests SET status = 'confirmed', confirmed_by_actor_id = :actor, confirmed_at = :now
             WHERE id = :id AND status = 'pending'"
        );
        $upd->execute([':actor' => $confirmingActorId, ':now' => $now, ':id' => $requestId]);

        if ($upd->rowCount() === 0) {
            throw new DestroyRequestException(
                "Destroy request #{$requestId}, SİZ onaylamaya çalışırken BAŞKA biri tarafından zaten "
                . 'onaylandı/iptal edildi (eşzamanlı onay yarışı). Talebin güncel durumunu kontrol edin.'
            );
        }

        $this->events->appendEvent((int) $request['entity_id'], 'destroyed', 'destroyed', $confirmingActorId, null, null, null, [
            'reason' => $request['reason'],
            'requested_by' => (int) $request['requested_by_actor_id'],
            'confirmed_by' => $confirmingActorId,
        ]);
        $this->entities->transitionStatus((int) $request['entity_id'], 'DESTROYED');
    }

    public function cancelRequest(int $requestId): void
    {
        $stmt = $this->pdo->prepare("UPDATE destroy_requests SET status = 'cancelled' WHERE id = :id AND status = 'pending'");
        $stmt->execute([':id' => $requestId]);
    }
}
