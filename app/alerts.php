<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/metrics.php';

require_once __DIR__ . '/lib/embedded_bootstrap.php';
require_once __DIR__ . '/lib/ui.php';
[$shop, $host, $shopRecord, $entitlements] = sbm_bootstrap_embedded(['includeEntitlements' => true]);

// Redirect to Action Center → Alerts tab. Page preserved for backwards compatibility.
$_redirectUrl = BASE_URL . '/action-center?tab=alerts&shop=' . urlencode($shop) . ($host !== '' ? ('&host=' . urlencode($host)) : '');
header('Location: ' . $_redirectUrl);
exit;

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$criticalAlerts = [];
$warningAlerts = [];
$infoAlerts = [];
$errorText = '';
$inventoryAgentId = 0;
$revenueAgentId = 0;
$productAgentId = 0;
$features = is_array($entitlements['features'] ?? null) ? $entitlements['features'] : [];
$lockInventoryAlerts = !((bool)($features['alerts_inventory'] ?? false));
$inventoryAlertsRequiredPlan = function_exists('getFeatureRequiredPlan') ? getFeatureRequiredPlan('alerts_inventory') : 'growth';
$inventoryAlertsUpgradeUrl = sbm_upgrade_url($shop, $host, $inventoryAlertsRequiredPlan);

$actionCenterUrl = BASE_URL . '/action-center.php?shop=' . urlencode($shop) . ($host !== '' ? ('&host=' . urlencode($host)) : '');

