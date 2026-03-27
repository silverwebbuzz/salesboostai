<?php
/**
 * SalesBoost Shopify OAuth callback (SalesBoost structure, sapi-style nonce).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/logger.php';

sendEmbeddedAppHeaders();
startEmbeddedSession();
 
$debug = SHOPIFY_DEBUG || (($_GET['debug'] ?? '') === '1');

$params = $_GET;
if (!verifyHmac($params)) die('Invalid HMAC');

// Validate nonce/state
/*$sessionNonce = $_SESSION['nonce'] ?? null;
$state = $_GET['state'] ?? null;
if (!is_string($sessionNonce) || $sessionNonce === '' || !is_string($state) || $state === '' || !hash_equals($sessionNonce, $state)) {
    http_response_code(400);
    die('Invalid state');
}*/

$shop = sanitizeShopDomain($_GET['shop'] ?? null);
$code = $_GET['code'] ?? null;
$host = $_GET['host'] ?? null;
if (!is_string($shop) || $shop === '' || !is_string($code) || $code === '') {
    http_response_code(400);
    die('Missing required parameters.');
}
if (!is_string($host) || $host === '') {
    $host = null;
}

// OAuth codes are single-use. Embedded flows can occasionally hit callback twice.
// Cache code usage in session and reuse stored token on duplicate callback requests.
$codeKey = 'oauth_code_used_' . hash('sha256', $shop . '|' . $code);
$codeWasUsed = !empty($_SESSION[$codeKey]);
$accessToken = null;
if ($codeWasUsed) {
    $existingStore = getShopByDomain($shop);
    $existingToken = is_array($existingStore) ? ($existingStore['access_token'] ?? null) : null;
    if (is_string($existingToken) && $existingToken !== '') {
        $accessToken = $existingToken;
        if (function_exists('sbm_log_write')) {
            sbm_log_write('auth', 'oauth_duplicate_callback_reused_existing_token', [
                'shop' => $shop,
            ]);
        }
    }
}

