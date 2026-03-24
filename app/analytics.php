<?php
require_once __DIR__ . '/config.php';

require_once __DIR__ . '/lib/embedded_bootstrap.php';
require_once __DIR__ . '/lib/ui.php';
[$shop, $host, $shopRecord, $entitlements] = sbm_bootstrap_embedded(['includeEntitlements' => true]);

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$storeName = (string)($shopRecord['store_name'] ?? '');
$features = is_array($entitlements['features'] ?? null) ? $entitlements['features'] : [];
$lockProducts = !((bool)($features['analytics_products'] ?? false));
$lockCustomers = !((bool)($features['analytics_customers'] ?? false));
$lockAov = !((bool)($features['analytics_aov'] ?? false));
$productsReqPlan = function_exists('getFeatureRequiredPlan') ? getFeatureRequiredPlan('analytics_products') : 'starter';
$customersReqPlan = function_exists('getFeatureRequiredPlan') ? getFeatureRequiredPlan('analytics_customers') : 'starter';
$aovReqPlan = function_exists('getFeatureRequiredPlan') ? getFeatureRequiredPlan('analytics_aov') : 'starter';
$productsUpgradeUrl = sbm_upgrade_url($shop, $host, $productsReqPlan);
$customersUpgradeUrl = sbm_upgrade_url($shop, $host, $customersReqPlan);
$aovUpgradeUrl = sbm_upgrade_url($shop, $host, $aovReqPlan);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SalesBoost AI Analytics</title>
  <link rel="stylesheet" href="<?php echo e(BASE_URL); ?>/assets/styles.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/styles.css'); ?>">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
  <main class="container">
    <?php include __DIR__ . '/nav.php'; ?>

    <div class="hero">
      <div class="hero-head">
        <div>
          <div class="hero-title">Premium Analytics</div>
          <div class="hero-subtitle">
            <?php echo e($storeName !== '' ? $storeName : $shop); ?> · SalesBoost AI
          </div>
        </div>
      </div>
    </div>

    <div id="analyticsErrorWrap" class="section" style="display:none;">
      <div class="card" style="border:1px solid #fecaca;background:#fef2f2;">
        <div><strong>Failed to load analytics.</strong></div>
        <div id="analyticsError" style="margin-top:6px;"></div>
      </div>
    </div>

    <div class="section">
      <div class="tabs" id="analyticsTabs">
        <button class="tab active" type="button" data-tab="revenue">Revenue</button>
        <button class="tab <?php echo $lockProducts ? 'tab-locked' : ''; ?>" type="button" data-tab="products" data-locked="<?php echo $lockProducts ? '1' : '0'; ?>">Products<?php if ($lockProducts): ?> 🔒<?php endif; ?></button>
        <button class="tab <?php echo $lockCustomers ? 'tab-locked' : ''; ?>" type="button" data-tab="customers" data-locked="<?php echo $lockCustomers ? '1' : '0'; ?>">Customers<?php if ($lockCustomers): ?> 🔒<?php endif; ?></button>
        <button class="tab <?php echo $lockAov ? 'tab-locked' : ''; ?>" type="button" data-tab="aov" data-locked="<?php echo $lockAov ? '1' : '0'; ?>">AOV<?php if ($lockAov): ?> 🔒<?php endif; ?></button>
      </div>
    </div>

    <div id="tab-revenue" class="tab-panel active">
      <div class="section">
        <div class="card chart-card">
          <div class="analytics-head">
            <div>
              <div class="kpi-title">Revenue</div>
              <div class="kpi-value" id="revenueTotal">—</div>
            </div>
            <div class="time-filters">
              <button type="button" class="btn btn-primary btn-sm time-filter active" data-range="7">7 days</button>
              <button type="button" class="btn btn-primary btn-sm time-filter" data-range="30">30 days</button>
            </div>
          </div>
          <div class="chart-wrap">
            <canvas id="analyticsRevenueChart"></canvas>
          </div>
        </div>
      </div>
      <div class="section">
        <div class="card ai-insight" id="revenueInsight">—</div>
      </div>
    </div>

    <div id="tab-products" class="tab-panel">
      <div class="feature-lock-card analytics-lock-wrap">
      <div class="<?php echo $lockProducts ? 'feature-lock-blur' : ''; ?>">
      <div class="section">
        <div class="card chart-card">
          <div class="kpi-title">Product Revenue (Top 5)</div>
          <div class="chart-wrap">
            <canvas id="analyticsProductsChart"></canvas>
          </div>
        </div>
      </div>
      <div class="section grid-50-50">
        <div class="card">
          <div class="kpi-title">Top Products</div>
          <div id="productsTopList"></div>
        </div>
        <div class="card">
          <div class="kpi-title">Worst Performing</div>
          <div id="productsWorstList"></div>
        </div>
      </div>
      <div class="section">
        <div class="card ai-insight" id="productsInsight">—</div>
      </div>
      </div>
      <?php if ($lockProducts): ?>
        <div class="feature-lock-overlay">
          <?php renderLockedFeatureBlock(
              'Products Analytics',
              'Unlock top/worst product performance breakdown and product-level insight cards.',
              $productsReqPlan,
              $productsUpgradeUrl
          ); ?>
        </div>
      <?php endif; ?>
      </div>
    </div>

    <div id="tab-customers" class="tab-panel">
      <div class="feature-lock-card analytics-lock-wrap">
      <div class="<?php echo $lockCustomers ? 'feature-lock-blur' : ''; ?>">
      <div class="section">
        <div class="card chart-card">
          <div class="kpi-title">New vs Returning</div>
          <div class="chart-wrap">
            <canvas id="analyticsCustomersChart"></canvas>
          </div>
        </div>
      </div>
      <div class="section grid-50-50">
        <div class="card">
          <div class="kpi-title">New vs Returning Customers</div>
          <div class="SbListRow"><div class="sb-list-left">New Customers</div><div class="sb-list-right" id="customersNew">—</div></div>
          <div class="SbListRow"><div class="sb-list-left">Returning Customers</div><div class="sb-list-right" id="customersReturning">—</div></div>
        </div>
        <div class="card">
          <div class="kpi-title">Top Customers</div>
          <div id="customersTopList"></div>
        </div>
      </div>
      <div class="section">
        <div class="card ai-insight" id="customersInsight">—</div>
      </div>
      </div>
      <?php if ($lockCustomers): ?>
        <div class="feature-lock-overlay">
          <?php renderLockedFeatureBlock(
              'Customers Analytics',
              'Unlock customer cohorts, top buyer performance, and retention-focused insights.',
              $customersReqPlan,
              $customersUpgradeUrl
          ); ?>
        </div>
      <?php endif; ?>
      </div>
    </div>

    <div id="tab-aov" class="tab-panel">
      <div class="feature-lock-card analytics-lock-wrap">
      <div class="<?php echo $lockAov ? 'feature-lock-blur' : ''; ?>">
      <div class="section">
        <div class="card chart-card">
          <div>
            <div class="kpi-title">AOV</div>
            <div class="kpi-value" id="aovValue">—</div>
          </div>
          <div class="chart-wrap">
            <canvas id="analyticsAovChart"></canvas>
          </div>
        </div>
      </div>
      <div class="section">
        <div class="card ai-insight" id="aovInsight">—</div>
      </div>
      </div>
      <?php if ($lockAov): ?>
        <div class="feature-lock-overlay">
          <?php renderLockedFeatureBlock(
              'AOV Analytics',
              'Unlock average order value trend analysis and optimization-focused recommendations.',
              $aovReqPlan,
              $aovUpgradeUrl
          ); ?>
        </div>
      <?php endif; ?>
      </div>
    </div>
  </main>

  <script src="<?php echo e(BASE_URL); ?>/assets/analytics.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/analytics.js'); ?>"></script>
</body>
</html>
