<nav class="top-nav" aria-label="Primary">
  <div class="top-nav__center" aria-label="Main navigation tabs">
    <ul class="top-nav__menu">
      <li><a id="nav-dashboard" class="top-nav__link">Dashboard</a></li>
      <li><a id="nav-analytics" class="top-nav__link">Analytics</a></li>
      <li><a id="nav-alerts" class="top-nav__link">Alerts</a></li>
      <li><a id="nav-customers" class="top-nav__link">Customers</a></li>
      <li><a id="nav-sales-boost" class="top-nav__link">Sales Boost</a></li>
    </ul>
  </div>

  <div class="top-nav__right">
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
    }

    var params = new URLSearchParams(window.location.search);
    var shop = params.get("shop");
    var host = params.get("host");

    var query = "?shop=" + encodeURIComponent(shop || "") + "&host=" + encodeURIComponent(host || "");

    var dashboardLink = document.getElementById("nav-dashboard");
    var analyticsLink = document.getElementById("nav-analytics");
    var alertsLink = document.getElementById("nav-alerts");
    var customersLink = document.getElementById("nav-customers");
    var salesBoostLink = document.getElementById("nav-sales-boost");
    var agentsLink = document.getElementById("nav-ai-agents");

    if (dashboardLink) dashboardLink.href = "dashboard.php" + query;
    if (analyticsLink) analyticsLink.href = "analytics.php" + query;
    if (alertsLink) alertsLink.href = "alerts.php" + query;
    if (customersLink) customersLink.href = "customers.php" + query;
    if (salesBoostLink) salesBoostLink.href = "sales-boost.php" + query;
    if (agentsLink) agentsLink.href = "ai-agents.php" + query;

    // Active state
    var path = window.location.pathname.toLowerCase();
    if (path.includes("dashboard") && dashboardLink) {
      dashboardLink.classList.add("active");
    }
    if (path.includes("analytics") && analyticsLink) {
      analyticsLink.classList.add("active");
    }
    if (path.includes("alerts") && alertsLink) {
      alertsLink.classList.add("active");
    }
    if (path.includes("customers") && customersLink) {
      customersLink.classList.add("active");
    }
    if (path.includes("sales-boost") && salesBoostLink) {
      salesBoostLink.classList.add("active");
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