// Process-level lock to avoid double code exchange when callback is hit twice.
$codeLock = rtrim((string)sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR
    . 'sbm_oauth_code_'
    . hash('sha256', $shop . '|' . $code)
    . '.lock';
$lockAcquired = false;

if (!is_string($accessToken) || $accessToken === '') {
    $lockHandle = @fopen($codeLock, 'x');
    if (is_resource($lockHandle)) {
        $lockAcquired = true;
        fclose($lockHandle);
    } else {
        // Another request is already processing this code. Wait briefly and reuse token.
        for ($i = 0; $i < 30; $i++) {
            usleep(100000); // 100ms, total max wait ~3s
            $existingStore = getShopByDomain($shop);
            $existingToken = is_array($existingStore) ? ($existingStore['access_token'] ?? null) : null;
            if (is_string($existingToken) && $existingToken !== '') {
                $accessToken = $existingToken;
                if (function_exists('sbm_log_write')) {
                    sbm_log_write('auth', 'oauth_duplicate_callback_wait_reused_token', [
                        'shop' => $shop,
                    ]);
                }
                break;
            }
        }
    }

    if (!is_string($accessToken) || $accessToken === '') {
        // Shopify code for access token
        $accessToken = exchangeCodeForAccessToken($shop, $code);
        // If Shopify says "code already used", it usually means another callback already
        // exchanged successfully. Reuse token from DB instead of failing install.
        if (!is_string($accessToken) || $accessToken === '') {
            $existingStore = getShopByDomain($shop);
            $existingToken = is_array($existingStore) ? ($existingStore['access_token'] ?? null) : null;
            if (is_string($existingToken) && $existingToken !== '') {
                $accessToken = $existingToken;
                if (function_exists('sbm_log_write')) {
                    sbm_log_write('auth', 'oauth_exchange_failed_reused_existing_token', [
                        'shop' => $shop,
                    ]);
                }
            }
        }
    }
}
if (!is_string($accessToken) || $accessToken === '') {
    http_response_code(400);
    $oauthErr = isset($GLOBALS['sbm_oauth_last_error']) && is_array($GLOBALS['sbm_oauth_last_error'])
        ? $GLOBALS['sbm_oauth_last_error']
        : [];
    if (function_exists('sbm_log_write')) {
        sbm_log_write('auth', 'oauth_failed_to_obtain_access_token', [
            'shop' => $shop,
            'host_present' => is_string($host) && $host !== '',
            'oauth_error' => $oauthErr,
        ]);
    }
    $httpCode = (int)($oauthErr['http_code'] ?? 0);
    $resp = (string)($oauthErr['response'] ?? '');
    die(
        'Failed to obtain access token. '
        . 'HTTP=' . $httpCode
        . ($resp !== '' ? (' Response=' . $resp) : '')
        . ' | Check SHOPIFY_API_KEY/SHOPIFY_API_SECRET and callback URL in Partner Dashboard.'
    );
}
$_SESSION[$codeKey] = 1;
if ($lockAcquired && is_file($codeLock)) {
    @unlink($codeLock);
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
    sbm_log_write('app', '[shopify_callback] setup_failed', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo $debug ? ('ERROR: ' . $e->getMessage()) : 'Server setup failed.';
    exit;
}

unset($_SESSION['nonce'], $_SESSION['shop']);

// IMPORTANT (embedded apps):
// Never server-redirect to Shopify Admin URL from within an iframe.
// Use App Bridge redirect to break out to top-level.
$shopDomain = (string)$shop;
$shopHandle = explode('.', $shopDomain)[0] ?? '';
$adminUrl = 'https://admin.shopify.com/store/' . rawurlencode((string)$shopHandle)
    . '/apps/' . rawurlencode((string)SHOPIFY_APP_HANDLE)
    . '?shop=' . urlencode($shopDomain)
    . ($host ? ('&host=' . urlencode((string)$host)) : '');

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Redirecting…</title>
    <style>
      body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; padding: 24px; color: #111827; }
      .btn { display:inline-block; padding:12px 16px; border-radius:10px; background:#111827; color:#fff; text-decoration:none; font-weight:600; }
      .muted { color:#6b7280; margin-top:10px; font-size:13px; }
    </style>
    <script src="https://unpkg.com/@shopify/app-bridge@3"></script>
  </head>
  <body>
    <p>Redirecting back to Shopify…</p>
    <p><a id="btnContinue" class="btn" href="<?php echo htmlspecialchars($adminUrl, ENT_QUOTES); ?>">Continue</a></p>
    <p class="muted">If the redirect is blocked by the embedded frame, click Continue.</p>
    <script>
      (function () {
        var adminUrl = <?php echo json_encode($adminUrl); ?>;
        var host = <?php echo json_encode((string)($host ?? '')); ?>;
        // Avoid multiple redirects firing.
        try {
          if (window.__sbCallbackRedirected) return;
          window.__sbCallbackRedirected = true;
        } catch (e0) {}

        function go() {
          try {
            // Prefer App Bridge when embedded to avoid sandbox restrictions.
            var AppBridge = window['app-bridge'];
            if (AppBridge && host && window.top && window.top !== window) {
              var app = AppBridge.createApp({ apiKey: <?php echo json_encode(SHOPIFY_API_KEY); ?>, host: host, forceRedirect: true });
              if (AppBridge.actions && AppBridge.actions.Redirect) {
                var Redirect = AppBridge.actions.Redirect;
                Redirect.create(app).dispatch(Redirect.Action.REMOTE, adminUrl);
                return;
              }
            }
          } catch (e1) {}

          // Fallback: top-level navigation (may require user activation).
          try {
            if (window.top && window.top !== window) window.top.location.href = adminUrl;
            else window.location.href = adminUrl;
          } catch (e2) {
            window.location.href = adminUrl;
          }
        }

        go();
        var btn = document.getElementById('btnContinue');
        if (btn) btn.addEventListener('click', function (e) { e.preventDefault(); go(); });
      })();
    </script>
  </body>
</html>
<?php
exit;