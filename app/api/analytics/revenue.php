<?php
/**
 * Analytics Revenue API: /app/api/analytics/revenue.php
 *
 * Returns JSON:
 * { "total": 1240.89, "trend": [...], "change": 18, "labels": [...] }
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
$entitlements = function_exists('getPlanEntitlements') ? getPlanEntitlements($shop) : ['features' => [], 'limits' => []];
$features = is_array($entitlements['features'] ?? null) ? $entitlements['features'] : [];
$limits = is_array($entitlements['limits'] ?? null) ? $entitlements['limits'] : [];
$funnelEnabled = (bool)($features['analytics_funnel'] ?? false);
$attributionEnabled = (bool)($features['analytics_attribution'] ?? false);
$funnelDepth = max(1, (int)($limits['funnel_breakdown_depth'] ?? 2));
$sourceLimit = max(1, (int)($limits['attribution_sources'] ?? 4));

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

// Funnel (derived table preferred, fallback approximation from orders volume)
$funnel = [
    'enabled' => $funnelEnabled,
    'steps' => [],
];
try {
    $funnelTable = perStoreTableName($shopName, 'funnel');
    $safe = $mysqli->real_escape_string($funnelTable);
    $exists = $mysqli->query("SHOW TABLES LIKE '{$safe}'");
    if ($exists && $exists->num_rows > 0) {
        $stmtF = $mysqli->prepare(
            "SELECT step_name, step_order, step_count, conversion_rate
             FROM `{$funnelTable}` WHERE window_key='last_30d'
             ORDER BY step_order ASC
             LIMIT ?"
        );
        if ($stmtF) {
            $stmtF->bind_param('i', $funnelDepth);
            $stmtF->execute();
            $resF = $stmtF->get_result();
            while ($row = $resF->fetch_assoc()) {
                $funnel['steps'][] = [
                    'name' => (string)($row['step_name'] ?? ''),
                    'count' => (int)($row['step_count'] ?? 0),
                    'conversion_rate' => (float)($row['conversion_rate'] ?? 0),
                ];
            }
            $stmtF->close();
        }
    }
} catch (Throwable $e) {
    // fallback below
}
if (empty($funnel['steps'])) {
    $sessions = max((int)round(array_sum($series)) * 20, 1);
    $purchases = max((int)round(array_sum($series) / max(1, ($total / max(1, array_sum($series))))), 0);
    $funnel['steps'] = [
        ['name' => 'Sessions', 'count' => $sessions, 'conversion_rate' => 100.0],
        ['name' => 'Purchase', 'count' => $purchases, 'conversion_rate' => round(($purchases / max(1, $sessions)) * 100, 2)],
    ];
}
if (!$funnelEnabled) {
    $funnel['steps'] = array_slice($funnel['steps'], 0, 2);
}

// Attribution by source
$attribution = [
    'enabled' => $attributionEnabled,
    'sources' => [],
];
try {
    $attributionTable = perStoreTableName($shopName, 'attribution');
    $safeA = $mysqli->real_escape_string($attributionTable);
    $existsA = $mysqli->query("SHOW TABLES LIKE '{$safeA}'");
    if ($existsA && $existsA->num_rows > 0) {
        $stmtA = $mysqli->prepare(
            "SELECT source_name, orders_count, revenue_total, aov
             FROM `{$attributionTable}` WHERE window_key='last_30d'
             ORDER BY revenue_total DESC
             LIMIT ?"
        );
        if ($stmtA) {
            $stmtA->bind_param('i', $sourceLimit);
            $stmtA->execute();
            $resA = $stmtA->get_result();
            while ($row = $resA->fetch_assoc()) {
                $attribution['sources'][] = [
                    'source' => (string)($row['source_name'] ?? 'unknown'),
                    'orders' => (int)($row['orders_count'] ?? 0),
                    'revenue' => round((float)($row['revenue_total'] ?? 0), 2),
                    'aov' => round((float)($row['aov'] ?? 0), 2),
                ];
            }
            $stmtA->close();
        }
    }
} catch (Throwable $e) {
    // fallback below
}
if (empty($attribution['sources'])) {
    $attribution['sources'][] = ['source' => 'unknown', 'orders' => 0, 'revenue' => 0.0, 'aov' => 0.0];
}
if (!$attributionEnabled) {
    $attribution['sources'] = array_slice($attribution['sources'], 0, 2);
}

echo json_encode([
    'total' => round($total, 2),
    'trend' => array_map(fn($v) => round((float)$v, 2), $series),
    'change' => $change,
    'labels' => $labels,
    'funnel' => $funnel,
    'attribution' => $attribution,
], JSON_UNESCAPED_UNICODE);

