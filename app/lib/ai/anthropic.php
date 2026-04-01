<?php
/**
 * Anthropic client (server-side only).
 *
 * Uses env var: ANTHROPIC_API_KEY
 * Docs: https://docs.anthropic.com/
 */

require_once __DIR__ . '/../logger.php';

if (!function_exists('sbm_anthropic_api_key')) {
    function sbm_anthropic_api_key(): string
    {
        $k = getenv('ANTHROPIC_API_KEY');
        return is_string($k) ? trim($k) : '';
    }
}

if (!function_exists('sbm_anthropic_post_json')) {
    /**
     * @return array{ok:bool,status:int,body:array|null,error:string}
     */
    function sbm_anthropic_post_json(string $url, array $payload, int $timeoutSec = 20): array
    {
        $key = sbm_anthropic_api_key();
        if ($key === '') {
            if (function_exists('sbm_log_write')) {
                sbm_log_write('ai', 'anthropic_missing_api_key', ['url' => $url]);
            }
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'Missing ANTHROPIC_API_KEY'];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'Unable to init curl'];
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'Unable to encode payload'];
        }

        $headers = [
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSec,
        ]);

        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            if (function_exists('sbm_log_write')) {
                sbm_log_write('ai', 'anthropic_network_error', [
                    'url' => $url,
                    'status' => $status,
                    'error' => $curlErr !== '' ? $curlErr : 'Network error',
                ]);
            }
            return ['ok' => false, 'status' => $status, 'body' => null, 'error' => $curlErr !== '' ? $curlErr : 'Network error'];
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            if (function_exists('sbm_log_write')) {
                sbm_log_write('ai', 'anthropic_invalid_json_response', [
                    'url' => $url,
                    'status' => $status,
                ]);
            }
            return ['ok' => false, 'status' => $status, 'body' => null, 'error' => 'Invalid JSON response'];
        }

        if ($status < 200 || $status >= 300) {
            $msg = '';
            if (isset($decoded['error']) && is_array($decoded['error'])) {
                $msg = (string)($decoded['error']['message'] ?? '');
            }
            if ($msg === '') $msg = 'AI provider request failed';
            if (function_exists('sbm_log_write')) {
                sbm_log_write('ai', 'anthropic_http_error', [
                    'url' => $url,
                    'status' => $status,
                    'error' => $msg,
                ]);
            }
            return ['ok' => false, 'status' => $status, 'body' => $decoded, 'error' => $msg];
        }

        if (function_exists('sbm_log_write')) {
            sbm_log_write('ai', 'anthropic_http_ok', [
                'url' => $url,
                'status' => $status,
            ]);
        }

        return ['ok' => true, 'status' => $status, 'body' => $decoded, 'error' => ''];
    }
}

if (!function_exists('sbm_anthropic_message_text')) {
    /**
     * Extract first text block from response.
     */
    function sbm_anthropic_message_text(array $body): string
    {
        $content = $body['content'] ?? null;
        if (!is_array($content)) return '';
        foreach ($content as $block) {
            if (!is_array($block)) continue;
            if (($block['type'] ?? '') === 'text') {
                return trim((string)($block['text'] ?? ''));
            }
        }
        return '';
    }
}

if (!function_exists('sbm_anthropic_messages')) {
    /**
     * Convenience wrapper for /v1/messages.
     *
     * @return array{ok:bool,text:string,error:string,status:int,raw:array|null}
     */
    function sbm_anthropic_messages(array $payload, int $timeoutSec = 20): array
    {
        $res = sbm_anthropic_post_json('https://api.anthropic.com/v1/messages', $payload, $timeoutSec);
        if (!$res['ok']) {
            return ['ok' => false, 'text' => '', 'error' => $res['error'], 'status' => (int)$res['status'], 'raw' => $res['body']];
        }
        $text = sbm_anthropic_message_text((array)$res['body']);
        if ($text === '') {
            return ['ok' => false, 'text' => '', 'error' => 'Empty AI response', 'status' => (int)$res['status'], 'raw' => $res['body']];
        }
        return ['ok' => true, 'text' => $text, 'error' => '', 'status' => (int)$res['status'], 'raw' => $res['body']];
    }
}

