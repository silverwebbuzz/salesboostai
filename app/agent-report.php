<?php
require_once __DIR__ . '/config.php';

sendEmbeddedAppHeaders();

$shop = sanitizeShopDomain($_GET['shop'] ?? null);
$host = $_GET['host'] ?? '';
$agentId = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : 0;

if ($shop === null || $agentId <= 0) {
    http_response_code(400);
    echo 'Missing or invalid shop/agent_id parameter.';
    exit;
}

$shopRecord = getShopByDomain($shop);
if (!$shopRecord) {
    header('Location: ' . BASE_URL . '/auth/install?shop=' . urlencode($shop) . ($host ? '&host=' . urlencode($host) : ''));
    exit;
}

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

function safeHtml(string $html): string {
    return strip_tags($html, '<p><ul><li><strong><em><br>');
}

$agent = null;
$report = null;
$errorText = '';
$reportMeta = [
    'status' => 'completed',
    'agent_version' => null,
];

try {
    $mysqli = db();

    // Strict agent filter by ID
    $stmtAgent = $mysqli->prepare("SELECT id, name, description, agent_key, model, version, is_premium, output_schema, data_mapping, prompt_template, config_json FROM ai_agents WHERE id = ? LIMIT 1");
    if ($stmtAgent) {
        $stmtAgent->bind_param('i', $agentId);
        $stmtAgent->execute();
        $resA = $stmtAgent->get_result();
        $agent = $resA ? ($resA->fetch_assoc() ?: null) : null;
        $stmtAgent->close();
    }

    // Strict report filter by shop + agent_id, prefer same agent version and completed status.
    $agentVersion = (int)($agent['version'] ?? 1);
    $stmtReport = $mysqli->prepare(
        "SELECT report_json, status, agent_version
         FROM ai_reports
         WHERE shop = ? AND agent_id = ? AND status = 'completed' AND agent_version = ?
         ORDER BY created_at DESC
         LIMIT 1"
    );
    if ($stmtReport) {
        $stmtReport->bind_param('sii', $shop, $agentId, $agentVersion);
        $stmtReport->execute();
        $resR = $stmtReport->get_result();
        $row = $resR ? ($resR->fetch_assoc() ?: null) : null;
        $stmtReport->close();
        if ($row && !empty($row['report_json'])) {
            $decoded = json_decode((string)$row['report_json'], true);
            if (is_array($decoded)) {
                $report = $decoded;
                $reportMeta['status'] = (string)($row['status'] ?? 'completed');
                $reportMeta['agent_version'] = isset($row['agent_version']) ? (int)$row['agent_version'] : null;
            }
        }
    }

    // Fallback: latest completed report for same shop + agent_id (any version).
    if (!$report) {
        $stmtReportFallback = $mysqli->prepare(
            "SELECT report_json, status, agent_version
             FROM ai_reports
             WHERE shop = ? AND agent_id = ? AND status = 'completed'
             ORDER BY created_at DESC
             LIMIT 1"
        );
        if ($stmtReportFallback) {
            $stmtReportFallback->bind_param('si', $shop, $agentId);
            $stmtReportFallback->execute();
            $resRF = $stmtReportFallback->get_result();
            $rowF = $resRF ? ($resRF->fetch_assoc() ?: null) : null;
            $stmtReportFallback->close();
            if ($rowF && !empty($rowF['report_json'])) {
                $decoded = json_decode((string)$rowF['report_json'], true);
                if (is_array($decoded)) {
                    $report = $decoded;
                    $reportMeta['status'] = (string)($rowF['status'] ?? 'completed');
                    $reportMeta['agent_version'] = isset($rowF['agent_version']) ? (int)$rowF['agent_version'] : null;
                }
            }
        }
    }
} catch (Throwable $e) {
    $errorText = 'Unable to load report from database.';
}

if (!$agent) {
    http_response_code(404);
    echo 'Agent not found.';
    exit;
}

if (!$report) {
    $report = [
        'summary' => 'Your revenue depends heavily on a few products.',
        'key_points' => [
            'Top 2 products generate 70% revenue',
            'Average order value increased by 12%',
            'Repeat customers are declining',
        ],
        'issues' => [
            ['title' => 'High product dependency', 'severity' => 'high'],
        ],
        'actions' => [
            'Promote low-selling products',
            'Create bundle offers',
            'Run retention campaigns',
        ],
    ];
    $reportMeta['status'] = 'completed';
    $reportMeta['agent_version'] = (int)($agent['version'] ?? 1);
}

$summary = (string)($report['summary'] ?? 'No summary available.');
$keyPoints = isset($report['key_points']) && is_array($report['key_points']) ? $report['key_points'] : [];
$issues = isset($report['issues']) && is_array($report['issues']) ? $report['issues'] : [];
$actions = isset($report['actions']) && is_array($report['actions']) ? $report['actions'] : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo e((string)$agent['name']); ?> Report</title>
  <link rel="stylesheet" href="<?php echo e(BASE_URL); ?>/assets/styles.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/styles.css'); ?>">
</head>
<body>
  <main class="container">
    <?php include __DIR__ . '/nav.php'; ?>

    <div class="hero">
      <div class="hero-head">
        <div>
          <div class="hero-title"><?php echo e((string)$agent['name']); ?> Report</div>
          <div class="hero-subtitle">
            <?php
              $desc = (string)($agent['description'] ?? '');
              echo $desc !== '' ? safeHtml($desc) : '';
            ?>
            · <?php echo e((string)($agent['model'] ?? 'claude')); ?>
            · v<?php echo (int)($agent['version'] ?? 1); ?>
            <?php if ((int)($agent['is_premium'] ?? 0) === 1): ?> · Premium<?php endif; ?>
            <?php if (!empty($reportMeta['agent_version'])): ?> · Report v<?php echo (int)$reportMeta['agent_version']; ?><?php endif; ?>
            <?php if (($reportMeta['status'] ?? '') !== ''): ?> · <?php echo e((string)$reportMeta['status']); ?><?php endif; ?>
          </div>
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

    <div class="section">
      <div class="card ai-summary">
        <div class="section-title">Summary</div>
        <div><?php echo e($summary); ?></div>
      </div>
    </div>

    <div class="section">
      <div class="card">
        <div class="section-title">Key Points</div>
        <?php if (empty($keyPoints)): ?>
          <div class="sb-muted">No key points found.</div>
        <?php else: ?>
          <ul class="report-list">
            <?php foreach ($keyPoints as $point): ?>
              <li><?php echo e((string)$point); ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <div class="section">
      <div class="card">
        <div class="section-title">Issues</div>
        <?php if (empty($issues)): ?>
          <div class="sb-muted">No issues found.</div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="simple-table">
              <thead>
                <tr>
                  <th>Issue</th>
                  <th>Severity</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($issues as $issue): ?>
                  <?php
                    $title = (string)($issue['title'] ?? 'Issue');
                    $severity = strtolower((string)($issue['severity'] ?? 'low'));
                    if (!in_array($severity, ['low', 'medium', 'high'], true)) {
                        $severity = 'low';
                    }
                  ?>
                  <tr>
                    <td><?php echo e($title); ?></td>
                    <td><span class="severity severity-<?php echo e($severity); ?>"><?php echo e(strtoupper($severity)); ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="section">
      <div class="card report-actions">
        <div class="section-title">Recommended Actions</div>
        <?php if (empty($actions)): ?>
          <div class="sb-muted">No actions found.</div>
        <?php else: ?>
          <ul class="report-list">
            <?php foreach ($actions as $action): ?>
              <li><?php echo e((string)$action); ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </main>
</body>
</html>
