<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/auth.php';
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

$limit = (int)($_GET['limit'] ?? 6);
$limit = max(1, min(20, $limit));

$critical = [];
$warning = [];
$themes = [];
try {
    $alerts = sbm_getAlertsData($shop, $store, 120);
    $critical = is_array($alerts['criticalAlerts'] ?? null) ? $alerts['criticalAlerts'] : [];
    $warning = is_array($alerts['warningAlerts'] ?? null) ? $alerts['warningAlerts'] : [];

    foreach (array_merge($critical, $warning) as $a) {
        if (!is_array($a)) continue;
        $k = strtolower(trim((string)($a['details_url_key'] ?? 'other')));
        if ($k === '') $k = 'other';
        $themes[$k] = ($themes[$k] ?? 0) + 1;
    }
} catch (Throwable $e) {
    $critical = [];
    $warning = [];
    $themes = [];
}

// Truncate lists (client can deep-dive to alerts page).
$critical = array_slice($critical, 0, $limit);
$warning = array_slice($warning, 0, $limit);

arsort($themes);
$themeRows = [];
foreach ($themes as $k => $cnt) {
    $themeRows[] = ['key' => $k, 'count' => (int)$cnt];
    if (count($themeRows) >= 6) break;
}

echo json_encode([
    'ok' => true,
    'critical' => $critical,
    'warning' => $warning,
    'themes' => $themeRows,
    'counts' => [
        'critical' => (int)count($critical),
        'warning' => (int)count($warning),
    ],
], JSON_UNESCAPED_UNICODE);

