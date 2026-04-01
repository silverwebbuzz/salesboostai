<?php

/**
 * Shared bootstrap for embedded app pages.
 *
 * Must be included after `config.php` so helper functions/constants exist.
 */
function sbm_bootstrap_embedded(array $options = []): array
{
    $shopInvalidMessage = (string)($options['shopInvalidMessage'] ?? 'Missing or invalid shop parameter.');
    $includeEntitlements = (bool)($options['includeEntitlements'] ?? false);

    // Standard embedded header so Shopify App Bridge works in all surfaces.
    sendEmbeddedAppHeaders();
    require_once __DIR__ . '/logger.php';
    require_once __DIR__ . '/entitlements.php';

    $shop = sanitizeShopDomain($_GET['shop'] ?? null);
    $host = $_GET['host'] ?? '';
    $chargeId = isset($_GET['charge_id']) && is_string($_GET['charge_id']) ? trim($_GET['charge_id']) : '';

    // If Shopify returns with only charge_id, try best-effort shop recovery.
    if ($shop === null && $chargeId !== '') {
        try {
            $stmt = db()->prepare("SELECT shop FROM store_subscription WHERE shopify_charge_id = ? ORDER BY id DESC LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $chargeId);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                $stmt->close();
                $shop = sanitizeShopDomain(is_array($row) ? ($row['shop'] ?? null) : null);
            }
        } catch (Throwable $e) {
            sbm_log_write('billing', '[embedded_bootstrap] recover_shop_by_charge_failed', [
                'charge_id' => $chargeId,
                'error' => $e->getMessage(),
            ]);
        }
        if ($shop === null) {
            try {
                // Fallback for single-store environments.
                $resOne = db()->query("SELECT shop FROM stores WHERE status = 'installed' ORDER BY updated_at DESC LIMIT 2");
                if ($resOne) {
                    $rows = [];
                    while ($r = $resOne->fetch_assoc()) {
                        $rows[] = $r;
                    }
                    if (count($rows) === 1) {
                        $shop = sanitizeShopDomain((string)($rows[0]['shop'] ?? ''));
                        sbm_log_write('billing', '[embedded_bootstrap] recovered_shop_single_store', [
                            'charge_id' => $chargeId,
                            'shop' => $shop,
                        ]);
                    }
                }
            } catch (Throwable $e) {
                sbm_log_write('billing', '[embedded_bootstrap] recover_shop_single_store_failed', [
                    'charge_id' => $chargeId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    if ($shop === null) {
        sbm_log_write('billing', '[embedded_bootstrap] missing_shop', [
            'query' => $_GET,
            'charge_id' => $chargeId,
        ]);
        http_response_code(400);
        echo $shopInvalidMessage;
        exit;
    }

    $shopRecord = getShopByDomain($shop);
    if (!$shopRecord) {
        // Do NOT 302 to /auth/install from inside the embedded iframe: the iframe would load
        // install.php, App Bridge REMOTE to Shopify OAuth can hang, and merchants see a stuck
        // "Redirecting to Shopify…" page. Break out to top-level install entry instead.
        if (function_exists('sbm_log_write')) {
            sbm_log_write('auth', 'embedded_bootstrap_no_store_breakout_install', [
                'shop' => $shop,
                'host_present' => $host !== '',
            ]);
        }
        $installQuery = ['shop' => $shop];
        if (is_string($host) && $host !== '') {
            $installQuery['host'] = $host;
        }
        $installEntry = BASE_URL . '/auth/install?' . http_build_query($installQuery);

        header('Content-Type: text/html; charset=UTF-8');
        http_response_code(200);
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Install app</title>
  <script src="https://unpkg.com/@shopify/app-bridge@3"></script>
</head>
<body style="font-family:system-ui,sans-serif;padding:24px;">
  <p><strong>This app needs to be installed.</strong></p>
  <p>If you are not redirected automatically, click below.</p>
  <p><a id="sbContinueInstall" href="<?php echo htmlspecialchars($installEntry, ENT_QUOTES, 'UTF-8'); ?>" target="_top" style="display:inline-block;padding:10px 16px;background:#111827;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;">Continue to install</a></p>
  <script>
    (function () {
      var installEntry = <?php echo json_encode($installEntry); ?>;
      var host = <?php echo json_encode(is_string($host) ? $host : ''); ?>;
      try {
        if (window.__sbEmbedInstallBreakout) return;
        window.__sbEmbedInstallBreakout = true;
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
            Redirect.create(app).dispatch(Redirect.Action.REMOTE, installEntry);
            return;
          }
        }
      } catch (e1) {}
      try {
        if (window.top && window.top !== window) {
          window.top.location.href = installEntry;
        } else {
          window.location.href = installEntry;
        }
      } catch (e2) {
        window.location.href = installEntry;
      }
    })();
  </script>
</body>
</html>
        <?php
        exit;
    }

    // Managed Shopify pricing page can redirect directly back to app URL with charge_id.
    // Finalize billing here so subscription is updated even if /billing/confirm is skipped.
    if ($chargeId !== '' && function_exists('sbm_finalize_billing_charge')) {
        sbm_log_write('billing', '[embedded_bootstrap] finalize_attempt', [
            'shop' => $shop,
            'charge_id' => $chargeId,
            'host_present' => $host !== '',
        ]);
        try {
            $result = sbm_finalize_billing_charge($shop, $chargeId);
            sbm_log_write('billing', '[embedded_bootstrap] finalized_charge', [
                'shop' => $shop,
                'charge_id' => $chargeId,
                'result' => $result,
            ]);
        } catch (Throwable $e) {
            sbm_log_write('billing', '[embedded_bootstrap] finalize_failed', [
                'shop' => $shop,
                'charge_id' => $chargeId,
                'error' => $e->getMessage(),
            ]);
        }

        // Clean URL to avoid re-processing on refresh.
        $clean = $_GET;
        unset($clean['charge_id'], $clean['hmac'], $clean['signature']);
        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/dashboard';
        }
        $cleanQs = http_build_query($clean);
        header('Location: ' . $path . ($cleanQs !== '' ? ('?' . $cleanQs) : ''));
        exit;
    }

    if (!$includeEntitlements) {
        return [$shop, $host, $shopRecord];
    }

    $entitlements = function_exists('getPlanEntitlements') ? getPlanEntitlements($shop) : [
        'plan_key' => 'free',
        'plan_label' => 'Free',
        'features' => [],
        'limits' => [],
    ];

    return [$shop, $host, $shopRecord, $entitlements];
}

