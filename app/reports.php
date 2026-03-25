<?php
require_once __DIR__ . '/config.php';

require_once __DIR__ . '/lib/embedded_bootstrap.php';
require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/usage.php';
[$shop, $host, $shopRecord, $entitlements] = sbm_bootstrap_embedded(['includeEntitlements' => true]);

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$storeName = (string)($shopRecord['store_name'] ?? '');
$features = is_array($entitlements['features'] ?? null) ? $entitlements['features'] : [];
$limits = is_array($entitlements['limits'] ?? null) ? $entitlements['limits'] : [];

$lockFunnel = !((bool)($features['analytics_funnel'] ?? false));
$lockAttribution = !((bool)($features['analytics_attribution'] ?? false));
$lockGoals = !((bool)($features['goals_tracking'] ?? false));
$lockReportsExport = !((bool)($features['reports_export'] ?? false));
$lockReportsSchedule = !((bool)($features['reports_scheduled'] ?? false));

$funnelReqPlan = function_exists('getFeatureRequiredPlan') ? getFeatureRequiredPlan('analytics_funnel') : 'starter';
$attributionReqPlan = function_exists('getFeatureRequiredPlan') ? getFeatureRequiredPlan('analytics_attribution') : 'growth';
$goalsReqPlan = function_exists('getFeatureRequiredPlan') ? getFeatureRequiredPlan('goals_tracking') : 'starter';
$reportsExportReqPlan = function_exists('getFeatureRequiredPlan') ? getFeatureRequiredPlan('reports_export') : 'growth';
$reportsScheduleReqPlan = function_exists('getFeatureRequiredPlan') ? getFeatureRequiredPlan('reports_scheduled') : 'growth';

$funnelUpgradeUrl = sbm_upgrade_url($shop, $host, $funnelReqPlan);
$attributionUpgradeUrl = sbm_upgrade_url($shop, $host, $attributionReqPlan);
$goalsUpgradeUrl = sbm_upgrade_url($shop, $host, $goalsReqPlan);
$reportsExportUpgradeUrl = sbm_upgrade_url($shop, $host, $reportsExportReqPlan);
$reportsScheduleUpgradeUrl = sbm_upgrade_url($shop, $host, $reportsScheduleReqPlan);

$aiLimit = (int)($limits['ai_insights_per_week'] ?? 1);
$aiUsage = sbm_usage_state($shop, 'ai_insights', $aiLimit);
$lockAi = $aiUsage['reached'] && !$aiUsage['unlimited'];
$nextPlan = 'starter';
if (($entitlements['plan_key'] ?? 'free') === 'starter') $nextPlan = 'growth';
if (($entitlements['plan_key'] ?? 'free') === 'growth') $nextPlan = 'premium';
$aiUpgradeUrl = sbm_upgrade_url($shop, $host, $nextPlan);

