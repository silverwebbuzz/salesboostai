<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/webhook.php';

$event = validateWebhookRequest();
$shop = (string)$event['shop'];
$topic = (string)$event['topic'];
$webhookId = (string)($event['webhook_id'] ?? '');

respondWebhookAccepted();
webhookLog(['event' => 'incoming_webhook', 'topic' => $topic, 'shop' => $shop, 'webhook_id' => $webhookId]);

try {
    $mysqli = db();

    // GDPR shop/redact: erase every store-scoped artifact. purgeStoreData()
    // removes sync progress, subscription, webhook queue, and all per-store
    // data tables; we then remove the stores row itself.
    purgeStoreData($shop);

    $stmt = $mysqli->prepare("DELETE FROM stores WHERE shop=?");
    if ($stmt) {
        $stmt->bind_param('s', $shop);
        $stmt->execute();
        $stmt->close();
    }

    webhookLog(['event' => 'shop_redact_cleanup_done', 'shop' => $shop]);
} catch (Throwable $e) {
    webhookLog(['event' => 'shop_redact_processing_error', 'shop' => $shop, 'error' => $e->getMessage()]);
}

