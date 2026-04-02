<?php
/**
 * Shopify OAuth installation entry point.
 *
 * Expected entry:
 *   /app/auth/install.php?shop=storename.myshopify.com
 *
 * Aligns with newcode/install.php:
 * - Top-level: 302 to Shopify OAuth (fast path).
 * - embedded=1: small HTML + App Bridge (data-api-key) + redirects.toRemote / top fallback
 *   so OAuth runs outside the Admin iframe.
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

$hostParam = isset($_GET['host']) && is_string($_GET['host']) ? trim($_GET['host']) : '';
$embedded  = (int)($_GET['embedded'] ?? 0);

$state = bin2hex(random_bytes(16));
$_SESSION['nonce'] = $state;
$_SESSION['shop'] = $shop;

debugLog('[install] state created', [
    'shop' => $shop,
    'debug' => $debug,
    'embedded' => $embedded,
]);

$installUrl = function_exists('buildInstallUrl')
    ? buildInstallUrl($shop, $state)
    : ("https://{$shop}/admin/oauth/authorize?" . http_build_query([
        'client_id' => SHOPIFY_API_KEY,
        'scope' => SHOPIFY_SCOPES,
        'redirect_uri' => SHOPIFY_REDIRECT_URI,
        'state' => $state,
    ]));

if ($debug) {
    $installUrl .= (strpos($installUrl, '?') !== false ? '&' : '?') . 'debug=1';
}

// newcode: already installed and opened inside Admin iframe → app UI (avoid OAuth loop)
$existing = getShopByDomain($shop);
$hasToken = is_array($existing) && is_string($existing['access_token'] ?? null) && ($existing['access_token'] ?? '') !== '';
if ($hasToken && $embedded === 1 && $hostParam !== '') {
    $dash = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/') . '/dashboard?shop=' . urlencode($shop) . '&host=' . urlencode($hostParam);
    header('Location: ' . $dash);
    exit;
}

// newcode: embedded install must break out of iframe before OAuth
if ($embedded === 1) {
    header('Content-Type: text/html; charset=UTF-8');
    http_response_code(200);
    $apiKeyEsc = htmlspecialchars((string)SHOPIFY_API_KEY, ENT_QUOTES, 'UTF-8');
    $authJson  = json_encode($installUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<script src="https://cdn.shopify.com/shopifycloud/app-bridge.js" data-api-key="' . $apiKeyEsc . '"></script>';
    echo '<title>Installing…</title></head><body style="font-family:system-ui,sans-serif;padding:24px;">';
    echo '<p>Redirecting to Shopify for authorization…</p>';
    echo '<script>(function(){var authUrl=' . $authJson . ';function go(){try{';
    echo 'if(window.shopify&&shopify.redirects&&typeof shopify.redirects.toRemote==="function"){shopify.redirects.toRemote(authUrl);return;}';
    echo '}catch(e){}if(window.top&&window.top!==window){window.top.location.href=authUrl;return;}window.location.href=authUrl;}';
    echo 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",go);else go();})();</script>';
    echo '</body></html>';
    exit;
}

debugLog('[install] redirect', [
    'shop' => $shop,
    'has_state' => strpos($installUrl, 'state=') !== false,
    'installUrl' => $installUrl,
]);

header('Location: ' . $installUrl);
exit;
