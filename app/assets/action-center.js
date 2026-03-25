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

  async function fetchDashboard(rangeDays) {
    var shop = getParam('shop');
    var host = getParam('host');
    if (!shop) throw new Error('Missing shop.');
    var url = '/app/api/dashboard.php?shop=' + encodeURIComponent(shop) + '&host=' + encodeURIComponent(host || '') + '&range=' + encodeURIComponent(String(rangeDays || 30));
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
    setHTML('acCriticalInsights', renderSimpleList(
      critical.map(function (c) {
        return { left: c.title || 'Insight', right: String(c.severity || 'medium').toUpperCase() };
      }),
      'No critical insights yet.'
    ));

    // Snapshot
    var health = dash.store_health || {};
    var score = (health && typeof health.score !== 'undefined') ? Number(health.score || 0) : NaN;
    setHTML('acHealthScore', Number.isFinite(score) ? (Math.round(score) + '/100') : '—');
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

