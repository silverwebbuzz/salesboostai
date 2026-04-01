<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/metrics.php';
require_once __DIR__ . '/lib/usage.php';
$agentId = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : 0;
$agentKey = strtolower(trim((string)($_GET['agent_key'] ?? '')));
$demoMode = (isset($_GET['demo']) && (string)$_GET['demo'] === '1');

if ($agentId <= 0 && $agentKey === '') {
    http_response_code(400);
    echo 'Missing or invalid shop/agent_id parameter.';
    exit;
}

require_once __DIR__ . '/lib/embedded_bootstrap.php';
[$shop, $host, $shopRecord, $entitlements] = sbm_bootstrap_embedded([
    'shopInvalidMessage' => 'Missing or invalid shop/agent_id parameter.',
    'includeEntitlements' => true,
]);

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

function safeHtml(string $html): string {
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

function sbm_default_agent_by_key(string $key): ?array {
    $map = [
        'sales' => [
            'id' => 0, 'name' => 'Sales Performance Agent',
            'description' => 'Analyzes revenue, orders, and AOV trends to highlight what is driving growth or decline.',
            'agent_key' => 'sales', 'model' => 'default', 'version' => 1, 'is_premium' => 0,
        ],
        'customers' => [
            'id' => 0, 'name' => 'Customer Retention Agent',
            'description' => 'Finds repeat purchase signals, retention gaps, and high-value customer opportunities.',
            'agent_key' => 'customers', 'model' => 'default', 'version' => 1, 'is_premium' => 0,
        ],
        'inventory' => [
            'id' => 0, 'name' => 'Inventory Optimization Agent',
            'description' => 'Surfaces low-stock risks, dead-stock pressure, and inventory actions to protect revenue.',
            'agent_key' => 'inventory', 'model' => 'default', 'version' => 1, 'is_premium' => 0,
        ],
        'actions' => [
            'id' => 0, 'name' => 'Action Recommendations Agent',
            'description' => 'Generates prioritized next-best actions across products, customers, and promotions.',
            'agent_key' => 'actions', 'model' => 'default', 'version' => 1, 'is_premium' => 1,
        ],
    ];
    return $map[$key] ?? null;
}

function sbm_normalize_loaded_report($report): array {
    if (!is_array($report)) {
        return [
            'summary' => 'No report available.',
            'key_points' => [],
            'issues' => [],
            'actions' => [],
        ];
    }
    $summary = (string)($report['summary'] ?? '');
    $keyPoints = is_array($report['key_points'] ?? null) ? array_values($report['key_points']) : [];
    $issues = is_array($report['issues'] ?? null) ? array_values($report['issues']) : [];
    $actions = is_array($report['actions'] ?? null) ? array_values($report['actions']) : [];

    // Backward compatibility: summary may contain fenced/nested JSON.
    if ($summary !== '' && empty($keyPoints) && empty($issues) && empty($actions)) {
        $nested = trim($summary);
        $nested = preg_replace('/^\s*```(?:json)?\s*/i', '', $nested);
        $nested = preg_replace('/\s*```\s*$/', '', (string)$nested);
        $nestedDecoded = json_decode((string)$nested, true);
        if (is_array($nestedDecoded)) {
            $summary = (string)($nestedDecoded['summary'] ?? $summary);
            $keyPoints = is_array($nestedDecoded['key_points'] ?? null) ? array_values($nestedDecoded['key_points']) : [];
            $issues = is_array($nestedDecoded['issues'] ?? null) ? array_values($nestedDecoded['issues']) : [];
            $actions = is_array($nestedDecoded['actions'] ?? null) ? array_values($nestedDecoded['actions']) : [];
        }
    }

    return [
        'summary' => $summary !== '' ? $summary : 'AI generated report.',
        'key_points' => $keyPoints,
        'issues' => $issues,
        'actions' => $actions,
    ];
}

$agent = null;
$report = null;
$errorText = '';
$reportMeta = [
    'status' => 'not_generated',
    'agent_version' => null,
    'created_at' => null,
];
$limits = is_array($entitlements['limits'] ?? null) ? $entitlements['limits'] : [];
$aiLimit = (int)($limits['ai_insights_per_week'] ?? 1);
$aiUsage = sbm_usage_state($shop, 'ai_insights', $aiLimit);
$scheduleLimit = (int)($limits['report_schedules'] ?? 0);
$scheduleUsage = sbm_usage_state($shop, 'report_schedules', $scheduleLimit);
$features = is_array($entitlements['features'] ?? null) ? $entitlements['features'] : [];
$reportsScheduledEnabled = (bool)($features['reports_scheduled'] ?? false);

try {
    $mysqli = db();

    if ($agentId > 0) {
        $stmtAgent = $mysqli->prepare("SELECT id, name, description, agent_key, model, version, is_premium, output_schema, data_mapping, prompt_template, config_json FROM ai_agents WHERE id = ? LIMIT 1");
        if ($stmtAgent) {
            $stmtAgent->bind_param('i', $agentId);
            $stmtAgent->execute();
            $resA = $stmtAgent->get_result();
            $agent = $resA ? ($resA->fetch_assoc() ?: null) : null;
            $stmtAgent->close();
        }
    } elseif ($agentKey !== '') {
        $agent = sbm_default_agent_by_key($agentKey);
    }

    $agentVersion = (int)($agent['version'] ?? 1);
    $stmtReport = $agentId > 0 ? $mysqli->prepare(
        "SELECT report_json, status, agent_version, created_at
         FROM ai_reports
         WHERE shop = ? AND agent_id = ? AND status = 'completed' AND agent_version = ?
         ORDER BY created_at DESC
         LIMIT 1"
    ) : null;
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
                $reportMeta['created_at'] = (string)($row['created_at'] ?? '');
            }
        }
    }

    if (!$report && $agentId > 0) {
        $stmtReportFallback = $mysqli->prepare(
            "SELECT report_json, status, agent_version, created_at
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
                    $reportMeta['created_at'] = (string)($rowF['created_at'] ?? '');
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

$dummyReport = [
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

if (!$report && $demoMode) {
    if ($aiUsage['reached']) {
        $errorText = 'Weekly AI insights limit reached for your plan. Please upgrade to generate more reports.';
    } else {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $wk = (string)$aiUsage['week_key'];
        $usageMarkKey = $shop . ':' . (string)$agentId . ':' . $wk;
        if (!isset($_SESSION['sbm_ai_usage_mark'][$usageMarkKey])) {
            sbm_increment_weekly_usage($shop, 'ai_insights', 1, $wk);
            $_SESSION['sbm_ai_usage_mark'][$usageMarkKey] = 1;
            $aiUsage = sbm_usage_state($shop, 'ai_insights', $aiLimit);
        }
        $report = $dummyReport;
        $reportMeta['status'] = 'completed';
        $reportMeta['agent_version'] = (int)($agent['version'] ?? 1);
        $reportMeta['created_at'] = date('Y-m-d H:i:s');
    }
}

$hasReport = is_array($report);
$reportNormalized = sbm_normalize_loaded_report($report);
$summary = (string)($reportNormalized['summary'] ?? '');
$keyPoints = is_array($reportNormalized['key_points'] ?? null) ? $reportNormalized['key_points'] : [];
$issues = is_array($reportNormalized['issues'] ?? null) ? $reportNormalized['issues'] : [];
$actions = is_array($reportNormalized['actions'] ?? null) ? $reportNormalized['actions'] : [];

$isInventoryAgent = strtolower((string)($agent['agent_key'] ?? '')) === 'inventory';
$aiStatus = strtolower(trim((string)($_GET['ai_status'] ?? '')));
$aiStatusMsg = trim((string)($_GET['ai_msg'] ?? ''));
if (!in_array($aiStatus, ['ok', 'error'], true)) {
    $aiStatus = '';
}
$aiStatusClass = $aiStatus === 'ok' ? 'status-positive' : 'status-high';
$aiStatusTitle = $aiStatus === 'ok' ? 'AI call success' : 'AI call failed';
$inventoryInsights = [
    'low_stock' => [],
    'out_of_stock' => [],
    'velocity_counts' => ['fast' => 0, 'medium' => 0, 'slow' => 0, 'dead' => 0],
    'velocity_top' => ['fast' => [], 'medium' => [], 'slow' => [], 'dead' => []],
];

if ($isInventoryAgent) {
    try {
        $inventoryInsights = sbm_getInventoryInsights($shop, 180);
    } catch (Throwable $e) {
        // Silent fail: report page should still load.
    }
}
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
          <div class="hero-subtitle report-desc">
            <?php
              $desc = (string)($agent['description'] ?? '');
              echo $desc !== '' ? safeHtml($desc) : '';
            ?>
          </div>
          <div class="report-meta-row">
            <span>Last updated: <?php echo e($hasReport ? formatTimeAgo((string)($reportMeta['created_at'] ?? '')) : 'Not generated yet'); ?></span>
            <span class="status-badge <?php echo e($hasReport ? 'status-positive' : 'status-medium'); ?>">
              <?php echo e($hasReport ? '🟢 Completed' : '🟡 Not Generated'); ?>
            </span>
            <span>
              AI usage:
              <?php if ($aiUsage['unlimited']): ?>
                <?php echo e((string)$aiUsage['used']); ?> (unlimited)
              <?php else: ?>
                <?php echo e((string)$aiUsage['used']); ?>/<?php echo e((string)$aiUsage['limit']); ?>
              <?php endif; ?>
            </span>
            <span>
              Schedules:
              <?php if ($scheduleUsage['unlimited']): ?>
                <?php echo e((string)$scheduleUsage['used']); ?> (unlimited)
              <?php else: ?>
                <?php echo e((string)$scheduleUsage['used']); ?>/<?php echo e((string)$scheduleUsage['limit']); ?>
              <?php endif; ?>
            </span>
          </div>
        </div>
        <button class="btn btn-primary" type="button" id="generateAiBtn">Generate AI Report</button>
      </div>
    </div>

    <?php if ($aiStatus !== ''): ?>
      <div class="section">
        <div class="card" style="border:1px solid <?php echo $aiStatus === 'ok' ? '#86efac' : '#fecaca'; ?>;background:<?php echo $aiStatus === 'ok' ? '#f0fdf4' : '#fef2f2'; ?>;">
          <div class="agent-meta-row">
            <span class="status-badge <?php echo e($aiStatusClass); ?>"><?php echo e($aiStatusTitle); ?></span>
            <span><?php echo e($aiStatusMsg !== '' ? $aiStatusMsg : ($aiStatus === 'ok' ? 'Report generated successfully.' : 'Unable to generate report.')); ?></span>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="section">
      <div class="card">
        <div class="kpi-title">Saved Report & Digest</div>
        <div class="hero-subtitle" style="margin-top:6px;">Export this report and schedule recurring digests (feature rollout ready).</div>
        <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;">
          <button class="btn btn-primary" type="button" <?php echo $hasReport ? '' : 'disabled'; ?>>Export snapshot (coming soon)</button>
          <button class="btn btn-primary" type="button" <?php echo $reportsScheduledEnabled ? '' : 'disabled'; ?>>Schedule weekly digest (coming soon)</button>
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

    <?php if (!$hasReport): ?>
      <div class="section">
        <div class="card report-empty">
          <div class="section-title">No AI report generated yet</div>
          <div class="sb-muted">Generate your first AI insight to understand your store performance.</div>
          <div style="margin-top:14px;">
            <button class="btn btn-primary" type="button" id="generateAiBtnEmpty">Generate AI Report</button>
          </div>
        </div>
      </div>
    <?php else: ?>
      <div class="section">
        <div class="card summary-highlight">
          <div class="section-title">Summary</div>
          <div>⚠️ <?php echo e($summary); ?></div>
        </div>
      </div>

      <div class="section">
        <div class="card">
          <div class="section-title">Key Points</div>
          <?php if (empty($keyPoints)): ?>
            <div class="sb-muted">No key points found.</div>
          <?php else: ?>
            <ul class="report-list report-list-points">
              <?php foreach ($keyPoints as $point): ?>
                <li>→ <?php echo e((string)$point); ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>

      <div class="section">
        <div class="card">
          <div class="section-title">Issues</div>
          <?php if (empty($issues)): ?>
            <div class="sb-muted">No major issues detected</div>
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
          <div class="section-title">Recommended Actions (What you should do next)</div>
          <?php if (empty($actions)): ?>
            <div class="sb-muted">No actions found.</div>
          <?php else: ?>
            <ul class="report-list report-list-actions">
              <?php foreach ($actions as $action): ?>
                <li>✅ <?php echo e((string)$action); ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($isInventoryAgent): ?>
      <div class="section">
        <div class="card">
          <div class="section-title">Inventory Insights</div>

          <div class="inventory-insights-grid inventory-insights-grid--two-col">
            <div class="card inventory-insight-card">
              <div class="section-title">Stock Alerts</div>
              <div class="inventory-block-title">Low stock (Top 5)</div>
              <?php if (empty($inventoryInsights['low_stock'])): ?>
                <div class="sb-muted">No low stock products.</div>
              <?php else: ?>
                <ul class="report-list">
                  <?php foreach ($inventoryInsights['low_stock'] as $p): ?>
                    <li>
                      <span class="severity severity-medium">LOW</span>
                      <?php echo e((string)$p['title']); ?> (<?php echo (int)$p['inventory_quantity']; ?> left)
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>

              <div class="inventory-block-title" style="margin-top:12px;">Out of stock (Top 5)</div>
              <?php if (empty($inventoryInsights['out_of_stock'])): ?>
                <div class="sb-muted">No out-of-stock products.</div>
              <?php else: ?>
                <ul class="report-list">
                  <?php foreach ($inventoryInsights['out_of_stock'] as $p): ?>
                    <li>
                      <span class="severity severity-high">OUT</span>
                      <?php echo e((string)$p['title']); ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>

            <div class="card inventory-insight-card">
              <div class="section-title">Product Velocity (Last 30 days)</div>
              <div class="velocity-counters">
                <span class="severity severity-low">FAST: <?php echo (int)$inventoryInsights['velocity_counts']['fast']; ?></span>
                <span class="severity severity-medium">MEDIUM: <?php echo (int)$inventoryInsights['velocity_counts']['medium']; ?></span>
                <span class="severity" style="background:#eef2ff;color:#4338ca;">SLOW: <?php echo (int)$inventoryInsights['velocity_counts']['slow']; ?></span>
                <span class="severity severity-high">DEAD: <?php echo (int)$inventoryInsights['velocity_counts']['dead']; ?></span>
              </div>

              <div class="inventory-block-title">Fast moving</div>
              <?php if (empty($inventoryInsights['velocity_top']['fast'])): ?>
                <div class="sb-muted">No fast-moving products.</div>
              <?php else: ?>
                <ul class="report-list">
                  <?php foreach ($inventoryInsights['velocity_top']['fast'] as $p): ?>
                    <li><?php echo e((string)$p['title']); ?> (<?php echo e((string)$p['velocity']); ?>/day)</li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>

              <div class="inventory-block-title">Slow moving</div>
              <?php if (empty($inventoryInsights['velocity_top']['slow'])): ?>
                <div class="sb-muted">No slow-moving products.</div>
              <?php else: ?>
                <ul class="report-list">
                  <?php foreach ($inventoryInsights['velocity_top']['slow'] as $p): ?>
                    <li><?php echo e((string)$p['title']); ?> (<?php echo e((string)$p['velocity']); ?>/day)</li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>

              <div class="inventory-block-title">Dead products</div>
              <?php if (empty($inventoryInsights['velocity_top']['dead'])): ?>
                <div class="sb-muted">No dead products.</div>
              <?php else: ?>
                <ul class="report-list">
                  <?php foreach ($inventoryInsights['velocity_top']['dead'] as $p): ?>
                    <li><?php echo e((string)$p['title']); ?> (0/day)</li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </main>
  <script>
    (function () {
      var inlineStatus = document.createElement('div');
      inlineStatus.className = 'card';
      inlineStatus.style.display = 'none';
      inlineStatus.style.marginTop = '12px';
      inlineStatus.style.border = '1px solid #fecaca';
      inlineStatus.style.background = '#fef2f2';
      inlineStatus.style.padding = '10px';
      inlineStatus.id = 'aiCallInlineStatus';
      var hero = document.querySelector('.hero');
      if (hero && hero.parentNode) {
        hero.parentNode.insertBefore(inlineStatus, hero.nextSibling);
      }

      function setInlineStatus(type, msg) {
        var box = document.getElementById('aiCallInlineStatus');
        if (!box) return;
        var ok = type === 'ok';
        box.style.display = '';
        box.style.borderColor = ok ? '#86efac' : '#fecaca';
        box.style.background = ok ? '#f0fdf4' : '#fef2f2';
        box.innerHTML = '<strong>' + (ok ? 'AI call success:' : 'AI call failed:') + '</strong> ' + String(msg || '');
      }

      function wireGenerateButton(id) {
        var btn = document.getElementById(id);
        if (!btn) return;
        btn.addEventListener('click', async function () {
          if (btn.disabled) return;
          btn.disabled = true;
          btn.textContent = 'Analyzing your store...';
          try {
            var params = new URLSearchParams(window.location.search);
            var shop = params.get('shop') || '';
            var host = params.get('host') || '';
            var agentId = params.get('agent_id') || '';
            var agentKey = params.get('agent_key') || '';
            var qs = 'shop=' + encodeURIComponent(shop);
            if (host) qs += '&host=' + encodeURIComponent(host);
            if (agentId) qs += '&agent_id=' + encodeURIComponent(agentId);
            if (!agentId && agentKey) qs += '&agent_key=' + encodeURIComponent(agentKey);
            var doFetch = window.authFetch || fetch;
            var res = await doFetch('/app/api/ai/agent_run.php?' + qs, {
              method: 'POST',
              headers: { Accept: 'application/json' }
            });
            var data = await res.json();
            if (!res.ok || !data || !data.ok) {
              throw new Error((data && data.error) ? data.error : 'AI report generation failed');
            }
            var url = new URL(window.location.href);
            url.searchParams.delete('demo');
            url.searchParams.set('ai_status', 'ok');
            url.searchParams.set('ai_msg', (data && data.cached) ? 'Using latest report (no data changes detected).' : 'AI report generated successfully.');
            window.location.href = url.toString();
          } catch (e) {
            setInlineStatus('error', (e && e.message) ? e.message : 'AI report generation failed');
            btn.disabled = false;
            btn.textContent = 'Generate AI Report';
          }
        });
      }
      wireGenerateButton('generateAiBtn');
      wireGenerateButton('generateAiBtnEmpty');
    })();
  </script>
</body>
</html>
