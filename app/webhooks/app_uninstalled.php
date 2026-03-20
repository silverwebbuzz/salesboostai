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

    // Mark and delete store-scoped data.
    $stmt = $mysqli->prepare("UPDATE stores SET status='uninstalled', updated_at=NOW() WHERE shop=?");
    if ($stmt) {
        $stmt->bind_param('s', $shop);
        $stmt->execute();
        $stmt->close();
    }

    markSubscriptionUninstalled($shop);

    // Remove async webhook queue entries.
    $stmt = $mysqli->prepare("DELETE FROM webhook_events WHERE shop=?");
    if ($stmt) {
        $stmt->bind_param('s', $shop);
        $stmt->execute();
        $stmt->close();
    }

    // Best-effort: delete per-store tables.
    $shopName = makeShopName($shop);
    $tables = [
        perStoreTableName($shopName, 'order'),
        perStoreTableName($shopName, 'customer'),
        perStoreTableName($shopName, 'products_inventory'),
        perStoreTableName($shopName, 'analytics'),
    ];
    foreach ($tables as $table) {
        if (preg_match('/^[a-z0-9_]{1,64}$/', $table) === 1) {
            $mysqli->query("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    // Remove store row last.
    $stmt = $mysqli->prepare("DELETE FROM stores WHERE shop=?");
    if ($stmt) {
        $stmt->bind_param('s', $shop);
        $stmt->execute();
        $stmt->close();
    }
} catch (Throwable $e) {
    webhookLog(['event' => 'app_uninstalled_processing_error', 'shop' => $shop, 'error' => $e->getMessage()]);
}

