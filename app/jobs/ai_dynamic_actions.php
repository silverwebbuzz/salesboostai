<?php
/**
 * Daily Dynamic Actions generator (AI Batch 3).
 *
 * How to run (recommended via cron):
 * - CLI: php /path/to/app/jobs/ai_dynamic_actions.php
 * - Web: /app/jobs/ai_dynamic_actions?key=YOUR_CRON_KEY[&shop=storename.myshopify.com]
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/metrics.php';
require_once __DIR__ . '/../lib/entitlements.php';
require_once __DIR__ . '/../lib/ai/anthropic.php';
require_once __DIR__ . '/../lib/ai/cache.php';

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

$shopParam = $_GET['shop'] ?? null;
if (!is_string($shopParam) || $shopParam === '') $shopParam = null;
$shopParam = $shopParam ? sanitizeShopDomain($shopParam) : null;

$mysqli = db();

$sql = "SELECT shop FROM stores WHERE status <> 'uninstalled'";
if ($shopParam) $sql .= " AND shop = ?";
$sql .= " ORDER BY id ASC LIMIT 2000";

$shops = [];
if ($shopParam) {
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $shopParam);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $shops[] = (string)($row['shop'] ?? '');
        }
        $stmt->close();
    }
} else {
    $res = $mysqli->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $shops[] = (string)($row['shop'] ?? '');
        }
    }
}

$generated = 0;
$skipped = 0;
$failed = 0;
$details = [];
$errors = [];

foreach ($shops as $shop) {
    $shop = sanitizeShopDomain($shop);
    if ($shop === null) continue;

    try {
        $ent = function_exists('getPlanEntitlements') ? getPlanEntitlements($shop) : ['plan_key' => 'free'];
        $planKey = (string)($ent['plan_key'] ?? 'free');
        if ($planKey !== 'premium') {
            $skipped++;
            $details[] = ['shop' => $shop, 'plan' => $planKey, 'result' => 'skipped', 'reason' => 'plan_not_eligible'];
            continue;
        }

        $store = getShopByDomain($shop);
        $tz = (string)($store['iana_timezone'] ?? 'UTC');
        if ($tz === '') $tz = 'UTC';
        $dateKey = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d');
        $cacheKey = 'ai_dynamic_actions:' . $dateKey;
        $ttlSec = 24 * 3600;

        $model = getenv('ANTHROPIC_MODEL_ACTIONS');
        if (!is_string($model) || trim($model) === '') $model = 'claude-sonnet-4-20250514';

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

            $resAi = sbm_anthropic_messages([
                'model' => $model,
                'max_tokens' => 400,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ], 35);

            if (!$resAi['ok']) {
                return ['ok' => false, 'error' => $resAi['error'], 'actions' => [], 'meta' => ['model' => $model]];
            }

            $decoded = json_decode((string)$resAi['text'], true);
            if (!is_array($decoded)) {
                return ['ok' => false, 'error' => 'AI returned invalid JSON', 'actions' => [], 'meta' => ['model' => $model], 'raw_text' => (string)$resAi['text']];
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

            return ['ok' => true, 'actions' => $actions, 'meta' => ['model' => $model]];
        }, false);

        if (!is_array($payload) || empty($payload['ok'])) {
            throw new Exception((string)($payload['error'] ?? 'AI generation failed'));
        }

        $actions = is_array($payload['actions'] ?? null) ? $payload['actions'] : [];

        // Upsert into per-store action_items table (if present).
        $shopName = makeShopName($shop);
        $actionTable = perStoreTableName($shopName, 'action_items');
        $safe = $mysqli->real_escape_string($actionTable);
        $exists = $mysqli->query("SHOW TABLES LIKE '{$safe}'");
        if (!$exists || $exists->num_rows < 1) {
            $skipped++;
            $details[] = ['shop' => $shop, 'plan' => $planKey, 'result' => 'skipped', 'reason' => 'missing_action_items_table'];
            continue;
        }

        $didWrite = false;
        foreach ($actions as $idx => $a) {
            if (!is_array($a)) continue;
            $impact = strtolower(trim((string)($a['impact'] ?? 'medium')));
            $severity = $impact;
            if (!in_array($severity, ['high', 'medium', 'low'], true)) $severity = 'medium';
            $impactScore = ($severity === 'high') ? 90 : (($severity === 'low') ? 35 : 60);
            $confidence = 0.75;

            $actionKey = 'ai_dyn_' . $dateKey . '_' . ($idx + 1);
            $title = (string)($a['action'] ?? 'Action');
            $desc = (string)($a['reason'] ?? '');

            $ctaLabel = 'View details';
            $ctaUrl = '#';
            $owner = ($severity === 'high') ? 'revenue' : 'inventory';
            $sourceJson = json_encode([
                'type' => 'ai_dynamic_actions',
                'date_key' => $dateKey,
                'impact' => $severity,
                'raw' => $a,
            ], JSON_UNESCAPED_UNICODE);

            $stmt = $mysqli->prepare(
                "INSERT INTO `{$actionTable}`
                    (action_key, title, description, severity, impact_score, confidence_score, status, owner_section, cta_label, cta_url, source_json)
                 VALUES (?, ?, ?, ?, ?, ?, 'new', ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    description = VALUES(description),
                    severity = VALUES(severity),
                    impact_score = VALUES(impact_score),
                    confidence_score = VALUES(confidence_score),
                    owner_section = VALUES(owner_section),
                    cta_label = VALUES(cta_label),
                    cta_url = VALUES(cta_url),
                    source_json = VALUES(source_json),
                    updated_at = NOW()"
            );
            if ($stmt) {
                $stmt->bind_param(
                    'ssssddssss',
                    $actionKey,
                    $title,
                    $desc,
                    $severity,
                    $impactScore,
                    $confidence,
                    $owner,
                    $ctaLabel,
                    $ctaUrl,
                    $sourceJson
                );
                $stmt->execute();
                $stmt->close();
                $didWrite = true;
            }
        }

        if ($didWrite && !empty($payload['cache']) && empty($payload['cache']['hit'])) {
            $generated++;
            $details[] = ['shop' => $shop, 'plan' => $planKey, 'result' => 'generated', 'reason' => 'cache_miss_or_stale'];
        } else {
            $skipped++;
            $details[] = ['shop' => $shop, 'plan' => $planKey, 'result' => 'skipped', 'reason' => (!empty($payload['cache']) && !empty($payload['cache']['hit'])) ? 'cache_hit' : 'no_actions_written'];
        }
    } catch (Throwable $e) {
        $failed++;
        $errors[] = ['shop' => $shop, 'error' => $e->getMessage()];
        $details[] = ['shop' => $shop, 'plan' => $planKey ?? 'unknown', 'result' => 'failed', 'reason' => 'exception', 'error' => $e->getMessage()];
    }
}

header('Content-Type: application/json');
echo json_encode([
    'ok' => true,
    'shops' => count($shops),
    'generated' => $generated,
    'skipped' => $skipped,
    'failed' => $failed,
    'details' => array_slice($details, 0, 200),
    'errors' => array_slice($errors, 0, 20),
], JSON_UNESCAPED_UNICODE);

