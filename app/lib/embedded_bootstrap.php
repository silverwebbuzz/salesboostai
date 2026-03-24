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

