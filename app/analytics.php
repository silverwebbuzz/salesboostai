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
$lockFunnel = !((bool)($features['analytics_funnel'] ?? false));
$lockAttribution = !((bool)($features['analytics_attribution'] ?? false));
$lockRetention = !((bool)($features['analytics_retention'] ?? false));
$lockCustomersLtv = !((bool)($features['customers_ltv'] ?? false));
$productsReqPlan = function_exists('getFeatureRequiredPlan') ? getFeatureRequiredPlan('analytics_products') : 'starter';
$customersReqPlan = function_exists('getFeatureRequiredPlan') ? getFeatureRequiredPlan('analytics_customers') : 'starter';
$aovReqPlan = function_exists('getFeatureRequiredPlan') ? getFeatureRequiredPlan('analytics_aov') : 'starter';
$funnelReqPlan = function_exists('getFeatureRequiredPlan') ? getFeatureRequiredPlan('analytics_funnel') : 'starter';
$attributionReqPlan = function_exists('getFeatureRequiredPlan') ? getFeatureRequiredPlan('analytics_attribution') : 'growth';
$retentionReqPlan = function_exists('getFeatureRequiredPlan') ? getFeatureRequiredPlan('analytics_retention') : 'starter';
$customersLtvReqPlan = function_exists('getFeatureRequiredPlan') ? getFeatureRequiredPlan('customers_ltv') : 'starter';
$productsUpgradeUrl = sbm_upgrade_url($shop, $host, $productsReqPlan);
$customersUpgradeUrl = sbm_upgrade_url($shop, $host, $customersReqPlan);
$aovUpgradeUrl = sbm_upgrade_url($shop, $host, $aovReqPlan);
$funnelUpgradeUrl = sbm_upgrade_url($shop, $host, $funnelReqPlan);
$attributionUpgradeUrl = sbm_upgrade_url($shop, $host, $attributionReqPlan);
$retentionUpgradeUrl = sbm_upgrade_url($shop, $host, $retentionReqPlan);
$customersLtvUpgradeUrl = sbm_upgrade_url($shop, $host, $customersLtvReqPlan);
$reportsUrl = BASE_URL . '/reports.php?shop=' . urlencode($shop) . ($host !== '' ? ('&host=' . urlencode($host)) : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php include __DIR__ . '/partials/app_bridge_first.php'; ?>
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
      <div class="section">
        <div class="card">
          <div class="kpi-title">Full Revenue Report</div>
          <div class="hero-subtitle" style="margin-top:6px;">See Revenue, Funnel, Attribution, recommendations, and actions in one place.</div>
          <div style="margin-top:12px;">
            <a class="btn btn-primary" href="<?php echo e($reportsUrl); ?>">Open Reports →</a>
          </div>
        </div>
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
      <div class="section">
        <div class="card">
          <div class="kpi-title">Full Product Performance Report</div>
          <div class="hero-subtitle" style="margin-top:6px;">See product insights, inventory risk, and action plans in Reports.</div>
          <div style="margin-top:12px;">
            <a class="btn btn-primary" href="<?php echo e($reportsUrl); ?>">Open Reports →</a>
          </div>
        </div>
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
      <div class="section grid-50-50">
        <div class="card">
          <div class="kpi-title">Customer Segments</div>
          <div class="SbListRow"><div class="sb-list-left">Total customers</div><div class="sb-list-right" id="customersTotal">—</div></div>
          <div class="SbListRow"><div class="sb-list-left">Repeat customers</div><div class="sb-list-right" id="customersRepeat">—</div></div>
          <div class="SbListRow"><div class="sb-list-left">VIP customers</div><div class="sb-list-right" id="customersVip">—</div></div>
          <div class="SbListRow"><div class="sb-list-left">At-risk customers</div><div class="sb-list-right" id="customersAtRisk">—</div></div>
          <div class="SbListRow"><div class="sb-list-left">Inactive customers</div><div class="sb-list-right" id="customersInactive">—</div></div>
        </div>
        <div class="card feature-lock-card analytics-lock-wrap">
          <div class="<?php echo $lockCustomersLtv ? 'feature-lock-blur' : ''; ?>">
            <div class="kpi-title">LTV & Value</div>
            <div class="SbListRow"><div class="sb-list-left">Avg LTV (est.)</div><div class="sb-list-right" id="customersAvgLtv">—</div></div>
            <div class="SbListRow"><div class="sb-list-left">VIP LTV (est.)</div><div class="sb-list-right" id="customersVipLtv">—</div></div>
            <div class="SbListRow"><div class="sb-list-left">Orders scanned</div><div class="sb-list-right" id="customersOrdersScanned">—</div></div>
          </div>
          <?php if ($lockCustomersLtv): ?>
            <div class="feature-lock-overlay">
              <?php renderLockedFeatureBlock(
                  'Customer LTV',
                  'Unlock LTV and churn-style value metrics for customer segmentation and retention planning.',
                  $customersLtvReqPlan,
                  $customersLtvUpgradeUrl
              ); ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
      <div class="section reports-grid">
        <div class="card">
          <div class="kpi-title">Retention</div>
          <div class="hero-subtitle" style="margin-top:6px;">Preview based on recent cohorts (plan-gated depth).</div>
          <div style="margin-top:12px;" id="customersRetentionList"></div>
        </div>
        <div class="card">
          <div class="kpi-title">Next Step</div>
          <div class="hero-subtitle" style="margin-top:6px;">Use Reports for executive summaries, action plans, and deeper retention insights.</div>
          <div style="margin-top:12px;">
            <a class="btn btn-primary btn-sm" href="<?php echo e($reportsUrl); ?>&tab=customers">Open Customer Report →</a>
          </div>
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
