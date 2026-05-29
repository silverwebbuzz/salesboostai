<?php
/**
 * SalesBoost Shopify OAuth callback (SalesBoost structure, sapi-style nonce).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/logger.php';

sendEmbeddedAppHeaders();
startEmbeddedSession();
 
$debug = SHOPIFY_DEBUG || (($_GET['debug'] ?? '') === '1');

// --- Callback debug breadcrumbs (must run before HMAC/state checks) ---
if (function_exists('sbm_log_write')) {
    sbm_log_write('auth', '[callback] received', [
        'query' => $_GET,
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'ua' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ]);
}
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err && function_exists('sbm_log_write')) {
        sbm_log_write('auth', '[callback] shutdown_error', ['error' => $err]);
    }
});

$params = $_GET;
if (!verifyHmac($params)) {
    if (function_exists('sbm_log_write')) {
        sbm_log_write('auth', '[callback] invalid_hmac', ['query' => $_GET]);
    }
    die('Invalid HMAC');
}

// newcode/callback.php: reject stale OAuth callbacks (when Shopify sends timestamp)
$ts = $_GET['timestamp'] ?? null;
if (is_string($ts) && $ts !== '' && ctype_digit($ts)) {
    if (abs(time() - (int)$ts) > 3600) {
        http_response_code(403);
        die('Request expired.');
    }
}

// State / CSRF: validate when both session nonce and query state are present (newcode pattern)
$sessionNonce = $_SESSION['nonce'] ?? null;
$state = $_GET['state'] ?? null;
if (is_string($sessionNonce) && $sessionNonce !== '' && is_string($state) && $state !== '') {
    if (!hash_equals($sessionNonce, $state)) {
        http_response_code(403);
        die('Invalid state');
    }
}

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

if (function_exists('sbm_log_write')) {
    sbm_log_write('auth', '[callback] token_exchanged', [
        'shop' => $shop,
        'host_present' => is_string($host) && $host !== '',
    ]);
}

/*
 * Lightweight OAuth install path:
 * - Save auth/store basics only (must succeed so app can authenticate)
 * - Do NOT create per-store tables (deferred until first sync)
 * - Do NOT enqueue sync tasks
 * - Do NOT register webhooks here (fired in background below)
 *
 * Each step is wrapped independently so a non-essential failure cannot
 * abort install with a "Server setup failed" page. Shopify App Review
 * (and merchants) must always be able to land in the embedded app once
 * OAuth completes successfully.
 */
try {
    ensureGlobalAppSchema();
} catch (Throwable $e) {
    sbm_log_write('auth', '[callback] ensure_global_schema_failed', [
        'shop' => $shop,
        'error' => $e->getMessage(),
    ]);
}

// Best-effort shop details. If unavailable, still proceed with basic install.
$shopDetails = [];
try {
    $maybe = fetchShopDetails($shop, $accessToken);
    if (is_array($maybe)) {
        $shopDetails = $maybe;
    }
} catch (Throwable $e) {
    sbm_log_write('auth', '[callback] fetch_shop_details_failed', [
        'shop' => $shop,
        'error' => $e->getMessage(),
    ]);
    $shopDetails = [];
}

// Determine whether this is a fresh install / reinstall (vs. a re-auth of an
// already-installed store, e.g. after a scope change). If the store was not
// previously installed-with-token, we must clear any stale sync progress so
// the data re-syncs. This is a defensive backstop in case the app/uninstalled
// webhook was missed or failed when the merchant removed the app.
$preExisting = getShopByDomain($shop);
$wasInstalledWithToken = is_array($preExisting)
    && (($preExisting['status'] ?? '') === 'installed')
    && is_string($preExisting['access_token'] ?? null)
    && trim((string)$preExisting['access_token']) !== '';

// Persist access token. This is the only step that truly must succeed:
// without it the embedded app cannot make API calls. Fall back to a
// minimal write if the full upsert fails (e.g. schema drift).
$tokenPersisted = false;
try {
    upsertStore($shop, $accessToken, $host, $shopDetails);
    $tokenPersisted = true;
} catch (Throwable $e) {
    sbm_log_write('auth', '[callback] upsert_store_full_failed', [
        'shop' => $shop,
        'error' => $e->getMessage(),
    ]);
    if (function_exists('upsertStoreMinimal')) {
        try {
            upsertStoreMinimal($shop, $accessToken, is_string($host) ? $host : null);
            $tokenPersisted = true;
            sbm_log_write('auth', '[callback] upsert_store_minimal_ok', ['shop' => $shop]);
        } catch (Throwable $e2) {
            sbm_log_write('auth', '[callback] upsert_store_minimal_failed', [
                'shop' => $shop,
                'error' => $e2->getMessage(),
            ]);
        }
    }
}

// Default subscription row on first install. Non-fatal.
try {
    ensureFreeSubscription($shop);
} catch (Throwable $e) {
    sbm_log_write('auth', '[callback] ensure_free_subscription_failed', [
        'shop' => $shop,
        'error' => $e->getMessage(),
    ]);
}

