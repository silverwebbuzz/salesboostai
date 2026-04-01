<?php
$rawShopForPlan = $_GET['shop'] ?? null;
$shopForPlan = sanitizeShopDomain($rawShopForPlan);
$hostForPlan = isset($_GET['host']) && is_string($_GET['host']) ? $_GET['host'] : '';
if ($hostForPlan === '' && $shopForPlan) {
  $rec = getShopByDomain((string)$shopForPlan);
  $hostForPlan = is_array($rec) ? (string)($rec['host'] ?? '') : '';
}
$planKey = $shopForPlan ? getCurrentPlanKey($shopForPlan) : 'free';
$planLabel = function_exists('sbm_plan_label') ? sbm_plan_label($planKey) : ucfirst($planKey);
$isPremiumPlan = ($planKey === 'premium');
$managePlansUrl = function_exists('sbm_upgrade_url')
  ? sbm_upgrade_url((string)$shopForPlan, $hostForPlan, 'starter')
  : (BASE_URL . '/billing/subscribe?plan=starter' . ($shopForPlan ? '&shop=' . urlencode((string)$shopForPlan) : '') . ($hostForPlan !== '' ? '&host=' . urlencode($hostForPlan) : ''));
?>

<nav class="top-nav" aria-label="Primary">
  <div class="top-nav__center" aria-label="Main navigation tabs">
    <ul class="top-nav__menu">
      <li><a id="nav-dashboard" class="top-nav__link">Dashboard</a></li>
      <li><a id="nav-analytics" class="top-nav__link">Analytics</a></li>
      <li><a id="nav-action-center" class="top-nav__link">Action Center</a></li>
    </ul>
  </div>

  <div class="top-nav__right">
    <button id="nav-plan-trigger" class="top-nav__plan-badge top-nav__plan-badge--clickable" type="button">
      Plan: <?php echo htmlspecialchars($planLabel, ENT_QUOTES, 'UTF-8'); ?>
    </button>
    <a id="nav-ai-agents" class="top-nav__cta">✨ AI Agents</a>
  </div>
</nav>

<div class="sb-modal" id="planCompareModal" aria-hidden="true">
  <div class="sb-modal__panel sb-plan-modal" role="dialog" aria-modal="true" aria-labelledby="planCompareTitle">
    <div class="sb-modal__head">
      <div>
        <div class="sb-modal__title" id="planCompareTitle">Choose your SalesBoost AI plan</div>
        <div class="sb-modal__meta">Compare plans and change anytime.</div>
      </div>
      <button class="sb-modal__close" type="button" id="planCompareClose">Close</button>
    </div>
    <div class="sb-modal__body">
      <div class="sb-plan-grid">
        <div class="sb-plan-card <?php echo $planKey === 'free' ? 'is-current' : ''; ?>">
          <div class="sb-plan-name">Free</div>
          <div class="sb-plan-copy">Best for trying core analytics and basic monitoring.</div>
          <ul class="sb-plan-features">
            <li>Dashboard KPIs and trend preview</li>
            <li>Basic Action Center insights</li>
            <li>Basic Alerts and Analytics preview</li>
            <li>AI digest preview (limited)</li>
          </ul>
          <div class="sb-plan-action">
            <?php if ($planKey === 'free'): ?>
              <span class="btn btn-ghost btn-sm is-current">Current plan</span>
            <?php else: ?>
              <a class="btn btn-primary btn-sm plan-change-cta" href="<?php echo htmlspecialchars($managePlansUrl, ENT_QUOTES, 'UTF-8'); ?>">
                Change to Free
              </a>
            <?php endif; ?>
          </div>
        </div>
        <div class="sb-plan-card <?php echo $planKey === 'starter' ? 'is-current' : ''; ?>">
          <div class="sb-plan-name">Starter</div>
          <div class="sb-plan-copy">Core revenue analytics and operational actions.</div>
          <ul class="sb-plan-features">
            <li>Full dashboard with stronger insights</li>
            <li>Action Center next-best actions</li>
            <li>Analytics: products, customers, AOV</li>
            <li>Inventory forecasting basics</li>
          </ul>
          <div class="sb-plan-action">
            <a class="btn btn-primary btn-sm plan-change-cta <?php echo $planKey === 'starter' ? 'feature-disabled' : ''; ?>" href="<?php echo htmlspecialchars($managePlansUrl, ENT_QUOTES, 'UTF-8'); ?>">
              <?php echo $planKey === 'starter' ? 'Current plan' : 'Change to Starter'; ?>
            </a>
          </div>
        </div>
        <div class="sb-plan-card <?php echo $planKey === 'growth' ? 'is-current' : ''; ?>">
          <div class="sb-plan-name">Growth</div>
          <div class="sb-plan-copy">Advanced retention, funnel and more AI insights.</div>
          <ul class="sb-plan-features">
            <li>Everything in Starter</li>
            <li>Funnel, attribution and deeper retention</li>
            <li>Daily AI digest and anomaly explanation</li>
            <li>Higher limits across dashboards and reports</li>
          </ul>
          <div class="sb-plan-action">
            <a class="btn btn-primary btn-sm plan-change-cta <?php echo $planKey === 'growth' ? 'feature-disabled' : ''; ?>" href="<?php echo htmlspecialchars($managePlansUrl, ENT_QUOTES, 'UTF-8'); ?>">
              <?php echo $planKey === 'growth' ? 'Current plan' : 'Change to Growth'; ?>
            </a>
          </div>
        </div>
        <div class="sb-plan-card <?php echo $isPremiumPlan ? 'is-current' : ''; ?>">
          <div class="sb-plan-name">Premium</div>
          <div class="sb-plan-copy">Complete suite with highest limits and AI power.</div>
          <ul class="sb-plan-features">
            <li>Everything in Growth</li>
            <li>Full reports suite and advanced alerts</li>
            <li>Dynamic AI recommendations and summaries</li>
            <li>Highest usage limits and plan capacity</li>
          </ul>
          <div class="sb-plan-action">
            <a class="btn btn-primary btn-sm plan-change-cta <?php echo $isPremiumPlan ? 'feature-disabled' : ''; ?>" href="<?php echo htmlspecialchars($managePlansUrl, ENT_QUOTES, 'UTF-8'); ?>">
              <?php echo $isPremiumPlan ? 'Current plan' : 'Change to Premium'; ?>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