try {
    $alerts = sbm_getAlertsData($shop, $shopRecord, 180);
    $criticalAlerts = $alerts['criticalAlerts'] ?? [];
    $warningAlerts = $alerts['warningAlerts'] ?? [];
    $infoAlerts = $alerts['infoAlerts'] ?? [];
    $inventoryAgentId = (int)($alerts['inventoryAgentId'] ?? 0);
    $revenueAgentId = (int)($alerts['revenueAgentId'] ?? 0);
    $productAgentId = (int)($alerts['productAgentId'] ?? 0);
} catch (Throwable $e) {
    $errorText = 'Unable to load alerts right now.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php include __DIR__ . '/partials/app_bridge_first.php'; ?>
  <title>Alerts</title>
  <link rel="stylesheet" href="<?php echo e(BASE_URL); ?>/assets/styles.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/styles.css'); ?>">
</head>
<body>
  <main class="container">
    <?php include __DIR__ . '/nav.php'; ?>

    <div class="hero">
      <div class="hero-head">
        <div>
          <div class="hero-title">Alerts</div>
          <div class="hero-subtitle">Important issues and opportunities in your store</div>
        </div>
        <div class="reports-controls">
          <a class="btn btn-primary btn-sm" href="<?php echo e($actionCenterUrl); ?>">← Back to Action Center</a>
        </div>
      </div>
    </div>

    <?php
      $reportsInventoryUrl = BASE_URL . '/reports.php?tab=inventory&shop=' . urlencode($shop);
      if ($host !== '') $reportsInventoryUrl .= '&host=' . urlencode($host);
    ?>

    <div class="section">
      <div class="card">
        <div class="kpi-title">Inventory Forecasting & Actions</div>
        <div class="hero-subtitle" style="margin-top:6px;">For stockout forecasts, recommendations, and action tracking, use Reports → Inventory.</div>
        <div style="margin-top:12px;">
          <a class="btn btn-primary" href="<?php echo e($reportsInventoryUrl); ?>">Open Inventory Report →</a>
        </div>
      </div>
    </div>

    <?php if ($errorText !== ''): ?>
      <div class="section">
        <div class="card" style="border:1px solid #fecaca;background:#fef2f2;">
          <strong><?php echo e($errorText); ?></strong>
        </div>
      </div>
    <?php endif; ?>

    <?php
      function agentReportUrl(int $agentId, string $shop, string $host): string {
          if ($agentId <= 0) return '#';
          $url = BASE_URL . '/agent-report.php?agent_id=' . $agentId . '&shop=' . urlencode($shop);
          if ($host !== '') $url .= '&host=' . urlencode($host);
          return $url;
      }
      $inventoryDetailsUrl = agentReportUrl($inventoryAgentId, $shop, $host);
      $revenueDetailsUrl = agentReportUrl($revenueAgentId, $shop, $host);
      $productDetailsUrl = agentReportUrl($productAgentId, $shop, $host);
    ?>

    <?php if (!empty($criticalAlerts)): ?>
      <div class="section">
        <div class="section-title">🔴 Critical Alerts</div>
        <div class="hero-subtitle" style="margin-bottom:10px;">Serious issues. Immediate action needed.</div>
        <div class="alerts-grid">
          <?php foreach ($criticalAlerts as $alert): ?>
            <?php
              $detailsUrl = $inventoryDetailsUrl;
              $key = (string)($alert['details_url_key'] ?? '');
              if ($key === 'revenue') $detailsUrl = $revenueDetailsUrl;
              if ($key === 'inventory') $detailsUrl = $inventoryDetailsUrl;
              if ($key === 'product') $detailsUrl = $productDetailsUrl;
            ?>
            <?php $isInventoryLocked = $lockInventoryAlerts && $key === 'inventory'; ?>
            <div class="card alert-card alert-card-critical <?php echo $isInventoryLocked ? 'feature-lock-card' : ''; ?>">
              <div class="<?php echo $isInventoryLocked ? 'feature-lock-blur' : ''; ?>">
              <div class="alert-title"><?php echo e((string)($alert['title'] ?? 'Alert')); ?></div>
              <?php if (!empty($alert['meta'])): ?><div class="alert-meta"><?php echo e((string)$alert['meta']); ?></div><?php endif; ?>
              <div style="margin-top:12px;">
                <a class="btn btn-primary" href="<?php echo e($detailsUrl); ?>">View Details</a>
              </div>
              </div>
              <?php if ($isInventoryLocked): ?>
                <div class="feature-lock-overlay">
                  <?php renderLockedFeatureBlock(
                      'Inventory Alerts',
                      'Unlock inventory-specific alert intelligence and recommended fixes.',
                      $inventoryAlertsRequiredPlan,
                      $inventoryAlertsUpgradeUrl
                  ); ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($warningAlerts)): ?>
      <div class="section">
        <div class="section-title">🟡 Warnings</div>
        <div class="hero-subtitle" style="margin-bottom:10px;">Needs attention. Not urgent.</div>
        <div class="alerts-grid">
          <?php foreach ($warningAlerts as $alert): ?>
            <?php
              $detailsUrl = $inventoryDetailsUrl;
              $key = (string)($alert['details_url_key'] ?? '');
              if ($key === 'revenue') $detailsUrl = $revenueDetailsUrl;
              if ($key === 'inventory') $detailsUrl = $inventoryDetailsUrl;
              if ($key === 'product') $detailsUrl = $productDetailsUrl;
            ?>
            <?php $isInventoryLocked = $lockInventoryAlerts && $key === 'inventory'; ?>
            <div class="card alert-card alert-card-warning <?php echo $isInventoryLocked ? 'feature-lock-card' : ''; ?>">
              <div class="<?php echo $isInventoryLocked ? 'feature-lock-blur' : ''; ?>">
              <div class="alert-title"><?php echo e((string)($alert['title'] ?? 'Warning')); ?></div>
              <?php if (!empty($alert['meta'])): ?><div class="alert-meta"><?php echo e((string)$alert['meta']); ?></div><?php endif; ?>
              <?php if (!empty($alert['list']) && is_array($alert['list'])): ?>
                <ul class="report-list" style="margin-top:8px;">
                  <?php foreach ($alert['list'] as $item): ?>
                    <li><?php echo e((string)$item); ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
              <div style="margin-top:12px;">
                <a class="btn btn-primary" href="<?php echo e($detailsUrl); ?>">View Details</a>
              </div>
              </div>
              <?php if ($isInventoryLocked): ?>
                <div class="feature-lock-overlay">
                  <?php renderLockedFeatureBlock(
                      'Inventory Alerts',
                      'Unlock inventory-specific alert intelligence and recommended fixes.',
                      $inventoryAlertsRequiredPlan,
                      $inventoryAlertsUpgradeUrl
                  ); ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (empty($criticalAlerts) && empty($warningAlerts) && $errorText === ''): ?>
      <div class="section">
        <div class="card">
          <div class="sb-muted">✅ No critical issues detected</div>
        </div>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>
