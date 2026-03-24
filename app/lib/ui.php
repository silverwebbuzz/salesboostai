<?php

/**
 * Shared UI helpers for locked/preview features.
 */

if (!function_exists('sbm_escape_html')) {
    function sbm_escape_html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sbm_plan_label')) {
    function sbm_plan_label(string $planKey): string
    {
        $map = [
            'free' => 'Free',
            'starter' => 'Starter',
            'growth' => 'Growth',
            'premium' => 'Premium',
        ];
        $k = strtolower(trim($planKey));
        return $map[$k] ?? 'Starter';
    }
}

if (!function_exists('sbm_upgrade_url')) {
    function sbm_upgrade_url(string $shop = '', string $host = '', string $toPlan = 'starter'): string
    {
        $toPlan = strtolower(trim($toPlan));
        if (!in_array($toPlan, ['starter', 'growth', 'premium'], true)) {
            $toPlan = 'starter';
        }
        $url = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/') . '/billing/subscribe?plan=' . urlencode($toPlan);
        if ($shop !== '') {
            $url .= '&shop=' . urlencode($shop);
        }
        if ($host !== '') {
            $url .= '&host=' . urlencode($host);
        }
        return $url;
    }
}

if (!function_exists('renderLockedFeatureBlock')) {
    /**
     * Render a reusable lock/upgrade UI block.
     */
    function renderLockedFeatureBlock(
        string $title,
        string $description,
        string $requiredPlanKey = 'starter',
        ?string $upgradeUrl = null
    ): void {
        $planLabel = sbm_plan_label($requiredPlanKey);
        $ctaUrl = $upgradeUrl ?? sbm_upgrade_url('', '', $requiredPlanKey);
        ?>
        <div class="feature-lock-overlay-inner">
          <div class="feature-lock-overlay-title"><?php echo sbm_escape_html($title); ?></div>
          <div class="feature-lock-overlay-copy"><?php echo sbm_escape_html($description); ?></div>
          <a class="feature-lock-cta" href="<?php echo sbm_escape_html($ctaUrl); ?>">
            Upgrade to <?php echo sbm_escape_html($planLabel); ?>
          </a>
          <div class="feature-lock-desc feature-lock-desc--hint">Included in <?php echo sbm_escape_html($planLabel); ?> plan</div>
        </div>
        <?php
    }
}

