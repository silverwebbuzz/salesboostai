<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/embedded_bootstrap.php';
require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/usage.php';
[$shop, $host, $shopRecord, $entitlements] = sbm_bootstrap_embedded(['includeEntitlements' => true]);

// Redirect to Action Center → Recommendations tab. Page preserved for backwards compatibility.
$_redirectUrl = BASE_URL . '/action-center?tab=recommendations&shop=' . urlencode($shop) . ($host !== '' ? ('&host=' . urlencode($host)) : '');
header('Location: ' . $_redirectUrl);
exit;

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function cleanProductTitle(string $title): string
{
    $t = trim($title);
    $t = preg_replace('/\s+/', ' ', $t);
    return $t !== '' ? $t : 'Unnamed product';
}

$infoMessage = 'Recommendations improve as more orders are placed.';

$shopName = (string)($shopRecord['store_name'] ?? $shop);

$errorText = '';
$topProducts = [];
$recommendationsByProduct = [];
$hasAnyRecommendations = false;
$planKey = (string)($entitlements['plan_key'] ?? 'free');
$limits = is_array($entitlements['limits'] ?? null) ? $entitlements['limits'] : [];
$recoLimit = (int)($limits['recommendations_per_week'] ?? 1);
$recoUsage = sbm_usage_state($shop, 'recommendations', $recoLimit);

function nextPlanForRecommendation(string $planKey): string {
    $k = strtolower(trim($planKey));
    if ($k === 'free') return 'starter';
    if ($k === 'starter') return 'growth';
    return 'premium';
}
$nextRecoPlan = nextPlanForRecommendation($planKey);
$recoUpgradeUrl = sbm_upgrade_url($shop, $host, $nextRecoPlan);

$actionCenterUrl = BASE_URL . '/action-center.php?shop=' . urlencode($shop) . ($host !== '' ? ('&host=' . urlencode($host)) : '');

