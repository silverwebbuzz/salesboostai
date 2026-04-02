<?php
require_once __DIR__ . '/config.php';

require_once __DIR__ . '/lib/embedded_bootstrap.php';
require_once __DIR__ . '/lib/ui.php';
[$shop, $host, $shopRecord, $entitlements] = sbm_bootstrap_embedded(['includeEntitlements' => true]);

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$storeName = (string)($shopRecord['store_name'] ?? '');
$features = is_array($entitlements['features'] ?? null) ? $entitlements['features'] : [];
$lockInventory = !((bool)($features['dashboard_inventory'] ?? false));
$lockCritical = !((bool)($features['dashboard_critical_full'] ?? false));
$lockTopLists = !((bool)($features['dashboard_top_lists_full'] ?? false));
$lockActionCenter = !((bool)($features['dashboard_action_center'] ?? false));
$lockGoals = !((bool)($features['goals_tracking'] ?? false));
$inventoryRequiredPlan = function_exists('getFeatureRequiredPlan') ? getFeatureRequiredPlan('dashboard_inventory') : 'starter';
$criticalRequiredPlan = function_exists('getFeatureRequiredPlan') ? getFeatureRequiredPlan('dashboard_critical_full') : 'starter';
$topListsRequiredPlan = function_exists('getFeatureRequiredPlan') ? getFeatureRequiredPlan('dashboard_top_lists_full') : 'starter';
$actionCenterRequiredPlan = function_exists('getFeatureRequiredPlan') ? getFeatureRequiredPlan('dashboard_action_center') : 'starter';
$goalsRequiredPlan = function_exists('getFeatureRequiredPlan') ? getFeatureRequiredPlan('goals_tracking') : 'starter';
$inventoryUpgradeUrl = sbm_upgrade_url($shop, $host, $inventoryRequiredPlan);
$criticalUpgradeUrl = sbm_upgrade_url($shop, $host, $criticalRequiredPlan);
$topListsUpgradeUrl = sbm_upgrade_url($shop, $host, $topListsRequiredPlan);
$actionCenterUpgradeUrl = sbm_upgrade_url($shop, $host, $actionCenterRequiredPlan);
$goalsUpgradeUrl = sbm_upgrade_url($shop, $host, $goalsRequiredPlan);
$hostForBootstrap = (string)$host;
if ($hostForBootstrap === '') {
  $hostForBootstrap = (string)($shopRecord['host'] ?? '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SalesBoost AI Dashboard</title>
  <link rel="stylesheet" href="assets/styles.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/styles.css'); ?>">

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
  <main class="container">
    <?php include __DIR__ . '/nav.php'; ?>

    <div id="sbError" style="display:none;">
      <div><strong>Something went wrong loading your data.</strong></div>
      <div id="sbErrorText">Failed to load.</div>
    </div>

    <div id="sbSyncNotice" style="display:none;">
      <div class="card" style="border:1px solid #dbeafe;background:#eff6ff;margin-bottom:14px;">
        <div><strong id="sbSyncTitle">Store sync in progress</strong></div>
        <div id="sbSyncText" style="margin-top:6px;">Your store data is being prepared. Please refresh in a moment.</div>
      </div>
    </div>

    <div id="sbSkeleton">
      <div class="hero">
        <div class="hero-head">
          <div>
            <div class="hero-title">SalesBoost AI Dashboard</div>
            <div class="hero-subtitle">AI-powered insights for your store</div>
          </div>
          <a class="btn btn-primary" href="#" aria-disabled="true">Loading…</a>
        </div>
        <div class="kpi-grid">
          <?php for ($i = 0; $i < 4; $i++): ?>
            <div class="card kpi"></div>
          <?php endfor; ?>
        </div>
      </div>
      <div class="section"><div class="card"></div></div>
      <div class="section"><div class="card"></div></div>
      <div class="section grid-70-30">
        <div class="card"></div>
        <div class="card"></div>
      </div>
      <div class="section grid-50-50">
        <div class="card"></div>
        <div class="card"></div>
      </div>
    </div>

    <div id="sbContent" style="display:none;">
      <div class="section" id="sbSyncGate" style="display:none;">
        <div class="card" style="border:1px solid #dbeafe;background:#eff6ff;">
          <div class="section-title" id="sbSyncGateTitle">Sync your store data</div>
          <div class="hero-subtitle" id="sbSyncGateText">
            Sync your store data before using your agents. We need your products and orders to generate insights.
          </div>
          <div class="hero-subtitle" id="sbSyncGateMeta" style="margin-top:8px;"></div>
          <div style="margin-top:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <button class="btn btn-primary" type="button" id="btnRunSync">Sync Now</button>
            <button class="btn btn-primary" type="button" id="btnRefreshDashboard" style="display:none;">Display dashboard</button>
            <span class="hero-subtitle" id="sbSyncGateHint" style="margin:0;"></span>
          </div>
        </div>
      </div>

      <div id="sbDashboardBody">
      <div class="section health-ai-row">
        <div class="card store-health-card">
          <div class="store-health-head">
            <div class="store-health-title">Store Health Score</div>
            <div class="store-health-status" id="storeHealthStatus">Needs Attention</div>
          </div>
          <div class="store-health-score-wrap">
            <span class="store-health-score" id="storeHealthScoreValue">0</span>
            <span class="store-health-score-max">/100</span>
          </div>
          <div class="store-health-breakdown" id="storeHealthBreakdown">
            <div class="store-health-row">
              <div class="store-health-row-label">Revenue</div>
              <div class="store-health-row-bar"><span style="width:0%"></span></div>
              <div class="store-health-row-value">0/30</div>
            </div>
            <div class="store-health-row">
              <div class="store-health-row-label">Inventory</div>
              <div class="store-health-row-bar"><span style="width:0%"></span></div>
              <div class="store-health-row-value">0/25</div>
            </div>
            <div class="store-health-row">
              <div class="store-health-row-label">Customers</div>
              <div class="store-health-row-bar"><span style="width:0%"></span></div>
              <div class="store-health-row-value">0/25</div>
            </div>
            <div class="store-health-row">
              <div class="store-health-row-label">Alerts</div>
              <div class="store-health-row-bar"><span style="width:0%"></span></div>
              <div class="store-health-row-value">0/20</div>
            </div>
          </div>
          <div class="store-health-issue-box" id="storeHealthIssue">Biggest Issue: —</div>
        </div>

        <div class="card ai-summary-card">
          <div class="ai-summary-head">
            <div class="ai-summary-title">Key insights</div>
            <div class="ai-summary-badge">Action Needed</div>
          </div>
          <div class="ai-summary-grid" id="aiSummaryGrid">
            <div class="ai-summary-item ai-summary-item--action">
              <div class="ai-summary-item-head">
                <span class="ai-summary-icon">!</span>
                <span class="ai-summary-label">Action needed</span>
              </div>
              <div class="ai-summary-text">Most sales come from one product. Promote other items.</div>
            </div>
            <div class="ai-summary-item ai-summary-item--action">
              <div class="ai-summary-item-head">
                <span class="ai-summary-icon">!</span>
                <span class="ai-summary-label">Action needed</span>
              </div>
              <div class="ai-summary-text">Customers are not returning. Try follow-up campaigns.</div>
            </div>
            <div class="ai-summary-item ai-summary-item--good">
              <div class="ai-summary-item-head">
                <span class="ai-summary-icon">✓</span>
                <span class="ai-summary-label">Good performance</span>
              </div>
              <div class="ai-summary-text">Sales look steady. Keep promoting your best products.</div>
            </div>
            <div class="ai-summary-item ai-summary-item--opportunity">
              <div class="ai-summary-item-head">
                <span class="ai-summary-icon">i</span>
                <span class="ai-summary-label">Opportunity</span>
              </div>
              <div class="ai-summary-text">Some winning items may run out. Restock soon.</div>
            </div>
          </div>
        </div>
      </div>

      <div class="hero">
        <div class="kpi-grid">
          <div class="card kpi kpi--revenue">
            <div class="kpi-head">
              <span class="kpi-icon-wrap" aria-hidden="true">💵</span>
              <div class="kpi-title">Revenue (30 days)</div>
            </div>
            <div class="kpi-value" id="kpiRevenue">0</div>
            <div id="trendRevenue"></div>
            <button class="sb-kpi-why" type="button" data-ai-metric="revenue" data-ai-period="7">Why did this change?</button>
          </div>
          <div class="card kpi kpi--orders">
            <div class="kpi-head">
              <span class="kpi-icon-wrap" aria-hidden="true">🛒</span>
              <div class="kpi-title">Orders</div>
            </div>
            <div class="kpi-value" id="kpiOrders">0</div>
            <div id="trendOrders"></div>
            <button class="sb-kpi-why" type="button" data-ai-metric="orders" data-ai-period="7">Why did this change?</button>
          </div>
          <div class="card kpi kpi--customers">
            <div class="kpi-head">
              <span class="kpi-icon-wrap" aria-hidden="true">👥</span>
              <div class="kpi-title">Customers</div>
            </div>
            <div class="kpi-value" id="kpiCustomers">0</div>
            <div id="trendCustomers"></div>
          </div>
          <div class="card kpi kpi--aov">
            <div class="kpi-head">
              <span class="kpi-icon-wrap" aria-hidden="true">🎯</span>
              <div class="kpi-title">AOV</div>
            </div>
            <div class="kpi-value" id="kpiAov">0</div>
            <div id="trendAov"></div>
            <button class="sb-kpi-why" type="button" data-ai-metric="aov" data-ai-period="7">Why did this change?</button>
          </div>
        </div>
      </div>

      <div class="sb-modal" id="aiExplainModal" aria-hidden="true">
        <div class="sb-modal__panel" role="dialog" aria-modal="true">
          <div class="sb-modal__head">
            <div>
              <div class="sb-modal__title" id="aiExplainTitle">AI explanation</div>
              <div class="hero-subtitle" id="aiExplainSubtitle" style="margin-top:2px;">2-sentence explanation based on your store data.</div>
            </div>
            <button class="sb-modal__close" type="button" id="aiExplainClose">Close</button>
          </div>
          <div class="sb-modal__body" id="aiExplainBody">—</div>
          <div class="sb-modal__meta" id="aiExplainMeta"></div>
        </div>
      </div>

      <div class="section">
        <div class="card feature-lock-card" data-lock-goals="<?php echo $lockGoals ? '1' : '0'; ?>">
          <div class="<?php echo $lockGoals ? 'feature-lock-blur' : ''; ?>">
            <div class="critical-insights-head">
              <div class="critical-insights-title-wrap">
                <span class="critical-insights-icon" aria-hidden="true">🎯</span>
                <div class="critical-insights-title">GOALS TRACKING</div>
              </div>
            </div>
            <div id="goalsList"></div>
          </div>
          <?php if ($lockGoals): ?>
            <div class="feature-lock-overlay">
              <?php renderLockedFeatureBlock(
                  'Goals Tracking',
                  'Set KPI targets and get proactive off-track warnings.',
                  $goalsRequiredPlan,
                  $goalsUpgradeUrl
              ); ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="section">
        <div class="card inventory-insights-card feature-lock-card" data-lock-inventory="<?php echo $lockInventory ? '1' : '0'; ?>">
          <div class="<?php echo $lockInventory ? 'feature-lock-blur' : ''; ?>">
          <div class="inventory-insights-head">
            <div class="inventory-insights-title-wrap">
              <span class="inventory-insights-icon" aria-hidden="true">📦</span>
              <div class="inventory-insights-title">Inventory</div>
            </div>
            <a class="inventory-outline-btn" href="#" id="btnViewProducts">View all products</a>
          </div>
          <div class="inventory-insights-grid">
            <div class="inventory-metric inventory-metric--cash">
              <div class="inventory-metric-label">Cash in inventory</div>
              <div class="kpi-value" id="kpiCashInventory">0</div>
              <div class="inventory-metric-help">Total stock value</div>
            </div>
            <div class="inventory-metric inventory-metric--dead" id="deadStockMetric">
              <div class="inventory-metric-top">
                <div class="inventory-metric-label">Dead stock value</div>
                <span class="inventory-pill inventory-pill--danger" id="deadStockBadge">High</span>
              </div>
              <div class="kpi-value" id="kpiDeadStock">0</div>
              <div class="inventory-metric-help">Products not sold in last 30 days</div>
            </div>
            <div class="inventory-metric inventory-metric--restock">
              <div class="inventory-metric-top">
                <div class="inventory-metric-label">Restock needed</div>
                <span class="inventory-pill" id="restockBadge">Healthy</span>
              </div>
              <div class="kpi-value" id="kpiRestockValue">0</div>
              <div class="inventory-metric-help">Items below preferred stock threshold</div>
            </div>
          </div>
          <div class="top-list-rows" id="inventoryForecastList" style="margin-top:14px;"></div>
          </div>
          <?php if ($lockInventory): ?>
            <div class="feature-lock-overlay">
              <?php renderLockedFeatureBlock(
                  'Inventory Insights',
                  'Unlock full inventory value, dead stock alerts, and restock intelligence.',
                  $inventoryRequiredPlan,
                  $inventoryUpgradeUrl
              ); ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="section grid-70-30">
        <div class="card chart-card">
          <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:10px;">
            <div class="kpi-title">Revenue</div>
            <div>
              <button class="btn btn-primary" type="button" data-range="7">Last 7 days</button>
              <button class="btn btn-primary" type="button" data-range="30">Last 30 days</button>
            </div>
          </div>
          <div><canvas id="revenueChart"></canvas></div>
        </div>
        <div class="card chart-card">
          <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:10px;">
            <div class="kpi-title">Orders</div>
            <div>
              <button class="btn btn-primary" type="button" data-range="7">Last 7 days</button>
              <button class="btn btn-primary" type="button" data-range="30">Last 30 days</button>
            </div>
          </div>
          <div><canvas id="ordersChart"></canvas></div>
        </div>
      </div>

      <div class="section">
        <div class="card action-center-card feature-lock-card" data-lock-actions="<?php echo $lockActionCenter ? '1' : '0'; ?>">
          <div class="<?php echo $lockActionCenter ? 'feature-lock-blur' : ''; ?>">
            <div class="critical-insights-head">
              <div class="critical-insights-title-wrap">
                <span class="critical-insights-icon" aria-hidden="true">🎯</span>
                <div class="critical-insights-title">ACTION CENTER</div>
              </div>
            </div>
            <div id="actionCenterList"></div>
          </div>
          <?php if ($lockActionCenter): ?>
            <div class="feature-lock-overlay">
              <?php renderLockedFeatureBlock(
                  'Action Center',
                  'Unlock prioritized impact scoring and guided next-best actions for your store.',
                  $actionCenterRequiredPlan,
                  $actionCenterUpgradeUrl
              ); ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="section">
        <div class="card critical-insights-card feature-lock-card" data-lock-critical="<?php echo $lockCritical ? '1' : '0'; ?>">
          <div class="<?php echo $lockCritical ? 'feature-lock-blur' : ''; ?>">
          <div class="critical-insights-head">
            <div class="critical-insights-title-wrap">
              <span class="critical-insights-icon" aria-hidden="true">⚠</span>
              <div class="critical-insights-title">CRITICAL INSIGHTS</div>
            </div>
          </div>
          <div id="criticalIssuesGrid"></div>
          </div>
          <?php if ($lockCritical): ?>
            <div class="feature-lock-overlay">
              <?php renderLockedFeatureBlock(
                  'Critical Insights',
                  'Get full prioritized issue detection with deeper severity and action guidance.',
                  $criticalRequiredPlan,
                  $criticalUpgradeUrl
              ); ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="section grid-50-50">
        <div class="card top-list-card feature-lock-card" data-lock-toplists="<?php echo $lockTopLists ? '1' : '0'; ?>">
          <div class="<?php echo $lockTopLists ? 'feature-lock-blur' : ''; ?>">
          <div class="top-list-head">
            <div class="top-list-title-wrap">
              <div class="top-list-title">Top Products</div>
              <div class="top-list-subtitle">Best performing items</div>
            </div>
          </div>
          <div class="top-list-rows" id="topProductsList"></div>
          <a class="top-list-link" href="#" id="btnViewAllProducts2">View all products →</a>
          </div>
          <?php if ($lockTopLists): ?>
            <div class="feature-lock-overlay">
              <?php renderLockedFeatureBlock(
                  'Top Products',
                  'See your best performing products with deeper ranking and performance trends.',
                  $topListsRequiredPlan,
                  $topListsUpgradeUrl
              ); ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="card top-list-card feature-lock-card" data-lock-toplists="<?php echo $lockTopLists ? '1' : '0'; ?>">
          <div class="<?php echo $lockTopLists ? 'feature-lock-blur' : ''; ?>">
          <div class="top-list-head">
            <div class="top-list-title-wrap">
              <div class="top-list-title">Top Customers</div>
              <div class="top-list-subtitle">Highest value buyers</div>
            </div>
          </div>
          <div class="top-list-rows" id="highValueCustomersList"></div>
          <a class="top-list-link" href="#" id="btnViewCustomers">View all customers →</a>
          </div>
          <?php if ($lockTopLists): ?>
            <div class="feature-lock-overlay">
              <?php renderLockedFeatureBlock(
                  'Top Customers',
                  'Unlock high-value customer ranking, spend patterns, and loyalty signals.',
                  $topListsRequiredPlan,
                  $topListsUpgradeUrl
              ); ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      </div>
    </div>
  </main>

  <script>
    window.__SB_CONTEXT = {
      shop: <?php echo json_encode((string)$shop); ?>,
      host: <?php echo json_encode((string)$hostForBootstrap); ?>
    };
  </script>
  <script src="assets/dashboard.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/dashboard.js'); ?>"></script>

</body>
</html>

