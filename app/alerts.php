<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/metrics.php';

require_once __DIR__ . '/lib/embedded_bootstrap.php';
[$shop, $host, $shopRecord] = sbm_bootstrap_embedded();

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$criticalAlerts = [];
$warningAlerts = [];
$infoAlerts = [];
$errorText = '';
$inventoryAgentId = 0;
$revenueAgentId = 0;
$productAgentId = 0;

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
            <div class="card alert-card alert-card-critical">
              <div class="alert-title"><?php echo e((string)($alert['title'] ?? 'Alert')); ?></div>
              <?php if (!empty($alert['meta'])): ?><div class="alert-meta"><?php echo e((string)$alert['meta']); ?></div><?php endif; ?>
              <div style="margin-top:12px;">
                <a class="btn btn-primary" href="<?php echo e($detailsUrl); ?>">View Details</a>
              </div>
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
            <div class="card alert-card alert-card-warning">
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
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($infoAlerts)): ?>
      <div class="section">
        <div class="section-title">🔵 Info</div>
        <div class="alerts-grid">
          <?php foreach ($infoAlerts as $alert): ?>
            <?php
              $detailsUrl = $inventoryDetailsUrl;
              $key = (string)($alert['details_url_key'] ?? '');
              if ($key === 'revenue') $detailsUrl = $revenueDetailsUrl;
              if ($key === 'inventory') $detailsUrl = $inventoryDetailsUrl;
              if ($key === 'product') $detailsUrl = $productDetailsUrl;
            ?>
            <div class="card alert-card alert-card-info">
              <div class="alert-title"><?php echo e((string)($alert['title'] ?? 'Insight')); ?></div>
              <?php if (!empty($alert['meta'])): ?><div class="alert-meta"><?php echo e((string)$alert['meta']); ?></div><?php endif; ?>
              <div style="margin-top:12px;">
                <a class="btn btn-primary" href="<?php echo e($detailsUrl); ?>">View Details</a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (empty($criticalAlerts) && empty($warningAlerts) && empty($infoAlerts) && $errorText === ''): ?>
      <div class="section">
        <div class="card">
          <div class="sb-muted">✅ No critical issues detected</div>
        </div>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>
