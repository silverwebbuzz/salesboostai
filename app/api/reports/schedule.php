<?php
/**
 * Reports scheduling stub (Batch R4).
 *
 * GET /app/api/reports/schedule.php?shop=...
 *
 * This is a placeholder for saving email schedules. For now it returns a friendly message.
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
if (!canAccessFeature($entitlements, 'reports_scheduled')) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'Scheduled digests are not available on your current plan.',
        'required_plan' => getFeatureRequiredPlan('reports_scheduled'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'message' => 'Scheduling is coming soon. This button is now wired and plan-gated correctly.',
], JSON_UNESCAPED_UNICODE);

