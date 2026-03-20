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

    // Remove queue entries first.
    $stmt = $mysqli->prepare("DELETE FROM webhook_events WHERE shop=?");
    if ($stmt) {
        $stmt->bind_param('s', $shop);
        $stmt->execute();
        $stmt->close();
    }

    // Remove subscription / store.
    $stmt = $mysqli->prepare("DELETE FROM store_subscription WHERE shop=?");
    if ($stmt) {
        $stmt->bind_param('s', $shop);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $mysqli->prepare("DELETE FROM stores WHERE shop=?");
    if ($stmt) {
        $stmt->bind_param('s', $shop);
        $stmt->execute();
        $stmt->close();
    }

    // Remove per-store tables.
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
} catch (Throwable $e) {
    webhookLog(['event' => 'shop_redact_processing_error', 'shop' => $shop, 'error' => $e->getMessage()]);
}

