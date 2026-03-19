<?php
require_once __DIR__ . '/config.php';

sendEmbeddedAppHeaders();

$shop = sanitizeShopDomain($_GET['shop'] ?? null);
$host = $_GET['host'] ?? '';

if ($shop === null) {
    http_response_code(400);
    echo 'Missing or invalid shop parameter.';
    exit;
}

$shopRecord = getShopByDomain($shop);
if (!$shopRecord) {
    header('Location: ' . BASE_URL . '/auth/install?shop=' . urlencode($shop) . ($host ? '&host=' . urlencode($host) : ''));
    exit;
}

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

function money(float $v): string {
    return '$' . number_format($v, 2);
}

function salesVelocity(int $sales30): float {
    return $sales30 / 30.0;
}

function cleanProductName(string $name): string {
    $v = trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n", "\t"], ' ', $name)));
    return $v !== '' ? $v : 'Unnamed product';
}

$criticalAlerts = [];
$warningAlerts = [];
$infoAlerts = [];
$errorText = '';
$inventoryAgentId = 0;

try {
    $mysqli = db();
    $shopName = makeShopName($shop);
    $ordersTable = perStoreTableName($shopName, 'order');
    $inventoryTable = perStoreTableName($shopName, 'products_inventory');
    $agentRes = $mysqli->query("SELECT id FROM ai_agents WHERE agent_key = 'inventory' LIMIT 1");
    if ($agentRes) {
        $ar = $agentRes->fetch_assoc();
        $inventoryAgentId = (int)($ar['id'] ?? 0);
    }

    $tz = (string)($shopRecord['iana_timezone'] ?? 'UTC');
    if ($tz === '') $tz = 'UTC';
    $now = new DateTimeImmutable('now', new DateTimeZone($tz));

    // --- Revenue Drop Alert: last 7 vs previous 7 ---
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
        $res = $stmtCur->get_result();
        $row = $res ? ($res->fetch_assoc() ?: null) : null;
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
        $res = $stmtPrev->get_result();
        $row = $res ? ($res->fetch_assoc() ?: null) : null;
        $prevTotal = (float)($row['s'] ?? 0);
        $stmtPrev->close();
    }

    if ($prevTotal > 0) {
        $dropPct = (($prevTotal - $curTotal) / $prevTotal) * 100;
        if ($dropPct >= 20) {
            $criticalAlerts[] = [
                'title' => '🚨 Revenue dropped by ' . round($dropPct) . '% this week',
                'meta' => 'Previous 7 days: ' . money($prevTotal) . ' · Last 7 days: ' . money($curTotal),
            ];
        }
    }

    // --- Inventory snapshot ---
    $inventory = [];
    $invRes = $mysqli->query("SELECT title, sku, inventory_quantity FROM `{$inventoryTable}` WHERE inventory_quantity IS NOT NULL");
    if ($invRes) {
        while ($r = $invRes->fetch_assoc()) {
            $title = cleanProductName((string)($r['title'] ?? ''));
            if ($title === 'Unnamed product') {
                $title = cleanProductName((string)($r['sku'] ?? ''));
            }
            if ($title === 'Unnamed product') continue;
            $inventory[$title] = (int)($r['inventory_quantity'] ?? 0);
        }
    }

    // --- Sales by product for last 30d + last 7d ---
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
                $title = cleanProductName((string)($li['title'] ?? ''));
                if ($title === 'Unnamed product') continue;
                $qty = (int)($li['quantity'] ?? 0);
                if (!isset($sales30[$title])) $sales30[$title] = 0;
                $sales30[$title] += max(0, $qty);
                if ($eventTs !== false && $eventTs >= $since7Ts) {
                    if (!isset($sales7[$title])) $sales7[$title] = 0;
                    $sales7[$title] += max(0, $qty);
                }
            }
        }
        $stmtOrders->close();
    }

    // Critical: Bestseller running low on stock.
    $topSelling = $sales30;
    arsort($topSelling);
    $topSelling = array_slice($topSelling, 0, 5, true);
    foreach ($topSelling as $title => $qty) {
        $invQty = isset($inventory[$title]) ? (int)$inventory[$title] : 0;
        if ($invQty < 5) {
            $criticalAlerts[] = [
                'title' => '🚨 Bestseller running low on stock',
                'meta' => cleanProductName((string)$title) . ' has only ' . $invQty . ' units left. Restock soon to avoid lost sales.',
                'type' => 'inventory',
            ];
            break; // single concise alert
        }
    }

    // Warning: product stopped selling.
    $stopped = [];
    foreach ($sales30 as $title => $qty30) {
        $qty7 = (int)($sales7[$title] ?? 0);
        if ($qty30 > 0 && $qty7 === 0) {
            $stopped[] = ['title' => $title, 'qty30' => (int)$qty30];
        }
    }
    if (!empty($stopped)) {
        usort($stopped, fn($a, $b) => ((int)$b['qty30']) <=> ((int)$a['qty30']));
        $topStopped = array_slice($stopped, 0, 3);
        $warningAlerts[] = [
            'title' => '⚠️ ' . count($stopped) . ' products stopped selling',
            'list' => array_map(fn($x) => (string)$x['title'], $topStopped),
            'type' => 'inventory',
        ];
    }

    // Warning: low stock products list (<5, >0)
    $lowStock = [];
    foreach ($inventory as $title => $qty) {
        if ($qty > 0 && $qty < 5) {
            $lowStock[] = ['title' => $title, 'qty' => $qty];
        }
    }
    if (!empty($lowStock)) {
        usort($lowStock, fn($a, $b) => ((int)$a['qty']) <=> ((int)$b['qty']));
        $warningAlerts[] = [
            'title' => '⚠️ Low stock products',
            'list' => array_map(fn($x) => cleanProductName((string)$x['title']) . ' - only ' . (int)$x['qty'] . ' left', array_slice($lowStock, 0, 5)),
            'type' => 'inventory',
        ];
    }

    // Warnings: dead stock (in stock, no sales in 30d)
    $dead = [];
    foreach ($inventory as $title => $qty) {
        if ($qty > 0 && ((int)($sales30[$title] ?? 0) === 0)) {
            $dead[] = $title;
        }
    }
    if (!empty($dead)) {
        $warningAlerts[] = [
            'title' => '⚠️ Dead stock identified',
            'meta' => count($dead) . ' products have stock but no sales in 30 days',
            'type' => 'inventory',
        ];
    }

    // Warnings: slow products (velocity < 2/day and >0 sales)
    $slow = [];
    foreach ($sales30 as $title => $qty30) {
        $v = salesVelocity((int)$qty30);
        if ($qty30 > 0 && $v < 2) {
            $slow[] = ['title' => $title, 'v' => $v];
        }
    }
    if (!empty($slow)) {
        usort($slow, fn($a, $b) => ((float)$a['v']) <=> ((float)$b['v']));
        $warningAlerts[] = [
            'title' => '⚠️ Slow-moving products',
            'meta' => count($slow) . ' products have low sales velocity',
            'type' => 'inventory',
        ];
    }
} catch (Throwable $e) {
    $errorText = 'Unable to load alerts right now.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Alerts</title>
  <link rel="stylesheet" href="<?php echo e(BASE_URL); ?>/assets/styles.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/styles.css'); ?>">
</head>
<body>
  <main class="container">
    <?php include __DIR__ . '/nav.php'; ?>

    <div class="hero">
      <div class="hero-head">
        <div>
          <div class="hero-title">Alerts</div>
          <div class="hero-subtitle">Important issues and opportunities in your store</div>
        </div>
      </div>
    </div>

    <?php if ($errorText !== ''): ?>
      <div class="section">
        <div class="card" style="border:1px solid #fecaca;background:#fef2f2;">
          <strong><?php echo e($errorText); ?></strong>
        </div>
      </div>
    <?php endif; ?>

    <?php
      $inventoryDetailsUrl = $inventoryAgentId > 0
        ? (BASE_URL . '/agent-report.php?agent_id=' . $inventoryAgentId . '&shop=' . urlencode($shop) . ($host !== '' ? '&host=' . urlencode($host) : ''))
        : '#';
    ?>

    <?php if (!empty($criticalAlerts)): ?>
      <div class="section">
        <div class="section-title">🔴 Critical Alerts</div>
        <div class="hero-subtitle" style="margin-bottom:10px;">Serious issues. Immediate action needed.</div>
        <div class="alerts-grid">
          <?php foreach ($criticalAlerts as $alert): ?>
            <div class="card alert-card alert-card-critical">
              <div class="alert-title"><?php echo e((string)($alert['title'] ?? 'Alert')); ?></div>
              <?php if (!empty($alert['meta'])): ?><div class="alert-meta"><?php echo e((string)$alert['meta']); ?></div><?php endif; ?>
              <div style="margin-top:12px;">
                <a class="btn btn-primary" href="<?php echo e($inventoryDetailsUrl); ?>">View Details</a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($warningAlerts)): ?>
      <div class="section">
        <div class="section-title">🟡 Warnings</div>
        <div class="hero-subtitle" style="margin-bottom:10px;">Needs attention. Not urgent.</div>
        <div class="alerts-grid">
          <?php foreach ($warningAlerts as $alert): ?>
            <div class="card alert-card alert-card-warning">
              <div class="alert-title"><?php echo e((string)($alert['title'] ?? 'Warning')); ?></div>
              <?php if (!empty($alert['meta'])): ?><div class="alert-meta"><?php echo e((string)$alert['meta']); ?></div><?php endif; ?>
              <?php if (!empty($alert['list']) && is_array($alert['list'])): ?>
                <ul class="report-list" style="margin-top:8px;">
                  <?php foreach ($alert['list'] as $item): ?>
                    <li><?php echo e((string)$item); ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
              <div style="margin-top:12px;">
                <a class="btn btn-primary" href="<?php echo e($inventoryDetailsUrl); ?>">View Details</a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($infoAlerts)): ?>
      <div class="section">
        <div class="section-title">🔵 Info</div>
        <div class="alerts-grid">
          <?php foreach ($infoAlerts as $alert): ?>
            <div class="card alert-card alert-card-info">
              <div class="alert-title"><?php echo e((string)($alert['title'] ?? 'Insight')); ?></div>
              <?php if (!empty($alert['meta'])): ?><div class="alert-meta"><?php echo e((string)$alert['meta']); ?></div><?php endif; ?>
              <div style="margin-top:12px;">
                <a class="btn btn-primary" href="<?php echo e($inventoryDetailsUrl); ?>">View Details</a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (empty($criticalAlerts) && empty($warningAlerts) && empty($infoAlerts) && $errorText === ''): ?>
      <div class="section">
        <div class="card">
          <div class="sb-muted">✅ No critical issues detected</div>
        </div>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>
