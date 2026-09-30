<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use Traceability\Risk\AuthorizationGuard;
use PDO;

/**
 * v1.2 AŞAMA 5 — HASARLI ÜRÜN / İPTAL MÜHÜR ENTEGRASYONU. Bir ürün
 * "hasarlı/kırık" diye işaretlendiğinde DAMAGED_HOLD durumuna geçer —
 * ama bu, çöp alanına atılıp sessizce kaybolabileceği anlamına GELMEZ.
 * Stoktan KESİN olarak düşülmesi (imha/hurda), bir süpervizörün fiziksel
 * hurda tutanağının referans numarasını girmesini ZORUNLU kılar. Bu,
 * "hasarlı diye işaretleyip aslında sağlam ürünü çöp üzerinden kaçırma"
 * zaafiyetini kapatır — kağıt izi olmadan hiçbir şey stoktan silinemez.
 */
final class DamagedItemService
{
    public function __construct(
        private PDO $pdo,
        private EntityRepository $entities,
        private AuthorizationGuard $authGuard
    ) {
    }

    /**
     * KASITLI TASARIM: bu metod rol kontrolü YAPMAZ — herhangi bir
     * personel gördüğü bir hasarı anında işaretleyebilmeli (düşük
     * sürtünme, hızlı raporlama). Asıl güvenlik kapısı scrapWithTutanak()'tadır.
     *
     * DENETİM BULGUSU (inceleme dosyası hazırlanırken bulundu): bu metod
     * $actorId parametresini alıyordu ama HİÇ KULLANMIYORDU — kim
     * işaretlediğine dair iz kalmıyordu. Artık risk_events'e bilgi
     * amaçlı (düşük önem) bir kayıt düşülüyor.
     */
    public function markDamaged(int $entityId, int $actorId): void
    {
        $this->entities->transitionStatus($entityId, 'DAMAGED_HOLD');
        $stmt = $this->pdo->prepare(
            "INSERT INTO risk_events (actor_id, event_type, severity, is_first_occurrence, detail, detected_at, resolved)
             VALUES (:actor, 'item_marked_damaged', 5, 0, :detail, :now, 1)"
        );
        $stmt->execute([
            ':actor' => $actorId,
            ':detail' => json_encode(['entity_id' => $entityId], JSON_UNESCAPED_UNICODE),
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Hasarlı ürünü stoktan KESİN olarak düşürür (DESTROYED) — sadece
     * süpervizör/yönetici, sadece dolu bir "Fiziki Hurda Tutanak Ref"
     * ile. Boş/geçersiz bir ref KABUL EDİLMEZ.
     */
    public function scrapWithTutanak(int $entityId, int $supervisorActorId, string $hurdaTutanakRef): void
    {
        if (trim($hurdaTutanakRef) === '') {
            throw new \RuntimeException('Fiziki Hurda Tutanak Ref ZORUNLUDUR — bu olmadan hasarlı ürün stoktan düşülemez.');
        }
        $this->authGuard->requireRole($supervisorActorId, ['supervizor', 'yonetici']);

        $entity = $this->entities->findById($entityId);
        if ($entity === null || $entity['status'] !== 'DAMAGED_HOLD') {
            throw new \RuntimeException("Entity #{$entityId} DAMAGED_HOLD durumunda değil — bu yol sadece hasarlı işaretli ürünler içindir.");
        }

        $this->entities->transitionStatus($entityId, 'DESTROYED');

        $stmt = $this->pdo->prepare(
            "INSERT INTO destroy_requests (entity_id, reason, requested_by_actor_id, requested_at, status, confirmed_by_actor_id, confirmed_at)
             VALUES (:entity, :reason, :actor, :now, 'confirmed', :actor, :now)"
        );
        $stmt->execute([
            ':entity' => $entityId,
            ':reason' => "Hasarlı ürün hurdaya ayrıldı — Tutanak Ref: {$hurdaTutanakRef}",
            ':actor' => $supervisorActorId,
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * KIDEMLI İNCELEME — SEÇENEK A'NIN SOMUT UYGULAMASI (SECURITY_REVIEW.md
     * Bölüm 3). `scrapWithTutanak()` YUKARIDA hâlâ TEK KİŞİLİK çalışıyor —
     * bu, kullanıcının literal isteğiyle uyumlu ORİJİNAL davranıştır ve
     * BİLEREK SİLİNMEDİ (geriye dönük uyumluluk + insan kararı bekleniyor).
     *
     * Bu iki metot, AYNI sonucu (hasarlı ürünün kalıcı imhası) `DestroyService`'in
     * MEVCUT dört-göz altyapısını kullanarak sunar — talep eden ve onaylayan
     * FARKLI kişi olmak ZORUNDADIR (bkz. DestroyService::confirmDestroy()).
     * Bir kıdemli mühendis, SECURITY_REVIEW.md Bölüm 3'te bu iki yoldan
     * HANGİSİNİN (ya da ikisinin, hacme göre) kalıcı olacağına karar
     * vermelidir — bu kod SADECE seçeneği somutlaştırır, seçimi YAPMAZ.
     */
    public function requestScrapViaFourEyes(int $entityId, int $requestingActorId, string $hurdaTutanakRef, DestroyService $destroyService): int
    {
        if (trim($hurdaTutanakRef) === '') {
            throw new \RuntimeException('Fiziki Hurda Tutanak Ref ZORUNLUDUR.');
        }
        $entity = $this->entities->findById($entityId);
        if ($entity === null || $entity['status'] !== 'DAMAGED_HOLD') {
            throw new \RuntimeException("Entity #{$entityId} DAMAGED_HOLD durumunda değil.");
        }
        return $destroyService->requestDestroy($entityId, $requestingActorId, "Hasarlı ürün hurdaya ayrılıyor — Tutanak Ref: {$hurdaTutanakRef}");
    }

    public function confirmScrapViaFourEyes(int $requestId, int $confirmingActorId, int $expectedProductId, DestroyService $destroyService): void
    {
        // DestroyService::confirmDestroy() ZATEN talep eden ≠ onaylayan
        // kuralını uyguluyor — burada AYRICA bir kontrol eklemeye gerek yok,
        // bu metodun tek amacı DamagedItemService API yüzeyinden erişilebilir kılmak.
        $destroyService->confirmDestroy($requestId, $confirmingActorId, $expectedProductId);
    }
}
