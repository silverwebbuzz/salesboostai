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
      <div class="section">
        <div class="card store-health-card">
          <div class="store-health-head">
            <div class="kpi-title">Store Health Score</div>
            <div class="store-health-status" id="storeHealthStatus">—</div>
          </div>
          <div class="store-health-score" id="storeHealthScore">— / 100</div>
          <div class="hero-subtitle" id="storeHealthIssue">Biggest issue: —</div>
          <div class="store-health-breakdown" id="storeHealthBreakdown">
            <div class="kpi-title" style="margin-bottom:8px;">Health Breakdown</div>
            <div class="hero-subtitle">📈 Revenue: — / 30</div>
            <div class="hero-subtitle">📦 Inventory: — / 25</div>
            <div class="hero-subtitle">👥 Customers: — / 25</div>
            <div class="hero-subtitle">🚨 Alerts: — / 20</div>
          </div>
        </div>
      </div>

      <div class="hero">
        <div class="hero-head">
          <div>
            <div class="hero-title">SalesBoost AI Dashboard</div>
            <div class="hero-subtitle">
              <?php echo e($storeName !== '' ? $storeName : $shop); ?>
              <?php if ($owner !== ''): ?> · Owner: <?php echo e($owner); ?><?php endif; ?>
            </div>
          </div>
          <a class="btn btn-primary" href="#" id="btnFixInventory">Fix inventory</a>
        </div>

        <div class="kpi-grid">
          <div class="card kpi kpi--revenue">
            <div class="kpi-title">Revenue (30 days)</div>
            <div class="kpi-value" id="kpiRevenue">0</div>
            <div id="trendRevenue"></div>
          </div>
          <div class="card kpi kpi--orders">
            <div class="kpi-title">Orders</div>
            <div class="kpi-value" id="kpiOrders">0</div>
            <div id="trendOrders"></div>
          </div>
          <div class="card kpi kpi--customers">
            <div class="kpi-title">Customers</div>
            <div class="kpi-value" id="kpiCustomers">0</div>
            <div id="trendCustomers"></div>
          </div>
          <div class="card kpi kpi--aov">
            <div class="kpi-title">AOV</div>
            <div class="kpi-value" id="kpiAov">0</div>
            <div id="trendAov"></div>
          </div>
        </div>
      </div>

      <div class="section">
        <div class="card ai-summary">
          <div class="kpi-title">AI Summary</div>
          <div id="aiSummaryText"></div>
        </div>
      </div>

      <div class="section">
        <div class="card">
          <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;">
            <div class="kpi-title">Inventory</div>
            <a class="btn btn-primary" href="#" id="btnViewProducts">View all products</a>
          </div>
          <div class="inventory-grid">
            <div class="card inventory-mini">
              <div class="kpi-title">Cash in inventory</div>
              <div class="kpi-value" id="kpiCashInventory">0</div>
            </div>
            <div class="card inventory-mini">
              <div class="kpi-title">Dead stock value</div>
              <div class="kpi-value" id="kpiDeadStock">0</div>
            </div>
            <div class="card inventory-mini">
              <div class="kpi-title">Restock needed</div>
              <div class="kpi-value" id="kpiRestockValue">0</div>
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

      <div class="section">
        <div class="card">
          <div class="kpi-title">Key insights</div>
          <div id="keyInsightsList"></div>
        </div>
      </div>
    </div>
  </main>

  <script src="<?php echo e(BASE_URL); ?>/assets/dashboard.js"></script>

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

