<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/entitlements.php';
require_once __DIR__ . '/../../lib/metrics.php';
require_once __DIR__ . '/../../lib/ai/anthropic.php';
require_once __DIR__ . '/../../lib/ai/cache.php';

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

$entitlements = function_exists('getPlanEntitlements') ? getPlanEntitlements($shop) : ['plan_key' => 'free'];
$planKey = (string)($entitlements['plan_key'] ?? 'free');
if ($planKey !== 'premium') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Dynamic AI actions are available on Premium plan.', 'required_plan' => 'premium'], JSON_UNESCAPED_UNICODE);
    exit;
}

$force = (isset($_GET['force']) && (string)$_GET['force'] === '1');

$tz = (string)($store['iana_timezone'] ?? 'UTC');
if ($tz === '') $tz = 'UTC';
$dateKey = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d');
$cacheKey = 'ai_dynamic_actions:' . $dateKey;
$ttlSec = 24 * 3600;

$model = getenv('ANTHROPIC_MODEL_ACTIONS');
if (!is_string($model) || trim($model) === '') {
    $model = 'claude-sonnet-4-20250514';
}

// Inputs: 30d window.
$topProducts = function_exists('sbm_get_top_products_from_orders')
    ? sbm_get_top_products_from_orders($shop, 30, 700, 5)
    : [];
$lowStockRows = function_exists('sbm_get_inventory_forecast_rows')
    ? sbm_get_inventory_forecast_rows($shop, 5)
    : [];
$cust = function_exists('sbm_getCustomerMetrics') ? sbm_getCustomerMetrics($shop, 180) : [];
$returningRatePct = 0.0;
if (is_array($cust)) {
    $total = (int)($cust['totalCustomers'] ?? 0);
    $repeat = (int)($cust['repeatCustomers'] ?? 0);
    $returningRatePct = $total > 0 ? round(($repeat / max(1, $total)) * 100, 2) : 0.0;
}

$payload = sbm_ai_cached($shop, $cacheKey, $ttlSec, function () use ($model, $shop, $returningRatePct, $topProducts, $lowStockRows) {
    $prompt = "Return ONLY valid JSON.\n"
        . "Generate exactly 3 action recommendations for an e-commerce store.\n"
        . "Schema: [{\"action\":\"...\",\"reason\":\"...\",\"impact\":\"high|medium|low\"}]\n"
        . "Keep action and reason concise.\n\n"
        . "Shop: {$shop}\n"
        . "Returning rate %: {$returningRatePct}\n"
        . "Top products (30d): " . json_encode($topProducts, JSON_UNESCAPED_UNICODE) . "\n"
        . "Low stock forecast: " . json_encode($lowStockRows, JSON_UNESCAPED_UNICODE) . "\n";

    $res = sbm_anthropic_messages([
        'model' => $model,
        'max_tokens' => 400,
        'messages' => [
            ['role' => 'user', 'content' => $prompt],
        ],
    ], 35);

    if (!$res['ok']) {
        return ['ok' => false, 'error' => $res['error'], 'actions' => [], 'meta' => ['model' => $model]];
    }

    $decoded = json_decode((string)$res['text'], true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'AI returned invalid JSON', 'actions' => [], 'meta' => ['model' => $model], 'raw_text' => (string)$res['text']];
    }

    $actions = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) continue;
        $action = trim((string)($row['action'] ?? ''));
        $reason = trim((string)($row['reason'] ?? ''));
        $impact = strtolower(trim((string)($row['impact'] ?? 'medium')));
        if ($action === '' || $reason === '') continue;
        if (!in_array($impact, ['high', 'medium', 'low'], true)) $impact = 'medium';
        $actions[] = ['action' => $action, 'reason' => $reason, 'impact' => $impact];
    }
    $actions = array_slice($actions, 0, 3);

    if (count($actions) < 1) {
        return ['ok' => false, 'error' => 'AI returned empty actions', 'actions' => [], 'meta' => ['model' => $model]];
    }

    return [
        'ok' => true,
        'actions' => $actions,
        'meta' => ['model' => $model],
    ];
}, $force);

if (empty($payload['ok'])) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => (string)($payload['error'] ?? 'AI request failed')], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'actions' => $payload['actions'] ?? [],
    'generated_at' => $payload['generated_at'] ?? '',
    'cache' => $payload['cache'] ?? null,
    'meta' => $payload['meta'] ?? null,
], JSON_UNESCAPED_UNICODE);

