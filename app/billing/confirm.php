<?php
/**
 * Shopify billing confirmation callback (REST RecurringApplicationCharge).
 *
 * Shopify redirects here after the merchant approves/declines the charge.
 * Expected:
 *   /app/billing/confirm?shop=...&charge_id=...&hmac=...
 */

require_once __DIR__ . '/../config.php';

sendEmbeddedAppHeaders();

$params = $_GET;
$chargeId = $_GET['charge_id'] ?? null;
$shop = sanitizeShopDomain($_GET['shop'] ?? null);

$hasHmac = isset($_GET['hmac']) && is_string($_GET['hmac']) && $_GET['hmac'] !== '';
$hmacValid = $hasHmac ? verifyHmac($params) : false;

if (!is_string($chargeId) || $chargeId === '') {
    http_response_code(400);
    echo 'Missing charge_id.';
    exit;
}

// Fallback path: some Shopify admin flows may not include `shop` here.
// Resolve shop from the pending/active subscription row by charge id.
if ($shop === null) {
    $stmtShop = db()->prepare(
        "SELECT shop FROM store_subscription WHERE shopify_charge_id = ? ORDER BY id DESC LIMIT 1"
    );
    if ($stmtShop) {
        $stmtShop->bind_param('s', $chargeId);
        $stmtShop->execute();
        $resShop = $stmtShop->get_result();
        $rowShop = $resShop ? $resShop->fetch_assoc() : null;
        $stmtShop->close();
        $shop = sanitizeShopDomain(is_array($rowShop) ? ($rowShop['shop'] ?? null) : null);
    }
}

if ($shop === null) {
    http_response_code(400);
    echo 'Missing shop parameter.';
    exit;
}

$store = getShopByDomain($shop);
$token = is_array($store) ? ($store['access_token'] ?? null) : null;
if (!is_string($token) || $token === '') {
    http_response_code(400);
    echo 'Missing access token for shop.';
    exit;
}

$charge = getRecurringApplicationCharge($shop, $token, $chargeId);
if (!is_array($charge)) {
    http_response_code(500);
    echo 'Unable to fetch charge.';
    exit;
}

// If HMAC is missing/invalid, still allow only when charge belongs to this callback.
$chargeIdFromApi = (string)($charge['id'] ?? '');
if (!$hmacValid && $chargeIdFromApi !== (string)$chargeId) {
    http_response_code(400);
    echo 'Invalid confirmation payload.';
    exit;
}

$status = (string)($charge['status'] ?? '');
$sub = getSubscriptionByShop($shop);
$fallbackPlanKey = is_array($sub) ? normalizePlanKey((string)($sub['plan_key'] ?? 'free')) : 'free';
$chargeName = strtolower(trim((string)($charge['name'] ?? '')));
$planKeyFromCharge = 'free';
if (strpos($chargeName, 'premium') !== false) {
    $planKeyFromCharge = 'premium';
} elseif (strpos($chargeName, 'growth') !== false) {
    $planKeyFromCharge = 'growth';
} elseif (strpos($chargeName, 'starter') !== false) {
    $planKeyFromCharge = 'starter';
}
$resolvedPlanKey = ($planKeyFromCharge !== 'free') ? $planKeyFromCharge : $fallbackPlanKey;

if ($status === 'accepted') {
    $activated = activateRecurringApplicationCharge($shop, $token, $chargeId);
    if (!is_array($activated)) {
        http_response_code(500);
        echo 'Unable to activate charge.';
        exit;
    }
    $billingOn = isset($activated['billing_on']) ? (string)$activated['billing_on'] : null;
    $currentPeriodEndsAt = $billingOn ? date('Y-m-d H:i:s', strtotime($billingOn)) : null;

    setSubscriptionPlan($shop, $resolvedPlanKey, 'active', (string)$chargeId, $currentPeriodEndsAt);
} elseif ($status === 'active') {
    // Already active, just reflect it in DB.
    $billingOn = isset($charge['billing_on']) ? (string)$charge['billing_on'] : null;
    $currentPeriodEndsAt = $billingOn ? date('Y-m-d H:i:s', strtotime($billingOn)) : null;
    setSubscriptionPlan($shop, $resolvedPlanKey, 'active', (string)$chargeId, $currentPeriodEndsAt);
} else {
    // declined / cancelled / expired
    setSubscriptionPlan($shop, 'free', 'free', null, null);
}

// Redirect back into the embedded app.
$redirectUrl = "https://{$shop}/admin/apps/" . SHOPIFY_APP_HANDLE;
header('Location: ' . $redirectUrl);
exit;

