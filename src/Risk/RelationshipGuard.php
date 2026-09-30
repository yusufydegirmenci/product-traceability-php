<?php

declare(strict_types=1);

namespace Traceability\Risk;

use PDO;


/**
 * Bir süpervizör/yönetici, KENDİSİYLE kişisel/duygusal ilişkisi olan bir
 * personelin işlemini onaylayamaz veya soruşturamaz — bu, "dört göz"
 * ilkesinin (bkz. DestroyService) doğal bir uzantısı: farklı bir kişi
 * olmak yetmez, GERÇEKTEN BAĞIMSIZ bir kişi olmalı.
 *
 * İlişki beyanı gönüllü/İK kaydına dayanır — sistem bir ilişkiyi
 * "keşfedemez", sadece BEYAN EDİLMİŞ bir ilişkiyi kontrol noktalarında
 * uygular. Bu yüzden şirket politikası olarak ilişki beyanının zorunlu
 * olması (ve beyan etmemenin kendisinin bir disiplin nedeni olması)
 * bu kontrolün gerçekten işlemesi için şarttır — kod bunu tek başına
 * garanti edemez.
 */
final class RelationshipGuard
{
    public function __construct(private PDO $pdo)
    {
    }

    public function declareRelationship(int $actorId1, int $actorId2, string $type = 'romantic'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO actor_relationships (actor_id_1, actor_id_2, relationship_type, declared_at) VALUES (:a1, :a2, :type, :now)'
        );
        $stmt->execute([
            ':a1' => $actorId1,
            ':a2' => $actorId2,
            ':type' => $type,
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function hasRelationship(int $actorId1, int $actorId2): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM actor_relationships
             WHERE (actor_id_1 = :a1 AND actor_id_2 = :a2) OR (actor_id_1 = :a2 AND actor_id_2 = :a1)'
        );
        $stmt->execute([':a1' => $actorId1, ':a2' => $actorId2]);
        return ((int) $stmt->fetchColumn()) > 0;
    }

    /**
     * $approverActorId, $involvedActorIds içindeki HERHANGİ biriyle
     * ilişkiliyse istisna fırlatır — onay/inceleme başka, TAMAMEN
     * tarafsız bir kişiye devredilmeli.
     */
    public function assertNoConflict(int $approverActorId, array $involvedActorIds): void
    {
        foreach (array_unique(array_filter($involvedActorIds)) as $involvedId) {
            if ((int) $involvedId === $approverActorId) {
                continue; // kendi kendine onay zaten ayrı kurallarla engelleniyor
            }
            if ($this->hasRelationship($approverActorId, (int) $involvedId)) {
                throw new RelationshipConflictException(
                    "Actor #{$approverActorId}, actor #{$involvedId} ile beyan edilmiş bir ilişkiye sahip — "
                    . 'çıkar çatışması nedeniyle bu onayı/incelemeyi yapamaz. TAMAMEN tarafsız üçüncü bir kişi gerekli.'
                );
            }
        }
    }
}
