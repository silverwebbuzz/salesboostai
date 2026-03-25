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

$top = function_exists('sbm_get_top_customers_from_orders')
    ? sbm_get_top_customers_from_orders($shop, 30, 300, 5)
    : [];

// Customer segments + LTV metrics (from canonical helper used by legacy customers.php)
$segments = [];
try {
    $m = function_exists('sbm_getCustomerMetrics') ? sbm_getCustomerMetrics($shop, 180) : [];
    if (is_array($m)) {
        $segments = [
            'total_customers' => (int)($m['totalCustomers'] ?? 0),
            'new_customers' => (int)($m['newCustomers'] ?? 0),
            'repeat_customers' => (int)($m['repeatCustomers'] ?? 0),
            'vip_customers' => (int)($m['vipCustomers'] ?? 0),
            'at_risk_customers' => (int)($m['atRiskCustomers'] ?? 0),
            'inactive_customers' => (int)($m['inactiveCustomers'] ?? 0),
            'orders_scanned' => (int)($m['ordersScanned'] ?? 0),
            'avg_ltv' => round((float)($m['avgLtv'] ?? 0), 2),
            'vip_ltv' => round((float)($m['vipLtv'] ?? 0), 2),
        ];
    }
} catch (Throwable $e) { $segments = []; }

// If LTV is not enabled for plan, only allow counts (hide monetary values).
$customersLtvEnabled = (bool)($features['customers_ltv'] ?? false);
if (!$customersLtvEnabled && !empty($segments)) {
    $segments['avg_ltv'] = 0.0;
    $segments['vip_ltv'] = 0.0;
}

// Retention cohorts (derived table preferred, fallback estimate)
$retention = [
    'enabled' => $retentionEnabled,
    'months_limit' => $cohortMonthsLimit,
    'cohorts' => [],
    'repeat_rate' => ($newCustomers + $returningCustomers) > 0
        ? round(($returningCustomers / max(1, ($newCustomers + $returningCustomers))) * 100, 2)
        : 0.0,
];
$retention['cohorts'] = function_exists('sbm_get_retention_cohort_detail_rows')
    ? sbm_get_retention_cohort_detail_rows($shop, $cohortMonthsLimit)
    : [];

if (!$retentionEnabled) {
    // Free/locked tiers only get top-line retention preview.
    $retention['cohorts'] = array_slice($retention['cohorts'], 0, 1);
}

echo json_encode([
    'new' => $newCustomers,
    'returning' => $returningCustomers,
    'top' => $top,
    'segments' => $segments,
    'retention' => $retention,
], JSON_UNESCAPED_UNICODE);

