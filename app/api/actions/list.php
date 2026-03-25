<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/metrics.php';

header('Content-Type: application/json');

$shop = sanitizeShopDomain($_GET['shop'] ?? null);
if ($shop === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid shop.'], JSON_UNESCAPED_UNICODE);
    exit;
}

requireSessionTokenAuth($shop);

$store = getShopByDomain($shop);
if (!$store || (($store['status'] ?? '') === 'uninstalled')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Store not installed.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$status = strtolower(trim((string)($_GET['status'] ?? 'new')));
$allowed = ['new', 'viewed', 'acted', 'dismissed'];
if (!in_array($status, $allowed, true)) $status = 'new';

$limit = (int)($_GET['limit'] ?? 20);
$limit = max(1, min(100, $limit));

$items = [];
try {
    $mysqli = db();
    $shopName = makeShopName($shop);
    $actionTable = perStoreTableName($shopName, 'action_items');

    $safe = $mysqli->real_escape_string($actionTable);
    $exists = $mysqli->query("SHOW TABLES LIKE '{$safe}'");
    if ($exists && $exists->num_rows > 0) {
        $stmt = $mysqli->prepare(
            "SELECT action_key, title, description, severity, impact_score, confidence_score, status, owner_section, cta_label, cta_url, source_json
             FROM `{$actionTable}`
             WHERE status = ?
             ORDER BY impact_score DESC, updated_at DESC
             LIMIT ?"
        );
        if ($stmt) {
            $stmt->bind_param('si', $status, $limit);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $items[] = [
                    'key' => (string)($r['action_key'] ?? ''),
                    'title' => (string)($r['title'] ?? ''),
                    'description' => (string)($r['description'] ?? ''),
                    'severity' => (string)($r['severity'] ?? 'medium'),
                    'impact_score' => round((float)($r['impact_score'] ?? 0), 2),
                    'confidence_score' => round((float)($r['confidence_score'] ?? 0), 2),
                    'status' => (string)($r['status'] ?? 'new'),
                    'owner_section' => (string)($r['owner_section'] ?? ''),
                    'cta_label' => (string)($r['cta_label'] ?? 'View details'),
                    'cta_url' => (string)($r['cta_url'] ?? '#'),
                    'why' => (string)($r['source_json'] ?? ''),
                ];
            }
            $stmt->close();
        }
    }
} catch (Throwable $e) {
    $items = [];
}

echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);

