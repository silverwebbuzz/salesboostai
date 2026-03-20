<?php
/**
 * Analytics Revenue API: /app/api/analytics/revenue.php
 *
 * Returns JSON:
 * { "total": 1240.89, "trend": [...], "change": 18, "labels": [...] }
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

$range = (int)($_GET['range'] ?? 7);
if ($range !== 7 && $range !== 30) {
    $range = 7;
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

$labels = lastNDaysLabels($range, $tz);
$series = array_fill(0, count($labels), 0.0);
$idx = array_flip($labels);

$since = (new DateTime('now', new DateTimeZone($tz)))->modify('-' . ($range - 1) . ' days')->setTime(0, 0, 0);
$sinceStr = $since->format('Y-m-d H:i:s');
$sinceEsc = $mysqli->real_escape_string($sinceStr);

// Revenue per day (best-effort using JSON_EXTRACT)
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
            $series[$idx[$d]] = (float)($r['s'] ?? 0);
        }
    }
}

$total = 0.0;
foreach ($series as $v) $total += (float)$v;

// Compare to previous period (same length) for % change
$prevSince = (new DateTime('now', new DateTimeZone($tz)))->modify('-' . ((2 * $range) - 1) . ' days')->setTime(0, 0, 0);
$prevUntil = (new DateTime('now', new DateTimeZone($tz)))->modify('-' . ($range) . ' days')->setTime(23, 59, 59);
$prevSinceStr = $prevSince->format('Y-m-d H:i:s');
$prevUntilStr = $prevUntil->format('Y-m-d H:i:s');
$ps = $mysqli->real_escape_string($prevSinceStr);
$pu = $mysqli->real_escape_string($prevUntilStr);

$prevTotal = 0.0;
$prevRes = $mysqli->query(
    "SELECT SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.total_price')) AS DECIMAL(12,2))) AS s
     FROM `{$ordersTable}`
     WHERE COALESCE(created_at, fetched_at) BETWEEN '{$ps}' AND '{$pu}'"
);
if ($prevRes) {
    $row = $prevRes->fetch_assoc();
    $prevTotal = (float)($row['s'] ?? 0);
}

$change = 0;
if ($prevTotal > 0.0) {
    $change = (int)round((($total - $prevTotal) / $prevTotal) * 100);
}

echo json_encode([
    'total' => round($total, 2),
    'trend' => array_map(fn($v) => round((float)$v, 2), $series),
    'change' => $change,
    'labels' => $labels,
], JSON_UNESCAPED_UNICODE);

