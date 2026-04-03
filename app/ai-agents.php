<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/usage.php';
require_once __DIR__ . '/lib/ui.php';

require_once __DIR__ . '/lib/embedded_bootstrap.php';
[$shop, $host, $shopRecord, $entitlements] = sbm_bootstrap_embedded(['includeEntitlements' => true]);

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

function safeHtml(string $html): string {
    // Allow only simple formatting tags used in agent descriptions.
    return strip_tags($html, '<p><ul><li><strong><em><br>');
}

function formatTimeAgo(?string $timestamp): string {
    if (!$timestamp) return 'Not generated yet';
    $time = strtotime($timestamp);
    if (!$time) return 'Not generated yet';
    $diff = time() - $time;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) {
        $m = (int)floor($diff / 60);
        return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 86400) {
        $h = (int)floor($diff / 3600);
        return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
    }
    $d = (int)floor($diff / 86400);
    return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
}

$defaultAgents = [
    [
        'id' => 0,
        'name' => 'Sales Performance Agent',
        'description' => 'Analyzes revenue, orders, and AOV trends to highlight what is driving growth or decline.',
        'agent_key' => 'sales',
        'model' => 'default',
        'version' => 1,
        'is_premium' => 0,
        'output_schema' => '',
        'data_mapping' => '',
        'last_created_at' => '',
    ],
    [
        'id' => 0,
        'name' => 'Customer Retention Agent',
        'description' => 'Finds repeat purchase signals, retention gaps, and high-value customer opportunities.',
        'agent_key' => 'customers',
        'model' => 'default',
        'version' => 1,
        'is_premium' => 0,
        'output_schema' => '',
        'data_mapping' => '',
        'last_created_at' => '',
    ],
    [
        'id' => 0,
        'name' => 'Inventory Optimization Agent',
        'description' => 'Surfaces low-stock risks, dead-stock pressure, and inventory actions to protect revenue.',
        'agent_key' => 'inventory',
        'model' => 'default',
        'version' => 1,
        'is_premium' => 0,
        'output_schema' => '',
        'data_mapping' => '',
        'last_created_at' => '',
    ],
    [
        'id' => 0,
        'name' => 'Action Recommendations Agent',
        'description' => 'Generates prioritized next-best actions across products, customers, and promotions.',
        'agent_key' => 'actions',
        'model' => 'default',
        'version' => 1,
        'is_premium' => 1,
        'output_schema' => '',
        'data_mapping' => '',
        'last_created_at' => '',
    ],
];

$storeName = (string)($shopRecord['store_name'] ?? '');
$agents = [];
$dbError = '';
$planKey = (string)($entitlements['plan_key'] ?? 'free');
$limits = is_array($entitlements['limits'] ?? null) ? $entitlements['limits'] : [];
$aiLimit = (int)($limits['ai_insights_per_week'] ?? 1);
$aiUsage = sbm_usage_state($shop, 'ai_insights', $aiLimit);
$scheduleLimit = (int)($limits['report_schedules'] ?? 0);
$scheduleUsage = sbm_usage_state($shop, 'report_schedules', $scheduleLimit);
$features = is_array($entitlements['features'] ?? null) ? $entitlements['features'] : [];
$reportsScheduledEnabled = (bool)($features['reports_scheduled'] ?? false);

function nextPlanForUpgrade(string $planKey): string {
    $k = strtolower(trim($planKey));
    if ($k === 'free') return 'starter';
    if ($k === 'starter') return 'growth';
    return 'premium';
}
$nextPlan = nextPlanForUpgrade($planKey);
$upgradeUrl = sbm_upgrade_url($shop, $host, $nextPlan);

