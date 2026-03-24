<?php
/**
 * Analytics AOV API: /app/api/analytics/aov.php
 *
 * Returns JSON:
 * { "value": 310.22, "trend": [...], "labels": [...] }
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/entitlements.php';

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

$entitlements = function_exists('getPlanEntitlements') ? getPlanEntitlements($shop) : ['features' => []];
if (!canAccessFeature($entitlements, 'analytics_aov')) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'AOV analytics is not available on your current plan.',
        'required_plan' => getFeatureRequiredPlan('analytics_aov'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$mysqli = db();
$shopName = makeShopName($shop);
$ordersTable = perStoreTableName($shopName, 'order');

$tz = (string)($store['iana_timezone'] ?? '');
if ($tz === '') $tz = 'UTC';

function lastNDaysLabels(int $days, string $tz): array {
    $out = [];
    $dt = new DateTime('now', new DateTimeZone($tz));
    $dt->setTime(0, 0, 0);
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = clone $dt;
        $d->modify("-{$i} days");
        $out[] = $d->format('Y-m-d');
    }
    return $out;
}

$days = 30;
$labels = lastNDaysLabels($days, $tz);
$idx = array_flip($labels);
$rev = array_fill(0, count($labels), 0.0);
$ord = array_fill(0, count($labels), 0);

$since = (new DateTime('now', new DateTimeZone($tz)))->modify('-29 days')->setTime(0, 0, 0);
$sinceStr = $since->format('Y-m-d H:i:s');
$sinceEsc = $mysqli->real_escape_string($sinceStr);

$ordersByDay = $mysqli->query(
    "SELECT DATE(COALESCE(created_at, fetched_at)) AS d, COUNT(*) AS c
     FROM `{$ordersTable}`
     WHERE COALESCE(created_at, fetched_at) >= '{$sinceEsc}'
     GROUP BY d"
);
if ($ordersByDay) {
    while ($r = $ordersByDay->fetch_assoc()) {
        $d = (string)($r['d'] ?? '');
        if ($d !== '' && isset($idx[$d])) {
            $ord[$idx[$d]] = (int)($r['c'] ?? 0);
        }
    }
}

$revByDay = $mysqli->query(
    "SELECT DATE(COALESCE(created_at, fetched_at)) AS d,
            SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.total_price')) AS DECIMAL(12,2))) AS s
     FROM `{$ordersTable}`
     WHERE COALESCE(created_at, fetched_at) >= '{$sinceEsc}'
     GROUP BY d"
);
if ($revByDay) {
    while ($r = $revByDay->fetch_assoc()) {
        $d = (string)($r['d'] ?? '');
        if ($d !== '' && isset($idx[$d])) {
            $rev[$idx[$d]] = (float)($r['s'] ?? 0);
        }
    }
}

$trend = [];
$totalRev = 0.0;
$totalOrd = 0;
for ($i = 0; $i < count($labels); $i++) {
    $totalRev += (float)$rev[$i];
    $totalOrd += (int)$ord[$i];
    $trend[] = $ord[$i] > 0 ? round(((float)$rev[$i]) / max(1, (int)$ord[$i]), 2) : 0.0;
}

$value = $totalOrd > 0 ? round($totalRev / $totalOrd, 2) : 0.0;

echo json_encode([
    'value' => $value,
    'trend' => $trend,
    'labels' => $labels,
], JSON_UNESCAPED_UNICODE);

