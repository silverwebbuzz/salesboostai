<?php
/**
 * SalesBoost dashboard (embedded app surface).
 */
require_once __DIR__ . '/config.php';

sendEmbeddedAppHeaders();

$rawShop = $_GET['shop'] ?? null;
$shop    = sanitizeShopDomain($rawShop);
$host    = $_GET['host'] ?? '';

if ($shop === null) {
    http_response_code(400);
    echo 'Missing or invalid shop parameter.';
    exit;
}

$qs = $_SERVER['QUERY_STRING'] ?? '';
if (!headers_sent() && (($_GET['view'] ?? '') !== 'legacy')) {
    header('Location: ' . BASE_URL . '/dashboard' . ($qs ? ('?' . $qs) : ''));
    exit;
}

$shopRecord = getShopByDomain($shop);

if (!$shopRecord) {
    header('Location: ' . BASE_URL . '/auth/install?shop=' . urlencode($shop) . ($host ? '&host=' . urlencode($host) : ''));
    exit;
}

$accessToken = $shopRecord['access_token'];
$totalOrders = getOrders($shop, $accessToken);
$totalProducts = getProducts($shop, $accessToken);
$totalCustomers = getCustomers($shop, $accessToken);

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SalesBoost AI</title>
  <link rel="stylesheet" href="https://unpkg.com/@shopify/polaris@12/build/esm/styles.css">
  <style>
    body { margin: 0; background: #f6f6f7; }
    .Page { max-width: 1040px; margin: 0 auto; padding: 20px 20px 60px; }
    .Muted { color: var(--p-color-text-secondary, #616a75); }
    .KpiRow { display:flex; gap: 12px; flex-wrap: wrap; }
    .Kpi { min-width: 180px; flex: 1 1 180px; }
    .KpiValue { font-size: 28px; font-weight: 650; line-height: 1.15; }
    .KpiLabel { font-size: 13px; }
  </style>
</head>
<body>
  <div class="Page">
    <div class="Polaris-Text--root Polaris-Text--headingLg">SalesBoost AI</div>
    <div class="Polaris-Text--root Polaris-Text--bodyMd Muted" style="margin-top:6px;">
      Store: <?php echo e($shopRecord['store_name'] ?: $shop); ?> — Owner: <?php echo e($shopRecord['shop_owner'] ?: '—'); ?>
    </div>

    <div class="Polaris-Card" style="margin-top:16px;">
      <div class="Polaris-Card__Section">
        <div class="Polaris-Text--root Polaris-Text--headingMd" style="margin-bottom:10px;">Key metrics</div>
        <div class="KpiRow">
          <div class="Kpi">
            <div class="KpiValue"><?php echo $totalOrders !== null ? (int)$totalOrders : '—'; ?></div>
            <div class="KpiLabel Muted">Orders</div>
          </div>
          <div class="Kpi">
            <div class="KpiValue"><?php echo $totalProducts !== null ? (int)$totalProducts : '—'; ?></div>
            <div class="KpiLabel Muted">Products</div>
          </div>
          <div class="Kpi">
            <div class="KpiValue"><?php echo $totalCustomers !== null ? (int)$totalCustomers : '—'; ?></div>
            <div class="KpiLabel Muted">Customers</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>