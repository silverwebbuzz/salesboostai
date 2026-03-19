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

$shopName = (string)($shopRecord['store_name'] ?? $shop);

$errorText = '';
$topProducts = [];
$recommendationsByProduct = [];

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

                $title = (string)($li['title'] ?? '');
                if (trim($title) === '') $title = (string)($li['sku'] ?? '');
                $title = cleanProductTitle($title);
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
        </div>
        <div class="hero-subtitle"><?php echo e($shopName); ?></div>
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

        <?php if (empty($topProducts)): ?>
            <div class="card report-empty">
                <div class="section-title">Not enough order data yet</div>
                <div class="sb-muted">Once you have more orders, recommendations will appear here.</div>
            </div>
        <?php else: ?>
            <div class="agents-grid">
                <?php foreach ($topProducts as $productA): ?>
                    <?php
                        $recs = $recommendationsByProduct[$productA] ?? [];
                    ?>
                    <div class="card agent-card">
                        <div class="agent-title"><?php echo e($productA); ?></div>
                        <div class="sb-muted" style="margin-top:2px;">Customers also bought:</div>
                        <?php if (empty($recs)): ?>
                            <div class="sb-muted" style="margin-top:6px;">No recommendations yet.</div>
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

