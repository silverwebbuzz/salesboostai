<?php
/**
 * Shopify OAuth installation entry point.
 *
 * Expected entry:
 *   /app/auth/install.php?shop=storename.myshopify.com
 */

require_once __DIR__ . '/../config.php';
 
sendEmbeddedAppHeaders();
// Match sapi flow: use a nonce in session (with cross-subdomain cookie domain).
startEmbeddedSession();

$debug = SHOPIFY_DEBUG || (($_GET['debug'] ?? '') === '1');

$rawShop = $_GET['shop'] ?? null;
$shop    = sanitizeShopDomain($rawShop);
$host    = isset($_GET['host']) && is_string($_GET['host']) ? $_GET['host'] : '';

if ($shop === null) {
    http_response_code(400);
    echo 'Invalid shop parameter.';
    exit;
}

// Generate nonce and store in session (sapi style)
$state = bin2hex(random_bytes(16));
$_SESSION['nonce'] = $state;
$_SESSION['shop'] = $shop;

debugLog('[install] state created', [
    'shop' => $shop,
    'debug' => $debug,
]);

// Redirect to Shopify OAuth authorization URL (ensure state is always included)
$installUrl = "https://{$shop}/admin/oauth/authorize?" . http_build_query([
    'client_id' => SHOPIFY_API_KEY,
    'scope' => SHOPIFY_SCOPES,
    'redirect_uri' => SHOPIFY_REDIRECT_URI,
    'state' => $state,
]);

if ($debug) {
    // Preserve debug mode into callback for step-by-step traces.
    $installUrl .= (strpos($installUrl, '?') !== false ? '&' : '?') . 'debug=1';
}

debugLog('[install] redirect', [
    'shop' => $shop,
    'has_state' => strpos($installUrl, 'state=') !== false,
    'installUrl' => $installUrl,
]);

// Embedded-safe: break out to top-level for OAuth.
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Redirecting…</title>
  </head>
  <body>
    <p>Redirecting to Shopify…</p>
    <script>
      (function () {
        var url = <?php echo json_encode($installUrl); ?>;
        // Avoid multiple redirects firing in embedded contexts.
        try {
          if (window.__sbInstallRedirected) return;
          window.__sbInstallRedirected = true;
        } catch (e0) {}

        // Top-level navigation for OAuth (works in Shopify admin iframe).
        try {
          if (window.top && window.top !== window) {
            window.top.location.href = url;
          } else {
            window.location.href = url;
          }
        } catch (e2) {
          window.location.href = url;
        }
      })();
    </script>
  </body>
</html>
<?php
exit;

