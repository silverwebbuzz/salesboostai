<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/entitlements.php';
require_once __DIR__ . '/../../lib/metrics.php';
require_once __DIR__ . '/../../lib/ai/anthropic.php';
require_once __DIR__ . '/../../lib/ai/cache.php';
require_once __DIR__ . '/../../lib/usage.php';

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

$entitlements = function_exists('getPlanEntitlements') ? getPlanEntitlements($shop) : ['features' => [], 'limits' => [], 'plan_key' => 'free'];
$planKey = (string)($entitlements['plan_key'] ?? 'free');
if (!in_array($planKey, ['growth', 'premium'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Weekly digest is available on Growth plan and above.', 'required_plan' => 'growth'], JSON_UNESCAPED_UNICODE);
    exit;
}

$force = (isset($_GET['force']) && (string)$_GET['force'] === '1');

$limits = is_array($entitlements['limits'] ?? null) ? $entitlements['limits'] : [];
$aiLimit = (int)($limits['ai_insights_per_week'] ?? 1);
$aiUsage = sbm_usage_state($shop, 'ai_insights', $aiLimit);

// Week-key (UTC)
$weekKey = sbm_usage_week_key();
$cacheKey = 'ai_weekly_digest:' . $weekKey;
$ttlSec = 7 * 24 * 3600;

$tz = (string)($store['iana_timezone'] ?? 'UTC');
if ($tz === '') $tz = 'UTC';

$model = getenv('ANTHROPIC_MODEL_DIGEST');
if (!is_string($model) || trim($model) === '') {
    $model = 'claude-sonnet-4-20250514';
}

// Build inputs (local DB only)
$mysqli = db();
$tables = sbm_getShopTables($shop);
$ordersTable = $tables['order'];
$customersTable = $tables['customer'];

$nowBounds = sbm_period_bounds($tz, 7);
$startNow = (string)$nowBounds['start'];
$endNow = (string)$nowBounds['end'];
$startPrev = (new DateTimeImmutable($startNow, new DateTimeZone($tz)))->modify('-7 days')->format('Y-m-d H:i:s');
$endPrev = (new DateTimeImmutable($endNow, new DateTimeZone($tz)))->modify('-7 days')->format('Y-m-d H:i:s');

function sbm_ai_sum_orders_range(mysqli $mysqli, string $ordersTable, string $start, string $end): array
{
    $out = ['orders' => 0, 'revenue' => 0.0];
    $stmt = $mysqli->prepare(
        "SELECT
            COUNT(*) AS c,
            SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.total_price')) AS DECIMAL(12,2))) AS s
         FROM `{$ordersTable}`
         WHERE COALESCE(created_at, fetched_at) BETWEEN ? AND ?"
    );
    if (!$stmt) return $out;
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? ($res->fetch_assoc() ?: null) : null;
    $stmt->close();
    $out['orders'] = (int)($row['c'] ?? 0);
    $out['revenue'] = (float)($row['s'] ?? 0);
    return $out;
}

$cur = sbm_ai_sum_orders_range($mysqli, $ordersTable, $startNow, $endNow);
$prev = sbm_ai_sum_orders_range($mysqli, $ordersTable, $startPrev, $endPrev);
$revNow = (float)($cur['revenue'] ?? 0);
$revPrev = (float)($prev['revenue'] ?? 0);
$ordersNow = (int)($cur['orders'] ?? 0);
$ordersPrev = (int)($prev['orders'] ?? 0);
$aovNow = $ordersNow > 0 ? round($revNow / max(1, $ordersNow), 2) : 0.0;
$revChangePct = $revPrev > 0 ? round((($revNow - $revPrev) / $revPrev) * 100, 2) : 0.0;

// New customers this week (proxy via customers table created_at/fetched_at)
$newCustomers = 0;
try {
    $stmtN = $mysqli->prepare("SELECT COUNT(*) AS c FROM `{$customersTable}` WHERE COALESCE(created_at, fetched_at) BETWEEN ? AND ?");
    if ($stmtN) {
        $stmtN->bind_param('ss', $startNow, $endNow);
        $stmtN->execute();
        $resN = $stmtN->get_result();
        $rowN = $resN ? ($resN->fetch_assoc() ?: null) : null;
        $stmtN->close();
        $newCustomers = (int)($rowN['c'] ?? 0);
    }
} catch (Throwable $e) {}

$cust = function_exists('sbm_getCustomerMetrics') ? sbm_getCustomerMetrics($shop, 180) : [];
$returningRatePct = 0.0;
if (is_array($cust)) {
    $total = (int)($cust['totalCustomers'] ?? 0);
    $repeat = (int)($cust['repeatCustomers'] ?? 0);
    $returningRatePct = $total > 0 ? round(($repeat / max(1, $total)) * 100, 2) : 0.0;
}

$topProducts = function_exists('sbm_get_top_products_from_orders')
    ? sbm_get_top_products_from_orders($shop, 7, 700, 3)
    : [];
$invInsights = function_exists('sbm_getInventoryInsights') ? sbm_getInventoryInsights($shop, 180) : [];
$lowStockCount = is_array($invInsights['low_stock'] ?? null) ? count($invInsights['low_stock']) : 0;

$payload = sbm_ai_cached($shop, $cacheKey, $ttlSec, function () use (
    $model,
    $revNow,
    $revChangePct,
    $ordersNow,
    $aovNow,
    $newCustomers,
    $returningRatePct,
    $topProducts,
    $lowStockCount,
    $weekKey
) {
    $prompt = "You are a concise e-commerce analytics assistant for a Shopify merchant.\n"
        . "Write a single paragraph (3-4 sentences) weekly digest.\n"
        . "Use the numbers provided. Mention top products and inventory risk if present.\n"
        . "No bullet points.\n\n"
        . "Week: {$weekKey}\n"
        . "Revenue total (7d): {$revNow}\n"
        . "Revenue change % vs previous 7d: {$revChangePct}\n"
        . "Order count (7d): {$ordersNow}\n"
        . "AOV (7d): {$aovNow}\n"
        . "New customers (7d): {$newCustomers}\n"
        . "Returning rate % (proxy): {$returningRatePct}\n"
        . "Top products (top 3): " . json_encode($topProducts, JSON_UNESCAPED_UNICODE) . "\n"
        . "Low stock SKU count (<~5 units): {$lowStockCount}\n";

    $res = sbm_anthropic_messages([
        'model' => $model,
        'max_tokens' => 250,
        'messages' => [
            ['role' => 'user', 'content' => $prompt],
        ],
    ], 30);

    if (!$res['ok']) {
        return ['ok' => false, 'error' => $res['error'], 'text' => '', 'meta' => ['model' => $model]];
    }

    return [
        'ok' => true,
        'text' => (string)$res['text'],
        'meta' => ['model' => $model, 'week_key' => $weekKey],
        'inputs' => [
            'revenue_total' => round($revNow, 2),
            'revenue_change_pct' => $revChangePct,
            'order_count' => $ordersNow,
            'aov' => $aovNow,
            'new_customers' => $newCustomers,
            'returning_rate_pct' => $returningRatePct,
            'low_stock_count' => $lowStockCount,
        ],
    ];
}, $force);

if (empty($payload['ok'])) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => (string)($payload['error'] ?? 'AI request failed')], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'text' => (string)($payload['text'] ?? ''),
    'generated_at' => (string)($payload['generated_at'] ?? ''),
    'cache' => $payload['cache'] ?? null,
    'meta' => $payload['meta'] ?? null,
    'ai_usage' => $aiUsage,
], JSON_UNESCAPED_UNICODE);