<script>
  (function () {
    if (!window.__sbAppBridgeInit) {
      window.__sbAppBridgeInit = true;

      var AppBridge = window['app-bridge'];
      var params0 = new URLSearchParams(window.location.search);
      var sbmDebug = params0.get('debug') === '1';
      var sbmConsole = (sbmDebug && window.console) ? window.console : null;
      var host0 = params0.get('host') || <?php echo json_encode((string)$hostForPlan); ?>;
      var app = null;

      if (AppBridge && typeof AppBridge.createApp === 'function' && host0) {
        app = AppBridge.createApp({
          apiKey: <?php echo json_encode(SHOPIFY_API_KEY); ?>,
          host: host0,
          forceRedirect: true
        });
      }
      window.__sbmApp = app;

      window.getToken = async function getToken() {
        try {
          // Modern App Bridge runtime (Shopify CDN).
          var shopifyNow = window.shopify || null;
          if (shopifyNow && typeof shopifyNow.idToken === 'function') {
            return await shopifyNow.idToken();
          }
          if (!app && AppBridge && typeof AppBridge.createApp === 'function' && host0) {
            app = AppBridge.createApp({
              apiKey: <?php echo json_encode(SHOPIFY_API_KEY); ?>,
              host: host0,
              forceRedirect: true
            });
            window.__sbmApp = app;
          }
          if (!app) return '';
          // Compatibility: App Bridge utility location differs by build/version.
          if (AppBridge && typeof AppBridge.getSessionToken === 'function') {
            return await AppBridge.getSessionToken(app);
          }
          if (AppBridge && AppBridge.utilities && typeof AppBridge.utilities.getSessionToken === 'function') {
            return await AppBridge.utilities.getSessionToken(app);
          }
        } catch (e) {}
        return '';
      };

      window.authFetch = async function authFetch(url, options) {
        var opts = options || {};
        var token = null;
        for (var i = 0; i < 3 && !token; i++) {
          try {
            token = await window.getToken();
          } catch (e) {
            if (i === 2 && sbmConsole) sbmConsole.error("Token fetch failed", e);
          }
          if (!token && i < 2) {
            await new Promise(function (resolve) { setTimeout(resolve, 150); });
          }
        }
        if (!token) {
          if (sbmConsole) sbmConsole.warn("No session token available");
        }
        var headers = Object.assign({}, opts.headers || {});
        if (token) headers.Authorization = 'Bearer ' + token;
        opts.headers = headers;
        return fetch(url, opts);
      };

      // Embedded-safe redirect helper for plan upgrades and external flows.
      window.sbmOpenRemote = function sbmOpenRemote(url) {
        try {
          var target = String(url || '');
          if (!target) return;
          var AppBridgeNow = window['app-bridge'];
          var appNow = window.__sbmApp || null;
          if (AppBridgeNow && appNow && AppBridgeNow.actions && AppBridgeNow.actions.Redirect) {
            var Redirect = AppBridgeNow.actions.Redirect;
            Redirect.create(appNow).dispatch(Redirect.Action.REMOTE, target);
            return;
          }
          if (window.top && window.top !== window) {
            window.top.location.href = target;
            return;
          }
          window.location.href = target;
        } catch (e) {
          window.location.href = String(url || '');
        }
      };
    }

    var params = new URLSearchParams(window.location.search);
    var shop = params.get("shop") || <?php echo json_encode((string)($shopForPlan ?? '')); ?>;
    var host = params.get("host") || <?php echo json_encode((string)$hostForPlan); ?>;

    var query = "?shop=" + encodeURIComponent(shop || "") + "&host=" + encodeURIComponent(host || "");

    var dashboardLink = document.getElementById("nav-dashboard");
    var analyticsLink = document.getElementById("nav-analytics");
    var actionCenterLink = document.getElementById("nav-action-center");
    var agentsLink = document.getElementById("nav-ai-agents");
    var planTrigger = document.getElementById("nav-plan-trigger");
    var planModal = document.getElementById("planCompareModal");
    var planModalClose = document.getElementById("planCompareClose");

    if (dashboardLink) dashboardLink.href = "dashboard.php" + query;
    if (actionCenterLink) actionCenterLink.href = "action-center.php" + query;
    if (analyticsLink) analyticsLink.href = "analytics.php" + query;
    if (agentsLink) agentsLink.href = "ai-agents.php" + query;
    if (planTrigger && planModal) {
      var openPlanModal = function () {
        planModal.classList.add('is-open');
        planModal.setAttribute('aria-hidden', 'false');
      };
      var closePlanModal = function () {
        planModal.classList.remove('is-open');
        planModal.setAttribute('aria-hidden', 'true');
      };
      planTrigger.addEventListener('click', openPlanModal);
      if (planModalClose) {
        planModalClose.addEventListener('click', closePlanModal);
      }
      planModal.addEventListener('click', function (ev) {
        if (ev.target === planModal) closePlanModal();
      });
      document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && planModal.classList.contains('is-open')) closePlanModal();
      });
    }

    // Ensure all upgrade/change-plan links use embedded-safe redirect.
    // Works for nav CTA, lock overlays, reports page buttons, AI agents page buttons, etc.
    document.addEventListener('click', function (ev) {
      var target = ev.target;
      if (!target || typeof target.closest !== 'function') return;
      var anchor = target.closest('a[href]');
      if (!anchor) return;
      var href = String(anchor.getAttribute('href') || '');
      if (!href) return;
      var isPlanLink =
        anchor.classList.contains('plan-change-cta') ||
        anchor.classList.contains('feature-lock-cta') ||
        href.indexOf('/billing/subscribe') !== -1 ||
        href.indexOf('billing/subscribe') !== -1 ||
        href.indexOf('/pricing_plans') !== -1 ||
        href.indexOf('pricing_plans') !== -1;
      if (!isPlanLink) return;
      ev.preventDefault();
      window.sbmOpenRemote(href);
    }, false);

    // Active state
    var path = window.location.pathname.toLowerCase();
    if (path.includes("dashboard") && dashboardLink) {
      dashboardLink.classList.add("active");
    }
    if (path.includes("action-center") && actionCenterLink) {
      actionCenterLink.classList.add("active");
    }
    if (path.includes("analytics") && analyticsLink) {
      analyticsLink.classList.add("active");
    }
    // Customers is considered part of Analytics (tab-level).
    if (path.includes("customers") && analyticsLink) {
      analyticsLink.classList.add("active");
    }
    // When viewing deeper operational pages, keep user anchored to Action Center.
    if ((path.includes("reports") || path.includes("alerts") || path.includes("sales-boost")) && actionCenterLink) {
      actionCenterLink.classList.add("active");
    }
    if (path.includes("ai-agents") && agentsLink) {
      agentsLink.classList.add("active");
    }
    if (path.includes("agent-report") && agentsLink) {
      agentsLink.classList.add("active");
    }
  })();
</script>
<script>
  (async () => {
    if (window.getToken) {
      try {
        const t = await window.getToken();
        // Intentionally silent in production to avoid console noise during Shopify review.
      } catch (e) {}
    }
  })();
</script>
