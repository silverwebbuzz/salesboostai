<?php
require_once __DIR__ . '/config.php';

require_once __DIR__ . '/lib/embedded_bootstrap.php';
[$shop, $host, $shopRecord] = sbm_bootstrap_embedded();

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$storeName = (string)($shopRecord['store_name'] ?? '');
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
        <button class="tab" type="button" data-tab="products">Products</button>
        <button class="tab" type="button" data-tab="customers">Customers</button>
        <button class="tab" type="button" data-tab="aov">AOV</button>
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

    <div id="tab-customers" class="tab-panel">
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

    <div id="tab-aov" class="tab-panel">
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
  </main>

  <script src="<?php echo e(BASE_URL); ?>/assets/analytics.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/analytics.js'); ?>"></script>
</body>
</html>
