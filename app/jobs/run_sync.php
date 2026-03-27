<?php
/**
 * Background sync runner (one page per run).
 *
 * How to run (recommended via cron):
 * - CLI: php /path/to/app/jobs/run_sync.php
 * - Web: /app/jobs/run_sync?key=YOUR_CRON_KEY[&shop=storename.myshopify.com]
 *
 * It will:
 * - pick 1 pending/in_progress sync task from DB
 * - fetch 250 records (one page) for that resource
 * - store into per-store tables
 * - update cursor (page_info) in DB
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/metrics.php';
require_once __DIR__ . '/../lib/logger.php';

// Allow CLI always. Browser allowed only with ?key= that matches CRON_KEY.
$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    $key = $_GET['key'] ?? '';
    if (!is_string($key) || $key === '' || CRON_KEY === '' || !hash_equals(CRON_KEY, $key)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

$shop = $_GET['shop'] ?? null;
if (!is_string($shop) || $shop === '') {
    $shop = null;
}

try {
    $result = runOneSyncStep($shop);
    $resultShop = is_array($result) ? (string)($result['shop'] ?? '') : '';
    if ($resultShop !== '' && function_exists('sbm_refresh_foundation_analytics')) {
        try {
            sbm_refresh_foundation_analytics($resultShop);
        } catch (Throwable $e) {
            // non-blocking; keep sync response successful
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    sbm_log_write('app', '[run_sync] exception', ['error' => $e->getMessage()]);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

