<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use PDO;

/**
 * Bir bayi/müşteri haksız yere bir iddiayı DIŞARIDA (sosyal medya, şikayet
 * sitesi, basın) büyütmeye karar verirse, sistemin bunu ENGELLEME gücü
 * yoktur — ama sistemin ELDE HAZIR, ÇÜRÜTÜLEMEZ KANIT olma gücü vardır.
 * Bu servis, bir iddiayla ilgili HER ŞEYİ (sistem olayları + hash-chain
 * doğrulaması + kargo kayıtları + fotoğraf referansları + inceleme
 * sonucu + kimlerin dahil olduğu) tek, sunulabilir bir dosyada toplar.
 *
 * İtibar saldırısına karşı asıl savunma budur: saatler değil, SANİYELER
 * içinde hukuk/basın ekibine somut, doğrulanmış bir cevap verebilmek.
 */
final class CaseFileExportService
{
    public function __construct(
        private PDO $pdo,
        private EventStore $events
    ) {
    }

    public function exportCase(int $claimId): array
    {
        $claimStmt = $this->pdo->prepare('SELECT * FROM customer_claims WHERE id = :id');
        $claimStmt->execute([':id' => $claimId]);
        $claim = $claimStmt->fetch(PDO::FETCH_ASSOC);
        if ($claim === false) {
            throw new \RuntimeException("Claim #{$claimId} bulunamadı.");
        }

        $invStmt = $this->pdo->prepare('SELECT * FROM investigation_results WHERE claim_id = :id ORDER BY id DESC LIMIT 1');
        $invStmt->execute([':id' => $claimId]);
        $investigation = $invStmt->fetch(PDO::FETCH_ASSOC);

        $systemEvents = [];
        $chainVerified = null;
        if ($claim['entity_id'] !== null) {
            $entityId = (int) $claim['entity_id'];
            $systemEvents = $this->events->history($entityId);
            $chainVerified = $this->events->verifyChain($entityId);
        }

        $carrierEvents = [];
        if ($claim['entity_id'] !== null) {
            $stmt = $this->pdo->prepare('SELECT * FROM carrier_events WHERE entity_id = :id ORDER BY id ASC');
            $stmt->execute([':id' => $claim['entity_id']]);
            $carrierEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Olaylarda geçen kişilerin kimliğini (ad + rol) çözümle — anonim
        // "actor #4" değil, "Mehmet K. (depo_gorevlisi)" gibi sunulabilir olsun.
        $actorIds = array_unique(array_filter(array_map(static fn ($e) => $e['actor_id'] ?? null, $systemEvents)));
        $actors = [];
        if ($actorIds !== []) {
            $placeholders = implode(',', array_fill(0, count($actorIds), '?'));
            $stmt = $this->pdo->prepare("SELECT id, name, role FROM actors WHERE id IN ({$placeholders})");
            $stmt->execute(array_values($actorIds));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $actors[(int) $row['id']] = ['name' => $row['name'], 'role' => $row['role']];
            }
        }

        return [
            'exported_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'claim' => $claim,
            'investigation' => $investigation,
            'system_events' => $systemEvents,
            'chain_integrity_verified' => $chainVerified,
            'carrier_events' => $carrierEvents,
            'involved_actors' => $actors,
            'summary' => $this->buildSummaryLine($claim, $investigation, $chainVerified),
        ];
    }

    private function buildSummaryLine(array $claim, ?array $investigation, ?bool $chainVerified): string
    {
        $conclusion = $investigation['conclusion'] ?? 'henüz sonuçlanmamış';
        $chainNote = $chainVerified === null ? '' : ($chainVerified ? ', kayıt zinciri doğrulandı (değiştirilmemiş)' : ', UYARI: kayıt zincirinde tutarsızlık tespit edildi');
        return "Talep #{$claim['id']} ({$claim['claim_type']}) — sonuç: {$conclusion}{$chainNote}.";
    }
}
