<?php

/**
 * Shared Shopify webhook utilities.
 */
require_once __DIR__ . '/logger.php';

if (!function_exists('webhookLog')) {
    function webhookLog($message): void
    {
        if (is_array($message)) {
            sbm_log_write('webhooks', 'webhook', $message);
            return;
        }
        sbm_log_write('webhooks', (string)$message);
    }
}

if (!function_exists('validateWebhookRequest')) {
    /**
     * Validates incoming Shopify webhook and returns parsed payload + headers.
     *
     * @return array{data:array,shop:string,topic:string,webhook_id:string}
     */
    function validateWebhookRequest(): array
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            http_response_code(405);
            echo 'Method Not Allowed';
            exit;
        }

        // Read raw body exactly once.
        $rawBody = file_get_contents('php://input');
        $hmacHeader = (string)($_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '');
        $topic = (string)($_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? '');
        $shop = (string)($_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'] ?? '');
        $webhookId = (string)($_SERVER['HTTP_X_SHOPIFY_WEBHOOK_ID'] ?? '');

        if (!is_string($rawBody) || $rawBody === '') {
            webhookLog(['event' => 'webhook_invalid_body', 'topic' => $topic, 'shop' => $shop]);
            http_response_code(400);
            echo 'Bad Request';
            exit;
        }

        if ($hmacHeader === '') {
            webhookLog(['event' => 'webhook_missing_hmac', 'topic' => $topic, 'shop' => $shop]);
            http_response_code(401);
            echo 'Unauthorized';
            exit;
        }

        $calculatedHmac = base64_encode(hash_hmac('sha256', $rawBody, SHOPIFY_API_SECRET, true));
        if (!hash_equals(trim($hmacHeader), $calculatedHmac)) {
            webhookLog(['event' => 'webhook_hmac_failed', 'topic' => $topic, 'shop' => $shop]);
            http_response_code(401);
            echo 'Unauthorized';
            exit;
        }

        if ($shop === '' || preg_match('/^[a-z0-9-]+\.myshopify\.com$/', strtolower($shop)) !== 1) {
            webhookLog(['event' => 'webhook_invalid_shop', 'topic' => $topic, 'shop' => $shop]);
            http_response_code(400);
            echo 'Bad Request';
            exit;
        }

        if ($topic === '') {
            webhookLog(['event' => 'webhook_missing_topic', 'shop' => $shop]);
            http_response_code(400);
            echo 'Bad Request';
            exit;
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            webhookLog(['event' => 'webhook_invalid_json', 'topic' => $topic, 'shop' => $shop]);
            http_response_code(400);
            echo 'Bad Request';
            exit;
        }

        return [
            'data' => $payload,
            'shop' => $shop,
            'topic' => $topic,
            'webhook_id' => $webhookId,
        ];
    }
}

if (!function_exists('respondWebhookAccepted')) {
    function respondWebhookAccepted(): void
    {
        http_response_code(200);
        echo 'OK';
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        @ignore_user_abort(true);
    }
}

