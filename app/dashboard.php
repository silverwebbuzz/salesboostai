<?php
require_once __DIR__ . '/config.php';

require_once __DIR__ . '/lib/embedded_bootstrap.php';
[$shop, $host, $shopRecord] = sbm_bootstrap_embedded();

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$storeName = (string)($shopRecord['store_name'] ?? '');
$owner = (string)($shopRecord['shop_owner'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SalesBoost AI Dashboard</title>
  <link rel="stylesheet" href="<?php echo e(BASE_URL); ?>/assets/styles.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/styles.css'); ?>">

  <script src="https://unpkg.com/@shopify/app-bridge@3"></script>
  <script src="https://unpkg.com/@shopify/app-bridge-utils@3"></script>
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
          </div>
          <div class="card kpi kpi--orders">
            <div class="kpi-head">
              <span class="kpi-icon-wrap" aria-hidden="true">🛒</span>
              <div class="kpi-title">Orders</div>
            </div>
            <div class="kpi-value" id="kpiOrders">0</div>
            <div id="trendOrders"></div>
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
          </div>
        </div>
      </div>

      <div class="section">
        <div class="card inventory-insights-card">
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
        <div class="card">
          <div class="kpi-title">Critical insights</div>
          <div id="criticalIssuesGrid"></div>
        </div>
      </div>

      <div class="section grid-50-50">
        <div class="card">
          <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;">
            <div class="kpi-title">Top products</div>
            <a class="btn btn-primary" href="#" id="btnViewAllProducts2">View all products</a>
          </div>
          <div id="topProductsList"></div>
        </div>
        <div class="card">
          <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;">
            <div class="kpi-title">Customers</div>
            <a class="btn btn-primary" href="#" id="btnViewCustomers">View customers</a>
          </div>
          <div id="highValueCustomersList"></div>
        </div>
      </div>

      </div>
    </div>
  </main>

  <script src="<?php echo e(BASE_URL); ?>/assets/dashboard.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/dashboard.js'); ?>"></script>

  <script>
    // Shopify App Bridge init (no manual postMessage usage).
    (function () {
      var params = new URLSearchParams(window.location.search);
      var host = params.get('host');

      if (!host) {
        // If host is missing, bounce to auth/install to get a fresh embedded context.
        var shop = params.get('shop') || <?php echo json_encode($shop); ?>;
        if (shop) {
          window.location.href = <?php echo json_encode(BASE_URL . '/auth/install?shop='); ?> + encodeURIComponent(shop);
        }
        return;
      }

      var AppBridge = window['app-bridge'];
      if (!AppBridge || typeof AppBridge.createApp !== 'function') {
        return;
      }

      var createApp = AppBridge.createApp;
      createApp({
        apiKey: <?php echo json_encode(SHOPIFY_API_KEY); ?>,
        host: host,
        forceRedirect: true
      });
    })();
  </script>
</body>
</html>

