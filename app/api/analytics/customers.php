<?php
/**
 * Analytics Customers API: /app/api/analytics/customers.php
 *
 * Returns JSON:
 * { "new": 120, "returning": 80, "top": [...] }
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
if (!canAccessFeature($entitlements, 'analytics_customers')) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'Customers analytics is not available on your current plan.',
        'required_plan' => getFeatureRequiredPlan('analytics_customers'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$planLimits = is_array($entitlements['limits'] ?? null) ? $entitlements['limits'] : [];
$features = is_array($entitlements['features'] ?? null) ? $entitlements['features'] : [];
$cohortMonthsLimit = max(1, (int)($planLimits['cohort_months'] ?? 3));
$retentionEnabled = (bool)($features['analytics_retention'] ?? false);

$mysqli = db();
$shopName = makeShopName($shop);
$ordersTable = perStoreTableName($shopName, 'order');
$customersTable = perStoreTableName($shopName, 'customer');

$tz = (string)($store['iana_timezone'] ?? '');
if ($tz === '') $tz = 'UTC';

$since = (new DateTime('now', new DateTimeZone($tz)))->modify('-29 days')->setTime(0, 0, 0);
$sinceStr = $since->format('Y-m-d H:i:s');

// Best-effort new vs returning using customers table created_at (if present)
$newCustomers = 0;
$totalCustomers = 0;
try {
    $totalRes = $mysqli->query("SELECT COUNT(*) AS c FROM `{$customersTable}`");
    if ($totalRes) {
        $r = $totalRes->fetch_assoc();
        $totalCustomers = (int)($r['c'] ?? 0);
    }

    $sinceEsc = $mysqli->real_escape_string($sinceStr);
    $newRes = $mysqli->query("SELECT COUNT(*) AS c FROM `{$customersTable}` WHERE COALESCE(created_at, fetched_at) >= '{$sinceEsc}'");
    if ($newRes) {
        $r = $newRes->fetch_assoc();
        $newCustomers = (int)($r['c'] ?? 0);
    }
} catch (Throwable $e) {
    // ignore
}

$returningCustomers = max(0, $totalCustomers - $newCustomers);

// Top customers from last 30 days orders (best-effort)
$agg = [];
$stmt = $mysqli->prepare(
    "SELECT payload_json FROM `{$ordersTable}`
     WHERE COALESCE(created_at, fetched_at) >= ?
     ORDER BY COALESCE(created_at, fetched_at) DESC
     LIMIT 300"
);
if ($stmt) {
    $stmt->bind_param('s', $sinceStr);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $p = json_decode((string)($row['payload_json'] ?? ''), true);
        if (!is_array($p)) continue;

        $orderTotal = isset($p['total_price']) ? (float)$p['total_price'] : 0.0;
        $cust = isset($p['customer']) && is_array($p['customer']) ? $p['customer'] : null;
        if (!$cust || !isset($cust['id'])) continue;

        $cid = (string)$cust['id'];
        if (!isset($agg[$cid])) {
            $email = (string)($cust['email'] ?? '');
            $fn = (string)($cust['first_name'] ?? '');
            $ln = (string)($cust['last_name'] ?? '');
            $label = trim($fn . ' ' . $ln);
            if ($label === '') $label = $email !== '' ? $email : ('Customer #' . $cid);

            $agg[$cid] = [
                'id' => $cid,
                'label' => $label,
                'total' => 0.0,
                'orders' => 0,
            ];
        }
        $agg[$cid]['total'] += $orderTotal;
        $agg[$cid]['orders'] += 1;
    }
    $stmt->close();
}

$top = array_values($agg);
usort($top, fn($a, $b) => ((float)($b['total'] ?? 0)) <=> ((float)($a['total'] ?? 0)));
$top = array_slice($top, 0, 5);
$top = array_map(function ($x) {
    return [
        'id' => (string)($x['id'] ?? ''),
        'label' => (string)($x['label'] ?? ''),
        'total' => round((float)($x['total'] ?? 0), 2),
        'orders' => (int)($x['orders'] ?? 0),
    ];
}, $top);

// Retention cohorts (derived table preferred, fallback estimate)
$retention = [
    'enabled' => $retentionEnabled,
    'months_limit' => $cohortMonthsLimit,
    'cohorts' => [],
    'repeat_rate' => ($newCustomers + $returningCustomers) > 0
        ? round(($returningCustomers / max(1, ($newCustomers + $returningCustomers))) * 100, 2)
        : 0.0,
];
try {
    $cohortsTable = perStoreTableName($shopName, 'cohorts');
    $safe = $mysqli->real_escape_string($cohortsTable);
    $exists = $mysqli->query("SHOW TABLES LIKE '{$safe}'");
    if ($exists && $exists->num_rows > 0) {
        $stmtC = $mysqli->prepare(
            "SELECT cohort_key, period_index, base_customers, retained_customers, retention_rate
             FROM `{$cohortsTable}`
             ORDER BY cohort_key DESC, period_index ASC
             LIMIT ?"
        );
        if ($stmtC) {
            $stmtC->bind_param('i', $cohortMonthsLimit);
            $stmtC->execute();
            $resC = $stmtC->get_result();
            while ($row = $resC->fetch_assoc()) {
                $retention['cohorts'][] = [
                    'cohort_key' => (string)($row['cohort_key'] ?? ''),
                    'period_index' => (int)($row['period_index'] ?? 0),
                    'base_customers' => (int)($row['base_customers'] ?? 0),
                    'retained_customers' => (int)($row['retained_customers'] ?? 0),
                    'retention_rate' => (float)($row['retention_rate'] ?? 0),
                ];
            }
            $stmtC->close();
        }
    }
} catch (Throwable $e) {
    // fallback to empty cohorts
}

if (!$retentionEnabled) {
    // Free/locked tiers only get top-line retention preview.
    $retention['cohorts'] = array_slice($retention['cohorts'], 0, 1);
}

echo json_encode([
    'new' => $newCustomers,
    'returning' => $returningCustomers,
    'top' => $top,
    'retention' => $retention,
], JSON_UNESCAPED_UNICODE);

