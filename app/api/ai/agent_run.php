<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/entitlements.php';
require_once __DIR__ . '/../../lib/usage.php';
require_once __DIR__ . '/../../lib/metrics.php';
require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/ai/anthropic.php';
require_once __DIR__ . '/../../lib/ai/cache.php';

header('Content-Type: application/json');

function sbm_normalize_ai_report_payload(string $text): array
{
    $clean = trim($text);
    if ($clean === '') {
        return [
            'summary' => 'AI returned an empty response.',
            'key_points' => [],
            'issues' => [],
            'actions' => [],
        ];
    }

    // Remove fenced code wrapper if present.
    $clean = preg_replace('/^\s*```(?:json)?\s*/i', '', $clean);
    $clean = preg_replace('/\s*```\s*$/', '', (string)$clean);
    $clean = trim((string)$clean);

    $decoded = json_decode($clean, true);

    // If full-string decode failed, try extracting the first JSON object.
    if (!is_array($decoded)) {
        $start = strpos($clean, '{');
        $end = strrpos($clean, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $chunk = substr($clean, $start, $end - $start + 1);
            $decoded = json_decode($chunk, true);
        }
    }

    // If model returned summary as a nested JSON string, parse and merge.
    if (is_array($decoded) && isset($decoded['summary']) && is_string($decoded['summary'])) {
        $nested = trim((string)$decoded['summary']);
        $nested = preg_replace('/^\s*```(?:json)?\s*/i', '', $nested);
        $nested = preg_replace('/\s*```\s*$/', '', (string)$nested);
        $nestedDecoded = json_decode((string)$nested, true);
        if (is_array($nestedDecoded)) {
            foreach (['summary', 'key_points', 'issues', 'actions'] as $k) {
                if (!isset($decoded[$k]) || (is_array($decoded[$k]) && count($decoded[$k]) === 0) || $k === 'summary') {
                    if (isset($nestedDecoded[$k])) {
                        $decoded[$k] = $nestedDecoded[$k];
                    }
                }
            }
        }
    }

    if (!is_array($decoded)) {
        return [
            'summary' => $clean,
            'key_points' => [],
            'issues' => [],
            'actions' => [],
        ];
    }

    $out = [
        'summary' => (string)($decoded['summary'] ?? ''),
        'key_points' => is_array($decoded['key_points'] ?? null) ? array_values($decoded['key_points']) : [],
        'issues' => is_array($decoded['issues'] ?? null) ? array_values($decoded['issues']) : [],
        'actions' => is_array($decoded['actions'] ?? null) ? array_values($decoded['actions']) : [],
    ];
    if ($out['summary'] === '') {
        $out['summary'] = 'AI generated report.';
    }
    return $out;
}

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

$agentId = (int)($_GET['agent_id'] ?? 0);
$agentKey = strtolower(trim((string)($_GET['agent_key'] ?? '')));

