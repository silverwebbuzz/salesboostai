<?php

/**
 * Central feature/limit matrix by plan.
 *
 * Canonical plan keys:
 * - free
 * - starter
 * - growth
 * - premium
 */

if (!function_exists('sbm_entitlement_matrix')) {
    function sbm_entitlement_matrix(): array
    {
        return [
            'free' => [
                'label' => 'Free',
                'features' => [
                    'dashboard_core' => true,
                    'dashboard_inventory' => false,
                    'dashboard_critical_full' => false,
                    'dashboard_top_lists_full' => false,
                    'analytics_revenue' => true,
                    'analytics_products' => false,
                    'analytics_customers' => false,
                    'analytics_aov' => false,
                    'alerts_inventory' => false,
                    'customers_ltv' => false,
                    'sales_boost_full' => false,
                    'priority_support' => false,
                ],
                'limits' => [
                    'ai_insights_per_week' => 1,
                    'recommendations_per_week' => 1,
                    'top_products_count' => 3,
                    'critical_insights_count' => 2,
                ],
            ],
            'starter' => [
                'label' => 'Starter',
                'features' => [
                    'dashboard_core' => true,
                    'dashboard_inventory' => true,
                    'dashboard_critical_full' => true,
                    'dashboard_top_lists_full' => true,
                    'analytics_revenue' => true,
                    'analytics_products' => true,
                    'analytics_customers' => true,
                    'analytics_aov' => true,
                    'alerts_inventory' => false,
                    'customers_ltv' => true,
                    'sales_boost_full' => true,
                    'priority_support' => false,
                ],
                'limits' => [
                    'ai_insights_per_week' => 2,
                    'recommendations_per_week' => 2,
                    'top_products_count' => 5,
                    'critical_insights_count' => 4,
                ],
            ],
            'growth' => [
                'label' => 'Growth',
                'features' => [
                    'dashboard_core' => true,
                    'dashboard_inventory' => true,
                    'dashboard_critical_full' => true,
                    'dashboard_top_lists_full' => true,
                    'analytics_revenue' => true,
                    'analytics_products' => true,
                    'analytics_customers' => true,
                    'analytics_aov' => true,
                    'alerts_inventory' => true,
                    'customers_ltv' => true,
                    'sales_boost_full' => true,
                    'priority_support' => false,
                ],
                'limits' => [
                    'ai_insights_per_week' => 5,
                    'recommendations_per_week' => 5,
                    'top_products_count' => 5,
                    'critical_insights_count' => 6,
                ],
            ],
            'premium' => [
                'label' => 'Premium',
                'features' => [
                    'dashboard_core' => true,
                    'dashboard_inventory' => true,
                    'dashboard_critical_full' => true,
                    'dashboard_top_lists_full' => true,
                    'analytics_revenue' => true,
                    'analytics_products' => true,
                    'analytics_customers' => true,
                    'analytics_aov' => true,
                    'alerts_inventory' => true,
                    'customers_ltv' => true,
                    'sales_boost_full' => true,
                    'priority_support' => true,
                ],
                'limits' => [
                    'ai_insights_per_week' => -1, // unlimited
                    'recommendations_per_week' => -1, // unlimited
                    'top_products_count' => 5,
                    'critical_insights_count' => 8,
                ],
            ],
        ];
    }
}

if (!function_exists('getPlanEntitlements')) {
    function getPlanEntitlements(string $shop): array
    {
        $planKey = function_exists('getCurrentPlanKey') ? getCurrentPlanKey($shop) : 'free';
        if (function_exists('normalizePlanKey')) {
            $planKey = normalizePlanKey($planKey);
        }
        $matrix = sbm_entitlement_matrix();
        $base = $matrix[$planKey] ?? $matrix['free'];

        return [
            'plan_key' => $planKey,
            'plan_label' => (string)($base['label'] ?? ucfirst($planKey)),
            'features' => is_array($base['features'] ?? null) ? $base['features'] : [],
            'limits' => is_array($base['limits'] ?? null) ? $base['limits'] : [],
        ];
    }
}

if (!function_exists('canAccessFeature')) {
    function canAccessFeature(array $entitlements, string $feature): bool
    {
        $features = is_array($entitlements['features'] ?? null) ? $entitlements['features'] : [];
        return (bool)($features[$feature] ?? false);
    }
}

if (!function_exists('getFeatureRequiredPlan')) {
    function getFeatureRequiredPlan(string $feature): string
    {
        $order = ['free', 'starter', 'growth', 'premium'];
        $matrix = sbm_entitlement_matrix();
        foreach ($order as $key) {
            $features = (array)($matrix[$key]['features'] ?? []);
            if (!empty($features[$feature])) {
                return $key;
            }
        }
        return 'starter';
    }
}

