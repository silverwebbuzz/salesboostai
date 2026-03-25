<?php
/**
 * Reports export stub (Batch R4).
 *
 * GET /app/api/reports/export.php?shop=...&tab=...&range=...
 *
 * This is a placeholder for PDF/CSV generation. For now it returns a friendly message.
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

$entitlements = function_exists('getPlanEntitlements') ? getPlanEntitlements($shop) : ['features' => []];
if (!canAccessFeature($entitlements, 'reports_export')) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'Reports export is not available on your current plan.',
        'required_plan' => getFeatureRequiredPlan('reports_export'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$tab = (string)($_GET['tab'] ?? 'revenue');
$range = (int)($_GET['range'] ?? 7);

echo json_encode([
    'ok' => true,
    'message' => 'Export is coming soon. This button is now wired and plan-gated correctly.',
    'tab' => $tab,
    'range' => $range,
], JSON_UNESCAPED_UNICODE);