$entitlements = function_exists('getPlanEntitlements') ? getPlanEntitlements($shop) : ['limits' => [], 'plan_key' => 'free'];
$limits = is_array($entitlements['limits'] ?? null) ? $entitlements['limits'] : [];
$aiLimit = (int)($limits['ai_insights_per_week'] ?? 1);
$aiUsage = sbm_usage_state($shop, 'ai_insights', $aiLimit);
if ($aiUsage['reached']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Weekly AI insights limit reached for your plan.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$mysqli = db();
$agent = null;
try {
    if ($agentId > 0) {
        $stmtA = $mysqli->prepare("SELECT id, name, description, agent_key, version FROM ai_agents WHERE id = ? LIMIT 1");
        if ($stmtA) {
            $stmtA->bind_param('i', $agentId);
            $stmtA->execute();
            $resA = $stmtA->get_result();
            $agent = $resA ? ($resA->fetch_assoc() ?: null) : null;
            $stmtA->close();
        }
    } elseif ($agentKey !== '') {
        $stmtK = $mysqli->prepare("SELECT id, name, description, agent_key, version FROM ai_agents WHERE agent_key = ? AND is_active = 1 LIMIT 1");
        if ($stmtK) {
            $stmtK->bind_param('s', $agentKey);
            $stmtK->execute();
            $resK = $stmtK->get_result();
            $agent = $resK ? ($resK->fetch_assoc() ?: null) : null;
            $stmtK->close();
        }
    }
} catch (Throwable $e) {
    sbm_log_write('ai', 'agent_lookup_failed', ['shop' => $shop, 'error' => $e->getMessage()]);
}

if (!is_array($agent)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Agent not found.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$agentId = (int)($agent['id'] ?? 0);
$agentKey = (string)($agent['agent_key'] ?? '');
$agentVersion = (int)($agent['version'] ?? 1);
$currentFingerprint = function_exists('sbm_ai_data_fingerprint') ? sbm_ai_data_fingerprint($shop) : '';

$tables = sbm_getShopTables($shop);
$ordersTable = $tables['order'];
$customersTable = $tables['customer'];
$inventoryTable = $tables['products_inventory'];

$orders = 0;
$customers = 0;
$revenue = 0.0;
$lowStock = 0;
try {
    if ($r1 = $mysqli->query("SELECT COUNT(*) AS c FROM `{$ordersTable}`")) $orders = (int)(($r1->fetch_assoc() ?: [])['c'] ?? 0);
    if ($r2 = $mysqli->query("SELECT COUNT(*) AS c FROM `{$customersTable}`")) $customers = (int)(($r2->fetch_assoc() ?: [])['c'] ?? 0);
    if ($r3 = $mysqli->query("SELECT SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.total_price')) AS DECIMAL(12,2))) AS s FROM `{$ordersTable}`")) $revenue = (float)(($r3->fetch_assoc() ?: [])['s'] ?? 0);
    if ($r4 = $mysqli->query("SELECT COUNT(*) AS c FROM `{$inventoryTable}` WHERE inventory_quantity IS NOT NULL AND inventory_quantity < 10")) $lowStock = (int)(($r4->fetch_assoc() ?: [])['c'] ?? 0);
} catch (Throwable $e) {
    sbm_log_write('ai', 'agent_metrics_build_failed', ['shop' => $shop, 'agent_id' => $agentId, 'error' => $e->getMessage()]);
}

$aov = $orders > 0 ? round($revenue / $orders, 2) : 0.0;

// If data did not change since the last completed report for this agent, return cached report.
try {
    if ($agentId > 0 && $currentFingerprint !== '') {
        $stmtPrev = $mysqli->prepare(
            "SELECT report_json
             FROM ai_reports
             WHERE shop = ? AND agent_id = ? AND status = 'completed'
             ORDER BY created_at DESC
             LIMIT 1"
        );
        if ($stmtPrev) {
            $stmtPrev->bind_param('si', $shop, $agentId);
            $stmtPrev->execute();
            $resPrev = $stmtPrev->get_result();
            $rowPrev = $resPrev ? ($resPrev->fetch_assoc() ?: null) : null;
            $stmtPrev->close();
            $prev = is_array($rowPrev) ? json_decode((string)($rowPrev['report_json'] ?? ''), true) : null;
            $prevFp = '';
            if (is_array($prev) && isset($prev['_meta']) && is_array($prev['_meta'])) {
                $prevFp = (string)($prev['_meta']['fingerprint'] ?? '');
            }
            if ($prevFp !== '' && hash_equals($prevFp, $currentFingerprint)) {
                sbm_log_write('ai', 'agent_run_cache_hit_unchanged_fingerprint', [
                    'shop' => $shop,
                    'agent_id' => $agentId,
                ]);
                echo json_encode([
                    'ok' => true,
                    'cached' => true,
                    'report' => is_array($prev) ? $prev : [],
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }
} catch (Throwable $e) {
    sbm_log_write('ai', 'agent_run_cache_check_failed', [
        'shop' => $shop,
        'agent_id' => $agentId,
        'error' => $e->getMessage(),
    ]);
}

$prompt = "You are a Shopify analytics assistant.\n"
    . "Generate STRICT JSON with keys: summary (string), key_points (array of 3-5 strings), issues (array of objects {title,severity}), actions (array of 3-5 strings).\n"
    . "Severity must be one of: low, medium, high.\n"
    . "Keep recommendations practical and concise.\n\n"
    . "Agent: " . (string)($agent['name'] ?? 'Agent') . " ({$agentKey})\n"
    . "Shop: {$shop}\n"
    . "Orders: {$orders}\n"
    . "Customers: {$customers}\n"
    . "Revenue: {$revenue}\n"
    . "AOV: {$aov}\n"
    . "Low-stock product count: {$lowStock}\n";

sbm_log_write('ai', 'agent_run_started', [
    'shop' => $shop,
    'agent_id' => $agentId,
    'agent_key' => $agentKey,
    'plan_key' => (string)($entitlements['plan_key'] ?? 'free'),
    'usage_before' => (int)($aiUsage['used'] ?? 0),
]);

$model = getenv('ANTHROPIC_MODEL_AGENT_REPORT');
if (!is_string($model) || trim($model) === '') {
    $model = 'claude-sonnet-4-20250514';
}

$resAi = sbm_anthropic_messages([
    'model' => $model,
    'max_tokens' => 700,
    'messages' => [
        ['role' => 'user', 'content' => $prompt],
    ],
], 35);

if (!$resAi['ok']) {
    sbm_log_write('ai', 'agent_run_failed', [
        'shop' => $shop,
        'agent_id' => $agentId,
        'status' => (int)($resAi['status'] ?? 0),
        'error' => (string)($resAi['error'] ?? 'Unknown'),
    ]);
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'AI provider request failed: ' . (string)($resAi['error'] ?? 'Unknown')], JSON_UNESCAPED_UNICODE);
    exit;
}

$text = (string)($resAi['text'] ?? '');
$decoded = sbm_normalize_ai_report_payload($text);
$decoded['_meta'] = [
    'fingerprint' => $currentFingerprint,
    'generated_at' => gmdate('c'),
    'model' => $model,
];

sbm_increment_weekly_usage($shop, 'ai_insights', 1, (string)$aiUsage['week_key']);

// Best effort persistence for history.
try {
    $reportJson = json_encode($decoded, JSON_UNESCAPED_UNICODE);
    if (is_string($reportJson) && $agentId > 0) {
        $stmtIns = $mysqli->prepare(
            "INSERT INTO ai_reports (shop, agent_id, report_json, status, agent_version, created_at)
             VALUES (?, ?, ?, 'completed', ?, NOW())"
        );
        if ($stmtIns) {
            $stmtIns->bind_param('sisi', $shop, $agentId, $reportJson, $agentVersion);
            $okInsert = $stmtIns->execute();
            $insertErr = (string)$stmtIns->error;
            $stmtIns->close();
            if (!$okInsert && stripos($insertErr, "Field 'id' doesn't have a default value") !== false) {
                // Compatibility fallback for schemas where ai_reports.id is NOT AUTO_INCREMENT.
                $nextId = 1;
                if ($resMax = $mysqli->query("SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM ai_reports")) {
                    $rowMax = $resMax->fetch_assoc() ?: [];
                    $nextId = max(1, (int)($rowMax['next_id'] ?? 1));
                }
                $stmtIns2 = $mysqli->prepare(
                    "INSERT INTO ai_reports (id, shop, agent_id, report_json, status, agent_version, created_at)
                     VALUES (?, ?, ?, ?, 'completed', ?, NOW())"
                );
                if ($stmtIns2) {
                    $stmtIns2->bind_param('isisi', $nextId, $shop, $agentId, $reportJson, $agentVersion);
                    $okInsert2 = $stmtIns2->execute();
                    $insertErr2 = (string)$stmtIns2->error;
                    $stmtIns2->close();
                    if (!$okInsert2) {
                        throw new RuntimeException($insertErr2 !== '' ? $insertErr2 : 'Insert failed with explicit id');
                    }
                    sbm_log_write('ai', 'agent_run_persist_used_explicit_id', [
                        'shop' => $shop,
                        'agent_id' => $agentId,
                        'inserted_id' => $nextId,
                    ]);
                }
            } elseif (!$okInsert) {
                throw new RuntimeException($insertErr !== '' ? $insertErr : 'Insert failed');
            }
        }
    }
} catch (Throwable $e) {
    sbm_log_write('ai', 'agent_run_persist_failed', ['shop' => $shop, 'agent_id' => $agentId, 'error' => $e->getMessage()]);
}

sbm_log_write('ai', 'agent_run_completed', [
    'shop' => $shop,
    'agent_id' => $agentId,
    'status' => (int)($resAi['status'] ?? 200),
]);

echo json_encode(['ok' => true, 'cached' => false, 'report' => $decoded], JSON_UNESCAPED_UNICODE);
