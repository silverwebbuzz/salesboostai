<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/entitlements.php';
require_once __DIR__ . '/../../lib/metrics.php';
require_once __DIR__ . '/../../lib/usage.php';
require_once __DIR__ . '/../../lib/ai/anthropic.php';
require_once __DIR__ . '/../../lib/ai/cache.php';

header('Content-Type: application/json');

$shop = resolveApiShopFromToken($_GET['shop'] ?? null);

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
    echo json_encode(['ok' => false, 'error' => 'Anomaly explanations are available on Growth plan and above.', 'required_plan' => 'growth'], JSON_UNESCAPED_UNICODE);
    exit;
}

$metric = strtolower(trim((string)($_GET['metric'] ?? 'revenue')));
if (!in_array($metric, ['revenue', 'orders', 'aov'], true)) $metric = 'revenue';

$periodDays = (int)($_GET['period_days'] ?? 7);
if (!in_array($periodDays, [7, 30], true)) $periodDays = 7;

$force = (isset($_GET['force']) && (string)$_GET['force'] === '1');

$tz = (string)($store['iana_timezone'] ?? 'UTC');
if ($tz === '') $tz = 'UTC';

// Compute current vs previous window from orders.
$mysqli = db();
$tables = sbm_getShopTables($shop);
$ordersTable = $tables['order'];

$boundsNow = sbm_period_bounds($tz, $periodDays);
$startNow = (string)$boundsNow['start'];
$endNow = (string)$boundsNow['end'];
$startPrev = (new DateTimeImmutable($startNow, new DateTimeZone($tz)))->modify("-{$periodDays} days")->format('Y-m-d H:i:s');
$endPrev = (new DateTimeImmutable($endNow, new DateTimeZone($tz)))->modify("-{$periodDays} days")->format('Y-m-d H:i:s');

function sbm_ai_sum_orders(mysqli $mysqli, string $ordersTable, string $start, string $end): array
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

$cur = sbm_ai_sum_orders($mysqli, $ordersTable, $startNow, $endNow);
$prev = sbm_ai_sum_orders($mysqli, $ordersTable, $startPrev, $endPrev);

$currentValue = 0.0;
$prevValue = 0.0;
if ($metric === 'orders') {
    $currentValue = (float)($cur['orders'] ?? 0);
    $prevValue = (float)($prev['orders'] ?? 0);
} elseif ($metric === 'aov') {
    $currentValue = ((int)($cur['orders'] ?? 0)) > 0 ? ((float)($cur['revenue'] ?? 0) / max(1, (int)($cur['orders'] ?? 0))) : 0.0;
    $prevValue = ((int)($prev['orders'] ?? 0)) > 0 ? ((float)($prev['revenue'] ?? 0) / max(1, (int)($prev['orders'] ?? 0))) : 0.0;
} else {
    $currentValue = (float)($cur['revenue'] ?? 0);
    $prevValue = (float)($prev['revenue'] ?? 0);
}

$changePct = ($prevValue > 0) ? round((($currentValue - $prevValue) / $prevValue) * 100, 2) : 0.0;

// Enrich inputs with lightweight store signals (local DB only).
$topProducts = function_exists('sbm_get_top_products_from_orders')
    ? sbm_get_top_products_from_orders($shop, $periodDays, 700, 5)
    : [];
$invInsights = function_exists('sbm_getInventoryInsights') ? sbm_getInventoryInsights($shop, 180) : [];
$lowStock = is_array($invInsights['low_stock'] ?? null) ? $invInsights['low_stock'] : [];
$lowStock = array_slice(array_map(function ($x) {
    return [
        'title' => (string)($x['title'] ?? ''),
        'inventory_quantity' => (int)($x['inventory_quantity'] ?? 0),
    ];
}, $lowStock), 0, 8);

$cust = function_exists('sbm_getCustomerMetrics') ? sbm_getCustomerMetrics($shop, 180) : [];
$returningRatePct = 0.0;
if (is_array($cust)) {
    $total = (int)($cust['totalCustomers'] ?? 0);
    $repeat = (int)($cust['repeatCustomers'] ?? 0);
    $returningRatePct = $total > 0 ? round(($repeat / max(1, $total)) * 100, 2) : 0.0;
}

$dateKey = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d');
$cacheKey = 'ai_anomaly_explain:' . $metric . ':' . $periodDays . ':' . $dateKey;
$ttlSec = 3600;

$model = getenv('ANTHROPIC_MODEL_ANOMALY');
if (!is_string($model) || trim($model) === '') {
    // Default to the model you referenced on the Claude platform example.
    $model = 'claude-sonnet-4-20250514';
}

$payload = sbm_ai_cached($shop, $cacheKey, $ttlSec, function () use ($model, $shop, $metric, $currentValue, $prevValue, $changePct, $topProducts, $lowStock, $returningRatePct, $periodDays) {
    $metricLabel = $metric === 'aov' ? 'AOV' : ucfirst($metric);
    $prompt = "You are a concise e-commerce analytics assistant for a Shopify merchant.\n"
        . "Explain in 2 sentences max why the metric changed.\n"
        . "Be specific, reference concrete signals (top products, low stock, returning rate).\n"
        . "If data is insufficient, say what additional data would improve confidence.\n\n"
        . "Shop: {$shop}\n"
        . "Metric: {$metricLabel}\n"
        . "Current value: {$currentValue}\n"
        . "Previous value: {$prevValue}\n"
        . "Change %: {$changePct}\n"
        . "Period days: {$periodDays}\n"
        . "Returning rate % (proxy): {$returningRatePct}\n"
        . "Top products: " . json_encode($topProducts, JSON_UNESCAPED_UNICODE) . "\n"
        . "Low stock: " . json_encode($lowStock, JSON_UNESCAPED_UNICODE) . "\n";

    $res = sbm_anthropic_messages([
        'model' => $model,
        'max_tokens' => 150,
        'messages' => [
            ['role' => 'user', 'content' => $prompt],
        ],
    ], 25);

    if (!$res['ok']) {
        return [
            'ok' => false,
            'error' => $res['error'],
            'text' => '',
            'meta' => [
                'model' => $model,
                'metric' => $metric,
            ],
        ];
    }

    return [
        'ok' => true,
        'text' => (string)$res['text'],
        'meta' => [
            'model' => $model,
            'metric' => $metric,
            'period_days' => $periodDays,
        ],
        'inputs' => [
            'current_value' => $currentValue,
            'prev_value' => $prevValue,
            'change_pct' => $changePct,
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
    'cache' => $payload['cache'] ?? null,
    'meta' => $payload['meta'] ?? null,
], JSON_UNESCAPED_UNICODE);

