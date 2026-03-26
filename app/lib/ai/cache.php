<?php
/**
 * Shared AI caching + fingerprint helpers.
 *
 * Uses per-store analytics cache via sbm_cache_get/sbm_cache_set (app/lib/metrics.php).
 */

if (!function_exists('sbm_ai_data_fingerprint')) {
    function sbm_ai_data_fingerprint(string $shop): string
    {
        try {
            $mysqli = db();
            $tables = function_exists('sbm_getShopTables') ? sbm_getShopTables($shop) : [];
            $ordersTable = (string)($tables['order'] ?? perStoreTableName(makeShopName($shop), 'order'));
            $customersTable = (string)($tables['customer'] ?? perStoreTableName(makeShopName($shop), 'customer'));
            $invTable = (string)($tables['products_inventory'] ?? perStoreTableName(makeShopName($shop), 'products_inventory'));

            $parts = [];

            $resO = $mysqli->query(
                "SELECT COUNT(*) AS c, MAX(COALESCE(updated_at, created_at, fetched_at)) AS m FROM `{$ordersTable}`"
            );
            if ($resO) {
                $r = $resO->fetch_assoc() ?: [];
                $parts[] = 'orders:' . (int)($r['c'] ?? 0) . '@' . (string)($r['m'] ?? '');
            }

            $resC = $mysqli->query(
                "SELECT COUNT(*) AS c, MAX(COALESCE(updated_at, created_at, fetched_at)) AS m FROM `{$customersTable}`"
            );
            if ($resC) {
                $r = $resC->fetch_assoc() ?: [];
                $parts[] = 'customers:' . (int)($r['c'] ?? 0) . '@' . (string)($r['m'] ?? '');
            }

            $resI = $mysqli->query(
                "SELECT COUNT(*) AS c, MAX(COALESCE(updated_at, fetched_at)) AS m FROM `{$invTable}`"
            );
            if ($resI) {
                $r = $resI->fetch_assoc() ?: [];
                $parts[] = 'inv:' . (int)($r['c'] ?? 0) . '@' . (string)($r['m'] ?? '');
            }

            $raw = implode('|', $parts);
            if ($raw === '') return 'empty';
            return sha1($raw);
        } catch (Throwable $e) {
            return 'err';
        }
    }
}

if (!function_exists('sbm_ai_cache_get')) {
    /**
     * @return array|null cached payload (must contain fingerprint)
     */
    function sbm_ai_cache_get(string $shop, string $cacheKey, int $ttlSec): ?array
    {
        if (!function_exists('sbm_cache_get')) return null;
        $cached = sbm_cache_get($shop, $cacheKey, $ttlSec);
        return is_array($cached) ? $cached : null;
    }
}

if (!function_exists('sbm_ai_cache_set')) {
    function sbm_ai_cache_set(string $shop, string $cacheKey, array $payload): void
    {
        if (!function_exists('sbm_cache_set')) return;
        sbm_cache_set($shop, $cacheKey, $payload);
    }
}

if (!function_exists('sbm_ai_cached')) {
    /**
     * Shared cache wrapper. If cache is fresh and fingerprint matches, returns cached payload.
     *
     * @param callable():array $generateFn
     * @return array payload
     */
    function sbm_ai_cached(string $shop, string $cacheKey, int $ttlSec, callable $generateFn, bool $force = false): array
    {
        $fp = sbm_ai_data_fingerprint($shop);
        if (!$force) {
            $cached = sbm_ai_cache_get($shop, $cacheKey, $ttlSec);
            if (is_array($cached) && (string)($cached['fingerprint'] ?? '') === $fp) {
                $cached['cache'] = ['hit' => true, 'ttl_sec' => $ttlSec];
                return $cached;
            }
        }

        $fresh = $generateFn();
        if (!is_array($fresh)) $fresh = [];
        $fresh['fingerprint'] = $fp;
        $fresh['generated_at'] = gmdate('c');
        $fresh['cache'] = ['hit' => false, 'ttl_sec' => $ttlSec];

        sbm_ai_cache_set($shop, $cacheKey, $fresh);
        return $fresh;
    }
}

