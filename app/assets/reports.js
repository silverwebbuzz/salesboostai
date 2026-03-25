/* Reports page shell (Batch R1) */
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

  function setList(id, items) {
    var el = document.getElementById(id);
    if (!el) return;
    var arr = items || [];
    if (!arr.length) {
      el.innerHTML = '<li class="sb-muted">No summary yet.</li>';
      return;
    }
    el.innerHTML = arr.map(function (t) { return '<li>' + esc(t) + '</li>'; }).join('');
  }

  function renderCritical(elId, items) {
    var el = document.getElementById(elId);
    if (!el) return;
    var arr = items || [];
    if (!arr.length) {
      el.innerHTML = '<div class="sb-muted">No critical insights yet.</div>';
      return;
    }
    el.innerHTML = arr.map(function (c) {
      return (
        '<div class="SbListRow">' +
          '<div class="sb-list-left">' + esc(c.title || 'Insight') + '</div>' +
          '<div class="sb-list-right">' + esc(String(c.severity || 'medium').toUpperCase()) + '</div>' +
        '</div>' +
        (c.description ? '<div class="sb-muted" style="margin-top:6px;">' + esc(c.description) + '</div>' : '') +
        '<div style="height:10px;"></div>'
      );
    }).join('');
  }

  function renderActions(elId, items) {
    var el = document.getElementById(elId);
    if (!el) return;
    var arr = items || [];
    if (!arr.length) {
      el.innerHTML = '<div class="sb-muted">No actions yet.</div>';
      return;
    }
    el.innerHTML = arr.map(function (a) {
      return (
        '<div class="SbListRow">' +
          '<div class="sb-list-left">' + esc(a.title || 'Action') + '</div>' +
          '<div class="sb-list-right">' + Math.round(Number(a.impact || 0)) + '/100</div>' +
        '</div>'
      );
    }).join('');
  }

  async function fetchSummary(tab, range) {
    var shop = getParam('shop');
    if (!shop) throw new Error('Missing shop.');
    var url = '/app/api/reports/summary.php?shop=' + encodeURIComponent(shop) +
      '&tab=' + encodeURIComponent(tab) +
      '&range=' + encodeURIComponent(String(range || 7));
    var doFetch = window.authFetch || fetch;
    var res = await doFetch(url, { headers: { Accept: 'application/json' } });
    var data = await res.json();
    if (!res.ok || !data || data.ok !== true) {
      throw new Error((data && data.error) ? data.error : 'Failed to load report.');
    }
    return data;
  }

  async function loadTab(tabName) {
    var rangeBtn = document.querySelector('.report-range.active');
    var range = rangeBtn ? Number(rangeBtn.getAttribute('data-range') || 7) : 7;
    var data = await fetchSummary(tabName, range);

    if (tabName === 'revenue') {
      setList('reportsRevenueSummary', data.summary_bullets || []);
      renderCritical('reportsRevenueCritical', data.critical_insights || []);
      renderActions('reportsRevenueActions', data.actions || []);
      return;
    }
    if (tabName === 'customers') {
      setList('reportsCustomersSummary', data.summary_bullets || []);
      renderCritical('reportsCustomersCritical', data.critical_insights || []);
      renderActions('reportsCustomersActions', data.actions || []);
      setHTML('reportsCustomersRetention', data.supporting && data.supporting.retention_html ? data.supporting.retention_html : '<div class="sb-muted">No retention data.</div>');
      return;
    }
    if (tabName === 'inventory') {
      setList('reportsInventorySummary', data.summary_bullets || []);
      renderCritical('reportsInventoryCritical', data.critical_insights || []);
      renderActions('reportsInventoryActions', data.actions || []);
      setHTML('reportsInventoryForecast', data.supporting && data.supporting.forecast_html ? data.supporting.forecast_html : '<div class="sb-muted">No forecast.</div>');
      return;
    }

    if (tabName === 'funnel') {
      setHTML('reportsFunnelSummary', (data.supporting && data.supporting.funnel_html) ? data.supporting.funnel_html : '<div class="sb-muted">No funnel data.</div>');
      return;
    }
    if (tabName === 'attribution') {
      setHTML('reportsAttributionSummary', (data.supporting && data.supporting.attribution_html) ? data.supporting.attribution_html : '<div class="sb-muted">No attribution data.</div>');
      return;
    }
    if (tabName === 'goals') {
      setHTML('reportsGoalsSummary', (data.supporting && data.supporting.goals_html) ? data.supporting.goals_html : '<div class="sb-muted">No goals configured.</div>');
      return;
    }
    if (tabName === 'ai') {
      setHTML('reportsAiSummary', '<div class="sb-muted">AI summary loaded. Usage week: ' + esc((data.supporting && data.supporting.ai_usage && data.supporting.ai_usage.week_key) || '') + '</div>');
      return;
    }
  }

  function activateTab(tabName) {
    document.querySelectorAll('#reportsTabs .tab').forEach(function (tab) {
      tab.classList.toggle('active', tab.getAttribute('data-tab') === tabName);
    });
    document.querySelectorAll('.tab-panel').forEach(function (panel) {
      panel.classList.toggle('active', panel.id === 'tab-' + tabName);
    });
  }

  function wireTabs() {
    document.querySelectorAll('#reportsTabs .tab').forEach(function (tab) {
      tab.addEventListener('click', function () {
        var name = tab.getAttribute('data-tab') || 'revenue';
        activateTab(name);
        var isLocked = tab.getAttribute('data-locked') === '1';
        if (isLocked) return;
        loadTab(name).catch(function () {});
      });
    });
  }

  function wireRanges() {
    var buttons = document.querySelectorAll('.report-range');
    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        buttons.forEach(function (b) { b.classList.remove('active'); });
        button.classList.add('active');
        var activeTab = document.querySelector('#reportsTabs .tab.active');
        var tabName = activeTab ? (activeTab.getAttribute('data-tab') || 'revenue') : 'revenue';
        var locked = activeTab && activeTab.getAttribute('data-locked') === '1';
        if (locked) return;
        loadTab(tabName).catch(function () {});
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    wireTabs();
    wireRanges();
    var initialTab = getParam('tab') || 'revenue';
    activateTab(initialTab);
    var initialBtn = document.querySelector('#reportsTabs .tab[data-tab=\"' + initialTab + '\"]');
    var locked = initialBtn && initialBtn.getAttribute('data-locked') === '1';
    if (!locked) {
      loadTab(initialTab).catch(function () {});
    }

    var notice = document.getElementById('reportsNotice');
    function showNotice(text, tone) {
      if (!notice) return;
      notice.className = 'card mb-12 ' + (tone === 'error' ? 'reports-notice reports-notice--error' : 'reports-notice');
      notice.classList.remove('is-hidden');
      notice.innerHTML = '<div><strong>' + esc(text) + '</strong></div>';
    }

    var exportBtn = document.getElementById('btnReportsExport');
    if (exportBtn) {
      exportBtn.addEventListener('click', function () {
        exportBtn.disabled = true;
        var shop = getParam('shop');
        var rangeBtn = document.querySelector('.report-range.active');
        var range = rangeBtn ? Number(rangeBtn.getAttribute('data-range') || 7) : 7;
        var activeTab = document.querySelector('#reportsTabs .tab.active');
        var tabName = activeTab ? (activeTab.getAttribute('data-tab') || 'revenue') : 'revenue';
        var url = '/app/api/reports/export.php?shop=' + encodeURIComponent(shop) + '&tab=' + encodeURIComponent(tabName) + '&range=' + encodeURIComponent(String(range));
        var doFetch = window.authFetch || fetch;
        doFetch(url, { headers: { Accept: 'application/json' } })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            showNotice((data && data.message) ? data.message : 'Export is coming soon.', 'info');
          })
          .catch(function () {
            showNotice('Export request failed.', 'error');
          })
          .finally(function () {
            exportBtn.disabled = false;
          });
      });
    }

    var scheduleBtn = document.getElementById('btnReportsSchedule');
    if (scheduleBtn) {
      scheduleBtn.addEventListener('click', function () {
        scheduleBtn.disabled = true;
        var shop = getParam('shop');
        var url = '/app/api/reports/schedule.php?shop=' + encodeURIComponent(shop);
        var doFetch = window.authFetch || fetch;
        doFetch(url, { headers: { Accept: 'application/json' } })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            showNotice((data && data.message) ? data.message : 'Scheduling is coming soon.', 'info');
          })
          .catch(function () {
            showNotice('Schedule request failed.', 'error');
          })
          .finally(function () {
            scheduleBtn.disabled = false;
          });
      });
    }
  });
})();

