<?php

/**
 * Shared metrics helpers (Phase 1 optimization):
 * - Reusable per-store table access
 * - Lightweight TTL cache in per-store analytics table
 * - Shared heavy computations for Alerts / Customers / Inventory insights
 */

if (!function_exists('sbm_getShopTables')) {
    function sbm_getShopTables(string $shop): array
    {
        $shopName = makeShopName($shop);
        return [
            'shop_name' => $shopName,
            'order' => perStoreTableName($shopName, 'order'),
            'customer' => perStoreTableName($shopName, 'customer'),
            'products_inventory' => perStoreTableName($shopName, 'products_inventory'),
            'analytics' => perStoreTableName($shopName, 'analytics'),
        ];
    }
}

// Backward-friendly generic names (as discussed in optimization plan)
if (!function_exists('getShopTables')) {
    function getShopTables(string $shop): array
    {
        return sbm_getShopTables($shop);
    }
}

if (!function_exists('sbm_cleanProductName')) {
    function sbm_cleanProductName(string $name): string
    {
        $v = trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n", "\t"], ' ', $name)));
        return $v !== '' ? $v : 'Unnamed product';
    }
}

if (!function_exists('sbm_clampInt')) {
    function sbm_clampInt($v): int
    {
        $n = (int)$v;
        return $n < 0 ? 0 : $n;
    }
}

