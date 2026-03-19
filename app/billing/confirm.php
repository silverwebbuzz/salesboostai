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
if (!verifyHmac($params)) {
    http_response_code(400);
    echo 'Invalid HMAC';
    exit;
}

$shop = sanitizeShopDomain($_GET['shop'] ?? null);
$chargeId = $_GET['charge_id'] ?? null;

if ($shop === null || !is_string($chargeId) || $chargeId === '') {
    http_response_code(400);
    echo 'Missing parameters.';
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

$status = (string)($charge['status'] ?? '');
if ($status === 'accepted') {
    $activated = activateRecurringApplicationCharge($shop, $token, $chargeId);
    if (!is_array($activated)) {
        http_response_code(500);
        echo 'Unable to activate charge.';
        exit;
    }
    $billingOn = isset($activated['billing_on']) ? (string)$activated['billing_on'] : null;
    $currentPeriodEndsAt = $billingOn ? date('Y-m-d H:i:s', strtotime($billingOn)) : null;

    // Use whatever plan_key we stored as pending.
    $sub = getSubscriptionByShop($shop);
    $planKey = is_array($sub) ? (string)($sub['plan_key'] ?? 'free') : 'free';

    setSubscriptionPlan($shop, $planKey, 'active', (string)$chargeId, $currentPeriodEndsAt);
} elseif ($status === 'active') {
    // Already active, just reflect it in DB.
    $billingOn = isset($charge['billing_on']) ? (string)$charge['billing_on'] : null;
    $currentPeriodEndsAt = $billingOn ? date('Y-m-d H:i:s', strtotime($billingOn)) : null;
    $sub = getSubscriptionByShop($shop);
    $planKey = is_array($sub) ? (string)($sub['plan_key'] ?? 'free') : 'free';
    setSubscriptionPlan($shop, $planKey, 'active', (string)$chargeId, $currentPeriodEndsAt);
} else {
    // declined / cancelled / expired
    setSubscriptionPlan($shop, 'free', 'free', null, null);
}

// Redirect back into the embedded app.
$redirectUrl = "https://{$shop}/admin/apps/" . SHOPIFY_APP_HANDLE;
header('Location: ' . $redirectUrl);
exit;

