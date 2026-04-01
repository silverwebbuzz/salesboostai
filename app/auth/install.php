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

// Clean flow:
// - If we have `host` (embedded context), use App Bridge redirect.
// - Otherwise, do a normal server redirect (matches your previous flow).
if (!$host) {
    header('Location: ' . $installUrl);
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Redirecting…</title>
    <script src="https://unpkg.com/@shopify/app-bridge@3"></script>
  </head>
  <body>
    <p>Redirecting to Shopify…</p>
    <p style="margin-top:12px;"><a id="sbOauthTop" href="<?php echo htmlspecialchars($installUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_top" style="color:#2563eb;">Open Shopify install (if stuck)</a></p>
    <script>
      (function () {
        var url = <?php echo json_encode($installUrl); ?>;
        var host = <?php echo json_encode((string)$host); ?>;

        try {
          if (window.__sbInstallRedirected) return;
          window.__sbInstallRedirected = true;
        } catch (e0) {}

        try {
          var AppBridge = window['app-bridge'];
          if (AppBridge && host) {
            var app = AppBridge.createApp({
              apiKey: <?php echo json_encode(SHOPIFY_API_KEY); ?>,
              host: host,
              forceRedirect: true
            });
            if (AppBridge.actions && AppBridge.actions.Redirect) {
              var Redirect = AppBridge.actions.Redirect;
              Redirect.create(app).dispatch(Redirect.Action.REMOTE, url);
              return;
            }
          }
        } catch (e1) {}

        // Never load Shopify OAuth inside the admin iframe (CSP / X-Frame-Options).
        try {
          if (window.top && window.top !== window) {
            window.top.location.href = url;
            return;
          }
        } catch (e2) {}
        window.location.href = url;
      })();
    </script>
  </body>
</html>
<?php
exit;

