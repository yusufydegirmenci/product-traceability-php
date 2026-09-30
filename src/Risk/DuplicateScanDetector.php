<?php

declare(strict_types=1);

namespace Traceability\Risk;

use PDO;

/**
 * Catches Senaryo 1 from the plan: a code that is cloned/re-printed and
 * scanned into a packing/shipping step a second time while the original
 * is still legitimately in transit.
 *
 * Call checkAndFlag() BEFORE writing the new event, not after — this is a
 * gate, not just a log.
 */
final class DuplicateScanDetector
{
    private const ACTIVE_DISPOSITIONS = ['active', 'in_transit'];

    public function __construct(private PDO $pdo)
    {
    }

    public function checkAndFlag(
        int $entityId,
        string $incomingBizStep,
        ?int $incomingLocationId,
        ?int $actorId
    ): bool {
        $stmt = $this->pdo->prepare(
            "SELECT id, read_point_location_id, disposition
             FROM epcis_events
             WHERE entity_id = :id AND biz_step IN ('packing', 'shipping')
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':id' => $entityId]);
        $last = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($last === false) {
            return false; // first packing/shipping event for this entity — nothing suspicious
        }

        $stillActiveElsewhere = in_array($last['disposition'], self::ACTIVE_DISPOSITIONS, true);
        $differentLocation = (int) $last['read_point_location_id'] !== $incomingLocationId;

        if ($incomingBizStep === 'packing' && $stillActiveElsewhere && $differentLocation) {
            $this->flag($entityId, (int) $last['id'], $actorId);
            return true;
        }

        return false;
    }

    private function flag(int $entityId, int $firstEventId, ?int $actorId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO duplicate_scan_flags
                (entity_id, first_event_id, second_event_id, actor_id, flagged_at, resolved)
             VALUES (:entity_id, :first_event_id, NULL, :actor_id, :now, 0)'
        );
        $stmt->execute([
            ':entity_id' => $entityId,
            ':first_event_id' => $firstEventId,
            ':actor_id' => $actorId,
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }
}
