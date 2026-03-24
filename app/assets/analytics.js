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

  function esc(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function highlightNumbers(text) {
    return String(text || '').replace(/(-?\d+(\.\d+)?%?)/g, '<span class="highlight-number">$1</span>');
  }

  function formatInsight(insight) {
    var map = {
      high: {
        label: '⚠️ Action needed',
        color: '#b91c1c',
        bg: '#fef2f2',
        border: '#fecaca'
      },
      medium: {
        label: '💡 Opportunity',
        color: '#92400e',
        bg: '#fffbeb',
        border: '#fde68a'
      },
      positive: {
        label: '📈 Good performance',
        color: '#166534',
        bg: '#ecfdf5',
        border: '#bbf7d0'
      }
    };
    var t = map[insight && insight.type] ? insight.type : 'medium';
    var cfg = map[t];
    return (
      '<div style="display:flex;flex-direction:column;gap:6px;">' +
        '<span style="display:inline-block;align-self:flex-start;padding:4px 8px;border-radius:999px;border:1px solid ' + cfg.border + ';background:' + cfg.bg + ';color:' + cfg.color + ';font-size:12px;font-weight:600;">' + cfg.label + '</span>' +
        '<span style="font-size:14px;color:#1f2937;">' + highlightNumbers((insight && insight.message) || '') + '</span>' +
      '</div>'
    );
  }

  function generateInsight(data) {
    var revenueChange = Number(data && data.revenue_change != null ? data.revenue_change : NaN);
    var topShare = Number(data && data.top_product_share != null ? data.top_product_share : NaN);
    var aov = Number(data && data.aov != null ? data.aov : NaN);
    var returningRate = Number(
      data && data.customers && data.customers.returning_rate != null
        ? data.customers.returning_rate
        : NaN
    );

    if (!isNaN(revenueChange) && revenueChange === 0) {
      return { type: 'high', message: 'Sales are not growing. Try running a promotion this week.' };
    }
    if (!isNaN(revenueChange) && revenueChange > 10) {
      return { type: 'positive', message: 'Sales are increasing. Keep promoting your top products.' };
    }
    if (!isNaN(topShare) && topShare > 0.4) {
      return { type: 'high', message: 'Most sales come from one product. Promote other items.' };
    }
    if (!isNaN(aov) && aov < 50) {
      return { type: 'medium', message: 'Customers buy small orders. Add bundles or upsells.' };
    }
    if (!isNaN(returningRate) && returningRate < 0.3) {
      return { type: 'high', message: 'Customers are not returning. Try follow-up campaigns.' };
    }
    return { type: 'positive', message: 'Sales look steady. Keep promoting your best products.' };
  }

  function getTopProductShare(topProducts) {
    var items = topProducts || [];
    if (!items.length) return 0;
    var total = items.reduce(function (acc, p) { return acc + Number(p.revenue || 0); }, 0);
    var top = Number(items[0] && items[0].revenue ? items[0].revenue : 0);
    if (total <= 0) return 0;
    return top / total;
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
    var doFetch = window.authFetch || fetch;
    var res = await doFetch(url, { credentials: 'same-origin' });
    if (!res.ok) throw new Error('Request failed: ' + res.status);
    return await res.json();
  }

  async function loadRevenue(range) {
    var shop = getShop();
    if (!shop) throw new Error('Missing shop parameter.');

    var data = await fetchJson(apiUrl('revenue.php', { shop: shop, range: range || 7 }));

    setText('revenueTotal', money(data.total));
    setHTML('revenueInsight', formatInsight(generateInsight({
      revenue_change: Number(data.change || 0)
    })));

    var funnelHtml = (data.funnel && data.funnel.steps || []).map(function (s) {
      return '<div class="SbListRow"><div class="sb-list-left">' +
        esc(s.name || 'Step') +
        '</div><div class="sb-list-right">' +
        Number(s.count || 0) + ' (' + Number(s.conversion_rate || 0).toFixed(1) + '%)' +
        '</div></div>';
    }).join('');
    setHTML('revenueFunnelList', funnelHtml || '<div class="SbListRow"><div class="sb-list-left">No funnel data</div><div class="sb-list-right">—</div></div>');

    var attrHtml = (data.attribution && data.attribution.sources || []).map(function (s) {
      return '<div class="SbListRow"><div class="sb-list-left">' +
        esc(s.source || 'unknown') +
        '</div><div class="sb-list-right">' +
        money(s.revenue || 0) +
        '</div></div>';
    }).join('');
    setHTML('revenueAttributionList', attrHtml || '<div class="SbListRow"><div class="sb-list-left">No source data</div><div class="sb-list-right">—</div></div>');

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

    var insight = generateInsight({
      top_product_share: getTopProductShare(data.top || [])
    });
    setHTML('productsInsight', formatInsight(insight));
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

    var newCount = Number(data.new || 0);
    var returningCount = Number(data.returning || 0);
    var total = newCount + returningCount;
    var returningRate = total > 0 ? (returningCount / total) : 0;
    setHTML('customersInsight', formatInsight(generateInsight({
      customers: { returning_rate: returningRate }
    })));

    var cohorts = (data.retention && data.retention.cohorts) || [];
    var retentionHtml = cohorts.map(function (c) {
      return '<div class="SbListRow"><div class="sb-list-left">' +
        esc(c.cohort_key || 'Cohort') +
        '</div><div class="sb-list-right">' +
        Number(c.retention_rate || 0).toFixed(1) + '%</div></div>';
    }).join('');
    if (!retentionHtml) {
      retentionHtml = '<div class="SbListRow"><div class="sb-list-left">Retention preview</div><div class="sb-list-right">' + Number((data.retention && data.retention.repeat_rate) || 0).toFixed(1) + '%</div></div>';
    }
    setHTML('customersRetentionList', retentionHtml);
  }

  async function loadAOV() {
    var shop = getShop();
    if (!shop) throw new Error('Missing shop parameter.');

    var data = await fetchJson(apiUrl('aov.php', { shop: shop }));

    setText('aovValue', money(data.value));
    setHTML('aovInsight', formatInsight(generateInsight({
      aov: Number(data.value || 0)
    })));

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
        var isLocked = tab.getAttribute('data-locked') === '1';
        var tabName = tab.getAttribute('data-tab');
        if (isLocked) {
          activateTab(tabName);
          return;
        }
        onTab(tabName).catch(function (e) {
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

