<nav class="top-nav">
  <div class="nav-left">
    <span class="logo">SalesBoost AI</span>
  </div>

  <div class="nav-right">
    <a id="nav-dashboard">Dashboard</a>
    <a id="nav-analytics">Analytics</a>
    <a id="nav-alerts">Alerts</a>
    <a id="nav-customers">Customers</a>
    <a id="nav-ai-agents">AI Agents</a>
  </div>
</nav>

<script>
  (function () {
    var params = new URLSearchParams(window.location.search);
    var shop = params.get("shop");
    var host = params.get("host");

    var query = "?shop=" + encodeURIComponent(shop || "") + "&host=" + encodeURIComponent(host || "");

    var dashboardLink = document.getElementById("nav-dashboard");
    var analyticsLink = document.getElementById("nav-analytics");
    var alertsLink = document.getElementById("nav-alerts");
    var customersLink = document.getElementById("nav-customers");
    var agentsLink = document.getElementById("nav-ai-agents");

    if (dashboardLink) dashboardLink.href = "dashboard.php" + query;
    if (analyticsLink) analyticsLink.href = "analytics.php" + query;
    if (alertsLink) alertsLink.href = "alerts.php" + query;
    if (customersLink) customersLink.href = "customers.php" + query;
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
    if (path.includes("ai-agents") && agentsLink) {
      agentsLink.classList.add("active");
    }
    if (path.includes("agent-report") && agentsLink) {
      agentsLink.classList.add("active");
    }
  })();
</script>