$actionCenterUrl = BASE_URL . '/action-center.php?shop=' . urlencode($shop) . ($host !== '' ? ('&host=' . urlencode($host)) : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reports</title>
  <link rel="stylesheet" href="<?php echo e(BASE_URL); ?>/assets/styles.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/styles.css'); ?>">
</head>
<body>
  <main class="container">
    <?php include __DIR__ . '/nav.php'; ?>

    <div id="reportsNotice" class="card reports-notice is-hidden mb-12"></div>

    <div class="hero">
      <div class="hero-head">
        <div>
          <div class="hero-title">Reports</div>
          <div class="hero-subtitle">Executive summaries and deep dives</div>
        </div>
        <div class="reports-controls">
          <div class="time-filters">
            <button type="button" class="btn btn-primary btn-sm report-range active" data-range="7">7 days</button>
            <button type="button" class="btn btn-primary btn-sm report-range" data-range="30">30 days</button>
            <button type="button" class="btn btn-primary btn-sm report-range" data-range="90">90 days</button>
          </div>
          <a class="btn btn-primary btn-sm" href="<?php echo e($actionCenterUrl); ?>">← Back to Action Center</a>
          <?php if ($lockReportsExport): ?>
            <a class="btn btn-primary btn-sm" href="<?php echo e($reportsExportUpgradeUrl); ?>">Upgrade to Export</a>
          <?php else: ?>
            <button type="button" class="btn btn-primary btn-sm" id="btnReportsExport">Export (coming soon)</button>
          <?php endif; ?>

          <?php if ($lockReportsSchedule): ?>
            <a class="btn btn-primary btn-sm" href="<?php echo e($reportsScheduleUpgradeUrl); ?>">Upgrade to Schedule</a>
          <?php else: ?>
            <button type="button" class="btn btn-primary btn-sm" id="btnReportsSchedule">Schedule digest (coming soon)</button>
          <?php endif; ?>
        </div>
      </div>
      <div class="hero-subtitle"><?php echo e($storeName !== '' ? $storeName : $shop); ?> · SalesBoost AI</div>
    </div>

    <div class="section">
      <div class="tabs" id="reportsTabs">
        <button class="tab active" type="button" data-tab="revenue">Revenue</button>
        <button class="tab" type="button" data-tab="customers">Customers</button>
        <button class="tab" type="button" data-tab="inventory">Inventory</button>
        <button class="tab <?php echo $lockFunnel ? 'tab-locked' : ''; ?>" type="button" data-tab="funnel" data-locked="<?php echo $lockFunnel ? '1' : '0'; ?>">Funnel<?php if ($lockFunnel): ?> 🔒<?php endif; ?></button>
        <button class="tab <?php echo $lockAttribution ? 'tab-locked' : ''; ?>" type="button" data-tab="attribution" data-locked="<?php echo $lockAttribution ? '1' : '0'; ?>">Attribution<?php if ($lockAttribution): ?> 🔒<?php endif; ?></button>
        <button class="tab <?php echo $lockGoals ? 'tab-locked' : ''; ?>" type="button" data-tab="goals" data-locked="<?php echo $lockGoals ? '1' : '0'; ?>">Goals<?php if ($lockGoals): ?> 🔒<?php endif; ?></button>
        <button class="tab <?php echo $lockAi ? 'tab-locked' : ''; ?>" type="button" data-tab="ai" data-locked="<?php echo $lockAi ? '1' : '0'; ?>">AI Summary<?php if ($lockAi): ?> 🔒<?php endif; ?></button>
      </div>
    </div>

    <div id="tab-revenue" class="tab-panel active">
      <div class="section reports-grid">
        <div class="card">
          <div class="kpi-title">Summary</div>
          <ul class="report-list" id="reportsRevenueSummary"></ul>
        </div>
        <div class="card">
          <div class="kpi-title">Critical Insights</div>
          <div id="reportsRevenueCritical"></div>
        </div>
      </div>
      <div class="section">
        <div class="card">
          <div class="kpi-title">Actions</div>
          <div id="reportsRevenueActions"></div>
        </div>
      </div>
    </div>

    <div id="tab-customers" class="tab-panel">
      <div class="section reports-grid">
        <div class="card">
          <div class="kpi-title">Summary</div>
          <ul class="report-list" id="reportsCustomersSummary"></ul>
        </div>
        <div class="card">
          <div class="kpi-title">Retention & Cohorts</div>
          <div id="reportsCustomersRetention"></div>
        </div>
      </div>
      <div class="section reports-grid">
        <div class="card">
          <div class="kpi-title">Critical Insights</div>
          <div id="reportsCustomersCritical"></div>
        </div>
        <div class="card">
          <div class="kpi-title">Actions</div>
          <div id="reportsCustomersActions"></div>
        </div>
      </div>
    </div>

    <div id="tab-inventory" class="tab-panel">
      <div class="section reports-grid">
        <div class="card">
          <div class="kpi-title">Summary</div>
          <ul class="report-list" id="reportsInventorySummary"></ul>
        </div>
        <div class="card">
          <div class="kpi-title">Forecast</div>
          <div id="reportsInventoryForecast"></div>
        </div>
      </div>
      <div class="section reports-grid">
        <div class="card">
          <div class="kpi-title">Critical Insights</div>
          <div id="reportsInventoryCritical"></div>
        </div>
        <div class="card">
          <div class="kpi-title">Actions</div>
          <div id="reportsInventoryActions"></div>
        </div>
      </div>
    </div>

    <div id="tab-funnel" class="tab-panel">
      <div class="section">
        <div class="card feature-lock-card analytics-lock-wrap">
          <div class="<?php echo $lockFunnel ? 'feature-lock-blur' : ''; ?>">
            <div class="kpi-title">Conversion Funnel</div>
            <div id="reportsFunnelSummary"></div>
          </div>
          <?php if ($lockFunnel): ?>
            <div class="feature-lock-overlay">
              <?php renderLockedFeatureBlock('Conversion Funnel', 'Unlock funnel drop-off diagnostics and conversion optimization actions.', $funnelReqPlan, $funnelUpgradeUrl); ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div id="tab-attribution" class="tab-panel">
      <div class="section">
        <div class="card feature-lock-card analytics-lock-wrap">
          <div class="<?php echo $lockAttribution ? 'feature-lock-blur' : ''; ?>">
            <div class="kpi-title">Attribution</div>
            <div id="reportsAttributionSummary"></div>
          </div>
          <?php if ($lockAttribution): ?>
            <div class="feature-lock-overlay">
              <?php renderLockedFeatureBlock('Attribution', 'Unlock channel/source revenue contribution analysis.', $attributionReqPlan, $attributionUpgradeUrl); ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div id="tab-goals" class="tab-panel">
      <div class="section">
        <div class="card feature-lock-card analytics-lock-wrap">
          <div class="<?php echo $lockGoals ? 'feature-lock-blur' : ''; ?>">
            <div class="kpi-title">Goals</div>
            <div id="reportsGoalsSummary"></div>
          </div>
          <?php if ($lockGoals): ?>
            <div class="feature-lock-overlay">
              <?php renderLockedFeatureBlock('Goals', 'Set KPI targets and get off-track warnings inside reports.', $goalsReqPlan, $goalsUpgradeUrl); ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div id="tab-ai" class="tab-panel">
      <div class="section">
        <div class="card feature-lock-card analytics-lock-wrap">
          <div class="<?php echo $lockAi ? 'feature-lock-blur' : ''; ?>">
            <div class="kpi-title">AI Summary</div>
            <div id="reportsAiSummary"></div>
          </div>
          <?php if ($lockAi): ?>
            <div class="feature-lock-overlay">
              <?php renderLockedFeatureBlock('AI Summary', 'Weekly AI insights limit reached. Upgrade to unlock more AI summaries in Reports.', $nextPlan, $aiUpgradeUrl); ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </main>

  <script src="<?php echo e(BASE_URL); ?>/assets/reports.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/reports.js'); ?>"></script>
</body>
</html>

