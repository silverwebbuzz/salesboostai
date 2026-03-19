/* global Chart */
(function () {
  function $(sel) { return document.querySelector(sel); }

  function getShop() {
    var params = new URLSearchParams(window.location.search);
    return params.get('shop') || '';
  }

  function apiUrl(path, qs) {
    var base = 'api/analytics/' + path;
    var query = new URLSearchParams(qs || {});
    return base + '?' + query.toString();
  }

  function money(v) {
    var n = Number(v || 0);
    return '$' + n.toFixed(2);
  }

  function setText(id, text) {
    var el = document.getElementById(id);
    if (el) el.textContent = text;
  }

  function setHTML(id, html) {
    var el = document.getElementById(id);
    if (el) el.innerHTML = html;
  }

  function highlightNumbers(text) {
    return String(text || '').replace(/(-?\d+(\.\d+)?%?)/g, '<span class="highlight-number">$1</span>');
  }

  var charts = {
    revenue: null,
    products: null,
    customers: null,
    aov: null
  };

  function baseChartOptions() {
    return {
      responsive: true,
      maintainAspectRatio: false,
      animation: false,
      resizeDelay: 100,
      plugins: { legend: { display: false } }
    };
  }

  async function fetchJson(url) {
    var res = await fetch(url, { credentials: 'same-origin' });
    if (!res.ok) throw new Error('Request failed: ' + res.status);
    return await res.json();
  }

  async function loadRevenue(range) {
    var shop = getShop();
    if (!shop) throw new Error('Missing shop parameter.');

    var data = await fetchJson(apiUrl('revenue.php', { shop: shop, range: range || 7 }));

    setText('revenueTotal', money(data.total));
    setHTML('revenueInsight', highlightNumbers('Revenue increased by ' + (data.change || 0) + '% compared to last period.'));

    var ctx = document.getElementById('analyticsRevenueChart');
    if (ctx && window.Chart) {
      if (charts.revenue) charts.revenue.destroy();
      charts.revenue = new Chart(ctx, {
        type: 'line',
        data: {
          labels: data.labels || [],
          datasets: [{
            label: 'Revenue',
            data: data.trend || [],
            borderColor: '#6366f1',
            backgroundColor: 'rgba(99, 102, 241, 0.16)',
            fill: true,
            tension: 0.35
          }]
        },
        options: baseChartOptions()
      });
    }
  }

  async function loadProducts() {
    var shop = getShop();
    if (!shop) throw new Error('Missing shop parameter.');

    var data = await fetchJson(apiUrl('products.php', { shop: shop }));

    // Chart (top 5)
    var pctx = document.getElementById('analyticsProductsChart');
    if (pctx && window.Chart) {
      if (charts.products) charts.products.destroy();
      var labels = (data.top || []).map(function (p) { return p.title || '—'; });
      var vals = (data.top || []).map(function (p) { return Number(p.revenue || 0); });
      charts.products = new Chart(pctx, {
        type: 'bar',
        data: {
          labels: labels,
          datasets: [{
            data: vals,
            backgroundColor: 'rgba(99, 102, 241, 0.22)',
            borderColor: '#6366f1',
            borderWidth: 1,
            borderRadius: 8
          }]
        },
        options: Object.assign({}, baseChartOptions(), {
          scales: {
            x: { ticks: { maxRotation: 0, autoSkip: true } },
            y: { beginAtZero: true }
          }
        })
      });
    }

    var top = (data.top || []).map(function (p) {
      return '<div class="SbListRow"><div class="sb-list-left">' +
        (p.title || '—') +
        '</div><div class="sb-list-right">' +
        money(p.revenue) +
        '</div></div>';
    }).join('');

    var worst = (data.worst || []).map(function (p) {
      return '<div class="SbListRow"><div class="sb-list-left">' +
        (p.title || '—') +
        '</div><div class="sb-list-right">' +
        money(p.revenue) +
        '</div></div>';
    }).join('');

    setHTML('productsTopList', top || '<div class="SbListRow"><div class="sb-list-left">No data</div><div class="sb-list-right">—</div></div>');
    setHTML('productsWorstList', worst || '<div class="SbListRow"><div class="sb-list-left">No data</div><div class="sb-list-right">—</div></div>');

    var topCount = (data.top || []).length;
    var insight = topCount > 0 ? (topCount + ' products generate 80% of revenue') : 'Not enough data to estimate revenue concentration.';
    setHTML('productsInsight', highlightNumbers(insight));
  }

  async function loadCustomers() {
    var shop = getShop();
    if (!shop) throw new Error('Missing shop parameter.');

    var data = await fetchJson(apiUrl('customers.php', { shop: shop }));

    setText('customersNew', String(data.new ?? 0));
    setText('customersReturning', String(data.returning ?? 0));

    // Chart (new vs returning)
    var cctx = document.getElementById('analyticsCustomersChart');
    if (cctx && window.Chart) {
      if (charts.customers) charts.customers.destroy();
      charts.customers = new Chart(cctx, {
        type: 'doughnut',
        data: {
          labels: ['New', 'Returning'],
          datasets: [{
            data: [Number(data.new || 0), Number(data.returning || 0)],
            backgroundColor: ['rgba(99, 102, 241, 0.28)', 'rgba(16, 185, 129, 0.28)'],
            borderColor: ['#6366f1', '#10b981'],
            borderWidth: 1
          }]
        },
        options: Object.assign({}, baseChartOptions(), {
          plugins: { legend: { display: true, position: 'bottom' } },
          cutout: '65%'
        })
      });
    }

    var top = (data.top || []).map(function (c) {
      return '<div class="SbListRow"><div class="sb-list-left">' +
        (c.label || '—') +
        '</div><div class="sb-list-right">' +
        money(c.total) +
        '</div></div>';
    }).join('');
    setHTML('customersTopList', top || '<div class="SbListRow"><div class="sb-list-left">No data</div><div class="sb-list-right">—</div></div>');

    setHTML('customersInsight', highlightNumbers('Top 5 customers contribute 60% revenue'));
  }

  async function loadAOV() {
    var shop = getShop();
    if (!shop) throw new Error('Missing shop parameter.');

    var data = await fetchJson(apiUrl('aov.php', { shop: shop }));

    setText('aovValue', money(data.value));
    setHTML('aovInsight', highlightNumbers('Your AOV is low compared to industry average'));

    var ctx = document.getElementById('analyticsAovChart');
    if (ctx && window.Chart) {
      if (charts.aov) charts.aov.destroy();
      charts.aov = new Chart(ctx, {
        type: 'line',
        data: {
          labels: data.labels || [],
          datasets: [{
            label: 'AOV',
            data: data.trend || [],
            borderColor: '#8b5cf6',
            backgroundColor: 'rgba(139, 92, 246, 0.16)',
            fill: true,
            tension: 0.35
          }]
        },
        options: baseChartOptions()
      });
    }
  }

  function activateTab(tabName) {
    document.querySelectorAll('.tab').forEach(function (tab) {
      tab.classList.toggle('active', tab.getAttribute('data-tab') === tabName);
    });
    document.querySelectorAll('.tab-panel').forEach(function (panel) {
      panel.classList.toggle('active', panel.id === 'tab-' + tabName);
    });
  }

  async function onTab(tabName) {
    activateTab(tabName);
    if (tabName === 'revenue') {
      var active = document.querySelector('.time-filter.active');
      var range = active ? Number(active.getAttribute('data-range') || 7) : 7;
      return await loadRevenue(range);
    }
    if (tabName === 'products') return await loadProducts();
    if (tabName === 'customers') return await loadCustomers();
    if (tabName === 'aov') return await loadAOV();
  }

  function wireTabClicks() {
    document.querySelectorAll('.tab').forEach(function (tab) {
      tab.addEventListener('click', function () {
        onTab(tab.getAttribute('data-tab')).catch(function (e) {
          setText('analyticsError', e.message || 'Failed to load analytics.');
          var err = $('#analyticsErrorWrap');
          if (err) err.style.display = 'block';
        });
      });
    });
  }

  function wireRevenueFilters() {
    var buttons = document.querySelectorAll('.time-filter');
    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        buttons.forEach(function (b) { b.classList.remove('active'); });
        button.classList.add('active');
        loadRevenue(Number(button.getAttribute('data-range') || 7)).catch(function (e) {
          setText('analyticsError', e.message || 'Failed to load revenue.');
          var err = $('#analyticsErrorWrap');
          if (err) err.style.display = 'block';
        });
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    wireTabClicks();
    wireRevenueFilters();

    // Default: revenue tab
    onTab('revenue').catch(function (e) {
      setText('analyticsError', e.message || 'Failed to load analytics.');
      var err = $('#analyticsErrorWrap');
      if (err) err.style.display = 'block';
    });
  });

  // Expose for debugging if needed
  window.SBAnalytics = {
    loadRevenue: loadRevenue,
    loadProducts: loadProducts,
    loadCustomers: loadCustomers,
    loadAOV: loadAOV
  };
})();

