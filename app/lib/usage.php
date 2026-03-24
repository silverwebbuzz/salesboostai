<?php

/**
 * Weekly usage counters for plan-limited features.
 */

if (!function_exists('sbm_usage_week_key')) {
    function sbm_usage_week_key(?DateTimeInterface $dt = null): string
    {
        $d = $dt ? DateTime::createFromInterface($dt) : new DateTime('now', new DateTimeZone('UTC'));
        return $d->format('o-\WW'); // e.g. 2026-W12
    }
}

if (!function_exists('sbm_get_weekly_usage')) {
    function sbm_get_weekly_usage(string $shop, string $metricKey, ?string $weekKey = null): int
    {
        $wk = $weekKey ?: sbm_usage_week_key();
        $mysqli = db();
        $stmt = $mysqli->prepare("SELECT used_count FROM store_usage_limits WHERE shop = ? AND metric_key = ? AND week_key = ? LIMIT 1");
        if (!$stmt) return 0;
        $stmt->bind_param('sss', $shop, $metricKey, $wk);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? ($res->fetch_assoc() ?: null) : null;
        $stmt->close();
        return (int)($row['used_count'] ?? 0);
    }
}

if (!function_exists('sbm_increment_weekly_usage')) {
    function sbm_increment_weekly_usage(string $shop, string $metricKey, int $delta = 1, ?string $weekKey = null): void
    {
        if ($delta <= 0) return;
        $wk = $weekKey ?: sbm_usage_week_key();
        $mysqli = db();
        $stmt = $mysqli->prepare(
            "INSERT INTO store_usage_limits (shop, metric_key, week_key, used_count)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE used_count = used_count + VALUES(used_count), updated_at = NOW()"
        );
        if (!$stmt) return;
        $stmt->bind_param('sssi', $shop, $metricKey, $wk, $delta);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('sbm_usage_state')) {
    function sbm_usage_state(string $shop, string $metricKey, int $limit): array
    {
        $used = sbm_get_weekly_usage($shop, $metricKey);
        $unlimited = $limit < 0;
        $remaining = $unlimited ? -1 : max(0, $limit - $used);
        $reached = !$unlimited && $used >= $limit;
        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => $remaining,
            'unlimited' => $unlimited,
            'reached' => $reached,
            'week_key' => sbm_usage_week_key(),
        ];
    }
}

