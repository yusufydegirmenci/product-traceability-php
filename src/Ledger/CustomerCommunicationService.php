<?php

declare(strict_types=1);

namespace Traceability\Ledger;

use PDO;

/**
 * "Siparişinizin ödemesi onaylandı", "depoya iletildi", "kargoya verildi"
 * gibi İŞLEMSEL (transactional) durum mesajlarını kuyruğa alır.
 *
 * ÖNEMLİ — ASLA PAZARLAMA İÇERİĞİ EKLEME: Türkiye'de Ticari Elektronik
 * İleti Yönetmeliği kapsamında pazarlama/tanıtım amaçlı mesajlar için
 * İYS (İleti Yönetim Sistemi) onayı gerekir; sipariş durumu gibi işlemsel
 * bildirimler genelde bu onaydan muaftır. Ama bir şablona "kampanyamızı
 * kaçırma" gibi tek bir promosyon cümlesi eklemek bile mesajı "ticari
 * ileti" kategorisine sokup İYS onayı gerektirebilir. Ben avukat değilim,
 * bu satır kamuya açık mevzuat okumasına dayanıyor — gerçek uygulamaya
 * geçmeden önce doğrulatılmalı. Kod tarafında alınan önlem: şablonlar
 * SADECE durum bilgisi içerir, hiçbir tanıtım/kampanya metni barındırmaz.
 *
 * GÜVENLİK/MAHREMİYET: Şablonlar kasıtlı olarak NÖTR — örneğin bir sağlık
 * şikayeti iadesinde mesaj "iade talebiniz işlendi" der, "sağlık şikayeti"
 * kelimesini asla yazmaz. Bir SMS/e-posta paylaşılan bir cihazda okunabilir;
 * hassas kategori detayını mesaj metnine yazmak bir mahremiyet sızıntısıdır.
 */
final class CustomerCommunicationService
{
    private const TEMPLATES = [
        'payment_confirmed' => 'Siparişiniz (#%s) için ödemeniz onaylandı.',
        'sent_to_warehouse' => 'Siparişiniz (#%s) depomuza iletildi, hazırlanıyor.',
        'packed' => 'Siparişiniz (#%s) paketlendi.',
        'shipped' => 'Siparişiniz (#%s) kargoya verildi. Takip no: %s',
        'delivered' => 'Siparişiniz (#%s) teslim edildi.',
        'return_accepted' => 'İade talebiniz (#%s) kabul edildi.',
        'return_rejected' => 'İade talebiniz (#%s) için ek bilgiye ihtiyacımız var, lütfen bizimle iletişime geçin.',
        'refund_paid' => 'İade tutarınız (%s TL) hesabınıza yatırıldı.',
        'claim_received' => 'Talebiniz (#%s) alındı, inceleniyor.',
        'investigation_resolved' => 'Talebinizle (#%s) ilgili incelememiz sonuçlandı, detaylar için backoffice hesabınızı kontrol edin.',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function notify(
        int $actorId,
        string $messageType,
        array $params = [],
        string $channel = 'backoffice_inbox',
        ?int $entityId = null,
        ?string $orderRef = null
    ): int {
        $template = self::TEMPLATES[$messageType] ?? null;
        if ($template === null) {
            throw new \InvalidArgumentException("Bilinmeyen mesaj tipi: {$messageType}");
        }
        $text = vsprintf($template, $params);

        $stmt = $this->pdo->prepare(
            'INSERT INTO customer_messages (actor_id, entity_id, order_ref, message_type, channel, message_text, status, created_at)
             VALUES (:actor_id, :entity_id, :order_ref, :type, :channel, :text, :status, :now)'
        );
        $stmt->execute([
            ':actor_id' => $actorId,
            ':entity_id' => $entityId,
            ':order_ref' => $orderRef,
            ':type' => $messageType,
            ':channel' => $channel,
            ':text' => $text,
            ':status' => 'queued',
            ':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $messageId = (int) $this->pdo->lastInsertId();

        // Production'da: burada gerçek bir SMS (örn. İleti Merkezi/Netgsm)
        // veya e-posta (örn. bir SMTP/API sağlayıcısı) gateway'i çağrılır.
        // Şimdilik "kuyruğa alındı" durumunda bırakıyoruz — gönderim
        // adaptörü eklenene kadar gerçek bir mesaj GİTMEZ, sadece kaydedilir.
        return $messageId;
    }

    public function markSent(int $messageId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE customer_messages SET status = 'sent', sent_at = :now WHERE id = :id"
        );
        $stmt->execute([':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), ':id' => $messageId]);
    }

    public function myMessages(int $actorId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM customer_messages WHERE actor_id = :actor ORDER BY id DESC');
        $stmt->execute([':actor' => $actorId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
