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
     * Lightweight OAuth install path:
     * - Save auth/store basics only
     * - Do NOT create per-store tables
     * - Do NOT enqueue sync tasks
     * - Do NOT register webhooks here
     *
     * First dashboard view should be fast and show Sync box immediately.
     * Heavy/operational setup runs when merchant clicks Sync Now.
     */
    ensureGlobalAppSchema();

    // Best-effort shop details. If unavailable, still proceed with basic install.
    $shopDetails = [];
    try {
        $maybe = fetchShopDetails($shop, $accessToken);
        if (is_array($maybe)) {
            $shopDetails = $maybe;
        }
    } catch (Throwable $e) {
        $shopDetails = [];
    }
    upsertStore($shop, $accessToken, $host, $shopDetails);

    // Default subscription row on first install.
    ensureFreeSubscription($shop);

    // Register operational webhooks during callback (as requested).
    registerWebhooks($shop, $accessToken);
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