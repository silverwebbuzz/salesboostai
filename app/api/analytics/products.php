<?php
/**
 * Analytics Products API: /app/api/analytics/products.php
 *
 * Returns JSON:
 * { "top": [...], "worst": [...] }
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/auth.php';

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

$mysqli = db();
$shopName = makeShopName($shop);
$ordersTable = perStoreTableName($shopName, 'order');

$tz = (string)($store['iana_timezone'] ?? '');
if ($tz === '') $tz = 'UTC';

$since = (new DateTime('now', new DateTimeZone($tz)))->modify('-29 days')->setTime(0, 0, 0);
$sinceStr = $since->format('Y-m-d H:i:s');

$agg = [];

$stmt = $mysqli->prepare(
    "SELECT payload_json FROM `{$ordersTable}`
     WHERE COALESCE(created_at, fetched_at) >= ?
     ORDER BY COALESCE(created_at, fetched_at) DESC
     LIMIT 200"
);
if ($stmt) {
    $stmt->bind_param('s', $sinceStr);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $p = json_decode((string)($row['payload_json'] ?? ''), true);
        if (!is_array($p)) continue;

        $lineItems = isset($p['line_items']) && is_array($p['line_items']) ? $p['line_items'] : [];
        foreach ($lineItems as $li) {
            if (!is_array($li)) continue;
            $title = (string)($li['title'] ?? '');
            if ($title === '') continue;
            $qty = isset($li['quantity']) ? (int)$li['quantity'] : 0;
            $price = isset($li['price']) ? (float)$li['price'] : 0.0;

            if (!isset($agg[$title])) {
                $agg[$title] = ['title' => $title, 'quantity' => 0, 'revenue' => 0.0];
            }
            $agg[$title]['quantity'] += $qty;
            $agg[$title]['revenue'] += ($price * max(0, $qty));
        }
    }
    $stmt->close();
}

$items = array_values($agg);

usort($items, fn($a, $b) => ((float)($b['revenue'] ?? 0)) <=> ((float)($a['revenue'] ?? 0)));
$top = array_slice($items, 0, 5);

usort($items, fn($a, $b) => ((float)($a['revenue'] ?? 0)) <=> ((float)($b['revenue'] ?? 0)));
$worst = array_slice($items, 0, 5);

// Normalize rounding
$top = array_map(function ($x) {
    return [
        'title' => (string)($x['title'] ?? ''),
        'quantity' => (int)($x['quantity'] ?? 0),
        'revenue' => round((float)($x['revenue'] ?? 0), 2),
    ];
}, $top);

$worst = array_map(function ($x) {
    return [
        'title' => (string)($x['title'] ?? ''),
        'quantity' => (int)($x['quantity'] ?? 0),
        'revenue' => round((float)($x['revenue'] ?? 0), 2),
    ];
}, $worst);

echo json_encode([
    'top' => $top,
    'worst' => $worst,
], JSON_UNESCAPED_UNICODE);

