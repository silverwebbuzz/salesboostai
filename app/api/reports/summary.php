<?php
/**
 * Reports summary API (Batch R2).
 *
 * GET /app/api/reports/summary.php?shop=...&tab=revenue|customers|inventory|funnel|attribution|goals|ai&range=7|30|90
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/metrics.php';
require_once __DIR__ . '/../../lib/entitlements.php';
require_once __DIR__ . '/../../lib/usage.php';

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

$tab = strtolower((string)($_GET['tab'] ?? 'revenue'));
if (!in_array($tab, ['revenue', 'customers', 'inventory', 'funnel', 'attribution', 'goals', 'ai'], true)) {
    $tab = 'revenue';
}

$range = (int)($_GET['range'] ?? 7);
if (!in_array($range, [7, 30, 90], true)) {
    $range = 7;
}

$tz = (string)($store['iana_timezone'] ?? '');
if ($tz === '') $tz = 'UTC';

$mysqli = db();
$shopName = makeShopName($shop);
$tables = sbm_getShopTables($shop);
$ordersTable = $tables['order'];
$customersTable = $tables['customer'];
$invTable = $tables['products_inventory'];
$actionTable = perStoreTableName($shopName, 'action_items');
$forecastsTable = perStoreTableName($shopName, 'forecasts');
$funnelTable = perStoreTableName($shopName, 'funnel');
$attributionTable = perStoreTableName($shopName, 'attribution');
$analyticsTable = $tables['analytics'];

$entitlements = function_exists('getPlanEntitlements') ? getPlanEntitlements($shop) : ['features' => [], 'limits' => [], 'plan_key' => 'free'];
$features = is_array($entitlements['features'] ?? null) ? $entitlements['features'] : [];
$limits = is_array($entitlements['limits'] ?? null) ? $entitlements['limits'] : [];

$bounds = sbm_period_bounds($tz, $range);
$start = (string)$bounds['start'];

function sbm_money(float $v): string { return '$' . number_format($v, 2); }

function listRowsHtml(array $rows, string $empty = 'No data yet.'): string
{
    if (empty($rows)) {
        return '<div class="sb-muted">' . htmlspecialchars($empty, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    $out = '';
    foreach ($rows as $r) {
        $l = htmlspecialchars((string)($r['left'] ?? ''), ENT_QUOTES, 'UTF-8');
        $v = htmlspecialchars((string)($r['right'] ?? ''), ENT_QUOTES, 'UTF-8');
        $out .= '<div class="SbListRow"><div class="sb-list-left">' . $l . '</div><div class="sb-list-right">' . $v . '</div></div>';
    }
    return $out;
}

// Pull a few high-impact actions (shared across tabs).
$actions = [];
try {
    $acItems = function_exists('sbm_get_action_center_items') ? sbm_get_action_center_items($shop, 8) : [];
    foreach ($acItems as $it) {
        $actions[] = [
            'key' => (string)($it['key'] ?? ''),
            'title' => (string)($it['title'] ?? ''),
            'severity' => (string)($it['severity'] ?? 'medium'),
            'impact' => (float)($it['impact_score'] ?? 0),
            'status' => (string)($it['status'] ?? 'new'),
        ];
    }
} catch (Throwable $e) { $actions = []; }

$payload = [
    'ok' => true,
    'tab' => $tab,
    'range' => $range,
    'summary_bullets' => [],
    'critical_insights' => [],
    'recommendations' => [],
    'actions' => $actions,
    'supporting' => [],
];

// Plan gating for advanced tabs
if ($tab === 'funnel' && empty($features['analytics_funnel'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Funnel reports are not available on your current plan.', 'required_plan' => getFeatureRequiredPlan('analytics_funnel')], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($tab === 'attribution' && empty($features['analytics_attribution'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Attribution reports are not available on your current plan.', 'required_plan' => getFeatureRequiredPlan('analytics_attribution')], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($tab === 'goals' && empty($features['goals_tracking'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Goals reports are not available on your current plan.', 'required_plan' => getFeatureRequiredPlan('goals_tracking')], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($tab === 'revenue') {
    $rev = 0.0;
    $orders = 0;
    $stmt = $mysqli->prepare(
        "SELECT
            COUNT(*) AS c,
            SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.total_price')) AS DECIMAL(12,2))) AS s
         FROM `{$ordersTable}`
         WHERE COALESCE(created_at, fetched_at) >= ?"
    );
    if ($stmt) {
        $stmt->bind_param('s', $start);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? ($res->fetch_assoc() ?: null) : null;
        $stmt->close();
        $orders = (int)($row['c'] ?? 0);
        $rev = (float)($row['s'] ?? 0);
    }
    $aov = $orders > 0 ? ($rev / $orders) : 0.0;

    $payload['summary_bullets'] = [
        'Revenue: ' . sbm_money($rev) . ' from ' . number_format($orders) . ' orders.',
        'AOV: ' . sbm_money((float)$aov) . '.',
        'Review Critical Insights for concentration risk and volatility signals.',
    ];
    $payload['critical_insights'] = [
        ['title' => 'Revenue depends on a small set of products', 'description' => 'Check top product share and diversify promotions.', 'severity' => 'medium'],
    ];
    $payload['recommendations'] = [
        ['title' => 'Run a focused promotion on 2–3 mid-tier products', 'impact' => 'Increase revenue diversification and reduce dependency risk.'],
        ['title' => 'Bundle slow movers with best sellers', 'impact' => 'Lift AOV while reducing dead stock.'],
    ];
    $payload['supporting'] = [
        'kpis' => ['revenue' => round($rev, 2), 'orders' => $orders, 'aov' => round($aov, 2)],
    ];
}

if ($tab === 'customers') {
    $totalCustomers = 0;
    $newCustomers = 0;
    try {
        $resT = $mysqli->query("SELECT COUNT(*) AS c FROM `{$customersTable}`");
        if ($resT) {
            $totalCustomers = (int)($resT->fetch_assoc()['c'] ?? 0);
        }
        $stmtN = $mysqli->prepare("SELECT COUNT(*) AS c FROM `{$customersTable}` WHERE COALESCE(created_at, fetched_at) >= ?");
        if ($stmtN) {
            $stmtN->bind_param('s', $start);
            $stmtN->execute();
            $rN = $stmtN->get_result();
            $rowN = $rN ? ($rN->fetch_assoc() ?: null) : null;
            $stmtN->close();
            $newCustomers = (int)($rowN['c'] ?? 0);
        }
    } catch (Throwable $e) {
        // ignore
    }
    $returning = max(0, $totalCustomers - $newCustomers);
    $repeatRate = ($newCustomers + $returning) > 0 ? round(($returning / max(1, ($newCustomers + $returning))) * 100, 2) : 0.0;

    $payload['summary_bullets'] = [
        'Customers: ' . number_format($totalCustomers) . ' total.',
        'New customers in period: ' . number_format($newCustomers) . '.',
        'Repeat rate (proxy): ' . number_format($repeatRate, 1) . '%.',
    ];
    $payload['critical_insights'] = [
        ['title' => 'Repeat rate opportunity', 'description' => 'If repeat rate is low, focus on post-purchase follow-ups.', 'severity' => 'medium'],
    ];
    $payload['recommendations'] = [
        ['title' => 'Launch a 7-day post-purchase email sequence', 'impact' => 'Increase repeat purchase rate and LTV.'],
        ['title' => 'Create VIP segment and offer early access', 'impact' => 'Protect top customers and increase retention.'],
    ];

    // Cohorts/retention preview from derived table
    $retRows = [];
    try {
        $coh = function_exists('sbm_get_retention_cohort_rows') ? sbm_get_retention_cohort_rows($shop, 6) : [];
        foreach ($coh as $r) {
            $retRows[] = [
                'left' => (string)($r['cohort_key'] ?? ''),
                'right' => number_format((float)($r['retention_rate'] ?? 0), 1) . '%',
            ];
        }
    } catch (Throwable $e) {}
    $payload['supporting'] = [
        'retention_html' => listRowsHtml($retRows, 'No cohort retention data yet.'),
    ];
}

if ($tab === 'inventory') {
    $low = 0;
    $dead = 0;
    try {
        $resLow = $mysqli->query("SELECT COUNT(*) AS c FROM `{$invTable}` WHERE inventory_quantity IS NOT NULL AND inventory_quantity > 0 AND inventory_quantity < 5");
        if ($resLow) $low = (int)($resLow->fetch_assoc()['c'] ?? 0);
        $resDead = $mysqli->query("SELECT COUNT(*) AS c FROM `{$invTable}` WHERE inventory_quantity IS NOT NULL AND inventory_quantity > 0");
        if ($resDead) $dead = max(0, (int)($resDead->fetch_assoc()['c'] ?? 0));
    } catch (Throwable $e) {}

    $payload['summary_bullets'] = [
        'Low-stock SKUs: ' . number_format($low) . '.',
        'Inventory monitored: ' . number_format($dead) . ' rows in inventory table.',
        'Use Forecast to prevent stockouts and protect best sellers.',
    ];
    $payload['critical_insights'] = [
        ['title' => 'Stockout risk on best sellers', 'description' => 'Monitor items with low inventory and high sales velocity.', 'severity' => 'high'],
    ];
    $payload['recommendations'] = [
        ['title' => 'Restock items projected to stock out in < 14 days', 'impact' => 'Avoid lost revenue due to stockouts.'],
        ['title' => 'Discount dead stock bundles', 'impact' => 'Free up cash tied in slow inventory.'],
    ];

    $forecastRows = [];
    try {
        $fc = function_exists('sbm_get_inventory_forecast_rows') ? sbm_get_inventory_forecast_rows($shop, 8) : [];
        foreach ($fc as $r) {
            $forecastRows[] = [
                'left' => (string)($r['title'] ?? ''),
                'right' => number_format((float)($r['days_to_stockout'] ?? 0), 1) . ' days',
            ];
        }
    } catch (Throwable $e) {}

    $payload['supporting'] = [
        'forecast_html' => listRowsHtml($forecastRows, 'No stockout forecast yet.'),
    ];
}

if ($tab === 'funnel') {
    $depth = max(2, (int)($limits['funnel_breakdown_depth'] ?? 2));
    $rows = [];
    try {
        $safe = $mysqli->real_escape_string($funnelTable);
        $exists = $mysqli->query("SHOW TABLES LIKE '{$safe}'");
        if ($exists && $exists->num_rows > 0) {
            $stmt = $mysqli->prepare(
                "SELECT step_name, step_count, conversion_rate
                 FROM `{$funnelTable}`
                 WHERE window_key='last_30d'
                 ORDER BY step_order ASC
                 LIMIT ?"
            );
            if ($stmt) {
                $stmt->bind_param('i', $depth);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $rows[] = [
                        'left' => (string)($r['step_name'] ?? ''),
                        'right' => number_format((int)($r['step_count'] ?? 0)) . ' (' . number_format((float)($r['conversion_rate'] ?? 0), 1) . '%)',
                    ];
                }
                $stmt->close();
            }
        }
    } catch (Throwable $e) {}

    $payload['summary_bullets'] = [
        'Funnel shows estimated drop-offs from Sessions → Purchase.',
        'Focus on the largest step drop to improve conversion rate.',
    ];
    $payload['critical_insights'] = [
        ['title' => 'Checkout drop-off risk', 'description' => 'If Checkout → Purchase conversion is low, review shipping, trust signals, and checkout friction.', 'severity' => 'medium'],
    ];
    $payload['recommendations'] = [
        ['title' => 'Improve checkout trust signals', 'impact' => 'Increase purchase conversion from checkout.'],
        ['title' => 'Add cart upsells carefully', 'impact' => 'Lift AOV without increasing abandonment.'],
    ];
    $payload['supporting'] = [
        'funnel_html' => listRowsHtml($rows, 'No funnel data yet.'),
    ];
}

if ($tab === 'attribution') {
    $limit = max(3, (int)($limits['attribution_sources'] ?? 4));
    $rows = [];
    try {
        $safe = $mysqli->real_escape_string($attributionTable);
        $exists = $mysqli->query("SHOW TABLES LIKE '{$safe}'");
        if ($exists && $exists->num_rows > 0) {
            $stmt = $mysqli->prepare(
                "SELECT source_name, revenue_total, orders_count
                 FROM `{$attributionTable}`
                 WHERE window_key='last_30d'
                 ORDER BY revenue_total DESC
                 LIMIT ?"
            );
            if ($stmt) {
                $stmt->bind_param('i', $limit);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $rows[] = [
                        'left' => (string)($r['source_name'] ?? 'unknown'),
                        'right' => sbm_money((float)($r['revenue_total'] ?? 0)) . ' · ' . number_format((int)($r['orders_count'] ?? 0)) . ' orders',
                    ];
                }
                $stmt->close();
            }
        }
    } catch (Throwable $e) {}

    $payload['summary_bullets'] = [
        'Attribution shows which sources are contributing revenue.',
        'Optimize spend on the top converting sources and fix weak ones.',
    ];
    $payload['critical_insights'] = [
        ['title' => 'Over-reliance on a single source', 'description' => 'If most revenue comes from one channel, diversify acquisition.', 'severity' => 'medium'],
    ];
    $payload['recommendations'] = [
        ['title' => 'Double-down on highest ROI source', 'impact' => 'Increase revenue with controlled CAC.'],
        ['title' => 'Test a new channel with small budget', 'impact' => 'Reduce dependency risk.'],
    ];
    $payload['supporting'] = [
        'attribution_html' => listRowsHtml($rows, 'No attribution data yet.'),
    ];
}

if ($tab === 'goals') {
    $items = [];
    try {
        $stmtGoals = $mysqli->prepare("SELECT payload_json FROM `{$analyticsTable}` WHERE metric_key='dashboard_goals' LIMIT 1");
        if ($stmtGoals) {
            $stmtGoals->execute();
            $resG = $stmtGoals->get_result();
            $rowG = $resG ? ($resG->fetch_assoc() ?: null) : null;
            $stmtGoals->close();
            $decoded = json_decode((string)($rowG['payload_json'] ?? ''), true);
            if (is_array($decoded['items'] ?? null)) {
                $items = $decoded['items'];
            }
        }
    } catch (Throwable $e) {}

    $rows = [];
    foreach ($items as $g) {
        $label = (string)($g['label'] ?? 'Goal');
        $target = (float)($g['target'] ?? 0);
        $actual = (float)($g['actual'] ?? 0);
        $pct = $target > 0 ? min(100, round(($actual / $target) * 100, 1)) : 0;
        $rows[] = ['left' => $label, 'right' => number_format($pct, 1) . '%'];
    }

    $payload['summary_bullets'] = [
        'Goals show progress vs targets for key KPIs.',
        'Focus first on goals marked off-track to recover performance.',
    ];
    $payload['critical_insights'] = [
        ['title' => 'Off-track KPI risk', 'description' => 'If progress is below 75%, you may miss targets without action.', 'severity' => 'high'],
    ];
    $payload['recommendations'] = [
        ['title' => 'Review goals and adjust weekly actions', 'impact' => 'Bring KPIs back on track.'],
    ];
    $payload['supporting'] = [
        'goals_html' => listRowsHtml($rows, 'No goals configured yet.'),
    ];
}

if ($tab === 'ai') {
    $aiLimit = (int)($limits['ai_insights_per_week'] ?? 1);
    $aiUsage = sbm_usage_state($shop, 'ai_insights', $aiLimit);
    if ($aiUsage['reached'] && !$aiUsage['unlimited']) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Weekly AI insights limit reached for your plan.', 'required_plan' => 'starter'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payload['summary_bullets'] = [
        'AI Summary highlights the top opportunities and risks detected this week.',
        'Use Action Center items to execute quickly.',
    ];
    $payload['critical_insights'] = [
        ['title' => 'AI summary is ready', 'description' => 'This section will be enhanced with saved report snapshots in future.', 'severity' => 'low'],
    ];
    $payload['recommendations'] = [
        ['title' => 'Generate latest AI agent report', 'impact' => 'Get prioritized actions based on newest orders and inventory.' ],
    ];
    $payload['supporting'] = [
        'ai_usage' => $aiUsage,
    ];
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE);

