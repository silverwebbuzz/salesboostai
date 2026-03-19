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

function safeHtml(string $html): string {
    // Allow only simple formatting tags used in agent descriptions.
    return strip_tags($html, '<p><ul><li><strong><em><br>');
}

$storeName = (string)($shopRecord['store_name'] ?? '');
$agents = [];
$dbError = '';

try {
    $mysqli = db();
    $res = $mysqli->query("SELECT id, name, description, agent_key, model, version, is_premium, output_schema, data_mapping FROM ai_agents WHERE is_active = 1 ORDER BY id ASC");
    if ($res) {
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
            ];
        }
    }
} catch (Throwable $e) {
    $dbError = 'Unable to load AI agents right now.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>AI Agents</title>
  <link rel="stylesheet" href="<?php echo e(BASE_URL); ?>/assets/styles.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/styles.css'); ?>">
</head>
<body>
  <main class="container">
    <?php include __DIR__ . '/nav.php'; ?>

    <div class="hero">
      <div class="hero-head">
        <div>
          <div class="hero-title">AI Agents</div>
          <div class="hero-subtitle">AI-powered insights to grow your store</div>
        </div>
      </div>
      <div class="hero-subtitle"><?php echo e($storeName !== '' ? $storeName : $shop); ?></div>
    </div>

    <?php if ($dbError !== ''): ?>
      <div class="section">
        <div class="card" style="border:1px solid #fecaca;background:#fef2f2;">
          <strong><?php echo e($dbError); ?></strong>
        </div>
      </div>
    <?php endif; ?>

    <div class="section">
      <div class="section-title">Active Agents</div>
      <?php if (empty($agents)): ?>
        <div class="card">
          <div class="sb-muted">No active agents found. Add records to `ai_agents` table.</div>
        </div>
      <?php else: ?>
        <div class="agents-grid">
          <?php foreach ($agents as $agent): ?>
            <div class="card agent-card">
              <div class="kpi-title"><?php echo e($agent['agent_key'] !== '' ? $agent['agent_key'] : 'Agent'); ?></div>
              <div class="agent-title"><?php echo e($agent['name'] !== '' ? $agent['name'] : ('Agent #' . $agent['id'])); ?></div>
              <div class="agent-desc">
                <?php
                  $desc = (string)($agent['description'] ?? '');
                  echo $desc !== '' ? safeHtml($desc) : e('No description available.');
                ?>
              </div>
              <div class="agent-footer">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                  <span class="SbBadge"><?php echo e($agent['model'] !== '' ? $agent['model'] : 'claude'); ?></span>
                  <span class="SbBadge">v<?php echo (int)$agent['version']; ?></span>
                  <?php if ((int)$agent['is_premium'] === 1): ?>
                    <span class="sb-pill-badge sb-pill-badge--purple">Premium</span>
                  <?php endif; ?>
                </div>
                <a
                  class="btn btn-primary"
                  href="<?php echo e(BASE_URL); ?>/agent-report.php?agent_id=<?php echo (int)$agent['id']; ?>&shop=<?php echo urlencode($shop); ?><?php if ($host !== ''): ?>&host=<?php echo urlencode($host); ?><?php endif; ?>"
                >View Report</a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
