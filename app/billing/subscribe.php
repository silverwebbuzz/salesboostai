<?php
/**
 * Start Shopify billing (REST RecurringApplicationCharge).
 *
 * URL (example):
 *   /app/billing/subscribe?shop=storename.myshopify.com&plan=starter
 *
 * This will create a recurring charge and redirect the merchant to Shopify's confirmation_url.
 */

require_once __DIR__ . '/../config.php';

sendEmbeddedAppHeaders();

$params = $_GET;
$hmac = $_GET['hmac'] ?? null;
// Allow in-app upgrade links that do not include HMAC.
// If HMAC is present, it must validate.
if (is_string($hmac) && $hmac !== '' && !verifyHmac($params)) {
    http_response_code(400);
    echo 'Invalid HMAC';
    exit;
}

$shop = sanitizeShopDomain($_GET['shop'] ?? null);
$plan = $_GET['plan'] ?? 'free';
if (!is_string($plan) || $plan === '') {
    $plan = 'free';
}
$plan = normalizePlanKey($plan);

if ($shop === null) {
    http_response_code(400);
    echo 'Invalid shop parameter.';
    exit;
}

$store = getShopByDomain($shop);
$token = is_array($store) ? ($store['access_token'] ?? null) : null;
if (!is_string($token) || $token === '') {
    http_response_code(400);
    echo 'Missing access token for shop.';
    exit;
}

// Define your plan catalog here (amount in shop currency).
// NOTE: Keep these aligned with your in-app pricing UI.
$plans = [
    'free' => ['name' => 'Free', 'price' => 0.00, 'trial_days' => 0],
    'starter' => ['name' => 'Starter', 'price' => 109.99, 'trial_days' => 7],
    'growth' => ['name' => 'Growth', 'price' => 319.99, 'trial_days' => 7],
    'premium' => ['name' => 'Premium', 'price' => 529.99, 'trial_days' => 7],
];

if (!isset($plans[$plan])) {
    http_response_code(400);
    echo 'Unknown plan.';
    exit;
}

if ($plan === 'free') {
    // If you want to downgrade to free, you typically cancel the active charge (optional).
    setSubscriptionPlan($shop, 'free', 'free', null, null);
    $redirectUrl = "https://{$shop}/admin/apps/" . SHOPIFY_APP_HANDLE;
    header('Location: ' . $redirectUrl);
    exit;
}

$returnUrl = rtrim(SHOPIFY_APP_URL, '/') . '/billing/confirm';

$charge = createRecurringApplicationCharge($shop, $token, [
    'name' => $plans[$plan]['name'],
    'price' => $plans[$plan]['price'],
    'trial_days' => $plans[$plan]['trial_days'],
    'return_url' => $returnUrl,
    'test' => SHOPIFY_DEBUG,
]);

if (!is_array($charge) || !isset($charge['confirmation_url'])) {
    http_response_code(500);
    echo 'Failed to create charge.';
    exit;
}

// Save pending charge id (so we know what plan they selected).
setSubscriptionPlan($shop, $plan, 'pending', (string)($charge['id'] ?? ''), null);

header('Location: ' . $charge['confirmation_url']);
exit;

