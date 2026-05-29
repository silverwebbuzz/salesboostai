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

    // Mark uninstalled first so any concurrent request sees the correct state.
    $stmt = $mysqli->prepare("UPDATE stores SET status='uninstalled', updated_at=NOW() WHERE shop=?");
    if ($stmt) {
        $stmt->bind_param('s', $shop);
        $stmt->execute();
        $stmt->close();
    }

    markSubscriptionUninstalled($shop);

    // Purge ALL store-scoped artifacts: sync progress, subscription, webhook
    // queue, and every per-store data table. Removing store_sync_state is the
    // critical part — without it, a later reinstall keeps the stale 'done'
    // status and never re-syncs (which is the reported bug).
    purgeStoreData($shop);

    // Remove store row last.
    $stmt = $mysqli->prepare("DELETE FROM stores WHERE shop=?");
    if ($stmt) {
        $stmt->bind_param('s', $shop);
        $stmt->execute();
        $stmt->close();
    }

    webhookLog(['event' => 'app_uninstalled_cleanup_done', 'shop' => $shop]);
} catch (Throwable $e) {
    webhookLog(['event' => 'app_uninstalled_processing_error', 'shop' => $shop, 'error' => $e->getMessage()]);
}

