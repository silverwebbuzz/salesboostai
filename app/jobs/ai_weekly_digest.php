<?php
/**
 * Weekly AI digest generator (Batch 2).
 *
 * How to run (recommended via cron):
 * - CLI: php /path/to/app/jobs/ai_weekly_digest.php
 * - Web: /app/jobs/ai_weekly_digest?key=YOUR_CRON_KEY[&shop=storename.myshopify.com]
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/metrics.php';
require_once __DIR__ . '/../lib/entitlements.php';
require_once __DIR__ . '/../lib/ai/anthropic.php';
require_once __DIR__ . '/../lib/ai/cache.php';
require_once __DIR__ . '/../lib/usage.php';

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
$errors = [];
$details = [];

foreach ($shops as $shop) {
    $shop = sanitizeShopDomain($shop);
    if ($shop === null) continue;

    try {
        $ent = function_exists('getPlanEntitlements') ? getPlanEntitlements($shop) : ['plan_key' => 'free'];
        $planKey = (string)($ent['plan_key'] ?? 'free');
        if (!in_array($planKey, ['growth', 'premium'], true)) {
            $skipped++;
            $details[] = ['shop' => $shop, 'plan' => $planKey, 'result' => 'skipped', 'reason' => 'plan_not_eligible'];
            continue;
        }

        // Compute a stable weekly key (UTC)
        $weekKey = sbm_usage_week_key();
        $cacheKey = 'ai_weekly_digest:' . $weekKey;
        $ttlSec = 7 * 24 * 3600;

        // Generate only if missing/stale/fingerprint-changed (sbm_ai_cached handles it).
        $payload = sbm_ai_cached($shop, $cacheKey, $ttlSec, function () use ($shop) {
            // Reuse the API logic by calling it directly is not ideal here; we keep the job lightweight and server-side.
            // The weekly digest is derived from local DB via metrics helpers.

            $store = getShopByDomain($shop);
            $tz = (string)($store['iana_timezone'] ?? 'UTC');
            if ($tz === '') $tz = 'UTC';

            $mysqli = db();
            $tables = sbm_getShopTables($shop);
            $ordersTable = $tables['order'];
            $customersTable = $tables['customer'];

            $nowBounds = sbm_period_bounds($tz, 7);
            $startNow = (string)$nowBounds['start'];
            $endNow = (string)$nowBounds['end'];
            $startPrev = (new DateTimeImmutable($startNow, new DateTimeZone($tz)))->modify('-7 days')->format('Y-m-d H:i:s');
            $endPrev = (new DateTimeImmutable($endNow, new DateTimeZone($tz)))->modify('-7 days')->format('Y-m-d H:i:s');

            $sum = function (string $start, string $end) use ($mysqli, $ordersTable): array {
                $out = ['orders' => 0, 'revenue' => 0.0];
                $stmt = $mysqli->prepare(
                    "SELECT COUNT(*) AS c,
                            SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.total_price')) AS DECIMAL(12,2))) AS s
                     FROM `{$ordersTable}`
                     WHERE COALESCE(created_at, fetched_at) BETWEEN ? AND ?"
                );
                if (!$stmt) return $out;
                $stmt->bind_param('ss', $start, $end);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res ? ($res->fetch_assoc() ?: null) : null;
                $stmt->close();
                $out['orders'] = (int)($row['c'] ?? 0);
                $out['revenue'] = (float)($row['s'] ?? 0);
                return $out;
            };

            $cur = $sum($startNow, $endNow);
            $prev = $sum($startPrev, $endPrev);

            $revNow = (float)($cur['revenue'] ?? 0);
            $revPrev = (float)($prev['revenue'] ?? 0);
            $ordersNow = (int)($cur['orders'] ?? 0);
            $aovNow = $ordersNow > 0 ? round($revNow / max(1, $ordersNow), 2) : 0.0;
            $revChangePct = $revPrev > 0 ? round((($revNow - $revPrev) / $revPrev) * 100, 2) : 0.0;

            $newCustomers = 0;
            $stmtN = $mysqli->prepare("SELECT COUNT(*) AS c FROM `{$customersTable}` WHERE COALESCE(created_at, fetched_at) BETWEEN ? AND ?");
            if ($stmtN) {
                $stmtN->bind_param('ss', $startNow, $endNow);
                $stmtN->execute();
                $resN = $stmtN->get_result();
                $rowN = $resN ? ($resN->fetch_assoc() ?: null) : null;
                $stmtN->close();
                $newCustomers = (int)($rowN['c'] ?? 0);
            }

            $cust = function_exists('sbm_getCustomerMetrics') ? sbm_getCustomerMetrics($shop, 180) : [];
            $returningRatePct = 0.0;
            if (is_array($cust)) {
                $total = (int)($cust['totalCustomers'] ?? 0);
                $repeat = (int)($cust['repeatCustomers'] ?? 0);
                $returningRatePct = $total > 0 ? round(($repeat / max(1, $total)) * 100, 2) : 0.0;
            }

            $topProducts = function_exists('sbm_get_top_products_from_orders')
                ? sbm_get_top_products_from_orders($shop, 7, 700, 3)
                : [];
            $invInsights = function_exists('sbm_getInventoryInsights') ? sbm_getInventoryInsights($shop, 180) : [];
            $lowStockCount = is_array($invInsights['low_stock'] ?? null) ? count($invInsights['low_stock']) : 0;

            $model = getenv('ANTHROPIC_MODEL_DIGEST');
            if (!is_string($model) || trim($model) === '') $model = 'claude-sonnet-4-20250514';
            $weekKey = sbm_usage_week_key();

            $prompt = "You are a concise e-commerce analytics assistant for a Shopify merchant.\n"
                . "Write a single paragraph (3-4 sentences) weekly digest.\n"
                . "Use the numbers provided. Mention top products and inventory risk if present.\n"
                . "No bullet points.\n\n"
                . "Week: {$weekKey}\n"
                . "Revenue total (7d): {$revNow}\n"
                . "Revenue change % vs previous 7d: {$revChangePct}\n"
                . "Order count (7d): {$ordersNow}\n"
                . "AOV (7d): {$aovNow}\n"
                . "New customers (7d): {$newCustomers}\n"
                . "Returning rate % (proxy): {$returningRatePct}\n"
                . "Top products (top 3): " . json_encode($topProducts, JSON_UNESCAPED_UNICODE) . "\n"
                . "Low stock SKU count (<~5 units): {$lowStockCount}\n";

            $resAi = sbm_anthropic_messages([
                'model' => $model,
                'max_tokens' => 250,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ], 30);

            if (!$resAi['ok']) {
                return ['ok' => false, 'error' => $resAi['error'], 'text' => '', 'meta' => ['model' => $model]];
            }

            return [
                'ok' => true,
                'text' => (string)$resAi['text'],
                'meta' => ['model' => $model, 'week_key' => $weekKey],
            ];
        }, false);

        if (is_array($payload) && !empty($payload['ok']) && !empty($payload['cache']) && empty($payload['cache']['hit'])) {
            $generated++;
            $details[] = ['shop' => $shop, 'plan' => $planKey, 'result' => 'generated', 'reason' => 'cache_miss_or_stale'];
        } else {
            $skipped++;
            $details[] = ['shop' => $shop, 'plan' => $planKey, 'result' => 'skipped', 'reason' => 'cache_hit'];
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

