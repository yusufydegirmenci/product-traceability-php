<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use Traceability\Risk\AuthorizationGuard;
use Traceability\Risk\RelationshipGuard;
use PDO;

/**
 * Bir müşteri/bayi "böyle oldu" dediğinde, bu ASLA doğrudan "böyle olmuştur"
 * anlamına gelmez. Bu sınıf üç kaynağı kasıtlı olarak ayrı tutar:
 *
 *   - CUSTOMER_CLAIM  (trust_level: customer_input)  → iddia
 *   - SYSTEM_EVENT     (epcis_events, bizim kendi kaydımız) → yüksek güven
 *   - CARRIER_EVENT    (trust_level: carrier_api) → orta güven, kargo firması
 *     da hata yapabilir (yanlış zaman damgası, gecikmeli webhook, vb.)
 *
 * fileClaim() bir iddiayı KAYDEDER, hiçbir şeyi doğrulamaz.
 * investigate() bunları YAN YANA getirir ve bir İNSANIN vardığı sonucu
 * kaydeder — sistem kendi başına "hırsızlık/suistimal" gibi bir sonuç
 * ÜRETMEZ, sadece kanıtı bir araya toplar.
 */

final class ClaimService
{
    public function __construct(
        private PDO $pdo,
        private ?CustomerCommunicationService $messenger = null,
        private ?AuthorizationGuard $authGuard = null,
        private ?RelationshipGuard $relationshipGuard = null
    ) {
    }

