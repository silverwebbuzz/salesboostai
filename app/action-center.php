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
$planKey = (string)($entitlements['plan_key'] ?? 'free');

// Usage meters (shown in snapshot)
$aiLimit = (int)($limits['ai_insights_per_week'] ?? 1);
$aiUsage = sbm_usage_state($shop, 'ai_insights', $aiLimit);
$recoLimit = (int)($limits['recommendations_per_week'] ?? 1);
$recoUsage = sbm_usage_state($shop, 'recommendations', $recoLimit);

// Deep links
$reportsUrl = BASE_URL . '/reports?shop=' . urlencode($shop) . ($host !== '' ? ('&host=' . urlencode($host)) : '');
$analyticsUrl = BASE_URL . '/analytics?shop=' . urlencode($shop) . ($host !== '' ? ('&host=' . urlencode($host)) : '');

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php include __DIR__ . '/partials/app_bridge_first.php'; ?>
  <title>Action Center</title>
  <link rel="stylesheet" href="<?php echo e(BASE_URL); ?>/assets/styles.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/styles.css'); ?>">
</head>
<body>
  <main class="container">
    <?php include __DIR__ . '/nav.php'; ?>

    <div id="actionCenterNotice" class="card reports-notice is-hidden mb-12"></div>

    <div class="hero">
      <div class="hero-head">
        <div>
          <div class="hero-title">Action Center</div>
          <div class="hero-subtitle">Prioritized issues, recommendations, and weekly plan</div>
        </div>
        <div class="reports-controls">
          <div class="time-filters">
            <button type="button" class="btn btn-primary btn-sm ac-range active" data-range="7">7 days</button>
            <button type="button" class="btn btn-primary btn-sm ac-range" data-range="30">30 days</button>
            <button type="button" class="btn btn-primary btn-sm ac-range" data-range="90">90 days</button>
          </div>
          <button class="sb-kpi-why" type="button" id="btnAcExplainRevenue" data-ai-metric="revenue">Why did revenue change?</button>
          <a class="btn btn-primary btn-sm" href="<?php echo e($reportsUrl); ?>">Open Reports →</a>
        </div>
      </div>
      <div class="hero-subtitle"><?php echo e($storeName !== '' ? $storeName : $shop); ?> · Plan: <?php echo e(ucfirst($planKey)); ?></div>
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

    <div class="section action-center-focus">
      <div class="card">
        <div class="kpi-title">Today’s Focus</div>
        <div class="hero-subtitle" style="margin-top:6px;">Top actions based on your store’s latest signals.</div>
        <div id="acPriorityQueue" style="margin-top:14px;"></div>
      </div>

      <div class="card">
        <div class="kpi-title">This Week Snapshot</div>
        <div class="store-health-card" style="padding:0;border:0;box-shadow:none;background:transparent;margin-top:10px;">
          <div class="store-health-head">
            <div class="store-health-title" style="font-size:14px;">Store Health Score</div>
            <div class="store-health-status" id="acStoreHealthStatus">—</div>
          </div>
          <div class="store-health-score-wrap">
            <span class="store-health-score" id="acStoreHealthScoreValue">0</span>
            <span class="store-health-score-max">/100</span>
          </div>
          <div class="store-health-breakdown" id="acStoreHealthBreakdown"></div>
          <div class="store-health-issue-box" id="acStoreHealthIssue">Biggest Issue: —</div>
        </div>

        <div class="ac-snapshot" style="margin-top:10px;">
          <div class="SbListRow"><div class="sb-list-left">Critical issues</div><div class="sb-list-right" id="acCriticalCount">—</div></div>
          <div class="SbListRow"><div class="sb-list-left">Stockout risks</div><div class="sb-list-right" id="acStockoutCount">—</div></div>
        </div>

        <div style="height:10px;"></div>
        <div class="kpi-title">Usage</div>
        <div class="ac-snapshot" style="margin-top:10px;">
          <div class="SbListRow">
            <div class="sb-list-left">AI insights</div>
            <div class="sb-list-right">
              <?php if ($aiUsage['unlimited']): ?>
                <?php echo e((string)$aiUsage['used']); ?> (unlimited)
              <?php else: ?>
                <?php echo e((string)$aiUsage['used']); ?>/<?php echo e((string)$aiUsage['limit']); ?>
              <?php endif; ?>
            </div>
          </div>
          <div class="SbListRow">
            <div class="sb-list-left">Recommendations</div>
            <div class="sb-list-right">
              <?php if ($recoUsage['unlimited']): ?>
                <?php echo e((string)$recoUsage['used']); ?> (unlimited)
              <?php else: ?>
                <?php echo e((string)$recoUsage['used']); ?>/<?php echo e((string)$recoUsage['limit']); ?>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;">
          <button class="btn btn-primary btn-sm" type="button" onclick="acSwitchTab('alerts')">View Alerts</button>
          <button class="btn btn-primary btn-sm" type="button" onclick="acSwitchTab('recommendations')">View Recommendations</button>
          <a class="btn btn-primary btn-sm" href="<?php echo e($analyticsUrl); ?>">Open Analytics →</a>
        </div>
      </div>
    </div>

    <div class="section">
      <div class="tabs" id="acTabs">
        <button class="tab active" type="button" data-tab="overview">Overview</button>
        <button class="tab" type="button" data-tab="alerts">Alerts</button>
        <button class="tab" type="button" data-tab="recommendations">Recommendations</button>
        <button class="tab" type="button" data-tab="reports">Reports</button>
        <button class="tab" type="button" data-tab="history">History</button>
      </div>
    </div>

    <div id="ac-tab-overview" class="tab-panel active">
      <div class="section reports-grid">
        <div class="card critical-insights-card" style="padding:18px 20px;">
          <div class="critical-insights-head" style="margin-bottom:12px;">
            <div class="critical-insights-title-wrap">
              <span class="critical-insights-icon" aria-hidden="true">!</span>
              <div class="critical-insights-title" style="font-size:16px;">Critical Insights</div>
            </div>
          </div>
          <div id="acCriticalIssuesGrid"></div>
        </div>
        <div class="card">
          <div class="kpi-title">Next-best Actions</div>
          <div id="acActionsPreview"></div>
        </div>
      </div>
    </div>

    <div id="ac-tab-alerts" class="tab-panel">
      <div class="section reports-grid">
        <div class="card">
          <div class="kpi-title">Alerts Inbox</div>
          <div class="hero-subtitle" style="margin-top:6px;">Critical and warning alerts, grouped by urgency.</div>
          <div style="margin-top:12px;" id="acAlertsCritical"></div>
          <div style="height:12px;"></div>
          <div id="acAlertsWarning"></div>
        </div>
        <div class="card">
          <div class="kpi-title">Top Alert Themes</div>
          <div id="acAlertThemes"></div>
        </div>
      </div>
    </div>

    <div id="ac-tab-recommendations" class="tab-panel">
      <div class="section reports-grid">
        <div class="card">
          <div class="kpi-title">Recommendations</div>
          <div class="hero-subtitle" style="margin-top:6px;">Product bundles, upsells, and quick boosts based on your recent orders.</div>
          <div style="margin-top:12px;" id="acRecoFromReports"></div>
        </div>
        <div class="card">
          <div class="kpi-title">Quick Wins</div>
          <div id="acRecoQuickWins"></div>
        </div>
      </div>
    </div>

    <div id="ac-tab-reports" class="tab-panel">
      <div class="section reports-grid">
        <div class="card">
          <div class="kpi-title">Revenue</div>
          <ul class="report-list" id="acReportsRevenue"></ul>
          <div style="margin-top:12px;">
            <a class="btn btn-primary btn-sm" href="<?php echo e($reportsUrl); ?>&tab=revenue">Open Revenue Report →</a>
          </div>
        </div>
        <div class="card">
          <div class="kpi-title">Customers</div>
          <ul class="report-list" id="acReportsCustomers"></ul>
          <div style="margin-top:12px;">
            <a class="btn btn-primary btn-sm" href="<?php echo e($reportsUrl); ?>&tab=customers">Open Customer Report →</a>
          </div>
        </div>
      </div>
      <div class="section reports-grid">
        <div class="card">
          <div class="kpi-title">Inventory</div>
          <ul class="report-list" id="acReportsInventory"></ul>
          <div style="margin-top:12px;">
            <a class="btn btn-primary btn-sm" href="<?php echo e($reportsUrl); ?>&tab=inventory">Open Inventory Report →</a>
          </div>
        </div>
        <div class="card">
          <div class="kpi-title">Weekly Plan</div>
          <div class="hero-subtitle" style="margin-top:6px;">AI-generated weekly digest (cached).</div>
          <div id="acWeeklyDigest" style="margin-top:10px;"></div>
          <div style="height:12px;"></div>
          <div id="acWeeklyPlan"></div>
        </div>
      </div>
    </div>

    <div id="ac-tab-history" class="tab-panel">
      <div class="section reports-grid">
        <div class="card">
          <div class="kpi-title">Active (New / Viewed)</div>
          <div id="acHistoryActive"></div>
        </div>
        <div class="card">
          <div class="kpi-title">Acted</div>
          <div id="acHistoryActed"></div>
        </div>
      </div>
    </div>

  </main>

  <script src="<?php echo e(BASE_URL); ?>/assets/action-center.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/action-center.js'); ?>"></script>
</body>
</html>