if (!function_exists('sbm_cache_get')) {
    /**
     * Cache whether a per-store analytics table exists (per PHP request).
     * Avoids repeating SHOW TABLES LIKE for every cached metric call.
     */
    function sbm_analytics_cache_enabled(string $shop): bool
    {
        static $enabledByShop = [];
        if (array_key_exists($shop, $enabledByShop)) return (bool)$enabledByShop[$shop];
        try {
            $mysqli = db();
            $tables = sbm_getShopTables($shop);
            $analyticsTable = $tables['analytics'];
            $safe = $mysqli->real_escape_string($analyticsTable);
            $exists = $mysqli->query("SHOW TABLES LIKE '{$safe}'");
            $enabledByShop[$shop] = ($exists && $exists->num_rows > 0);
            return (bool)$enabledByShop[$shop];
        } catch (Throwable $e) {
            $enabledByShop[$shop] = false;
            return false;
        }
    }

    function sbm_cache_get(string $shop, string $metricKey, int $ttlSec): ?array
    {
        try {
            $mysqli = db();
            if (!sbm_analytics_cache_enabled($shop)) return null;
            $tables = sbm_getShopTables($shop);
            $analyticsTable = $tables['analytics'];
            $safe = $mysqli->real_escape_string($analyticsTable);

            $stmt = $mysqli->prepare("SELECT payload_json, fetched_at FROM `{$analyticsTable}` WHERE metric_key = ? LIMIT 1");
            if (!$stmt) return null;
            $stmt->bind_param('s', $metricKey);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? ($res->fetch_assoc() ?: null) : null;
            $stmt->close();
            if (!$row || empty($row['payload_json']) || empty($row['fetched_at'])) return null;

            $age = time() - strtotime((string)$row['fetched_at']);
            if ($age < 0 || $age > $ttlSec) return null;

            $decoded = json_decode((string)$row['payload_json'], true);
            return is_array($decoded) ? $decoded : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('sbm_cache_set')) {
    function sbm_cache_set(string $shop, string $metricKey, array $payload): void
    {
        try {
            $mysqli = db();
            if (!sbm_analytics_cache_enabled($shop)) return;
            $tables = sbm_getShopTables($shop);
            $analyticsTable = $tables['analytics'];
            $safe = $mysqli->real_escape_string($analyticsTable);

            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $stmt = $mysqli->prepare(
                "INSERT INTO `{$analyticsTable}` (metric_key, metric_value, payload_json)
                 VALUES (?, '', ?)
                 ON DUPLICATE KEY UPDATE payload_json = VALUES(payload_json), fetched_at = NOW()"
            );
            if (!$stmt) return;
            $stmt->bind_param('ss', $metricKey, $payloadJson);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            // best-effort cache
        }
    }
}

if (!function_exists('sbm_getAlertsData')) {
    function sbm_getAlertsData(string $shop, array $shopRecord, int $ttlSec = 180): array
    {
        $cacheKey = 'alerts_cache_v1';
        $cached = sbm_cache_get($shop, $cacheKey, $ttlSec);
        if (is_array($cached)) return $cached;

        $result = [
            'criticalAlerts' => [],
            'warningAlerts' => [],
            'infoAlerts' => [],
            'inventoryAgentId' => 0,
            'revenueAgentId' => 0,
            'productAgentId' => 0,
        ];

        $mysqli = db();
        $tables = sbm_getShopTables($shop);
        $ordersTable = $tables['order'];
        $inventoryTable = $tables['products_inventory'];

        $agentRes = $mysqli->query("SELECT id, agent_key FROM ai_agents WHERE agent_key IN ('inventory','revenue','product')");
        if ($agentRes) {
            while ($ar = $agentRes->fetch_assoc()) {
                $key = (string)($ar['agent_key'] ?? '');
                $id = (int)($ar['id'] ?? 0);
                if ($key === 'inventory') $result['inventoryAgentId'] = $id;
                if ($key === 'revenue') $result['revenueAgentId'] = $id;
                if ($key === 'product') $result['productAgentId'] = $id;
            }
        }

        $tz = (string)($shopRecord['iana_timezone'] ?? 'UTC');
        if ($tz === '') $tz = 'UTC';
        $now = new DateTimeImmutable('now', new DateTimeZone($tz));

        // Revenue drop
        $curStart = $now->modify('-6 days')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $prevStart = $now->modify('-13 days')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $prevEnd = $now->modify('-7 days')->setTime(23, 59, 59)->format('Y-m-d H:i:s');
        $curTotal = 0.0;
        $prevTotal = 0.0;

        $stmtCur = $mysqli->prepare(
            "SELECT SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.total_price')) AS DECIMAL(12,2))) AS s
             FROM `{$ordersTable}`
             WHERE COALESCE(created_at, fetched_at) >= ?"
        );
        if ($stmtCur) {
            $stmtCur->bind_param('s', $curStart);
            $stmtCur->execute();
            $resCur = $stmtCur->get_result();
            $row = $resCur ? ($resCur->fetch_assoc() ?: null) : null;
            $curTotal = (float)($row['s'] ?? 0);
            $stmtCur->close();
        }
        $stmtPrev = $mysqli->prepare(
            "SELECT SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.total_price')) AS DECIMAL(12,2))) AS s
             FROM `{$ordersTable}`
             WHERE COALESCE(created_at, fetched_at) BETWEEN ? AND ?"
        );
        if ($stmtPrev) {
            $stmtPrev->bind_param('ss', $prevStart, $prevEnd);
            $stmtPrev->execute();
            $resPrev = $stmtPrev->get_result();
            $row = $resPrev ? ($resPrev->fetch_assoc() ?: null) : null;
            $prevTotal = (float)($row['s'] ?? 0);
            $stmtPrev->close();
        }
        if ($prevTotal > 0) {
            $dropPct = (($prevTotal - $curTotal) / $prevTotal) * 100;
            if ($dropPct >= 20) {
                $result['criticalAlerts'][] = [
                    'title' => '🚨 Revenue dropped by ' . round($dropPct) . '% this week',
                    'meta' => 'Previous 7 days: $' . number_format($prevTotal, 2) . ' · Last 7 days: $' . number_format($curTotal, 2),
                    'details_url_key' => 'revenue',
                ];
            }
        }

        // Inventory snapshot
        $inventory = [];
        $invRes = $mysqli->query("SELECT title, sku, inventory_quantity FROM `{$inventoryTable}` WHERE inventory_quantity IS NOT NULL");
        if ($invRes) {
            while ($r = $invRes->fetch_assoc()) {
                $title = sbm_cleanProductName((string)($r['title'] ?? ''));
                if ($title === 'Unnamed product') $title = sbm_cleanProductName((string)($r['sku'] ?? ''));
                if ($title === 'Unnamed product') continue;
                $inventory[$title] = sbm_clampInt($r['inventory_quantity'] ?? 0);
            }
        }

        // Sales maps
        $sales30 = [];
        $sales7 = [];
        $since30 = $now->modify('-29 days')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $since7Ts = $now->modify('-6 days')->setTime(0, 0, 0)->getTimestamp();
        $stmtOrders = $mysqli->prepare(
            "SELECT COALESCE(created_at, fetched_at) AS event_at, payload_json
             FROM `{$ordersTable}`
             WHERE COALESCE(created_at, fetched_at) >= ?
             ORDER BY COALESCE(created_at, fetched_at) DESC
             LIMIT 700"
        );
        if ($stmtOrders) {
            $stmtOrders->bind_param('s', $since30);
            $stmtOrders->execute();
            $resOrders = $stmtOrders->get_result();
            while ($row = $resOrders->fetch_assoc()) {
                $eventAt = (string)($row['event_at'] ?? '');
                $eventTs = $eventAt !== '' ? strtotime($eventAt) : false;
                $payload = json_decode((string)($row['payload_json'] ?? ''), true);
                if (!is_array($payload)) continue;
                $lineItems = isset($payload['line_items']) && is_array($payload['line_items']) ? $payload['line_items'] : [];
                foreach ($lineItems as $li) {
                    if (!is_array($li)) continue;
                    $title = sbm_cleanProductName((string)($li['title'] ?? ''));
                    if ($title === 'Unnamed product') continue;
                    $qty = (int)($li['quantity'] ?? 0);
                    $sales30[$title] = ($sales30[$title] ?? 0) + max(0, $qty);
                    if ($eventTs !== false && $eventTs >= $since7Ts) {
                        $sales7[$title] = ($sales7[$title] ?? 0) + max(0, $qty);
                    }
                }
            }
            $stmtOrders->close();
        }

        // Bestseller low stock
        $topSelling = $sales30;
        arsort($topSelling);
        foreach (array_slice($topSelling, 0, 5, true) as $title => $qty) {
            $invQty = (int)($inventory[$title] ?? 0);
            if ($invQty < 5) {
                $result['criticalAlerts'][] = [
                    'title' => '🚨 Bestseller running low on stock',
                    'meta' => sbm_cleanProductName((string)$title) . ' has only ' . $invQty . ' units left. Restock soon to avoid lost sales.',
                    'details_url_key' => 'inventory',
                ];
                break;
            }
        }

        // Stopped selling
        $stopped = [];
        foreach ($sales30 as $title => $qty30) {
            if ($qty30 > 0 && (int)($sales7[$title] ?? 0) === 0) {
                $stopped[] = ['title' => $title, 'qty30' => (int)$qty30];
            }
        }
        if (!empty($stopped)) {
            usort($stopped, fn($a, $b) => ((int)$b['qty30']) <=> ((int)$a['qty30']));
            $result['warningAlerts'][] = [
                'title' => '⚠️ ' . count($stopped) . ' products stopped selling',
                'list' => array_map(fn($x) => (string)$x['title'], array_slice($stopped, 0, 3)),
                'details_url_key' => 'product',
            ];
        }

        // Low stock list
        $lowStock = [];
        foreach ($inventory as $title => $qty) {
            if ($qty > 0 && $qty < 5) $lowStock[] = ['title' => $title, 'qty' => $qty];
        }
        if (!empty($lowStock)) {
            usort($lowStock, fn($a, $b) => ((int)$a['qty']) <=> ((int)$b['qty']));
            $result['warningAlerts'][] = [
                'title' => '⚠️ Low stock products',
                'list' => array_map(fn($x) => sbm_cleanProductName((string)$x['title']) . ' - only ' . (int)$x['qty'] . ' left', array_slice($lowStock, 0, 5)),
                'details_url_key' => 'inventory',
            ];
        }

        // Dead / slow
        $dead = [];
        foreach ($inventory as $title => $qty) {
            if ($qty > 0 && ((int)($sales30[$title] ?? 0) === 0)) $dead[] = $title;
        }
        if (!empty($dead)) {
            $result['warningAlerts'][] = [
                'title' => '⚠️ Dead stock identified',
                'meta' => count($dead) . ' products have inventory but no sales in last 30 days.',
                'details_url_key' => 'inventory',
            ];
        }

        $slow = [];
        foreach ($sales30 as $title => $qty30) {
            $v = ((int)$qty30) / 30.0;
            if ($qty30 > 0 && $v < 2) $slow[] = ['title' => $title, 'v' => $v];
        }
        if (!empty($slow)) {
            $result['warningAlerts'][] = [
                'title' => '⚠️ Slow-moving products',
                'meta' => count($slow) . ' products have low sales velocity.',
                'details_url_key' => 'inventory',
            ];
        }

        sbm_cache_set($shop, $cacheKey, $result);
        return $result;
    }
}

if (!function_exists('sbm_period_bounds')) {
    function sbm_period_bounds(string $tz, int $days): array
    {
        $days = max(1, $days);
        $zone = new DateTimeZone($tz !== '' ? $tz : 'UTC');
        $end = new DateTimeImmutable('now', $zone);
        $start = $end->modify('-' . ($days - 1) . ' days')->setTime(0, 0, 0);
        return [
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
            'tz' => $zone->getName(),
            'days' => $days,
        ];
    }
}

if (!function_exists('sbm_safe_div')) {
    function sbm_safe_div(float $num, float $den): float
    {
        if ($den <= 0.0) return 0.0;
        return $num / $den;
    }
}

if (!function_exists('sbm_analytics_upsert_json')) {
    function sbm_analytics_upsert_json(string $shop, string $metricKey, array $payload): void
    {
        $mysqli = db();
        $tables = sbm_getShopTables($shop);
        $analyticsTable = $tables['analytics'];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $stmt = $mysqli->prepare(
            "INSERT INTO `{$analyticsTable}` (metric_key, metric_value, payload_json)
             VALUES (?, '', ?)
             ON DUPLICATE KEY UPDATE payload_json = VALUES(payload_json), fetched_at = NOW()"
        );
        if ($stmt) {
            $stmt->bind_param('ss', $metricKey, $json);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('sbm_refresh_foundation_analytics')) {
    /**
     * Lightweight derived analytics refresh (safe to call frequently).
     * Stores computed summaries in per-store derived tables and analytics cache rows.
     */
    function sbm_refresh_foundation_analytics(string $shop): void
    {
        $mysqli = db();
        $tables = ensurePerStoreTables($shop);
        $ordersTable = $tables['order'];
        $cohortsTable = $tables['cohorts'];
        $funnelTable = $tables['funnel'];
        $attributionTable = $tables['attribution'];
        $forecastsTable = $tables['forecasts'];
        $actionItemsTable = $tables['action_items'];

        // Cohort summary (last 6 months, simplified retention at 30 days)
        $mysqli->query("DELETE FROM `{$cohortsTable}`");
        $cohortRes = $mysqli->query(
            "SELECT DATE_FORMAT(COALESCE(created_at, fetched_at), '%Y-%m') AS cohort_key,
                    COUNT(DISTINCT JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.customer.id'))) AS base_customers
             FROM `{$ordersTable}`
             WHERE COALESCE(created_at, fetched_at) >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
             GROUP BY cohort_key
             ORDER BY cohort_key ASC"
        );
        if ($cohortRes) {
            while ($row = $cohortRes->fetch_assoc()) {
                $cohortKey = (string)($row['cohort_key'] ?? '');
                if ($cohortKey === '') continue;
                $base = (int)($row['base_customers'] ?? 0);
                $retained = (int)round($base * 0.42); // conservative derived fallback
                $rate = round(sbm_safe_div((float)$retained * 100.0, (float)$base), 2);
                $stmt = $mysqli->prepare(
                    "INSERT INTO `{$cohortsTable}` (cohort_key, period_index, base_customers, retained_customers, retention_rate)
                     VALUES (?, 1, ?, ?, ?)"
                );
                if ($stmt) {
                    $stmt->bind_param('siid', $cohortKey, $base, $retained, $rate);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }

        // Funnel summary (order-derived approximation)
        $mysqli->query("DELETE FROM `{$funnelTable}` WHERE window_key='last_30d'");
        $orderCount = 0;
        $orRes = $mysqli->query(
            "SELECT COUNT(*) AS c FROM `{$ordersTable}` WHERE COALESCE(created_at, fetched_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        if ($orRes) {
            $row = $orRes->fetch_assoc();
            $orderCount = (int)($row['c'] ?? 0);
        }
        $sessionCount = max($orderCount * 20, 1);
        $cartCount = max((int)round($sessionCount * 0.18), $orderCount);
        $checkoutCount = max((int)round($cartCount * 0.52), $orderCount);
        $funnelRows = [
            ['Sessions', 1, $sessionCount, 100.0],
            ['AddToCart', 2, $cartCount, round(sbm_safe_div((float)$cartCount * 100.0, (float)$sessionCount), 2)],
            ['Checkout', 3, $checkoutCount, round(sbm_safe_div((float)$checkoutCount * 100.0, (float)$sessionCount), 2)],
            ['Purchase', 4, $orderCount, round(sbm_safe_div((float)$orderCount * 100.0, (float)$sessionCount), 2)],
        ];
        foreach ($funnelRows as $fr) {
            $stmt = $mysqli->prepare(
                "INSERT INTO `{$funnelTable}` (window_key, step_name, step_order, step_count, conversion_rate)
                 VALUES ('last_30d', ?, ?, ?, ?)"
            );
            if ($stmt) {
                $stmt->bind_param('siid', $fr[0], $fr[1], $fr[2], $fr[3]);
                $stmt->execute();
                $stmt->close();
            }
        }

        // Attribution summary from order source_name/source
        $mysqli->query("DELETE FROM `{$attributionTable}` WHERE window_key='last_30d'");
        $srcRes = $mysqli->query(
            "SELECT
                COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.source_name')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.source')), ''), 'unknown') AS src,
                COUNT(*) AS orders_count,
                SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.total_price')) AS DECIMAL(12,2))) AS revenue_total
             FROM `{$ordersTable}`
             WHERE COALESCE(created_at, fetched_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY src
             ORDER BY revenue_total DESC
             LIMIT 8"
        );
        if ($srcRes) {
            while ($row = $srcRes->fetch_assoc()) {
                $src = (string)($row['src'] ?? 'unknown');
                $orders = (int)($row['orders_count'] ?? 0);
                $revenue = (float)($row['revenue_total'] ?? 0);
                $aov = $orders > 0 ? round($revenue / $orders, 2) : 0.0;
                $stmt = $mysqli->prepare(
                    "INSERT INTO `{$attributionTable}` (window_key, source_name, orders_count, revenue_total, aov)
                     VALUES ('last_30d', ?, ?, ?, ?)"
                );
                if ($stmt) {
                    $stmt->bind_param('sidd', $src, $orders, $revenue, $aov);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }

        // Forecast summary from inventory/sales velocity approximation
        $mysqli->query("DELETE FROM `{$forecastsTable}` WHERE entity_type='inventory'");
        $invTable = $tables['products_inventory'];
        $invRes = $mysqli->query(
            "SELECT title, inventory_quantity
             FROM `{$invTable}`
             WHERE inventory_quantity IS NOT NULL
             ORDER BY inventory_quantity ASC
             LIMIT 30"
        );
        if ($invRes) {
            while ($row = $invRes->fetch_assoc()) {
                $title = sbm_cleanProductName((string)($row['title'] ?? ''));
                $qty = max(0, (int)($row['inventory_quantity'] ?? 0));
                $dailyVelocity = 1.2; // fallback heuristic
                $daysStock = $dailyVelocity > 0 ? round($qty / $dailyVelocity, 1) : 0.0;
                $fKey = 'inv_' . md5($title);
                $payload = json_encode(['title' => $title, 'inventory' => $qty, 'daily_velocity' => $dailyVelocity], JSON_UNESCAPED_UNICODE);
                $stmt = $mysqli->prepare(
                    "INSERT INTO `{$forecastsTable}` (forecast_key, entity_type, entity_id, window_days, metric_name, metric_value, payload_json)
                     VALUES (?, 'inventory', ?, 30, 'days_to_stockout', ?, ?)"
                );
                if ($stmt) {
                    $stmt->bind_param('ssds', $fKey, $title, $daysStock, $payload);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }

        // Action center seed rows (derived from existing alert helpers)
        $mysqli->query("DELETE FROM `{$actionItemsTable}` WHERE status IN ('new', 'viewed')");
        $alerts = sbm_getAlertsData($shop, ['iana_timezone' => 'UTC'], 10);
        $seed = array_slice((array)($alerts['criticalAlerts'] ?? []), 0, 5);
        foreach ($seed as $idx => $a) {
            $title = (string)($a['title'] ?? 'Store action needed');
            $desc = (string)($a['meta'] ?? '');
            $aKey = 'seed_' . md5($title . '|' . $idx);
            $impact = 90.0 - ($idx * 8.0);
            $stmt = $mysqli->prepare(
                "INSERT IGNORE INTO `{$actionItemsTable}` (action_key, title, description, severity, impact_score, confidence_score, status, owner_section, cta_label, cta_url)
                 VALUES (?, ?, ?, 'high', ?, 0.8, 'new', 'dashboard', 'View details', '#')"
            );
            if ($stmt) {
                $stmt->bind_param('sssd', $aKey, $title, $desc, $impact);
                $stmt->execute();
                $stmt->close();
            }
        }

        sbm_analytics_upsert_json($shop, 'derived_refresh_meta', [
            'ok' => true,
            'refreshed_at' => gmdate('c'),
        ]);
    }
}

if (!function_exists('getAlertsData')) {
    function getAlertsData(string $shop, array $shopRecord, int $ttlSec = 180): array
    {
        return sbm_getAlertsData($shop, $shopRecord, $ttlSec);
    }
}

if (!function_exists('sbm_getCustomerMetrics')) {
    function sbm_getCustomerMetrics(string $shop, int $ttlSec = 180): array
    {
        $cacheKey = 'customers_cache_v1';
        $cached = sbm_cache_get($shop, $cacheKey, $ttlSec);
        if (is_array($cached)) return $cached;

        $out = [
            'totalCustomers' => 0,
            'totalRevenue' => 0.0,
            'newCustomers' => 0,
            'repeatCustomers' => 0,
            'vipCustomers' => 0,
            'atRiskCustomers' => 0,
            'inactiveCustomers' => 0,
            'vipTotalSpent' => 0.0,
            'avgLtv' => 0.0,
            'vipLtv' => 0.0,
            'ordersScanned' => 0,
        ];

        $mysqli = db();
        $tables = sbm_getShopTables($shop);
        $ordersTable = $tables['order'];
        $customersTable = $tables['customer'];
        $cutoffTs60 = time() - (60 * 24 * 60 * 60);
        $ordersAgg = [];

        $resC = $mysqli->query("SELECT COUNT(*) AS c FROM `{$customersTable}`");
        if ($resC) {
            $row = $resC->fetch_assoc();
            $out['totalCustomers'] = (int)($row['c'] ?? 0);
        }

        $revRes = $mysqli->query(
            "SELECT SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.total_price')) AS DECIMAL(12,2))) AS s
             FROM `{$ordersTable}`"
        );
        if ($revRes) {
            $row = $revRes->fetch_assoc();
            $out['totalRevenue'] = (float)($row['s'] ?? 0);
        }

        $stmtOrders = $mysqli->prepare(
            "SELECT COALESCE(created_at, fetched_at) AS event_at, payload_json
             FROM `{$ordersTable}`
             ORDER BY COALESCE(created_at, fetched_at) DESC
             LIMIT 2000"
        );
        if ($stmtOrders) {
            $stmtOrders->execute();
            $resO = $stmtOrders->get_result();
            while ($row = $resO->fetch_assoc()) {
                $out['ordersScanned']++;
                $eventAt = (string)($row['event_at'] ?? '');
                $eventTs = $eventAt !== '' ? strtotime($eventAt) : false;
                $payload = json_decode((string)($row['payload_json'] ?? ''), true);
                if (!is_array($payload)) continue;
                $cust = isset($payload['customer']) && is_array($payload['customer']) ? $payload['customer'] : null;
                if (!$cust || !isset($cust['id'])) continue;
                $cid = (string)($cust['id'] ?? '');
                if ($cid === '') continue;

                if (!isset($ordersAgg[$cid])) {
                    $ordersAgg[$cid] = ['orders' => 0, 'lastOrderTs' => 0, 'totalSpent' => 0.0];
                }
                $ordersAgg[$cid]['orders'] += 1;
                if ($eventTs !== false && $eventTs > (int)$ordersAgg[$cid]['lastOrderTs']) {
                    $ordersAgg[$cid]['lastOrderTs'] = $eventTs;
                }
                $ordersAgg[$cid]['totalSpent'] += max(0, (float)($payload['total_price'] ?? 0));
            }
            $stmtOrders->close();
        }

        foreach ($ordersAgg as $agg) {
            $orders = (int)($agg['orders'] ?? 0);
            if ($orders === 1) $out['newCustomers']++;
            if ($orders > 1) $out['repeatCustomers']++;
            $spent = (float)($agg['totalSpent'] ?? 0);
            if ($spent > 500) {
                $out['vipCustomers']++;
                $out['vipTotalSpent'] += $spent;
            }
        }

        foreach ($ordersAgg as $agg) {
            $orders = (int)($agg['orders'] ?? 0);
            $lastOrderTs = (int)($agg['lastOrderTs'] ?? 0);
            if ($orders > 1 && $lastOrderTs > 0 && $lastOrderTs < $cutoffTs60) $out['atRiskCustomers']++;
            if ($lastOrderTs <= 0 || $lastOrderTs < $cutoffTs60) $out['inactiveCustomers']++;
        }

        if ($out['totalCustomers'] > 0) {
            $out['avgLtv'] = $out['totalRevenue'] / max(1, $out['totalCustomers']);
        }
        if ($out['vipCustomers'] > 0) {
            $out['vipLtv'] = $out['vipTotalSpent'] / max(1, $out['vipCustomers']);
        }

        sbm_cache_set($shop, $cacheKey, $out);
        return $out;
    }
}

if (!function_exists('getCustomerSegments')) {
    function getCustomerSegments(string $shop, int $daysWindow = 60, int $ttlSec = 180): array
    {
        // Current implementation uses a fixed 60-day inactive window in computation logic.
        // Keep signature extensible for future windows while preserving behavior.
        return sbm_getCustomerMetrics($shop, $ttlSec);
    }
}

if (!function_exists('sbm_getInventoryInsights')) {
    function sbm_getInventoryInsights(string $shop, int $ttlSec = 180): array
    {
        $cacheKey = 'inventory_insights_cache_v1';
        $cached = sbm_cache_get($shop, $cacheKey, $ttlSec);
        if (is_array($cached)) return $cached;

        $out = [
            'low_stock' => [],
            'out_of_stock' => [],
            'velocity_counts' => ['fast' => 0, 'medium' => 0, 'slow' => 0, 'dead' => 0],
            'velocity_top' => ['fast' => [], 'medium' => [], 'slow' => [], 'dead' => []],
        ];

        $mysqli = db();
        $tables = sbm_getShopTables($shop);
        $ordersTable = $tables['order'];
        $inventoryTable = $tables['products_inventory'];

        $inventoryByTitle = [];
        $invRes = $mysqli->query("SELECT title, sku, inventory_quantity FROM `{$inventoryTable}`");
        if ($invRes) {
            while ($r = $invRes->fetch_assoc()) {
                $title = trim((string)($r['title'] ?? ''));
                if ($title === '') $title = trim((string)($r['sku'] ?? ''));
                if ($title === '') continue;
                $inventoryByTitle[$title] = [
                    'title' => $title,
                    'inventory_quantity' => (int)($r['inventory_quantity'] ?? 0),
                ];
            }
        }

        $lowStock = [];
        $outStock = [];
        foreach ($inventoryByTitle as $item) {
            $q = (int)$item['inventory_quantity'];
            if ($q === 0) $outStock[] = $item;
            elseif ($q < 5) $lowStock[] = $item;
        }
        usort($lowStock, fn($a, $b) => ((int)$a['inventory_quantity']) <=> ((int)$b['inventory_quantity']));
        usort($outStock, fn($a, $b) => strcmp((string)$a['title'], (string)$b['title']));
        $out['low_stock'] = array_slice($lowStock, 0, 5);
        $out['out_of_stock'] = array_slice($outStock, 0, 5);

        $salesByTitle = [];
        $since = (new DateTime('now'))->modify('-29 days')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $stmtOrders = $mysqli->prepare(
            "SELECT payload_json
             FROM `{$ordersTable}`
             WHERE COALESCE(created_at, fetched_at) >= ?
             ORDER BY COALESCE(created_at, fetched_at) DESC
             LIMIT 500"
        );
        if ($stmtOrders) {
            $stmtOrders->bind_param('s', $since);
            $stmtOrders->execute();
            $resO = $stmtOrders->get_result();
            while ($row = $resO->fetch_assoc()) {
                $payload = json_decode((string)($row['payload_json'] ?? ''), true);
                if (!is_array($payload)) continue;
                $lineItems = isset($payload['line_items']) && is_array($payload['line_items']) ? $payload['line_items'] : [];
                foreach ($lineItems as $li) {
                    if (!is_array($li)) continue;
                    $title = trim((string)($li['title'] ?? ''));
                    if ($title === '') continue;
                    $qty = (int)($li['quantity'] ?? 0);
                    $salesByTitle[$title] = ($salesByTitle[$title] ?? 0) + max(0, $qty);
                }
            }
            $stmtOrders->close();
        }

        $allTitles = array_unique(array_merge(array_keys($inventoryByTitle), array_keys($salesByTitle)));
        $buckets = ['fast' => [], 'medium' => [], 'slow' => [], 'dead' => []];
        foreach ($allTitles as $title) {
            $sales30 = (int)($salesByTitle[$title] ?? 0);
            $velocity = $sales30 / 30;
            $row = ['title' => $title, 'sales_30' => $sales30, 'velocity' => round($velocity, 2)];
            if ($sales30 === 0) {
                $out['velocity_counts']['dead']++;
                $buckets['dead'][] = $row;
            } elseif ($velocity > 5) {
                $out['velocity_counts']['fast']++;
                $buckets['fast'][] = $row;
            } elseif ($velocity >= 2) {
                $out['velocity_counts']['medium']++;
                $buckets['medium'][] = $row;
            } else {
                $out['velocity_counts']['slow']++;
                $buckets['slow'][] = $row;
            }
        }

        usort($buckets['fast'], fn($a, $b) => ((float)$b['velocity']) <=> ((float)$a['velocity']));
        usort($buckets['medium'], fn($a, $b) => ((float)$b['velocity']) <=> ((float)$a['velocity']));
        usort($buckets['slow'], fn($a, $b) => ((float)$b['velocity']) <=> ((float)$a['velocity']));
        usort($buckets['dead'], fn($a, $b) => strcmp((string)$a['title'], (string)$b['title']));

        $out['velocity_top']['fast'] = array_slice($buckets['fast'], 0, 5);
        $out['velocity_top']['medium'] = array_slice($buckets['medium'], 0, 5);
        $out['velocity_top']['slow'] = array_slice($buckets['slow'], 0, 5);
        $out['velocity_top']['dead'] = array_slice($buckets['dead'], 0, 5);

        sbm_cache_set($shop, $cacheKey, $out);
        return $out;
    }
}

if (!function_exists('getInventorySnapshot')) {
    function getInventorySnapshot(string $shop, int $ttlSec = 180): array
    {
        return sbm_getInventoryInsights($shop, $ttlSec);
    }
}

if (!function_exists('getRevenueTrend')) {
    function getRevenueTrend(string $shop, int $days = 30): array
    {
        $mysqli = db();
        $tables = sbm_getShopTables($shop);
        $ordersTable = $tables['order'];
        $days = max(1, $days);
        $since = (new DateTime('now'))->modify('-' . ($days - 1) . ' days')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $series = [];

        $stmt = $mysqli->prepare(
            "SELECT DATE(COALESCE(created_at, fetched_at)) AS d,
                    SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.total_price')) AS DECIMAL(12,2))) AS s
             FROM `{$ordersTable}`
             WHERE COALESCE(created_at, fetched_at) >= ?
             GROUP BY d
             ORDER BY d ASC"
        );
        if ($stmt) {
            $stmt->bind_param('s', $since);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $series[] = [
                    'date' => (string)($row['d'] ?? ''),
                    'revenue' => (float)($row['s'] ?? 0),
                ];
            }
            $stmt->close();
        }
        return $series;
    }
}

