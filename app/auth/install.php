<?php
/**
 * Shopify OAuth installation entry point.
 *
 * Expected entry:
 *   /app/auth/install.php?shop=storename.myshopify.com
 *
 * March-19 style: single server redirect to Shopify OAuth (no App Bridge / no intermediate HTML).
 */

require_once __DIR__ . '/../config.php';

sendEmbeddedAppHeaders();
startEmbeddedSession();

$debug = SHOPIFY_DEBUG || (($_GET['debug'] ?? '') === '1');

$rawShop = $_GET['shop'] ?? null;
$shop    = sanitizeShopDomain($rawShop);

if ($shop === null) {
    http_response_code(400);
    echo 'Invalid shop parameter.';
    exit;
}

$state = bin2hex(random_bytes(16));
$_SESSION['nonce'] = $state;
$_SESSION['shop'] = $shop;

debugLog('[install] state created', [
    'shop' => $shop,
    'debug' => $debug,
]);

$installUrl = "https://{$shop}/admin/oauth/authorize?" . http_build_query([
    'client_id' => SHOPIFY_API_KEY,
    'scope' => SHOPIFY_SCOPES,
    'redirect_uri' => SHOPIFY_REDIRECT_URI,
    'state' => $state,
]);

if ($debug) {
    $installUrl .= (strpos($installUrl, '?') !== false ? '&' : '?') . 'debug=1';
}

debugLog('[install] redirect', [
    'shop' => $shop,
    'has_state' => strpos($installUrl, 'state=') !== false,
    'installUrl' => $installUrl,
]);

header('Location: ' . $installUrl);
exit;
