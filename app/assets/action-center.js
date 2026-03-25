/* Action Center page (hub) */
(function () {
  function getParam(name) {
    var params = new URLSearchParams(window.location.search);
    return params.get(name) || '';
  }

  function esc(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function setHTML(id, html) {
    var el = document.getElementById(id);
    if (el) el.innerHTML = html;
  }

  function showNotice(text, tone) {
    var el = document.getElementById('actionCenterNotice');
    if (!el) return;
    el.className = 'card mb-12 ' + (tone === 'error' ? 'reports-notice reports-notice--error' : 'reports-notice');
    el.classList.remove('is-hidden');
    el.innerHTML = '<div><strong>' + esc(text) + '</strong></div>';
  }

  function money(v) {
    var n = Number(v || 0);
    try {
      return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD', maximumFractionDigits: 2 }).format(n);
    } catch (e) {
      return '$' + n.toFixed(2);
    }
  }

  function toSeverityClass(sev) {
    var s = String(sev || 'medium').toLowerCase();
    if (s === 'high') return 'critical-item--high';
    if (s === 'low') return 'critical-item--low';
    return 'critical-item--medium';
  }

  function toPriorityBadge(sev) {
    var s = String(sev || 'medium').toLowerCase();
    if (s === 'high') return 'critical-priority critical-priority--high';
    if (s === 'low') return 'critical-priority critical-priority--low';
    return 'critical-priority critical-priority--medium';
  }

  function clamp(n, min, max) {
    return Math.max(min, Math.min(max, n));
  }

  function computeTrend(current, previous) {
    if (previous <= 0) return null;
    var pct = ((current - previous) / previous) * 100;
    if (!isFinite(pct)) return null;
    return pct;
  }

  // Same scoring logic as Dashboard for consistency.
  function computeStoreHealth(data) {
    var issues = (data && data.critical_issues) ? data.critical_issues : [];

    // 1) Revenue score (30)
    var rev = (data && data.charts && data.charts.revenue) ? data.charts.revenue : [];
    var last7Rev = rev.slice(-7).reduce(function (a, b) { return a + Number(b || 0); }, 0);
    var prev7Rev = rev.slice(-14, -7).reduce(function (a, b) { return a + Number(b || 0); }, 0);
    var revTrend = computeTrend(last7Rev, prev7Rev);
    var revenueScore = 30;
    if (revTrend !== null && revTrend < 0) {
      var drop = Math.abs(revTrend);
      if (drop >= 20) revenueScore = 10;
      else revenueScore = 20;
    }

    // 2) Inventory score (25)
    var inv = (data && data.inventory_metrics) ? data.inventory_metrics : {};
    var deadStock = Number(inv.dead_stock_value || 0);
    var restockNeeded = Number(inv.restock_needed_value || 0);
    var inventoryScore = 25;
    if (deadStock > 1000) {
      inventoryScore = 10;
    } else if (deadStock > 0 || restockNeeded > 0) {
      inventoryScore = 18;
    }

    // 3) Customer score (25)
    var totalOrders = Number(data && data.kpi ? data.kpi.orders : 0);
    var totalCustomers = Number(data && data.kpi ? data.kpi.customers : 0);
    var repeatRate = totalCustomers > 0 ? clamp((totalOrders - totalCustomers) / totalCustomers, 0, 1) : 0;
    var customerScore = 25;
    if (repeatRate < 0.2) customerScore = 10;
    else if (repeatRate < 0.3) customerScore = 18;

    // 4) Alert score (20)
    var criticalCount = (issues || []).filter(function (i) { return String(i && i.severity || '').toLowerCase() === 'high'; }).length;
    var alertScore = 20;
    if (criticalCount >= 3) alertScore = 5;
    else if (criticalCount >= 1) alertScore = 12;

    var score = clamp(Math.round(revenueScore + inventoryScore + customerScore + alertScore), 0, 100);
    if (!isFinite(score)) {
      return { score: 0, status: 'Needs Attention', biggestIssue: 'No health data available yet.', breakdown: { revenue: 0, inventory: 0, customers: 0, alerts: 0 } };
    }
    var status = 'Critical';
    if (score >= 80) status = 'Good';
    else if (score >= 50) status = 'Needs Attention';

    var biggestIssue = 'No major issue detected.';
    if (revenueScore <= 10) {
      biggestIssue = 'Revenue dropped by more than 20% versus last week.';
    } else if (alertScore <= 12 && criticalCount > 0) {
      var highIssue = (issues || []).find(function (i) { return String(i && i.severity || '').toLowerCase() === 'high'; });
      biggestIssue = (highIssue && (highIssue.description || highIssue.title)) || 'Critical alerts need immediate action.';
    } else if (inventoryScore <= 18) {
      biggestIssue = deadStock > 1000 ? 'High dead stock is tying up inventory value.' : 'Some inventory is not moving or needs restock.';
    } else if (customerScore <= 18) {
      biggestIssue = 'Repeat customer rate is low.';
    }

    return {
      score: score,
      status: status,
      biggestIssue: biggestIssue,
      breakdown: { revenue: revenueScore, inventory: inventoryScore, customers: customerScore, alerts: alertScore }
    };
  }

  function getHealthStatusClass(status) {
    var value = String(status || '').toLowerCase();
    if (value === 'good') return 'health-status-good';
    if (value === 'critical') return 'health-status-critical';
    return 'health-status-needs-attention';
  }

  function renderAcStoreHealth(health) {
    var statusEl = document.getElementById('acStoreHealthStatus');
    var scoreEl = document.getElementById('acStoreHealthScoreValue');
    var breakdownEl = document.getElementById('acStoreHealthBreakdown');
    var issueEl = document.getElementById('acStoreHealthIssue');

    if (statusEl) {
      statusEl.textContent = health.status || 'Needs Attention';
      statusEl.classList.remove('health-status-good', 'health-status-needs-attention', 'health-status-critical');
      statusEl.classList.add(getHealthStatusClass(health.status));
    }
    if (scoreEl) scoreEl.textContent = String(Number(health.score || 0));
    if (issueEl) issueEl.textContent = 'Biggest Issue: ' + (health.biggestIssue || 'No major issue detected.');
    if (!breakdownEl) return;

    var revenue = Number(health && health.breakdown ? health.breakdown.revenue : 0);
    var inventory = Number(health && health.breakdown ? health.breakdown.inventory : 0);
    var customers = Number(health && health.breakdown ? health.breakdown.customers : 0);
    var alerts = Number(health && health.breakdown ? health.breakdown.alerts : 0);

    var rows = [
      { icon: '💵', label: 'Revenue', value: revenue, max: 30, cls: 'health-fill-revenue' },
      { icon: '📦', label: 'Inventory', value: inventory, max: 25, cls: 'health-fill-inventory' },
      { icon: '👥', label: 'Customers', value: customers, max: 25, cls: 'health-fill-customers' },
      { icon: '🚨', label: 'Alerts', value: alerts, max: 20, cls: 'health-fill-alerts' }
    ];
    breakdownEl.innerHTML = rows.map(function (row) {
      var pct = row.max > 0 ? Math.max(0, Math.min(100, Math.round((row.value / row.max) * 100))) : 0;
      return (
        '<div class="store-health-row">' +
          '<div class="store-health-row-label"><span class="store-health-icon">' + row.icon + '</span>' + row.label + '</div>' +
          '<div class="store-health-row-bar"><span class="' + row.cls + '" style="width:' + pct + '%"></span></div>' +
          '<div class="store-health-row-value">' + Math.round(row.value) + '/' + row.max + '</div>' +
        '</div>'
      );
    }).join('');
  }

  function renderCriticalIssues(targetId, issues) {
    var el = document.getElementById(targetId);
    if (!el) return;
    var arr = Array.isArray(issues) ? issues : [];
    if (!arr.length) {
      el.innerHTML = '<div class="critical-empty-state"><div class="critical-empty-title">No critical issues</div><div class="critical-empty-text">You’re looking good right now.</div></div>';
      return;
    }
    el.innerHTML = arr.map(function (i) {
      var sev = String(i && i.severity || 'medium').toLowerCase();
      return (
        '<div class="critical-item ' + toSeverityClass(sev) + '">' +
          '<div class="critical-item-main">' +
            '<div class="critical-item-left">' +
              '<span class="' + toPriorityBadge(sev) + '">' + esc(sev.toUpperCase()) + '</span>' +
              '<div class="critical-item-text">' +
                '<div class="critical-item-title">' + esc(i.title || 'Insight') + '</div>' +
                (i.description ? '<div class="critical-item-desc">' + esc(i.description) + '</div>' : '') +
              '</div>' +
            '</div>' +
          '</div>' +
        '</div>'
      );
    }).join('');
  }

  async function fetchDashboard(rangeDays) {
    var shop = getParam('shop');
    var host = getParam('host');
    if (!shop) throw new Error('Missing shop.');
    // Dashboard API route is /app/api/dashboard (no .php).
    var url = '/app/api/dashboard?shop=' + encodeURIComponent(shop) + '&host=' + encodeURIComponent(host || '') + '&range=' + encodeURIComponent(String(rangeDays || 30));
    var doFetch = window.authFetch || fetch;
    var res = await doFetch(url, { headers: { Accept: 'application/json' } });
    var data = await res.json();
    if (!res.ok || !data || data.ok !== true) {
      throw new Error((data && data.error) ? data.error : 'Failed to load dashboard data.');
    }
    return data;
  }

  async function fetchReportSummary(tab, rangeDays) {
    var shop = getParam('shop');
    if (!shop) throw new Error('Missing shop.');
    var url = '/app/api/reports/summary.php?shop=' + encodeURIComponent(shop) +
      '&tab=' + encodeURIComponent(tab) +
      '&range=' + encodeURIComponent(String(rangeDays || 7));
    var doFetch = window.authFetch || fetch;
    var res = await doFetch(url, { headers: { Accept: 'application/json' } });
    var data = await res.json();
    if (!res.ok || !data || data.ok !== true) {
      return null;
    }
    return data;
  }

  async function fetchAlertsSummary(limit) {
    var shop = getParam('shop');
    if (!shop) throw new Error('Missing shop.');
    var url = '/app/api/alerts/summary.php?shop=' + encodeURIComponent(shop) +
      '&limit=' + encodeURIComponent(String(limit || 6));
    var doFetch = window.authFetch || fetch;
    var res = await doFetch(url, { headers: { Accept: 'application/json' } });
    var data = await res.json();
    if (!res.ok || !data || data.ok !== true) return null;
    return data;
  }

  async function fetchActionList(status, limit) {
    var shop = getParam('shop');
    if (!shop) throw new Error('Missing shop.');
    var url = '/app/api/actions/list.php?shop=' + encodeURIComponent(shop) +
      '&status=' + encodeURIComponent(status || 'new') +
      '&limit=' + encodeURIComponent(String(limit || 20));
    var doFetch = window.authFetch || fetch;
    var res = await doFetch(url, { headers: { Accept: 'application/json' } });
    var data = await res.json();
    if (!res.ok || !data || data.ok !== true) return [];
    return data.items || [];
  }

  function renderPriorityQueue(items) {
    var arr = Array.isArray(items) ? items.slice(0, 8) : [];
    if (!arr.length) {
      return '<div class="sb-muted">No prioritized actions yet. Sync your store, then check again.</div>';
    }
    return arr.map(function (it) {
      var sev = String(it.severity || 'medium').toLowerCase();
      var impact = Math.round(Number(it.impact_score || 0));
      var conf = Math.round(Number(it.confidence_score || 0) * 100);
      var key = String(it.key || '');
      return (
        '<div class="critical-item ' + toSeverityClass(sev) + '">' +
          '<div class="critical-item-main">' +
            '<div class="critical-item-left">' +
              '<span class="' + toPriorityBadge(sev) + '">' + esc(sev.toUpperCase()) + '</span>' +
              '<div class="critical-item-text">' +
                '<div class="critical-item-title">' + esc(it.title || 'Action') + '</div>' +
                (it.description ? '<div class="critical-item-desc">' + esc(it.description) + '</div>' : '') +
                '<div class="critical-item-desc">Impact ' + impact + '/100 · Confidence ' + conf + '%</div>' +
              '</div>' +
            '</div>' +
            '<div class="critical-item-right">' +
              '<a class="critical-action-btn" href="' + esc(it.cta_url || '#') + '">' + esc(it.cta_label || 'View details') + '</a>' +
              '<button class="critical-action-btn ac-action-btn" type="button" data-action-key="' + esc(key) + '" data-action-state="acted">Mark acted</button>' +
            '</div>' +
          '</div>' +
        '</div>'
      );
    }).join('');
  }

  function renderSimpleList(items, emptyText) {
    var arr = Array.isArray(items) ? items : [];
    if (!arr.length) return '<div class="sb-muted">' + esc(emptyText || 'No data yet.') + '</div>';
    return arr.map(function (x) {
      return '<div class="SbListRow"><div class="sb-list-left">' + esc(x.left || '') + '</div><div class="sb-list-right">' + esc(x.right || '') + '</div></div>';
    }).join('');
  }

  async function wireActionButtons() {
    document.querySelectorAll('.ac-action-btn').forEach(function (btn) {
      btn.addEventListener('click', async function () {
        var key = btn.getAttribute('data-action-key') || '';
        var status = btn.getAttribute('data-action-state') || 'acted';
        if (!key) return;
        btn.disabled = true;
        try {
          var shop = getParam('shop');
          var doFetch = window.authFetch || fetch;
          var res = await doFetch('/app/api/actions/update.php?shop=' + encodeURIComponent(shop), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ action_key: key, status: status })
          });
          var data = await res.json();
          if (!res.ok || !data || data.ok !== true) throw new Error((data && data.error) ? data.error : 'Update failed');
          btn.textContent = 'Marked';
        } catch (e) {
          showNotice((e && e.message) ? e.message : 'Failed to update action.', 'error');
        } finally {
          btn.disabled = false;
        }
      });
    });
  }

  function activateTab(tabName) {
    document.querySelectorAll('#acTabs .tab').forEach(function (tab) {
      tab.classList.toggle('active', tab.getAttribute('data-tab') === tabName);
    });
    document.querySelectorAll('.tab-panel').forEach(function (panel) {
      panel.classList.toggle('active', panel.id === 'ac-tab-' + tabName);
    });
  }

  async function loadOverview(rangeDays) {
    // Load dashboard + alerts in parallel (keep UI snappy).
    var dash = null;
    var alerts = null;
    try {
      var results = await Promise.all([
        fetchDashboard(rangeDays),
        fetchAlertsSummary(6)
      ]);
      dash = results[0];
      alerts = results[1];
    } catch (e) {
      dash = await fetchDashboard(rangeDays);
      alerts = null;
    }

    var actions = Array.isArray(dash.action_center) ? dash.action_center : [];
    setHTML('acPriorityQueue', renderPriorityQueue(actions));
    setHTML('acActionsPreview', renderPriorityQueue(actions.slice(0, 5)));

    var critical = Array.isArray(dash.critical_issues) ? dash.critical_issues : [];
    renderCriticalIssues('acCriticalIssuesGrid', critical);

    // Store health (computed using same logic as Dashboard)
    var health = computeStoreHealth(dash);
    renderAcStoreHealth(health);
    setHTML('acCriticalCount', String(critical.length));
    var forecast = Array.isArray(dash.inventory_forecast) ? dash.inventory_forecast : [];
    setHTML('acStockoutCount', String(forecast.length));

    // Alerts theme preview (from alerts API if available)
    var themeRows = [];
    if (alerts && Array.isArray(alerts.themes)) {
      themeRows = alerts.themes.map(function (t) {
        return { left: String(t.key || 'other').replace(/_/g, ' '), right: String(t.count || 0) };
      });
    }
    setHTML('acAlertThemes', renderSimpleList(themeRows, 'No alert themes yet.'));

    // Alerts tab list preview (rendered even on Overview load for instant tab switch)
    if (alerts) {
      renderAlertsTab(alerts);
    }

    await wireActionButtons();
  }

  function renderAlertsTab(alerts) {
    var critical = alerts && Array.isArray(alerts.critical) ? alerts.critical : [];
    var warning = alerts && Array.isArray(alerts.warning) ? alerts.warning : [];

    function listBlock(title, items) {
      if (!items.length) return '<div class="sb-muted">No ' + esc(title.toLowerCase()) + ' alerts.</div>';
      return items.slice(0, 6).map(function (a) {
        var t = a && a.title ? String(a.title) : 'Alert';
        var meta = a && a.meta ? String(a.meta) : '';
        var key = a && a.details_url_key ? String(a.details_url_key) : '';
        return (
          '<div class="SbListRow">' +
            '<div class="sb-list-left">' + esc(t) + (meta ? '<div class="sb-muted" style="margin-top:3px;">' + esc(meta) + '</div>' : '') + '</div>' +
            '<div class="sb-list-right">' + esc(key ? key.toUpperCase() : '') + '</div>' +
          '</div>'
        );
      }).join('');
    }

    setHTML('acAlertsCritical',
      '<div class="kpi-title" style="margin-bottom:8px;">Critical</div>' + listBlock('Critical', critical)
    );
    setHTML('acAlertsWarning',
      '<div class="kpi-title" style="margin-bottom:8px;">Warnings</div>' + listBlock('Warnings', warning)
    );
  }

  async function loadReports(rangeDays) {
    var rev = await fetchReportSummary('revenue', rangeDays);
    var cust = await fetchReportSummary('customers', rangeDays);
    var inv = await fetchReportSummary('inventory', rangeDays);

    function setBullets(id, data, fallback) {
      var el = document.getElementById(id);
      if (!el) return;
      var bullets = data && Array.isArray(data.summary_bullets) ? data.summary_bullets : [];
      if (!bullets.length) {
        el.innerHTML = '<li class="sb-muted">' + esc(fallback || 'No summary yet.') + '</li>';
        return;
      }
      el.innerHTML = bullets.slice(0, 4).map(function (t) { return '<li>' + esc(t) + '</li>'; }).join('');
    }

    setBullets('acReportsRevenue', rev, 'Revenue summary not available.');
    setBullets('acReportsCustomers', cust, 'Customers summary not available.');
    setBullets('acReportsInventory', inv, 'Inventory summary not available.');

    // Weekly plan: simple synthesis from available summaries
    var plan = [];
    if (inv && inv.recommendations && inv.recommendations[0]) plan.push('Inventory: ' + (inv.recommendations[0].title || 'Restock high-risk SKUs'));
    if (rev && rev.recommendations && rev.recommendations[0]) plan.push('Revenue: ' + (rev.recommendations[0].title || 'Diversify promotions'));
    if (cust && cust.recommendations && cust.recommendations[0]) plan.push('Customers: ' + (cust.recommendations[0].title || 'Improve retention sequence'));
    if (!plan.length) plan = ['Generate a plan after your first sync completes.'];
    setHTML('acWeeklyPlan', '<ul class="report-list">' + plan.map(function (t) { return '<li>' + esc(t) + '</li>'; }).join('') + '</ul>');
  }

  async function loadRecommendations(rangeDays) {
    var rev = await fetchReportSummary('revenue', rangeDays);
    var cust = await fetchReportSummary('customers', rangeDays);
    var inv = await fetchReportSummary('inventory', rangeDays);

    var blocks = [];
    function addBlock(label, data) {
      var recs = data && Array.isArray(data.recommendations) ? data.recommendations : [];
      if (!recs.length) return;
      blocks.push(
        '<div class="SbListRow"><div class="sb-list-left"><strong>' + esc(label) + '</strong></div><div class="sb-list-right"></div></div>' +
        recs.slice(0, 2).map(function (r) {
          return '<div class="SbListRow"><div class="sb-list-left">' + esc(r.title || 'Recommendation') +
            (r.impact ? '<div class="sb-muted" style="margin-top:3px;">' + esc(r.impact) + '</div>' : '') +
          '</div><div class="sb-list-right">—</div></div>';
        }).join('')
      );
    }
    addBlock('Revenue', rev);
    addBlock('Customers', cust);
    addBlock('Inventory', inv);
    setHTML('acRecoFromReports', blocks.length ? blocks.join('') : '<div class="sb-muted">No recommendations yet.</div>');
  }

  async function loadHistory() {
    var active = []
      .concat(await fetchActionList('new', 20))
      .concat(await fetchActionList('viewed', 20));
    var acted = await fetchActionList('acted', 20);

    function renderHistoryList(items, emptyText) {
      var arr = Array.isArray(items) ? items : [];
      if (!arr.length) return '<div class="sb-muted">' + esc(emptyText || 'No actions yet.') + '</div>';
      return arr.map(function (it) {
        var sev = String(it.severity || 'medium').toLowerCase();
        var impact = Math.round(Number(it.impact_score || 0));
        return (
          '<div class="SbListRow">' +
            '<div class="sb-list-left">' + esc(it.title || 'Action') + '</div>' +
            '<div class="sb-list-right">' + esc(sev.toUpperCase()) + ' · ' + impact + '/100</div>' +
          '</div>'
        );
      }).join('');
    }

    setHTML('acHistoryActive', renderHistoryList(active, 'No active actions.'));
    setHTML('acHistoryActed', renderHistoryList(acted, 'No acted actions.'));
  }

  function getActiveRange() {
    var btn = document.querySelector('.ac-range.active');
    return btn ? Number(btn.getAttribute('data-range') || 7) : 7;
  }

  function getActiveTabName() {
    var activeTab = document.querySelector('#acTabs .tab.active');
    return activeTab ? (activeTab.getAttribute('data-tab') || 'overview') : 'overview';
  }

  function setUrlState(tabName, rangeDays) {
    try {
      var url = new URL(window.location.href);
      url.searchParams.set('tab', String(tabName || 'overview'));
      url.searchParams.set('range', String(rangeDays || 7));
      window.history.replaceState({}, '', url.toString());
    } catch (e) {}
  }

  function applyInitialStateFromUrl() {
    var tab = (getParam('tab') || 'overview').toLowerCase();
    var allowedTabs = { overview: 1, alerts: 1, recommendations: 1, reports: 1, history: 1 };
    if (!allowedTabs[tab]) tab = 'overview';

    var range = Number(getParam('range') || 7);
    if ([7, 30, 90].indexOf(range) === -1) range = 7;

    // Range button state
    document.querySelectorAll('.ac-range').forEach(function (b) {
      b.classList.toggle('active', Number(b.getAttribute('data-range') || 0) === range);
    });

    // Tab state
    activateTab(tab);

    // Ensure URL is normalized
    setUrlState(tab, range);

    return { tab: tab, range: range };
  }

  document.addEventListener('DOMContentLoaded', function () {
    // Tabs
    document.querySelectorAll('#acTabs .tab').forEach(function (tab) {
      tab.addEventListener('click', function () {
        var name = tab.getAttribute('data-tab') || 'overview';
        activateTab(name);
        var range = getActiveRange();
        setUrlState(name, range);
        if (name === 'overview' || name === 'alerts') {
          loadOverview(range).catch(function (e) { showNotice(e && e.message ? e.message : 'Failed to load Action Center.', 'error'); });
        }
        if (name === 'reports') {
          loadReports(range).catch(function () {});
        }
        if (name === 'history') {
          loadHistory().catch(function () {});
        }
        if (name === 'recommendations') {
          loadRecommendations(range).catch(function () {});
          setHTML('acRecoQuickWins', '<ul class="report-list"><li>Start with 1 cross-sell bundle on your top product page.</li><li>Add an upsell to cart for best sellers.</li><li>Send a 7-day post-purchase offer.</li></ul>');
        }
      });
    });

    // Range buttons
    document.querySelectorAll('.ac-range').forEach(function (btn) {
      btn.addEventListener('click', function () {
        document.querySelectorAll('.ac-range').forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
        var name = getActiveTabName();
        var range = getActiveRange();
        setUrlState(name, range);
        if (name === 'overview' || name === 'alerts') loadOverview(range).catch(function () {});
        if (name === 'reports') loadReports(range).catch(function () {});
        if (name === 'recommendations') loadRecommendations(range).catch(function () {});
      });
    });

    // Initial load
    var initial = applyInitialStateFromUrl();
    var initialTab = initial.tab;
    var initialRange = initial.range;

    if (initialTab === 'overview' || initialTab === 'alerts') {
      loadOverview(initialRange).catch(function (e) {
        showNotice(e && e.message ? e.message : 'Failed to load Action Center.', 'error');
      });
    } else if (initialTab === 'reports') {
      loadReports(initialRange).catch(function () {});
    } else if (initialTab === 'recommendations') {
      loadRecommendations(initialRange).catch(function () {});
      setHTML('acRecoQuickWins', '<ul class="report-list"><li>Start with 1 cross-sell bundle on your top product page.</li><li>Add an upsell to cart for best sellers.</li><li>Send a 7-day post-purchase offer.</li></ul>');
    } else if (initialTab === 'history') {
      loadHistory().catch(function () {});
    }
  });
})();