try {
    $mysqli = db();
    $shopNameSafe = makeShopName($shop);
    $ordersTable = perStoreTableName($shopNameSafe, 'order');

    // Performance: scan a bounded number of recent orders.
    $orderScanLimit = 1500;

    $stmt = $mysqli->prepare(
        "SELECT payload_json
         FROM `{$ordersTable}`
         ORDER BY COALESCE(created_at, fetched_at) DESC
         LIMIT ?"
    );

    if (!$stmt) {
        throw new Exception('Unable to prepare orders query.');
    }

    $stmt->bind_param('i', $orderScanLimit);
    $stmt->execute();
    $res = $stmt->get_result();

    $productCounts = [];      // [productTitle] => times appeared (order-level)
    $orderProductLists = [];  // list of productTitle arrays per order (unique titles per order)

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $payload = json_decode((string)($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) continue;

            $lineItems = isset($payload['line_items']) && is_array($payload['line_items']) ? $payload['line_items'] : [];
            if (empty($lineItems)) continue;

            // Build unique product list for this order (ignore quantity for pair building).
            $uniqueSet = [];
            foreach ($lineItems as $li) {
                if (!is_array($li)) continue;
                $qty = (int)($li['quantity'] ?? 0);
                if ($qty <= 0) continue;

                // Prefer common Shopify line-item product fields first.
                $titleRaw = (string)($li['product_title'] ?? $li['name'] ?? $li['title'] ?? $li['sku'] ?? '');
                $title = cleanProductTitle($titleRaw);
                if ($title === 'Unnamed product') continue;
                $uniqueSet[$title] = true;
            }

            $uniqueTitles = array_keys($uniqueSet);
            if (count($uniqueTitles) === 0) continue;

            $orderProductLists[] = $uniqueTitles;

            // Track overall product popularity (order-level appearance).
            foreach ($uniqueTitles as $pt) {
                $productCounts[$pt] = ($productCounts[$pt] ?? 0) + 1;
            }
        }
    }

    if (!empty($productCounts)) {
        arsort($productCounts); // desc by count
        $topProducts = array_slice(array_keys($productCounts), 0, 20);
        $topSet = array_flip($topProducts); // for fast membership checks

        // Compute pair frequencies only for A in top products.
        $pairCounts = []; // [productA][productB] => count

        foreach ($orderProductLists as $productsInOrder) {
            if (count($productsInOrder) < 2) continue;

            // For each A in top products that exists in this order:
            foreach ($productsInOrder as $a) {
                if (!isset($topSet[$a])) continue;

                foreach ($productsInOrder as $b) {
                    if ($b === $a) continue;
                    if (!isset($pairCounts[$a])) $pairCounts[$a] = [];
                    $pairCounts[$a][$b] = ($pairCounts[$a][$b] ?? 0) + 1;
                }
            }
        }

        // Pick top 3 recommendations for each product.
        foreach ($topProducts as $a) {
            $recs = $pairCounts[$a] ?? [];
            if (!empty($recs)) {
                arsort($recs);
                $recommendationsByProduct[$a] = array_slice(array_keys($recs), 0, 3);
            } else {
                $recommendationsByProduct[$a] = [];
            }
        }

        // Global empty state trigger: no cross-sell suggestions available for any top product.
        foreach ($topProducts as $productA) {
            if (!empty($recommendationsByProduct[$productA])) {
                $hasAnyRecommendations = true;
                break;
            }
        }
    }

    $stmt->close();
} catch (Throwable $e) {
    $errorText = 'Unable to generate sales recommendations right now.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include __DIR__ . '/partials/app_bridge_first.php'; ?>
    <title>Sales Boost</title>
    <link rel="stylesheet" href="<?php echo e(BASE_URL); ?>/assets/styles.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/styles.css'); ?>">
</head>
<body>
<main class="container">
    <?php include __DIR__ . '/nav.php'; ?>

    <div class="hero">
        <div class="hero-head">
            <div>
                <div class="hero-title">Sales Boost</div>
                <div class="hero-subtitle">Increase revenue with smart product recommendations</div>
            </div>
            <div class="reports-controls">
                <a class="btn btn-primary btn-sm" href="<?php echo e($actionCenterUrl); ?>">← Back to Action Center</a>
            </div>
        </div>
        <div class="hero-subtitle"><?php echo e($shopName); ?></div>
    </div>

    <div class="section" style="margin-top:-12px;">
        <div class="card" style="border:1px solid #e0e7ff;background:#f8fafc;">
            <div class="hero-subtitle" style="margin:0;"><?php echo e($infoMessage); ?></div>
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
        <div class="section-title">Top product recommendations</div>
        <div class="card sb-usage-meta">
            <div class="kpi-title">Usage</div>
            <div class="sb-usage-meta__row">
                Recommendations this week:
                <?php if ($recoUsage['unlimited']): ?>
                    <strong><?php echo e((string)$recoUsage['used']); ?></strong> used (unlimited)
                <?php else: ?>
                    <strong><?php echo e((string)$recoUsage['used']); ?></strong> / <strong><?php echo e((string)$recoUsage['limit']); ?></strong>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($recoUsage['reached']): ?>
            <div class="card feature-lock-card">
                <div class="feature-lock-overlay-inner" style="max-width:520px;margin:0 auto;">
                    <?php renderLockedFeatureBlock(
                        'Recommendations quota reached',
                        'You reached this week\'s recommendation limit. Upgrade to continue.',
                        $nextRecoPlan,
                        $recoUpgradeUrl
                    ); ?>
                </div>
            </div>
        <?php elseif (empty($topProducts) || !$hasAnyRecommendations): ?>

            <div class="card report-empty">
                <div style="font-size:34px;line-height:1;margin-bottom:8px;">📦</div>
                <div class="section-title" style="margin-bottom:6px;">No recommendations available yet</div>

                <div class="hero-subtitle" style="margin-top:2px;color:#6b7280;">
                    Data status: Low (not enough order data yet)
                </div>

                <div class="sb-muted" style="margin-top:10px;">
                    We need more order data to generate cross-sell suggestions.
                </div>

                <div class="hero-subtitle" style="margin-top:10px;color:#6b7280;">
                    To get recommendations faster:
                </div>
                <ul class="report-list" style="margin:10px auto 0;max-width:420px;">
                    <li>Encourage customers to buy multiple products</li>
                    <li>Create product bundles or combos</li>
                    <li>Run promotions on related products</li>
                </ul>

                <div class="hero-subtitle" style="margin-top:14px;color:#6b7280;">
                    Example:
                </div>
                <div class="card" style="margin:10px auto 0;max-width:420px;padding:14px;border:1px solid #e5e7eb;background:#ffffff;box-shadow:none;">
                    <div style="font-weight:700;color:#111827;margin-bottom:6px;">T-Shirt</div>
                    <div class="hero-subtitle" style="margin:0 0 6px;color:#6b7280;">Customers also bought:</div>
                    <ul class="report-list" style="margin:0;padding-left:18px;">
                        <li>Jeans</li>
                        <li>Sneakers</li>
                        <li>Cap</li>
                    </ul>
                </div>

                <div class="hero-subtitle" style="margin-top:14px;color:#6b7280;">
                    Works best when your store has 50+ orders
                </div>
            </div>
        <?php else: ?>
            <?php
                if (session_status() !== PHP_SESSION_ACTIVE) {
                    @session_start();
                }
                $wk = (string)$recoUsage['week_key'];
                $usageMarkKey = $shop . ':sales_boost:' . $wk;
                if (!isset($_SESSION['sbm_reco_usage_mark'][$usageMarkKey])) {
                    sbm_increment_weekly_usage($shop, 'recommendations', 1, $wk);
                    $_SESSION['sbm_reco_usage_mark'][$usageMarkKey] = 1;
                    $recoUsage = sbm_usage_state($shop, 'recommendations', $recoLimit);
                }
            ?>
            <div class="agents-grid">
                <?php foreach ($topProducts as $productA): ?>
                    <?php $recs = $recommendationsByProduct[$productA] ?? []; ?>
                    <div class="card agent-card">
                        <div class="agent-title"><?php echo e($productA); ?></div>
                        <div class="sb-muted" style="margin-top:2px;">Customers also bought:</div>
                        <?php if (empty($recs)): ?>
                            <div class="sb-muted" style="margin-top:6px;">Not enough data yet</div>
                        <?php else: ?>
                            <ul class="report-list" style="margin-top:10px;">
                                <?php foreach ($recs as $b): ?>
                                    <li><?php echo e($b); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>

