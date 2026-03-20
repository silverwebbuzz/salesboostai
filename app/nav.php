<nav class="top-nav">
  <div class="nav-left">
    <span class="logo">SalesBoost AI</span>
  </div>

  <div class="nav-right">
    <a id="nav-dashboard">Dashboard</a>
    <a id="nav-analytics">Analytics</a>
    <a id="nav-alerts">Alerts</a>
    <a id="nav-customers">Customers</a>
    <a id="nav-sales-boost">Sales Boost</a>
    <a id="nav-ai-agents">AI Agents</a>
  </div>
</nav>

<script src="https://unpkg.com/@shopify/app-bridge@3"></script>
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
        var token = await window.getToken();
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
