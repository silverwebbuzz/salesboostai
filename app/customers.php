<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/metrics.php';

require_once __DIR__ . '/lib/embedded_bootstrap.php';
require_once __DIR__ . '/lib/ui.php';
[$shop, $host, $shopRecord, $entitlements] = sbm_bootstrap_embedded(['includeEntitlements' => true]);

// Customers is merged into Analytics → Customers tab.
// Keep this page as a backward-compatible entry point.
$analyticsCustomersUrl = BASE_URL . '/analytics.php?tab=customers&shop=' . urlencode($shop) . ($host !== '' ? ('&host=' . urlencode($host)) : '');
header('Location: ' . $analyticsCustomersUrl, true, 302);
exit;

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

function money(float $v): string {
    return '$' . number_format($v, 2);
}

function formatInt($n): string {
    return number_format((int)$n);
}

function pct(int $count, int $total): string {
    if ($total <= 0) return '0.00%';
    return number_format(($count / max(1, $total)) * 100, 2) . '%';
}

$shopName = (string)($shopRecord['store_name'] ?? $shop);

$totalCustomers = 0;
$totalRevenue = 0.0;

$newCustomers = 0;
$repeatCustomers = 0;
$vipCustomers = 0;
$atRiskCustomers = 0;
$inactiveCustomers = 0;
$ordersScanned = 0;
$retentionRows = [];

$avgLtv = 0.0;
$vipLtv = 0.0;

try {
    $metrics = sbm_getCustomerMetrics($shop, 180);
    $totalCustomers = (int)($metrics['totalCustomers'] ?? 0);
    $totalRevenue = (float)($metrics['totalRevenue'] ?? 0);
    $newCustomers = (int)($metrics['newCustomers'] ?? 0);
    $repeatCustomers = (int)($metrics['repeatCustomers'] ?? 0);
    $vipCustomers = (int)($metrics['vipCustomers'] ?? 0);
    $atRiskCustomers = (int)($metrics['atRiskCustomers'] ?? 0);
    $inactiveCustomers = (int)($metrics['inactiveCustomers'] ?? 0);
    $avgLtv = (float)($metrics['avgLtv'] ?? 0);
    $vipLtv = (float)($metrics['vipLtv'] ?? 0);
    $ordersScanned = (int)($metrics['ordersScanned'] ?? 0);
} catch (Throwable $e) {
    // Keep page renderable even if DB errors happen.
}

try {
    $retentionRows = function_exists('sbm_get_retention_cohort_rows')
        ? sbm_get_retention_cohort_rows($shop, 6)
        : [];
} catch (Throwable $e) {
    $retentionRows = [];
}

