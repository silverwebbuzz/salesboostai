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

function formatInt($n): string {
    return number_format((int)$n);
}

$shopName = (string)($shopRecord['store_name'] ?? $shop);
$ordersTable = perStoreTableName(makeShopName($shop), 'order');
$customersTable = perStoreTableName(makeShopName($shop), 'customer');

$totalCustomers = 0;
$totalRevenue = 0.0;

$newCustomers = 0;
$repeatCustomers = 0;
$vipCustomers = 0;
$atRiskCustomers = 0;
$inactiveCustomers = 0;

$vipTotalSpent = 0.0;
$avgLtv = 0.0;
$vipLtv = 0.0;

$cutoffTs60 = time() - (60 * 24 * 60 * 60);

$ordersAgg = [];
$orderedLast60Set = [];

try {
    $mysqli = db();

    $resC = $mysqli->query("SELECT COUNT(*) AS c FROM `{$customersTable}`");
    if ($resC) {
        $row = $resC->fetch_assoc();
        $totalCustomers = (int)($row['c'] ?? 0);
    }

    // Total revenue (all-time in local DB)
    $revRes = $mysqli->query(
        "SELECT SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.total_price')) AS DECIMAL(12,2))) AS s
         FROM `{$ordersTable}`"
    );
    if ($revRes) {
        $row = $revRes->fetch_assoc();
        $totalRevenue = (float)($row['s'] ?? 0);
    }

    // Build per-customer aggregates from a bounded scan for speed.
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
            $eventAt = (string)($row['event_at'] ?? '');
            $eventTs = $eventAt !== '' ? strtotime($eventAt) : false;
            $payload = json_decode((string)($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) continue;

            $cust = isset($payload['customer']) && is_array($payload['customer']) ? $payload['customer'] : null;
            if (!$cust || !isset($cust['id'])) continue;

            $cid = (string)($cust['id'] ?? '');
            if ($cid === '') continue;

            if (!isset($ordersAgg[$cid])) {
                $ordersAgg[$cid] = [
                    'orders' => 0,
                    'lastOrderTs' => 0,
                    'totalSpent' => 0.0,
                ];
            }

            $ordersAgg[$cid]['orders'] += 1;

            if ($eventTs !== false && $eventTs > (int)($ordersAgg[$cid]['lastOrderTs'] ?? 0)) {
                $ordersAgg[$cid]['lastOrderTs'] = $eventTs;
            }

            $totalPrice = isset($payload['total_price']) ? (float)$payload['total_price'] : 0.0;
            $ordersAgg[$cid]['totalSpent'] += max(0, $totalPrice);
        }
        $stmtOrders->close();
    }

    // New/Repeat/VIP counts
    foreach ($ordersAgg as $cid => $agg) {
        $orders = (int)($agg['orders'] ?? 0);
        if ($orders === 1) $newCustomers++;
        if ($orders > 1) $repeatCustomers++;

        $spent = (float)($agg['totalSpent'] ?? 0);
        if ($spent > 500) {
            $vipCustomers++;
            $vipTotalSpent += $spent;
        }

        $lastOrderTs = (int)($agg['lastOrderTs'] ?? 0);
        if ($lastOrderTs >= $cutoffTs60) {
            $orderedLast60Set[$cid] = true;
        }
    }

    $orderedLast60Count = count($orderedLast60Set);
    $atRiskCustomers = max(0, $totalCustomers - $orderedLast60Count);
    $inactiveCustomers = $atRiskCustomers;

    // LTV
    if ($totalCustomers > 0) {
        $avgLtv = $totalRevenue / max(1, $totalCustomers);
    }
    if ($vipCustomers > 0) {
        $vipLtv = $vipTotalSpent / max(1, $vipCustomers);
    }
} catch (Throwable $e) {
    // Keep page renderable even if DB errors happen.
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Customers</title>
  <link rel="stylesheet" href="<?php echo e(BASE_URL); ?>/assets/styles.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/styles.css'); ?>">
</head>
<body>
  <main class="container">
    <?php include __DIR__ . '/nav.php'; ?>

    <div class="hero">
      <div class="hero-head">
        <div>
          <div class="hero-title">Customers</div>
          <div class="hero-subtitle">Understand your customers and improve retention</div>
        </div>
      </div>
      <div class="hero-subtitle"><?php echo e($shopName); ?></div>
    </div>

    <div class="section">
      <div class="section-title">Customer Overview</div>
      <div class="customers-overview-grid">
        <div class="card">
          <div class="kpi-title">Total customers</div>
          <div class="kpi-value"><span class="highlight-number"><?php echo e(formatInt($totalCustomers)); ?></span></div>
        </div>
        <div class="card">
          <div class="kpi-title">New customers</div>
          <div class="kpi-value"><span class="highlight-number"><?php echo e(formatInt($newCustomers)); ?></span></div>
        </div>
        <div class="card">
          <div class="kpi-title">Repeat customers</div>
          <div class="kpi-value"><span class="highlight-number"><?php echo e(formatInt($repeatCustomers)); ?></span></div>
        </div>
      </div>
    </div>

    <div class="section">
      <div class="section-title">Segmentation</div>
      <div class="customers-seg-grid">
        <div class="card">
          <div class="kpi-title">New</div>
          <div class="kpi-value"><span class="highlight-number"><?php echo e(formatInt($newCustomers)); ?></span></div>
        </div>
        <div class="card">
          <div class="kpi-title">Repeat</div>
          <div class="kpi-value"><span class="highlight-number"><?php echo e(formatInt($repeatCustomers)); ?></span></div>
        </div>
        <div class="card">
          <div class="kpi-title">VIP</div>
          <div class="kpi-value"><span class="highlight-number"><?php echo e(formatInt($vipCustomers)); ?></span></div>
        </div>
        <div class="card">
          <div class="kpi-title">At Risk</div>
          <div class="kpi-value"><span class="highlight-number"><?php echo e(formatInt($atRiskCustomers)); ?></span></div>
        </div>
      </div>
    </div>

    <div class="section grid-50-50">
      <div class="card">
        <div class="section-title" style="margin-bottom:8px;">LTV</div>
        <div class="kpi-title">Average LTV</div>
        <div class="kpi-value"><span class="highlight-number"><?php echo e($totalCustomers > 0 ? money($avgLtv) : '—'); ?></span></div>
        <div style="height:10px;"></div>
        <div class="kpi-title">VIP LTV</div>
        <div class="kpi-value"><span class="highlight-number"><?php echo e($vipCustomers > 0 ? money($vipLtv) : '—'); ?></span></div>
      </div>
      <div class="card">
        <div class="section-title" style="margin-bottom:8px;">Churn</div>
        <div class="kpi-title">Inactive customers</div>
        <div class="kpi-value"><span class="highlight-number"><?php echo e(formatInt($inactiveCustomers)); ?></span></div>
        <div class="hero-subtitle" style="margin-top:8px;">No orders in last 60 days</div>
      </div>
    </div>
  </main>

  <script>
    // No JS required for now.
  </script>
</body>
</html>