    public function fileClaim(
        ?int $entityId,
        ?string $orderRef,
        string $claimType,
        string $claimText,
        ?int $claimedByActorId
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO customer_claims (entity_id, order_ref, claim_type, claim_text, claimed_by_actor_id, trust_level, status, created_at)
             VALUES (:entity_id, :order_ref, :claim_type, :claim_text, :actor_id, :trust, :status, :now)'
        );
        $stmt->execute([
            ':entity_id' => $entityId,
            ':order_ref' => $orderRef,
            ':claim_type' => $claimType,
            ':claim_text' => $claimText,
            ':actor_id' => $claimedByActorId,
            ':trust' => 'customer_input',
            ':status' => 'open',
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $claimId = (int) $this->pdo->lastInsertId();

        if ($this->messenger !== null && $claimedByActorId !== null) {
            $this->messenger->notify($claimedByActorId, 'claim_received', [(string) $claimId]);
        }

        return $claimId;
    }

    public function recordCarrierEvent(
        ?int $entityId,
        ?string $orderRef,
        string $carrierName,
        string $carrierEventCode,
        \DateTimeImmutable $carrierReportedTime,
        array $rawPayload = [],
        string $proofType = 'none', // 'otp' | 'signature' | 'photo' | 'gps' | 'none'
        ?string $proofReference = null
    ): int {
        $now = new \DateTimeImmutable();
        // Kargo firmasının bildirdiği an ile bizim öğrendiğimiz an arasında
        // anormal bir fark varsa (örn. "3 gün önce teslim ettim" diyorsa),
        // bunu otomatik olarak "disputed" işaretle — körü körüne güvenme.
        $gapHours = abs($now->getTimestamp() - $carrierReportedTime->getTimestamp()) / 3600;
        $disputed = $gapHours > 48;

        $stmt = $this->pdo->prepare(
            'INSERT INTO carrier_events (entity_id, order_ref, carrier_name, carrier_event_code, event_time, received_at, proof_type, proof_reference, raw_payload, trust_level, disputed, created_at)
             VALUES (:entity_id, :order_ref, :carrier, :code, :event_time, :received_at, :proof_type, :proof_ref, :payload, :trust, :disputed, :now)'
        );
        $stmt->execute([
            ':entity_id' => $entityId,
            ':order_ref' => $orderRef,
            ':carrier' => $carrierName,
            ':code' => $carrierEventCode,
            ':event_time' => $carrierReportedTime->format('Y-m-d H:i:s'),
            ':received_at' => $now->format('Y-m-d H:i:s'),
            ':proof_type' => $proofType,
            ':proof_ref' => $proofReference,
            ':payload' => json_encode($rawPayload, JSON_UNESCAPED_UNICODE),
            ':trust' => 'carrier_api',
            ':disputed' => $disputed ? 1 : 0,
            ':now' => $now->format('Y-m-d H:i:s'),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * İnsan (denetçi/süpervizör) bir iddiayı inceledikten sonra vardığı
     * sonucu kaydeder. $conclusion asla otomatik hesaplanmaz, çağıran kod
     * (bir insan arayüzü) tarafından verilir.
     */
    public function recordInvestigation(
        int $claimId,
        array $linkedEventIds,
        array $linkedCarrierEventIds,
        string $conclusion,
        int $investigatedByActorId,
        ?string $notes = null
    ): int {
        // Bütünlük kuralı 1: talebi AÇAN kişi kendi talebini soruşturamaz.
        $claimRow = $this->pdo->prepare('SELECT claimed_by_actor_id FROM customer_claims WHERE id = :id');
        $claimRow->execute([':id' => $claimId]);
        $claimedByActorId = $claimRow->fetchColumn();
        if ($claimedByActorId !== false && (int) $claimedByActorId === $investigatedByActorId) {
            throw new SelfInvestigationException(
                "Actor #{$investigatedByActorId} bu talebi kendisi açmış — kendi talebini soruşturamaz."
            );
        }

        // Bütünlük kuralı 2: bir olayın öznesi (paketleyen, kargolayan, vb.
        // personel) KENDİ olayını soruşturamaz — başka bir denetçi gerekir.
        if ($linkedEventIds !== []) {
            $placeholders = implode(',', array_fill(0, count($linkedEventIds), '?'));
            $stmt = $this->pdo->prepare("SELECT DISTINCT actor_id FROM epcis_events WHERE id IN ({$placeholders})");
            $stmt->execute($linkedEventIds);
            $involvedActorIds = array_map('intval', array_filter($stmt->fetchAll(PDO::FETCH_COLUMN)));
            if (in_array($investigatedByActorId, $involvedActorIds, true)) {
                throw new SelfInvestigationException(
                    "Actor #{$investigatedByActorId} olayın kendisinde yer alıyor — kendi olayını soruşturamaz. "
                    . 'Farklı bir denetçi atanmalı.'
                );
            }
        }

        // Bütünlük kuralı 3: sadece supervizor/yönetici rolü soruşturma sonucu kaydedebilir.
        if ($this->authGuard !== null) {
            $this->authGuard->requireRole($investigatedByActorId, ['supervizor', 'yonetici']);
        }

        // Bütünlük kuralı 4: soruşturan kişi, olayla ilgili biriyle (talebi
        // açan veya olaya karışan) beyan edilmiş bir ilişkiye sahipse
        // çıkar çatışması nedeniyle devam edemez.
        if ($this->relationshipGuard !== null) {
            $involved = $linkedEventIds !== [] ? $this->actorIdsForEvents($linkedEventIds) : [];
            if ($claimedByActorId !== false) {
                $involved[] = (int) $claimedByActorId;
            }
            $this->relationshipGuard->assertNoConflict($investigatedByActorId, $involved);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO investigation_results (claim_id, linked_event_ids, linked_carrier_event_ids, conclusion, investigated_by_actor_id, investigated_at, notes)
             VALUES (:claim_id, :events, :carrier_events, :conclusion, :actor_id, :now, :notes)'
        );
        $stmt->execute([
            ':claim_id' => $claimId,
            ':events' => json_encode($linkedEventIds),
            ':carrier_events' => json_encode($linkedCarrierEventIds),
            ':conclusion' => $conclusion,
            ':actor_id' => $investigatedByActorId,
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ':notes' => $notes,
        ]);

        $update = $this->pdo->prepare("UPDATE customer_claims SET status = 'resolved' WHERE id = :id");
        $update->execute([':id' => $claimId]);

        if ($this->messenger !== null && $claimedByActorId !== false) {
            $this->messenger->notify((int) $claimedByActorId, 'investigation_resolved', [(string) $claimId]);
        }

        return (int) $this->pdo->lastInsertId();
    }

    private function actorIdsForEvents(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $stmt = $this->pdo->prepare("SELECT DISTINCT actor_id FROM epcis_events WHERE id IN ({$placeholders})");
        $stmt->execute($eventIds);
        return array_map('intval', array_filter($stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** Bir iddiayla ilgili tüm sistem olaylarını (salt-okunur) getirir — karar vermez, sadece toplar. */
    public function gatherEvidenceForClaim(int $claimId): array
    {
        $claim = $this->pdo->prepare('SELECT * FROM customer_claims WHERE id = :id');
        $claim->execute([':id' => $claimId]);
        $claimRow = $claim->fetch(PDO::FETCH_ASSOC);

        if ($claimRow === false || $claimRow['entity_id'] === null) {
            return ['claim' => $claimRow, 'system_events' => [], 'carrier_events' => []];
        }

        $entityId = (int) $claimRow['entity_id'];

        $sysEvents = $this->pdo->prepare('SELECT * FROM epcis_events WHERE entity_id = :id ORDER BY id ASC');
        $sysEvents->execute([':id' => $entityId]);

        $carrierEvents = $this->pdo->prepare('SELECT * FROM carrier_events WHERE entity_id = :id ORDER BY id ASC');
        $carrierEvents->execute([':id' => $entityId]);

        return [
            'claim' => $claimRow,
            'system_events' => $sysEvents->fetchAll(PDO::FETCH_ASSOC),
            'carrier_events' => $carrierEvents->fetchAll(PDO::FETCH_ASSOC),
        ];
    }
}
