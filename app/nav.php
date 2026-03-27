<?php
$rawShopForPlan = $_GET['shop'] ?? null;
$shopForPlan = sanitizeShopDomain($rawShopForPlan);
$hostForPlan = isset($_GET['host']) && is_string($_GET['host']) ? $_GET['host'] : '';
$planKey = $shopForPlan ? getCurrentPlanKey($shopForPlan) : 'free';
$planLabel = function_exists('sbm_plan_label') ? sbm_plan_label($planKey) : ucfirst($planKey);
$nextPlan = 'starter';
if ($planKey === 'starter') $nextPlan = 'growth';
if ($planKey === 'growth') $nextPlan = 'premium';
$nextPlanLabel = function_exists('sbm_plan_label') ? sbm_plan_label($nextPlan) : ucfirst($nextPlan);
$upgradeUrl = function_exists('sbm_upgrade_url')
  ? sbm_upgrade_url((string)$shopForPlan, $hostForPlan, $nextPlan)
  : (BASE_URL . '/billing/subscribe?plan=' . urlencode($nextPlan) . ($shopForPlan ? '&shop=' . urlencode((string)$shopForPlan) : '') . ($hostForPlan !== '' ? '&host=' . urlencode($hostForPlan) : ''));
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
    <span class="top-nav__plan-badge">Plan: <?php echo htmlspecialchars($planLabel, ENT_QUOTES, 'UTF-8'); ?></span>
    <?php if ($planKey !== 'premium'): ?>
      <a id="nav-upgrade-plan" class="top-nav__upgrade" href="<?php echo htmlspecialchars($upgradeUrl, ENT_QUOTES, 'UTF-8'); ?>">Upgrade to <?php echo htmlspecialchars($nextPlanLabel, ENT_QUOTES, 'UTF-8'); ?></a>
    <?php endif; ?>
    <a id="nav-ai-agents" class="top-nav__cta">✨ AI Agents</a>
  </div>
</nav>

<script src="https://unpkg.com/@shopify/app-bridge@3"></script>
<script src="https://unpkg.com/@shopify/app-bridge-utils@3"></script>
<script>
  (function () {
    if (!window.__sbAppBridgeInit) {
      window.__sbAppBridgeInit = true;

      var AppBridge = window['app-bridge'];
      var params0 = new URLSearchParams(window.location.search);
      var host0 = params0.get('host');
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
        if (!app) return '';
        try {
          // Compatibility: App Bridge utility location differs by build/version.
          if (AppBridge && typeof AppBridge.getSessionToken === 'function') {
            return await AppBridge.getSessionToken(app);
          }
          if (window['app-bridge-utils'] && typeof window['app-bridge-utils'].getSessionToken === 'function') {
            return await window['app-bridge-utils'].getSessionToken(app);
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
            if (i === 2) console.error("Token fetch failed", e);
          }
          if (!token && i < 2) {
            await new Promise(function (resolve) { setTimeout(resolve, 150); });
          }
        }
        if (!token) {
          console.warn("No session token available");
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
    var shop = params.get("shop");
    var host = params.get("host");

    var query = "?shop=" + encodeURIComponent(shop || "") + "&host=" + encodeURIComponent(host || "");

    var dashboardLink = document.getElementById("nav-dashboard");
    var analyticsLink = document.getElementById("nav-analytics");
    var actionCenterLink = document.getElementById("nav-action-center");
    var agentsLink = document.getElementById("nav-ai-agents");
    var upgradeLink = document.getElementById("nav-upgrade-plan");

    if (dashboardLink) dashboardLink.href = "dashboard.php" + query;
    if (actionCenterLink) actionCenterLink.href = "action-center.php" + query;
    if (analyticsLink) analyticsLink.href = "analytics.php" + query;
    if (agentsLink) agentsLink.href = "ai-agents.php" + query;
    if (upgradeLink && !upgradeLink.getAttribute('href')) upgradeLink.href = "billing/subscribe.php" + query + "&plan=starter";

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
        anchor.id === 'nav-upgrade-plan' ||
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
        if (t) console.log("Session token OK");
      } catch (e) {}
    }
  })();
</script>