$repeatRate = $totalCustomers > 0 ? ($repeatCustomers / max(1, $totalCustomers)) : 0.0;
$inactiveRate = $totalCustomers > 0 ? ($inactiveCustomers / max(1, $totalCustomers)) : 0.0;
$showLimitedDataNote = ($ordersScanned < 100 || $totalCustomers < 20);
$features = is_array($entitlements['features'] ?? null) ? $entitlements['features'] : [];
$lockCustomersLtv = !((bool)($features['customers_ltv'] ?? false));
$customersLtvRequiredPlan = function_exists('getFeatureRequiredPlan') ? getFeatureRequiredPlan('customers_ltv') : 'starter';
$customersLtvUpgradeUrl = sbm_upgrade_url($shop, $host, $customersLtvRequiredPlan);
$reportsUrl = BASE_URL . '/reports.php?shop=' . urlencode($shop) . ($host !== '' ? ('&host=' . urlencode($host)) : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php include __DIR__ . '/partials/app_bridge_first.php'; ?>
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
          <div class="hero-subtitle" style="margin-top:6px;">Based on recent order activity</div>
        </div>
      </div>
      <div class="hero-subtitle"><?php echo e($shopName); ?></div>
    </div>

    <?php if ($showLimitedDataNote): ?>
      <div class="section" style="margin-top:-8px;">
        <div class="card" style="border:1px solid #e5e7eb;background:#f8fafc;">
          <div class="hero-subtitle" style="margin:0;">Data may be limited for this store</div>
        </div>
      </div>
    <?php endif; ?>

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
          <div class="hero-subtitle" style="margin-top:6px;"><?php echo e(pct($newCustomers, $totalCustomers)); ?> of customers</div>
        </div>
        <div class="card">
          <div class="kpi-title">Repeat</div>
          <div class="kpi-value"><span class="highlight-number"><?php echo e(formatInt($repeatCustomers)); ?></span></div>
          <div class="hero-subtitle" style="margin-top:6px;"><?php echo e(pct($repeatCustomers, $totalCustomers)); ?> of customers</div>
        </div>
        <div class="card">
          <div class="kpi-title">VIP</div>
          <div class="kpi-value"><span class="highlight-number"><?php echo e(formatInt($vipCustomers)); ?></span></div>
          <div class="hero-subtitle" style="margin-top:6px;"><?php echo e(pct($vipCustomers, $totalCustomers)); ?> of customers</div>
        </div>
        <div class="card">
          <div class="kpi-title">At Risk</div>
          <div class="kpi-value"><span class="highlight-number"><?php echo e(formatInt($atRiskCustomers)); ?></span></div>
          <div class="hero-subtitle" style="margin-top:6px;"><?php echo e(pct($atRiskCustomers, $totalCustomers)); ?> of customers</div>
        </div>
      </div>
      <?php if ($repeatRate < 0.30 && $totalCustomers > 0): ?>
        <div class="hero-subtitle" style="margin-top:10px;color:#b45309;">⚠️ Low repeat rate</div>
      <?php endif; ?>
    </div>

    <div class="section grid-50-50 feature-lock-card">
      <div class="<?php echo $lockCustomersLtv ? 'feature-lock-blur' : ''; ?>">
      <div class="card">
        <div class="section-title" style="margin-bottom:8px;">LTV</div>
        <div class="kpi-title">Average LTV (all customers)</div>
        <div class="kpi-value"><span class="highlight-number"><?php echo e($totalCustomers > 0 ? money($avgLtv) : '—'); ?></span></div>
        <div style="height:10px;"></div>
        <div class="kpi-title">VIP LTV (high-value customers)</div>
        <div class="kpi-value"><span class="highlight-number"><?php echo e($vipCustomers > 0 ? money($vipLtv) : '—'); ?></span></div>
      </div>
      <div class="card">
        <div class="section-title" style="margin-bottom:8px;">Churn</div>
        <div class="kpi-title">Inactive customers</div>
        <div class="kpi-value"><span class="highlight-number"><?php echo e(formatInt($inactiveCustomers)); ?></span></div>
        <div class="hero-subtitle" style="margin-top:8px;">No orders in last 60 days</div>
        <?php if ($inactiveRate >= 0.30 && $inactiveCustomers > 0): ?>
          <div class="hero-subtitle" style="margin-top:10px;color:#b45309;">⚠️ Many customers inactive</div>
          <div class="hero-subtitle" style="margin-top:4px;">→ Consider email or discount campaigns</div>
        <?php endif; ?>
      </div>
      </div>
      <?php if ($lockCustomersLtv): ?>
        <div class="feature-lock-overlay">
          <?php renderLockedFeatureBlock(
              'LTV & Churn Insights',
              'Unlock full customer lifetime value and churn analytics to improve retention.',
              $customersLtvRequiredPlan,
              $customersLtvUpgradeUrl
          ); ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="section">
      <div class="card">
        <div class="section-title" style="margin-bottom:8px;">Customer Reports</div>
        <div class="hero-subtitle" style="margin-top:6px;">For a clean, executive view (Summary → Insights → Recommendations → Actions), use Reports.</div>
        <div style="margin-top:12px;">
          <a class="btn btn-primary" href="<?php echo e($reportsUrl); ?>">Open Reports →</a>
        </div>
      </div>
    </div>
  </main>

  <script>
    // No JS required for now.
  </script>
</body>
</html>
