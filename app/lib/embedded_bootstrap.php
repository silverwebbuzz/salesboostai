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
    require_once __DIR__ . '/entitlements.php';

    $shop = sanitizeShopDomain($_GET['shop'] ?? null);
    $host = $_GET['host'] ?? '';

    if ($shop === null) {
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

    // Managed Shopify pricing page can redirect directly back to app URL with charge_id.
    // Finalize billing here so subscription is updated even if /billing/confirm is skipped.
    $chargeId = isset($_GET['charge_id']) && is_string($_GET['charge_id']) ? trim($_GET['charge_id']) : '';
    if ($chargeId !== '' && function_exists('sbm_finalize_billing_charge')) {
        try {
            $result = sbm_finalize_billing_charge($shop, $chargeId);
            if (function_exists('sbm_log_write')) {
                sbm_log_write('billing', '[embedded_bootstrap] finalized_charge', [
                    'shop' => $shop,
                    'charge_id' => $chargeId,
                    'result' => $result,
                ]);
            }
        } catch (Throwable $e) {
            if (function_exists('sbm_log_write')) {
                sbm_log_write('billing', '[embedded_bootstrap] finalize_failed', [
                    'shop' => $shop,
                    'charge_id' => $chargeId,
                    'error' => $e->getMessage(),
                ]);
            }
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

