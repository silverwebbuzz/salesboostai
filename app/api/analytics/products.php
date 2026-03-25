<?php
/**
 * Analytics Products API: /app/api/analytics/products.php
 *
 * Returns JSON:
 * { "top": [...], "worst": [...] }
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
if (!canAccessFeature($entitlements, 'analytics_products')) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'Products analytics is not available on your current plan.',
        'required_plan' => getFeatureRequiredPlan('analytics_products'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$mysqli = db();
$top = function_exists('sbm_get_top_products_from_orders')
    ? sbm_get_top_products_from_orders($shop, 30, 700, 5)
    : [];

// Worst-performing uses same aggregation and then sorts ascending.
$worst = $top;
usort($worst, fn($a, $b) => ((float)($a['revenue'] ?? 0)) <=> ((float)($b['revenue'] ?? 0)));
$worst = array_slice($worst, 0, 5);

echo json_encode([
    'top' => $top,
    'worst' => $worst,
], JSON_UNESCAPED_UNICODE);

