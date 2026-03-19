<nav class="top-nav">
  <div class="nav-left">
    <span class="logo">SalesBoost AI</span>
  </div>

  <div class="nav-right">
    <a id="nav-dashboard">Dashboard</a>
    <a id="nav-analytics">Analytics</a>
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

    if (dashboardLink) dashboardLink.href = "dashboard.php" + query;
    if (analyticsLink) analyticsLink.href = "analytics.php" + query;

    // Active state
    var path = window.location.pathname.toLowerCase();
    if (path.includes("dashboard") && dashboardLink) {
      dashboardLink.classList.add("active");
    }
    if (path.includes("analytics") && analyticsLink) {
      analyticsLink.classList.add("active");
    }
  })();
</script>
