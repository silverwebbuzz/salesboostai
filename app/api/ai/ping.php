<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/auth.php';
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

echo json_encode([
    'ok' => true,
    'anthropic_key_present' => sbm_anthropic_api_key() !== '',
    'fingerprint' => sbm_ai_data_fingerprint($shop),
    'ts' => gmdate('c'),
], JSON_UNESCAPED_UNICODE);

