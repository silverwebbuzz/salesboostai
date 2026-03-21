<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/auth.php';

header('Content-Type: application/json');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

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

$steps = (int)($_GET['steps'] ?? 8);
if ($steps < 1) $steps = 1;
if ($steps > 30) $steps = 30;

$results = [];
try {
    // Ensure tasks exist on first install.
    enqueueFullSync($shop);

    for ($i = 0; $i < $steps; $i++) {
        $out = runOneSyncStep($shop);
        $results[] = $out;
        if (($out['shop'] ?? '') === '') {
            break; // no pending work
        }
    }

    $mysqli = db();
    $status = ['state' => 'ready', 'pending' => 0, 'in_progress' => 0, 'error' => 0];

    $stmtCnt = $mysqli->prepare('SELECT COUNT(*) AS c FROM store_sync_state WHERE shop = ?');
    $syncRowCount = 0;
    if ($stmtCnt) {
        $stmtCnt->bind_param('s', $shop);
        $stmtCnt->execute();
        $rc = $stmtCnt->get_result();
        $rw = $rc ? ($rc->fetch_assoc() ?: null) : null;
        $stmtCnt->close();
        $syncRowCount = (int)($rw['c'] ?? 0);
    }

    $stmt = $mysqli->prepare(
        "SELECT
            SUM(status='pending') AS pending_count,
            SUM(status='in_progress') AS in_progress_count,
            SUM(status='error') AS error_count
         FROM store_sync_state
         WHERE shop = ?"
    );
    if ($stmt) {
        $stmt->bind_param('s', $shop);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? ($res->fetch_assoc() ?: null) : null;
        $stmt->close();
        if ($row) {
            $status['pending'] = (int)($row['pending_count'] ?? 0);
            $status['in_progress'] = (int)($row['in_progress_count'] ?? 0);
            $status['error'] = (int)($row['error_count'] ?? 0);
            if ($status['error'] > 0) {
                $status['state'] = 'error';
            } elseif (($status['pending'] + $status['in_progress']) > 0) {
                $status['state'] = 'syncing';
            }
        }
    }
    if ($syncRowCount === 0) {
        $status['state'] = 'needs_sync';
    }

    echo json_encode([
        'ok' => true,
        'sync_status' => $status,
        'steps_ran' => count($results),
        'results' => $results,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

