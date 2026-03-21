<?php
/**
 * SalesBoost Shopify OAuth callback (SalesBoost structure, sapi-style nonce).
 */

require_once __DIR__ . '/../config.php';

sendEmbeddedAppHeaders();
startEmbeddedSession();
 
$debug = SHOPIFY_DEBUG || (($_GET['debug'] ?? '') === '1');

$params = $_GET;
if (!verifyHmac($params, SHOPIFY_API_SECRET)) die('Invalid HMAC');

// Validate nonce/state
/*$sessionNonce = $_SESSION['nonce'] ?? null;
$state = $_GET['state'] ?? null;
if (!is_string($sessionNonce) || $sessionNonce === '' || !is_string($state) || $state === '' || !hash_equals($sessionNonce, $state)) {
    http_response_code(400);
    die('Invalid state');
}*/

$shop = $_GET['shop'] ?? null;
$code = $_GET['code'] ?? null;
$host = $_GET['host'] ?? null;
if (!is_string($shop) || $shop === '' || !is_string($code) || $code === '') {
    http_response_code(400);
    die('Missing required parameters.');
}
if (!is_string($host) || $host === '') {
    $host = null;
}
// Shopify code for access token
$accessToken = exchangeCodeForAccessToken($shop, $code);
if (!is_string($accessToken) || $accessToken === '') {
    http_response_code(400);
    die('Failed to obtain access token.');
}

try {
    /*
     * OAuth install: set up all database structures and store metadata.
     * Intentionally NOT called here: fetchAndStoreInitialData() (no bulk orders/customers/products/inventory/analytics counts).
     * Merchants import that data via Dashboard → Sync Now (or cron calling runOneSyncStep).
     */
    ensureGlobalAppSchema();

    // Shop profile from Shopify (single GET shop.json — not order/customer/product lists).
    $shopDetails = fetchShopDetails($shop, $accessToken);
    upsertStore($shop, $accessToken, $host, $shopDetails);

    // Default subscription row on first install.
    ensureFreeSubscription($shop);

    // Register operational webhooks (orders, products, customers, uninstall, etc.).
    registerWebhooks($shop, $accessToken);

    // Per-store empty tables: order, customer, products_inventory, analytics (structure only).
    $tables = ensurePerStoreTables($shop);

    try {
        upsertAnalyticsMetric(db(), $tables['analytics'], 'install_initialized_at', date('c'), null);
    } catch (Throwable $e) {
        // non-blocking
    }

    // Queue sync tasks (rows in store_sync_state). Actual API fetch happens in sync runner / cron.
    enqueueFullSync($shop);
} catch (Throwable $e) {
    error_log('[shopify_callback] ' . $e->getMessage());
    http_response_code(500);
    echo $debug ? ('ERROR: ' . $e->getMessage()) : 'Server setup failed.';
    exit;
}

unset($_SESSION['nonce'], $_SESSION['shop']);

// Redirect to embedded app in Shopify Admin
$redirectUrl = "https://{$shop}/admin/apps/" . SHOPIFY_APP_HANDLE;
header('Location: ' . $redirectUrl);
exit;