// Fresh install / reinstall: clear any stale sync progress so data re-syncs.
// Skipped for a re-auth of an already-installed store to avoid a redundant
// full re-sync. Non-fatal.
if (!$wasInstalledWithToken && function_exists('resetStoreSyncState')) {
    try {
        resetStoreSyncState($shop);
        sbm_log_write('auth', '[callback] sync_state_reset_for_reinstall', ['shop' => $shop]);
    } catch (Throwable $e) {
        sbm_log_write('auth', '[callback] sync_state_reset_failed', [
            'shop' => $shop,
            'error' => $e->getMessage(),
        ]);
    }
}

if (function_exists('sbm_log_write')) {
    sbm_log_write('auth', '[callback] token_saved', [
        'shop' => $shop,
        'host_present' => is_string($host) && $host !== '',
        'token_persisted' => $tokenPersisted,
    ]);
}

// Only return a fatal error if we could not persist the access token at all,
// because without it the embedded app cannot function. In every other case
// continue to the embedded app so the merchant (and Shopify App Review) can
// always reach the UI after OAuth completes.
if (!$tokenPersisted) {
    sbm_log_write('app', '[shopify_callback] token_persist_failed', [
        'shop' => $shop,
    ]);
    http_response_code(500);
    echo $debug
        ? 'ERROR: Unable to persist access token. Please verify database connectivity and the `stores` table schema.'
        : 'We could not finish setting up your store. Please reinstall the app or contact support.';
    exit;
}

unset($_SESSION['nonce'], $_SESSION['shop']);

// Fire-and-forget webhook registration in background.
// This keeps OAuth callback fast and avoids Shopify install timeouts.
try {
    $jobUrlBase = rtrim((string)(defined('BASE_URL') ? BASE_URL : SHOPIFY_APP_URL), '/');
    $ts = (string)time();
    $sig = hash_hmac('sha256', $shop . '|' . $ts, SHOPIFY_API_SECRET);
    $jobUrl = $jobUrlBase
        . '/jobs/register_webhooks'
        . '?shop=' . urlencode($shop)
        . ($host ? ('&host=' . urlencode((string)$host)) : '')
        . '&ts=' . urlencode($ts)
        . '&sig=' . urlencode($sig);

    if (function_exists('curl_init')) {
        $ch = curl_init($jobUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 1,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_NOSIGNAL => 1,
        ]);
        @curl_exec($ch);
        @curl_close($ch);
    } else {
        // Best-effort fallback; do not block callback.
        @file_get_contents($jobUrl, false, stream_context_create(['http' => ['timeout' => 1]]));
    }

    if (function_exists('sbm_log_write')) {
        sbm_log_write('auth', '[callback] webhook_job_fired', ['shop' => $shop]);
    }
} catch (Throwable $e) {
    if (function_exists('sbm_log_write')) {
        sbm_log_write('auth', '[callback] webhook_job_fire_failed', ['shop' => $shop, 'error' => $e->getMessage()]);
    }
}

// Redirect back into Shopify Admin embedded app URL (admin.shopify.com).
// Prefer `host` (base64url) from the callback so App Bridge can postMessage to the parent.
$appHandle = defined('SHOPIFY_APP_HANDLE') ? trim((string)SHOPIFY_APP_HANDLE) : '';
$adminAppUrl = '';

if (is_string($host) && $host !== '') {
    $decoded = base64_decode(strtr($host, '-_', '+/'), true);
    // Validate decoded value looks like a Shopify admin host (admin.shopify.com/store/...)
    if (is_string($decoded) && $decoded !== '' && strpos($decoded, 'admin.shopify.com') !== false) {
        if ($appHandle !== '') {
            $adminAppUrl = 'https://' . rtrim($decoded, '/') . '/apps/' . rawurlencode($appHandle);
        }
    }
}

if ($adminAppUrl === '' && $appHandle !== '') {
    // Fallback: build admin URL from shop domain store handle.
    $adminHandle = (string)explode('.', $shop)[0];
    $adminAppUrl = 'https://admin.shopify.com/store/' . rawurlencode($adminHandle) . '/apps/' . rawurlencode($appHandle);
}

// Last-resort fallback: redirect to the app's own URL directly.
// This ensures the merchant always lands somewhere useful even if SHOPIFY_APP_HANDLE is misconfigured.
if ($adminAppUrl === '') {
    $adminAppUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/') . '/dashboard';
}

// Keep `shop` and `host` on the URL so subsequent app loads preserve context.
$qs = ['shop' => $shop];
if (is_string($host) && $host !== '') {
    $qs['host'] = $host;
}
$adminAppUrl .= (strpos($adminAppUrl, '?') !== false ? '&' : '?') . http_build_query($qs);

header('Location: ' . $adminAppUrl);
exit;