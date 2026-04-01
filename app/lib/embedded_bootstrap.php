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
        header(
            'Location: ' .
            BASE_URL .
            '/auth/install?shop=' .
            urlencode($shop) .
            ($host ? '&host=' . urlencode($host) : '')
        );
        exit;
    }

    // If Shopify loads our embedded page without `host`, App Bridge session tokens can fail
    // (e.g. appTokenGenerate 502). Recover a previously stored host and redirect once to
    // a canonical URL that includes it.
    if (($host === '' || !is_string($host)) && is_array($shopRecord)) {
        $storedHost = (string)($shopRecord['host'] ?? '');
        if ($storedHost !== '' && (!isset($_GET['host']) || (string)($_GET['host'] ?? '') === '')) {
            $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
            if (!is_string($path) || $path === '') {
                $path = '/dashboard';
            }
            $clean = $_GET;
            $clean['shop'] = $shop;
            $clean['host'] = $storedHost;
            $qs = http_build_query($clean);
            header('Location: ' . $path . ($qs !== '' ? ('?' . $qs) : ''));
            exit;
        }
        if ($host === '' && $storedHost !== '') {
            $host = $storedHost;
        }
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