try {
    $mysqli = db();
    $sql = "
        SELECT
            a.id, a.name, a.description, a.agent_key, a.model, a.version, a.is_premium, a.output_schema, a.data_mapping,
            r.last_created_at
        FROM ai_agents a
        LEFT JOIN (
            SELECT agent_id, MAX(created_at) AS last_created_at
            FROM ai_reports
            WHERE shop = ? AND status = 'completed'
            GROUP BY agent_id
        ) r ON r.agent_id = a.id
        WHERE a.is_active = 1
        ORDER BY a.id ASC
    ";
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $shop);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $agents[] = [
                'id' => (int)($row['id'] ?? 0),
                'name' => (string)($row['name'] ?? ''),
                'description' => (string)($row['description'] ?? ''),
                'agent_key' => (string)($row['agent_key'] ?? ''),
                'model' => (string)($row['model'] ?? ''),
                'version' => (int)($row['version'] ?? 1),
                'is_premium' => (int)($row['is_premium'] ?? 0),
                'output_schema' => (string)($row['output_schema'] ?? ''),
                'data_mapping' => (string)($row['data_mapping'] ?? ''),
                'last_created_at' => (string)($row['last_created_at'] ?? ''),
            ];
        }
        $stmt->close();
    }
} catch (Throwable $e) {
    $dbError = 'Unable to load AI agents right now.';
}
if (empty($agents)) {
    $agents = $defaultAgents;
    if ($dbError === '') {
        $dbError = 'Using default AI agents. Add rows in `ai_agents` to customize agents and history.';
    }
}
$isEmbed = !empty($_GET['embed']) && $_GET['embed'] === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php include __DIR__ . '/partials/app_bridge_first.php'; ?>
  <title>AI Agents</title>
  <link rel="stylesheet" href="<?php echo e(BASE_URL); ?>/assets/styles.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/styles.css'); ?>">
  <?php if ($isEmbed): ?>
  <style>body{background:#f9fafb;} .container{padding-top:0;}</style>
  <?php endif; ?>
</head>
<body>
  <main class="container">
    <?php if (!$isEmbed): include __DIR__ . '/nav.php'; endif; ?>

    <?php if (!$isEmbed): ?>
    <div class="hero">
      <div class="hero-head">
        <div>
          <div class="hero-title">AI Agents</div>
          <div class="hero-subtitle">AI-powered insights to grow your store</div>
        </div>
      </div>
      <div class="hero-subtitle"><?php echo e($storeName !== '' ? $storeName : $shop); ?></div>
    </div>
    <?php endif; ?>

    <?php if ($dbError !== ''): ?>
      <div class="section">
        <div class="card" style="border:1px solid #fecaca;background:#fef2f2;">
          <strong><?php echo e($dbError); ?></strong>
        </div>
      </div>
    <?php endif; ?>

    <div class="section">
      <div class="hero-subtitle" style="margin-bottom:12px;">
        AI insights usage this week:
        <?php if ($aiUsage['unlimited']): ?>
          <strong><?php echo e((string)$aiUsage['used']); ?></strong> used (unlimited)
        <?php else: ?>
          <strong><?php echo e((string)$aiUsage['used']); ?></strong> / <strong><?php echo e((string)$aiUsage['limit']); ?></strong>
        <?php endif; ?>
      </div>
      <div class="hero-subtitle" style="margin-bottom:12px;">
        Scheduled digests:
        <?php if ($scheduleUsage['unlimited']): ?>
          <strong><?php echo e((string)$scheduleUsage['used']); ?></strong> active (unlimited)
        <?php else: ?>
          <strong><?php echo e((string)$scheduleUsage['used']); ?></strong> / <strong><?php echo e((string)$scheduleUsage['limit']); ?></strong>
        <?php endif; ?>
      </div>
      <?php if (empty($agents)): ?>
        <div class="card">
          <div class="sb-muted">No active agents found. Add records to `ai_agents` table.</div>
        </div>
      <?php else: ?>
        <div class="agents-grid">
          <?php foreach ($agents as $agent): ?>
            <?php
              $hasReport = (string)($agent['last_created_at'] ?? '') !== '';
              $statusLabel = $hasReport ? '🟢 Active' : '🟡 Not Generated';
              $statusClass = $hasReport ? 'status-positive' : 'status-medium';
              $lastUpdated = $hasReport ? ('Last updated: ' . formatTimeAgo((string)$agent['last_created_at'])) : 'Not generated yet';
            ?>
            <div class="card agent-card">
              <div class="agent-key-badge"><?php echo e(strtoupper($agent['agent_key'] !== '' ? $agent['agent_key'] : 'AGENT')); ?></div>
              <div class="agent-title"><?php echo e($agent['name'] !== '' ? $agent['name'] : ('Agent #' . $agent['id'])); ?></div>
              <div class="agent-desc">
                <?php
                  $desc = (string)($agent['description'] ?? '');
                  echo $desc !== '' ? safeHtml($desc) : e('No description available.');
                ?>
              </div>
              <div class="agent-meta-row">
                <span class="status-badge <?php echo e($statusClass); ?>"><?php echo e($statusLabel); ?></span>
                <?php if ((int)$agent['is_premium'] === 1): ?>
                  <span class="sb-pill-badge sb-pill-badge--purple">Premium</span>
                <?php endif; ?>
              </div>
              <div class="agent-updated"><?php echo e($lastUpdated); ?></div>
              <div class="agent-footer">
                <?php if (!$hasReport && $aiUsage['reached']): ?>
                  <a class="btn btn-primary" href="<?php echo e($upgradeUrl); ?>">Upgrade to <?php echo e(sbm_plan_label($nextPlan)); ?></a>
                <?php else: ?>
                  <?php
                    $aid = (int)($agent['id'] ?? 0);
                    $aKey = (string)($agent['agent_key'] ?? '');
                    $reportUrl = BASE_URL . '/agent-report?shop=' . urlencode($shop);
                    if ($aid > 0) {
                        $reportUrl .= '&agent_id=' . $aid;
                    } else {
                        $reportUrl .= '&agent_key=' . urlencode($aKey !== '' ? $aKey : 'sales');
                    }
                    if ($host !== '') $reportUrl .= '&host=' . urlencode($host);
                  ?>
                  <a class="btn btn-primary" href="<?php echo e($reportUrl); ?>">
                    <?php echo e($hasReport ? 'View Report' : 'Generate Report'); ?>
                  </a>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </main>

</body>
</html>
