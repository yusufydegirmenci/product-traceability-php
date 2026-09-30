<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use PDO;

/**
 * Bir "ürün gelmedi/eksik" iddiası sonrası ürün yeniden gönderildiğinde,
 * orijinal ve yeni birim BAĞLANTILI kaydedilir. Sektör pratiği (Bigblue'nun
 * "never refund a reshipped order twice" özelliği) tam olarak bunu çözüyor:
 * kayıp sanılan ürün sonradan geri bulunur/teslim edilirse, hem yeniden
 * gönderilen hem orijinal için ayrı ayrı para iadesi/tazminat verilmesi
 * riskini ortadan kaldırır.
 */
final class ReshipmentService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function linkReshipment(int $originalEntityId, int $replacementEntityId, ?int $claimId, string $reason): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO reshipments (original_entity_id, replacement_entity_id, claim_id, reason, created_at)
             VALUES (:original, :replacement, :claim_id, :reason, :now)'
        );
        $stmt->execute([
            ':original' => $originalEntityId,
            ':replacement' => $replacementEntityId,
            ':claim_id' => $claimId,
            ':reason' => $reason,
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Bir entity daha önce "kayıp" sayılıp yerine başka bir ürün gönderilmiş mi?
     * Bu true dönerse ve şimdi bu entity için de bir iade/tazminat talebi
     * geliyorsa, ÖDEME YAPMADAN ÖNCE bir insanın "çift ödeme mi oluyor"
     * diye kontrol etmesi gerekir — otomatik engelleme değil, otomatik uyarı.
     */
    public function wasAlreadyReplaced(int $entityId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM reshipments WHERE original_entity_id = :id');
        $stmt->execute([':id' => $entityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }
}
