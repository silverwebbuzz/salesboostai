<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/auth.php';

header('Content-Type: application/json');

$shop = resolveApiShopFromToken($_GET['shop'] ?? null);

$store = getShopByDomain($shop);
if (!$store || (($store['status'] ?? '') === 'uninstalled')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Store not installed.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode(file_get_contents('php://input') ?: '{}', true);
$actionKey = is_array($payload) ? (string)($payload['action_key'] ?? '') : '';
$status = is_array($payload) ? strtolower((string)($payload['status'] ?? '')) : '';
$allowed = ['new', 'viewed', 'acted', 'dismissed'];
if ($actionKey === '' || !in_array($status, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid action update payload.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$mysqli = db();
$actionTable = perStoreTableName(makeShopName($shop), 'action_items');
$stmt = $mysqli->prepare("UPDATE `{$actionTable}` SET status = ?, updated_at = NOW() WHERE action_key = ? LIMIT 1");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to prepare action update.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$stmt->bind_param('ss', $status, $actionKey);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

echo json_encode(['ok' => true, 'updated' => max(0, (int)$affected)], JSON_UNESCAPED_UNICODE);

