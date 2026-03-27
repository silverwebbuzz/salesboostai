<?php
/**
 * Dashboard API: /app/api/dashboard
 *
 * Returns per-store KPIs, charts and insights from local DB.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/entitlements.php';
require_once __DIR__ . '/../lib/metrics.php';

header('Content-Type: application/json');

/** Labels for last N days in store timezone (used for charts + lightweight shell response). */
function lastNDaysLabels(int $days, string $tz = 'UTC'): array {
    $out = [];
    $dt = new DateTime('now', new DateTimeZone($tz));
    $dt->setTime(0, 0, 0);
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = clone $dt;
        $d->modify("-{$i} days");
        $out[] = $d->format('Y-m-d');
    }
    return $out;
}

/** Check table existence safely before querying it. */
function dashboardTableExists(mysqli $mysqli, string $table): bool {
    try {
        $safe = $mysqli->real_escape_string($table);
        $res = $mysqli->query("SHOW TABLES LIKE '{$safe}'");
        return (bool)($res && $res->num_rows > 0);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Minimal dashboard JSON when sync is not finished — avoids heavy per-store queries and stale cache.
 *
 * @param array{state:string,pending:int,in_progress:int,error:int} $syncStatus
 */
function dashboardShellPayload(string $tz, array $syncStatus): array {
    $labels = lastNDaysLabels(30, $tz);
    $n = count($labels);
    return [
        'ok' => true,
        'kpi' => [
            'revenue' => 0.0,
            'orders' => 0,
            'aov' => 0.0,
            'customers' => 0,
        ],
        'charts' => [
            'labels' => $labels,
            'revenue' => array_fill(0, $n, 0.0),
            'orders' => array_fill(0, $n, 0),
        ],
        'insights' => [
            'top_products' => [],
            'low_stock' => [],
            'high_value_customers' => [],
        ],
        'summary_text' => '',
        'critical_issues' => [],
        'action_center' => [],
        'inventory_metrics' => [
            'cash_in_inventory' => 0.0,
            'dead_stock_value' => 0.0,
            'restock_needed_value' => 0.0,
        ],
        'inventory_forecast' => [],
        'goals' => [],
        'key_insights' => [],
        'sync_status' => $syncStatus,
        'locks' => [],
        'entitlements' => [],
        'meta' => [
            'computed_at' => gmdate('c'),
            'source' => 'shell',
            'note' => 'Sync not finished; full KPIs are not computed yet.',
        ],
    ];
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

$mysqli = db();
$shopName = makeShopName($shop);
$ordersTable = perStoreTableName($shopName, 'order');
$customersTable = perStoreTableName($shopName, 'customer');
$inventoryTable = perStoreTableName($shopName, 'products_inventory');
$analyticsTable = perStoreTableName($shopName, 'analytics');
$entitlements = function_exists('getPlanEntitlements') ? getPlanEntitlements($shop) : [
    'plan_key' => 'free',
    'plan_label' => 'Free',
    'features' => [],
    'limits' => [],
];
$featureFlags = is_array($entitlements['features'] ?? null) ? $entitlements['features'] : [];
$planLimits = is_array($entitlements['limits'] ?? null) ? $entitlements['limits'] : [];
$locks = [
    'inventory_insights' => !((bool)($featureFlags['dashboard_inventory'] ?? false)),
    'critical_insights' => !((bool)($featureFlags['dashboard_critical_full'] ?? false)),
    'top_lists' => !((bool)($featureFlags['dashboard_top_lists_full'] ?? false)),
    'action_center' => !((bool)($featureFlags['dashboard_action_center'] ?? false)),
    'inventory_forecast' => !((bool)($featureFlags['inventory_forecasting'] ?? false)),
    'goals' => !((bool)($featureFlags['goals_tracking'] ?? false)),
];
$topProductsLimit = max(1, (int)($planLimits['top_products_count'] ?? 5));
$criticalLimit = max(1, (int)($planLimits['critical_insights_count'] ?? 4));
$actionItemsLimit = max(1, (int)($planLimits['action_items_count'] ?? 5));

// Sync status (for first-install UX guidance)
$syncStatus = [
    'state' => 'ready', // ready | needs_sync | syncing | error
    'pending' => 0,
    'in_progress' => 0,
    'error' => 0,
];
try {
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

    $stmtSync = $mysqli->prepare(
        "SELECT
            SUM(status='pending') AS pending_count,
            SUM(status='in_progress') AS in_progress_count,
            SUM(status='error') AS error_count
         FROM store_sync_state
         WHERE shop = ?"
    );
    if ($stmtSync) {
        $stmtSync->bind_param('s', $shop);
        $stmtSync->execute();
        $resSync = $stmtSync->get_result();
        $rowSync = $resSync ? ($resSync->fetch_assoc() ?: null) : null;
        $stmtSync->close();
        if ($rowSync) {
            $syncStatus['pending'] = (int)($rowSync['pending_count'] ?? 0);
            $syncStatus['in_progress'] = (int)($rowSync['in_progress_count'] ?? 0);
            $syncStatus['error'] = (int)($rowSync['error_count'] ?? 0);
            if ($syncStatus['error'] > 0) {
                $syncStatus['state'] = 'error';
            } elseif (($syncStatus['pending'] + $syncStatus['in_progress']) > 0) {
                $syncStatus['state'] = 'syncing';
            }
        }
    }

    // No sync rows yet (e.g. enqueue failed) — do not treat as "ready" with empty DB.
    if ($syncRowCount === 0) {
        $syncStatus['state'] = 'needs_sync';
    }
} catch (Throwable $e) {
    // Non-blocking: keep dashboard available.
}

$tz = (string)($store['iana_timezone'] ?? '');
if ($tz === '') {
    $tz = 'UTC';
}

// If per-store tables are not ready yet, keep first-load safe with sync shell.
// This prevents non-JSON fatal responses when install/sync is incomplete.
$requiredTablesReady =
    dashboardTableExists($mysqli, $ordersTable) &&
    dashboardTableExists($mysqli, $customersTable) &&
    dashboardTableExists($mysqli, $inventoryTable) &&
    dashboardTableExists($mysqli, $analyticsTable);
if (!$requiredTablesReady) {
    $syncStatus['state'] = 'needs_sync';
    $shell = dashboardShellPayload($tz, $syncStatus);
    $shell['locks'] = $locks;
    $shell['entitlements'] = [
        'plan_key' => (string)($entitlements['plan_key'] ?? 'free'),
        'plan_label' => (string)($entitlements['plan_label'] ?? 'Free'),
        'limits' => $planLimits,
    ];
    $shell['meta']['note'] = 'Required per-store tables are not ready yet.';
    echo json_encode($shell, JSON_UNESCAPED_UNICODE);
    exit;
}

// First open / sync not complete: skip cache + skip heavy queries — UI shows sync gate only.
if (($syncStatus['state'] ?? 'ready') !== 'ready') {
    $shell = dashboardShellPayload($tz, $syncStatus);
    $shell['locks'] = $locks;
    $shell['entitlements'] = [
        'plan_key' => (string)($entitlements['plan_key'] ?? 'free'),
        'plan_label' => (string)($entitlements['plan_label'] ?? 'Free'),
        'limits' => $planLimits,
    ];
    echo json_encode($shell, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 5 min cache in per-store analytics table (metric_key = dashboard_cache) ----
// Only when sync is ready. Also validate dashboard_cache_sig vs live row counts so post-sync data is never hidden behind stale zeros.
$cacheTtl = 300;
$bypassCache = !empty($_GET['nocache']) || !empty($_GET['refresh']);
try {
    $safe = $mysqli->real_escape_string($analyticsTable);
    $exists = $mysqli->query("SHOW TABLES LIKE '{$safe}'");
    if (!$bypassCache && $exists && $exists->num_rows > 0) {
        $stmt = $mysqli->prepare("SELECT payload_json, fetched_at FROM `{$analyticsTable}` WHERE metric_key='dashboard_cache' LIMIT 1");
        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? ($res->fetch_assoc() ?: null) : null;
            $stmt->close();
            if ($row && !empty($row['payload_json']) && !empty($row['fetched_at'])) {
                $age = time() - strtotime((string)$row['fetched_at']);
                if ($age >= 0 && $age <= $cacheTtl) {
                    $cached = json_decode((string)$row['payload_json'], true);
                    if (is_array($cached)) {
                        $liveFp = dashboardCacheLiveFingerprint($shop);
                        $stmtSig = $mysqli->prepare("SELECT metric_value FROM `{$analyticsTable}` WHERE metric_key='dashboard_cache_sig' LIMIT 1");
                        $storedSig = '';
                        if ($stmtSig) {
                            $stmtSig->execute();
                            $rs = $stmtSig->get_result();
                            $rw = $rs ? ($rs->fetch_assoc() ?: null) : null;
                            $stmtSig->close();
                            $storedSig = (string)($rw['metric_value'] ?? '');
                        }
                        $sigOk = $liveFp !== null && $storedSig !== '' && hash_equals($storedSig, $liveFp);
                        if ($sigOk) {
                            $cached['sync_status'] = $syncStatus;
                            $cached['locks'] = $locks;
                            $cached['entitlements'] = [
                                'plan_key' => (string)($entitlements['plan_key'] ?? 'free'),
                                'plan_label' => (string)($entitlements['plan_label'] ?? 'Free'),
                                'limits' => $planLimits,
                            ];
                            $cachedAt = strtotime((string)$row['fetched_at']);
                            $cached['meta'] = [
                                'computed_at' => $cachedAt ? gmdate('c', $cachedAt) : gmdate('c'),
                                'source' => 'cache',
                                'cache_age_seconds' => max(0, $age),
                                'data_fingerprint' => $liveFp,
                            ];
                            echo json_encode($cached, JSON_UNESCAPED_UNICODE);
                            exit;
                        }
                        // Stale cache (e.g. sync imported rows after cache was saved) — fall through and recompute fully.
                    }
                }
            }
        }
    }
} catch (Throwable $e) {
    // ignore cache errors
}

$labels = lastNDaysLabels(30, $tz);
$revSeries = array_fill(0, count($labels), 0.0);
$ordSeries = array_fill(0, count($labels), 0);
$labelIndex = array_flip($labels);

// ---- KPIs ----
$totalOrders = 0;
$totalCustomers = 0;
$totalRevenue = 0.0;

// Counts
if ($res = $mysqli->query("SELECT COUNT(*) AS c FROM `{$ordersTable}`")) {
    $row = $res->fetch_assoc();
    $totalOrders = (int)($row['c'] ?? 0);
}
if ($res = $mysqli->query("SELECT COUNT(*) AS c FROM `{$customersTable}`")) {
    $row = $res->fetch_assoc();
    $totalCustomers = (int)($row['c'] ?? 0);
}

// Revenue total (best-effort using JSON_EXTRACT)
$revSql = "SELECT SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.total_price')) AS DECIMAL(12,2))) AS s
           FROM `{$ordersTable}`";
$revRes = $mysqli->query($revSql);
if ($revRes) {
    $row = $revRes->fetch_assoc();
    $totalRevenue = (float)($row['s'] ?? 0);
} else {
    // Fallback: compute from up to 500 orders
    $fallback = $mysqli->query("SELECT payload_json FROM `{$ordersTable}` ORDER BY COALESCE(created_at,fetched_at) DESC LIMIT 500");
    if ($fallback) {
        while ($r = $fallback->fetch_assoc()) {
            $p = json_decode((string)($r['payload_json'] ?? ''), true);
            if (is_array($p) && isset($p['total_price'])) {
                $totalRevenue += (float)$p['total_price'];
            }
        }
    }
}

$aov = $totalOrders > 0 ? ($totalRevenue / $totalOrders) : 0.0;

// ---- Charts (last 30 days) ----
$since = (new DateTime('now', new DateTimeZone($tz)))->modify('-29 days')->setTime(0,0,0);
$sinceStr = $since->format('Y-m-d H:i:s');

// Orders count per day
$ordersByDay = $mysqli->query(
    "SELECT DATE(COALESCE(created_at, fetched_at)) AS d, COUNT(*) AS c
     FROM `{$ordersTable}`
     WHERE COALESCE(created_at, fetched_at) >= '{$mysqli->real_escape_string($sinceStr)}'
     GROUP BY d"
);
if ($ordersByDay) {
    while ($r = $ordersByDay->fetch_assoc()) {
        $d = (string)($r['d'] ?? '');
        if ($d !== '' && isset($labelIndex[$d])) {
            $ordSeries[$labelIndex[$d]] = (int)($r['c'] ?? 0);
        }
    }
}

// Revenue per day
$revByDay = $mysqli->query(
    "SELECT DATE(COALESCE(created_at, fetched_at)) AS d,
            SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.total_price')) AS DECIMAL(12,2))) AS s
     FROM `{$ordersTable}`
     WHERE COALESCE(created_at, fetched_at) >= '{$mysqli->real_escape_string($sinceStr)}'
     GROUP BY d"
);
if ($revByDay) {
    while ($r = $revByDay->fetch_assoc()) {
        $d = (string)($r['d'] ?? '');
        if ($d !== '' && isset($labelIndex[$d])) {
            $revSeries[$labelIndex[$d]] = (float)($r['s'] ?? 0);
        }
    }
}

// ---- Insights ----
// Low stock
$lowStock = [];
$ls = $mysqli->query("SELECT product_id, variant_id, sku, title, inventory_quantity
                      FROM `{$inventoryTable}`
                      WHERE inventory_quantity IS NOT NULL AND inventory_quantity < 10
                      ORDER BY inventory_quantity ASC
                      LIMIT 5");
if ($ls) {
    while ($r = $ls->fetch_assoc()) {
        $lowStock[] = [
            'product_id' => (int)($r['product_id'] ?? 0),
            'variant_id' => (int)($r['variant_id'] ?? 0),
            'sku' => (string)($r['sku'] ?? ''),
            'title' => (string)($r['title'] ?? ''),
            'inventory_quantity' => isset($r['inventory_quantity']) ? (int)$r['inventory_quantity'] : null,
        ];
    }
}

// Top products + high value customers (canonical helpers; last 30 days)
$topProductsTop5 = function_exists('sbm_get_top_products_from_orders')
    ? sbm_get_top_products_from_orders($shop, 30, 200, $topProductsLimit)
    : [];
$highValueCustomers = function_exists('sbm_get_top_customers_from_orders')
    ? sbm_get_top_customers_from_orders($shop, 30, 200, 5)
    : [];

// ---- Derived AI-style insights ----
$last30RevenueFromAgg = 0.0;
foreach ($topProductsTop5 as $tp) {
    $last30RevenueFromAgg += (float)($tp['revenue'] ?? 0.0);
}

$summaryRevenue = $last30RevenueFromAgg > 0 ? $last30RevenueFromAgg : $totalRevenue;
$summaryOrders = array_sum($ordSeries);

// Inventory snapshots for simple value metrics
$cashInInventory = 0.0;
$deadStockValue = 0.0;
$restockNeededValue = 0.0;
$deadStockCount = 0;
$productsNoSales = 0;
$inventoryLowButSold = 0;

// Build a set of product titles that had sales in last 30 days
$soldTitles = [];
foreach ($topProductsTop5 as $tp) {
    $soldTitles[(string)($tp['title'] ?? '')] = true;
}

$invRes = $mysqli->query("SELECT product_id, title, inventory_quantity
                          FROM `{$inventoryTable}`
                          WHERE inventory_quantity IS NOT NULL");
if ($invRes) {
    while ($r = $invRes->fetch_assoc()) {
        $title = (string)($r['title'] ?? '');
        $qty = (int)($r['inventory_quantity'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        // treat unit price as average AOV per order item roughly
        $avgUnit = $aov > 0 && $summaryOrders > 0 ? ($summaryRevenue / max(1, $summaryOrders)) : 0.0;
        $value = $avgUnit * $qty;
        $cashInInventory += $value;

        $soldRecently = $title !== '' && isset($soldTitles[$title]);
        if (!$soldRecently && $qty >= 1) {
            $deadStockValue += $value;
            $deadStockCount++;
        }
        if ($soldRecently && $qty < 5) {
            $restockNeededValue += $value;
            $inventoryLowButSold++;
        }
    }
}

// Critical issues
$criticalIssues = [];
// 1) Products with no sales in 30 days but inventory > 20
if ($deadStockCount > 0) {
    $criticalIssues[] = [
        'severity' => 'medium',
        'code' => 'dead_stock',
        'title' => 'Products not moving',
        'description' => "{$deadStockCount} products have stock but no sales in the last 30 days.",
    ];
}

// 2) Top product contributes > 40% revenue
if (!empty($topProductsTop5)) {
    $tp0 = $topProductsTop5[0];
    $tpRev = (float)($tp0['revenue'] ?? 0.0);
    if ($summaryRevenue > 0 && $tpRev / $summaryRevenue >= 0.4) {
        $pct = round(($tpRev / $summaryRevenue) * 100);
        $criticalIssues[] = [
            'severity' => 'high',
            'code' => 'revenue_concentration',
            'title' => 'Revenue depends on a single product',
            'description' => "Top product \"{$tp0['title']}\" generates about {$pct}% of revenue.",
        ];
    }
}

// 3) Inventory < 5 but recently sold
if ($inventoryLowButSold > 0) {
    $criticalIssues[] = [
        'severity' => 'high',
        'code' => 'low_stock_hot_items',
        'title' => 'High-demand items are close to stockout',
        'description' => 'Several products sold recently and now have less than 5 units left.',
    ];
}
$criticalIssues = array_slice($criticalIssues, 0, $criticalLimit);

// Key insight bullets
$numProductsNoSales = $deadStockCount;
$top3RevShare = 0.0;
if ($summaryRevenue > 0) {
    $top3Rev = 0.0;
    foreach (array_slice($topProductsTop5, 0, 3) as $tp) {
        $top3Rev += (float)($tp['revenue'] ?? 0.0);
    }
    $top3RevShare = ($top3Rev / $summaryRevenue) * 100;
}

// customers contributing 50% revenue
$sortedCustomers = $highValueCustomers;
usort($sortedCustomers, fn($a, $b) => (($b['total_spent'] ?? 0) <=> ($a['total_spent'] ?? 0)));
$halfTarget = $summaryRevenue * 0.5;
$halfCount = 0;
$acc = 0.0;
foreach ($sortedCustomers as $c) {
    $acc += (float)($c['total_spent'] ?? 0.0);
    $halfCount++;
    if ($acc >= $halfTarget) {
        break;
    }
}

$keyInsights = [];
$keyInsights[] = "{$numProductsNoSales} products have no sales in the last 30 days.";
if ($summaryRevenue > 0) {
    $keyInsights[] = "Top 3 products generate about " . round($top3RevShare) . "% of revenue.";
}
if ($summaryRevenue > 0 && $halfCount > 0) {
    $keyInsights[] = "{$halfCount} customers contribute roughly 50% of revenue.";
}

// Summary text
$summaryParts = [];
if ($summaryRevenue > 0 && $summaryOrders > 0) {
    $summaryParts[] = "Your store generated \$" . number_format($summaryRevenue, 2) . " revenue in the last 30 days from {$summaryOrders} orders.";
}
if ($cashInInventory > 0) {
    $summaryParts[] = "You currently have about \$" . number_format($cashInInventory, 2) . " locked in inventory.";
}
if (!empty($topProductsTop5)) {
    $tp0 = $topProductsTop5[0];
    $tpRev = (float)($tp0['revenue'] ?? 0.0);
    if ($summaryRevenue > 0 && $tpRev > 0) {
        $pct = round(($tpRev / $summaryRevenue) * 100);
        $summaryParts[] = "Your top product contributes about {$pct}% of revenue, which may indicate dependency risk.";
    }
}
$summaryText = implode(' ', $summaryParts);

$actionCenter = function_exists('sbm_get_action_center_items')
    ? sbm_get_action_center_items($shop, $actionItemsLimit)
    : [];

// Row-count fingerprint (must match dashboard_cache_sig when serving cached JSON after sync/webhooks).
$inventoryRowCount = 0;
if ($res = $mysqli->query("SELECT COUNT(*) AS c FROM `{$inventoryTable}`")) {
    $inventoryRowCount = (int)($res->fetch_assoc()['c'] ?? 0);
}
$dataFingerprint = $totalOrders . ':' . $totalCustomers . ':' . $inventoryRowCount;

$inventoryForecast = function_exists('sbm_get_inventory_forecast_rows')
    ? sbm_get_inventory_forecast_rows($shop, 5)
    : [];
if ($locks['inventory_forecast']) {
    $inventoryForecast = array_slice($inventoryForecast, 0, 1);
}

// Goals tracking (saved in analytics metric_key=dashboard_goals)
$goals = [
    'items' => [],
    'off_track_count' => 0,
];
try {
    $stmtGoals = $mysqli->prepare("SELECT payload_json FROM `{$analyticsTable}` WHERE metric_key='dashboard_goals' LIMIT 1");
    $goalItems = [];
    if ($stmtGoals) {
        $stmtGoals->execute();
        $resG = $stmtGoals->get_result();
        $rowG = $resG ? ($resG->fetch_assoc() ?: null) : null;
        $stmtGoals->close();
        $decoded = json_decode((string)($rowG['payload_json'] ?? ''), true);
        if (is_array($decoded['items'] ?? null)) {
            $goalItems = $decoded['items'];
        }
    }
    if (empty($goalItems)) {
        $goalItems = [
            ['key' => 'revenue_30d', 'label' => 'Revenue (30d)', 'target' => 5000, 'actual' => round($totalRevenue, 2)],
            ['key' => 'aov', 'label' => 'AOV', 'target' => 120, 'actual' => round($aov, 2)],
            ['key' => 'repeat_rate', 'label' => 'Repeat rate', 'target' => 30, 'actual' => $totalCustomers > 0 ? round((count($highValueCustomers) / max(1, $totalCustomers)) * 100, 2) : 0],
        ];
    }
    foreach ($goalItems as $g) {
        $target = (float)($g['target'] ?? 0);
        $actual = (float)($g['actual'] ?? 0);
        $progress = $target > 0 ? min(100, round(($actual / $target) * 100, 1)) : 0;
        $offTrack = $progress < 75;
        if ($offTrack) $goals['off_track_count']++;
        $goals['items'][] = [
            'key' => (string)($g['key'] ?? ''),
            'label' => (string)($g['label'] ?? 'Goal'),
            'target' => $target,
            'actual' => $actual,
            'progress_pct' => $progress,
            'off_track' => $offTrack,
        ];
    }
} catch (Throwable $e) {
    // keep defaults
}
if ($locks['goals']) {
    $goals['items'] = array_slice($goals['items'], 0, 1);
}

$out = [
    'ok' => true,
    'kpi' => [
        'revenue' => round($totalRevenue, 2),
        'orders' => $totalOrders,
        'aov' => round($aov, 2),
        'customers' => $totalCustomers,
    ],
    'charts' => [
        'labels' => $labels,
        'revenue' => array_map(fn($v) => round((float)$v, 2), $revSeries),
        'orders' => array_map('intval', $ordSeries),
    ],
    'insights' => [
        'top_products' => $topProductsTop5,
        'low_stock' => $lowStock,
        'high_value_customers' => $highValueCustomers,
    ],
    'summary_text' => $summaryText,
    'critical_issues' => $criticalIssues,
    'action_center' => $actionCenter,
    'inventory_metrics' => [
        'cash_in_inventory' => $locks['inventory_insights'] ? 0.0 : round($cashInInventory, 2),
        'dead_stock_value' => $locks['inventory_insights'] ? 0.0 : round($deadStockValue, 2),
        'restock_needed_value' => $locks['inventory_insights'] ? 0.0 : round($restockNeededValue, 2),
    ],
    'inventory_forecast' => $inventoryForecast,
    'goals' => $goals,
    'key_insights' => $keyInsights,
    'sync_status' => $syncStatus,
    'locks' => $locks,
    'entitlements' => [
        'plan_key' => (string)($entitlements['plan_key'] ?? 'free'),
        'plan_label' => (string)($entitlements['plan_label'] ?? 'Free'),
        'limits' => $planLimits,
    ],
    'meta' => [
        'computed_at' => gmdate('c'),
        'source' => 'live',
        'data_fingerprint' => $dataFingerprint,
    ],
];

// Save cache + fingerprint (best effort) — only after full aggregation above.
try {
    $safe = $mysqli->real_escape_string($analyticsTable);
    $exists = $mysqli->query("SHOW TABLES LIKE '{$safe}'");
    if ($exists && $exists->num_rows > 0) {
        $payloadJson = json_encode($out, JSON_UNESCAPED_UNICODE);
        $stmt = $mysqli->prepare("INSERT INTO `{$analyticsTable}` (metric_key, metric_value, payload_json)
            VALUES ('dashboard_cache', '', ?)
            ON DUPLICATE KEY UPDATE payload_json = VALUES(payload_json), fetched_at = NOW()");
        if ($stmt) {
            $stmt->bind_param('s', $payloadJson);
            $stmt->execute();
            $stmt->close();
        }
        $stmtSig = $mysqli->prepare("INSERT INTO `{$analyticsTable}` (metric_key, metric_value, payload_json)
            VALUES ('dashboard_cache_sig', ?, NULL)
            ON DUPLICATE KEY UPDATE metric_value = VALUES(metric_value), fetched_at = NOW()");
        if ($stmtSig) {
            $stmtSig->bind_param('s', $dataFingerprint);
            $stmtSig->execute();
            $stmtSig->close();
        }
    }
} catch (Throwable $e) {
    // ignore cache errors
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);

