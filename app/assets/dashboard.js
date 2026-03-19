/* SalesBoost AI Dashboard (vanilla JS) */

function fmtCurrency(amount) {
  const n = Number(amount || 0);
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD', maximumFractionDigits: 2 }).format(n);
}

function fmtNumber(n) {
  return new Intl.NumberFormat(undefined).format(Number(n || 0));
}

function getQueryParam(name) {
  const url = new URL(window.location.href);
  return url.searchParams.get(name) || '';
}

function setText(id, text) {
  const el = document.getElementById(id);
  if (el) el.textContent = text;
}

function setHtml(id, html) {
  const el = document.getElementById(id);
  if (el) el.innerHTML = html;
}

function escapeHtml(str) {
  return String(str ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function highlightNumbers(text) {
  const safe = escapeHtml(text);
  // Highlight currency amounts, percentages, and standalone large numbers
  return safe
    .replace(/(\$[0-9][0-9,]*\.[0-9]{2})/g, '<span class="sb-highlight">$1</span>')
    .replace(/(\b[0-9]{1,3}%\b)/g, '<span class="sb-highlight">$1</span>')
    .replace(/(\b[0-9]{4,}\b)/g, '<span class="sb-highlight">$1</span>');
}

function show(id, on) {
  const el = document.getElementById(id);
  if (!el) return;
  el.style.display = on ? '' : 'none';
}

function renderList(containerId, items, renderRow) {
  const el = document.getElementById(containerId);
  if (!el) return;
  el.innerHTML = '';
  if (!items || !items.length) {
    el.innerHTML = `<div class="sb-muted">No data yet.</div>`;
    return;
  }
  el.append(...items.map((it, idx) => renderRow(it, idx)));
}

function makeRow(left, right) {
  const row = document.createElement('div');
  row.className = 'SbListRow';
  const l = document.createElement('div');
  l.className = 'sb-list-left';
  l.textContent = left;
  const r = document.createElement('div');
  r.className = 'sb-list-right';
  r.innerHTML = right;
  row.appendChild(l);
  row.appendChild(r);
  return row;
}

let revenueChart = null;
let ordersChart = null;
let fullCharts = null;

function renderCharts(charts) {
  const labels = charts?.labels || [];
  const revenue = charts?.revenue || [];
  const orders = charts?.orders || [];

  const revenueCtx = document.getElementById('revenueChart')?.getContext('2d');
  const ordersCtx = document.getElementById('ordersChart')?.getContext('2d');
  if (!revenueCtx || !ordersCtx) return;

  if (revenueChart) revenueChart.destroy();
  if (ordersChart) ordersChart.destroy();

  // Revenue "hero" styling: gradient line + highlight last point
  const revLine = revenueCtx.createLinearGradient(0, 0, 0, 320);
  revLine.addColorStop(0, '#6366f1');
  revLine.addColorStop(1, '#22c55e');
  const revFill = revenueCtx.createLinearGradient(0, 0, 0, 320);
  revFill.addColorStop(0, 'rgba(99,102,241,0.18)');
  revFill.addColorStop(1, 'rgba(34,197,94,0.05)');
  const lastIdx = Math.max(0, revenue.length - 1);

  revenueChart = new Chart(revenueCtx, {
    type: 'line',
    data: {
      labels,
      datasets: [{
        label: 'Revenue',
        data: revenue,
        borderColor: revLine,
        backgroundColor: revFill,
        tension: 0.42,
        fill: true,
        pointRadius: (ctx) => (ctx.dataIndex === lastIdx ? 4 : 0),
        pointHoverRadius: 6,
        pointBackgroundColor: (ctx) => (ctx.dataIndex === lastIdx ? '#111827' : 'transparent'),
        pointBorderColor: (ctx) => (ctx.dataIndex === lastIdx ? '#ffffff' : 'transparent'),
        pointBorderWidth: (ctx) => (ctx.dataIndex === lastIdx ? 2 : 0),
        borderWidth: 3
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: (ctx) => ` ${fmtCurrency(ctx.parsed.y)}`
          }
        }
      },
      scales: {
        x: {
          ticks: { maxTicksLimit: 6, color: '#6b7280' },
          grid: { display: false }
        },
        y: {
          ticks: {
            color: '#6b7280',
            callback: (v) => fmtCurrency(v)
          }
        }
      }
    }
  });

  ordersChart = new Chart(ordersCtx, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: 'Orders',
        data: orders,
        backgroundColor: 'rgba(99, 102, 241, 0.18)',
        borderColor: '#4f46e5',
        borderWidth: 1
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: (ctx) => ` ${fmtNumber(ctx.parsed.y)} orders`
          }
        }
      },
      scales: {
        x: { ticks: { maxTicksLimit: 6, color: '#6b7280' }, grid: { display: false } },
        y: { beginAtZero: true, ticks: { color: '#6b7280' } }
      }
    }
  });
}

function sliceCharts(charts, days) {
  const labels = charts?.labels || [];
  const revenue = charts?.revenue || [];
  const orders = charts?.orders || [];
  const n = Math.max(1, Math.min(days, labels.length));
  return {
    labels: labels.slice(-n),
    revenue: revenue.slice(-n),
    orders: orders.slice(-n),
  };
}

function computeTrend(current, previous) {
  if (previous <= 0) return null;
  const pct = ((current - previous) / previous) * 100;
  if (!isFinite(pct)) return null;
  return pct;
}

function setTrend(elId, pct) {
  const el = document.getElementById(elId);
  if (!el) return;
  if (pct === null) {
    el.textContent = '';
    el.className = 'kpi-trend';
    return;
  }
  const sign = pct >= 0 ? '+' : '';
  el.textContent = `${sign}${pct.toFixed(0)}% ${pct >= 0 ? '↑' : '↓'}`;
  el.className = `kpi-trend ${pct >= 0 ? 'kpi-trend--up' : 'kpi-trend--down'}`;
}

function renderCriticalIssues(issues) {
  const grid = document.getElementById('criticalIssuesGrid');
  if (!grid) return;
  grid.innerHTML = '';

  if (!issues || !issues.length) {
    const empty = document.createElement('div');
    empty.className = 'card critical-empty';
    empty.innerHTML = `
      <div class="critical-empty-icon">🎉</div>
      <div class="section-title" style="margin-bottom:6px;">No critical issues detected</div>
      <div class="sb-muted" style="padding:0;">Your store is performing well. Keep going!</div>
    `;
    grid.appendChild(empty);
    return;
  }

  issues.slice(0, 4).forEach((issue) => {
    const sev = (issue.severity || 'medium').toLowerCase();
    const badge =
      sev === 'high' ? 'badge badge-red' :
      sev === 'low' ? 'badge badge-green' :
      'badge badge-orange';

    const card = document.createElement('div');
    card.className = `card critical-card critical-${sev}`;
    card.innerHTML = `
      <div class="critical-top">
        <span class="${badge}">${sev.toUpperCase()}</span>
      </div>
      <div class="critical-title">${escapeHtml(issue.title || 'Insight')}</div>
      <div class="sb-muted" style="padding:0;">${escapeHtml(issue.description || '')}</div>
      <div style="margin-top:12px;">
        <button class="btn btn-primary" type="button">View details</button>
      </div>
    `;
    grid.appendChild(card);
  });
}

async function loadDashboard() {
  const shop = getQueryParam('shop');
  const host = getQueryParam('host');

  show('sbSkeleton', true);
  show('sbContent', false);
  show('sbError', false);

  try {
    const res = await fetch(`/app/api/dashboard?shop=${encodeURIComponent(shop)}&host=${encodeURIComponent(host)}`, {
      headers: { 'Accept': 'application/json' }
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data?.error || 'Failed to load dashboard.');

    // KPIs
    setText('kpiRevenue', fmtCurrency(data?.kpi?.revenue || 0));
    setText('kpiOrders', fmtNumber(data?.kpi?.orders || 0));
    setText('kpiAov', fmtCurrency(data?.kpi?.aov || 0));
    setText('kpiCustomers', fmtNumber(data?.kpi?.customers || 0));
    setText('sbOrdersStat', fmtNumber(data?.kpi?.orders || 0));
    setText('sbHvCustomersStat', fmtNumber((data?.insights?.high_value_customers || []).length || 0));

    // AI summary
    setHtml('aiSummaryText', highlightNumbers(data?.summary_text || 'Insights will appear here once enough data is available.'));

    // Inventory KPIs
    const inv = data?.inventory_metrics || {};
    setText('kpiCashInventory', fmtCurrency(inv.cash_in_inventory || 0));
    setText('kpiDeadStock', fmtCurrency(inv.dead_stock_value || 0));
    setText('kpiRestockValue', fmtCurrency(inv.restock_needed_value || 0));

    // Charts
    fullCharts = data?.charts || {};
    renderCharts(fullCharts);

    // Chart range toggles
    document.querySelectorAll('[data-range]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const days = parseInt(btn.getAttribute('data-range') || '30', 10);
        const sliced = sliceCharts(fullCharts, days);
        renderCharts(sliced);
      });
    });

    // KPI trends (use last 7 vs previous 7 from chart series)
    const rev = fullCharts?.revenue || [];
    const ord = fullCharts?.orders || [];
    const last7Rev = rev.slice(-7).reduce((a, b) => a + Number(b || 0), 0);
    const prev7Rev = rev.slice(-14, -7).reduce((a, b) => a + Number(b || 0), 0);
    const last7Ord = ord.slice(-7).reduce((a, b) => a + Number(b || 0), 0);
    const prev7Ord = ord.slice(-14, -7).reduce((a, b) => a + Number(b || 0), 0);
    setTrend('trendRevenue', computeTrend(last7Rev, prev7Rev));
    setTrend('trendOrders', computeTrend(last7Ord, prev7Ord));
    setTrend('trendCustomers', null);
    setTrend('trendAov', null);

    // Insights
    renderList('topProductsList', data?.insights?.top_products || [], (p, idx) => {
      const badges = [];
      if (idx === 0) badges.push(`<span class="sb-pill-badge sb-pill-badge--purple">Top Seller</span>`);
      const right = `
        <div class="sb-badges">
          ${badges.join(' ')}
          <span class="SbBadge">${fmtNumber(p.quantity || 0)} sold</span>
        </div>`;
      return makeRow(p.title || '—', right);
    });
    renderList('lowStockList', data?.insights?.low_stock || [], (p) => {
      const right = `
        <div class="sb-badges">
          <span class="sb-pill-badge sb-pill-badge--orange">Low Stock</span>
          <span class="SbBadge">${fmtNumber(p.inventory_quantity ?? 0)} left</span>
        </div>`;
      return makeRow(p.title || p.sku || '—', right);
    });
    renderList('highValueCustomersList', data?.insights?.high_value_customers || [], (c, idx) => {
      const label = c.label || c.email || `Customer ${c.customer_id || ''}`.trim() || '—';
      const badges = [];
      if (idx === 0) badges.push(`<span class="sb-pill-badge sb-pill-badge--green">High Value</span>`);
      const right = `
        <div class="sb-badges">
          ${badges.join(' ')}
          <span class="SbBadge">${fmtCurrency(c.total_spent || 0)}</span>
        </div>`;
      return makeRow(label, right);
    });

    // Critical issues (premium cards + empty state)
    renderCriticalIssues(data?.critical_issues || []);

    // Key insights bullets
    const kiEl = document.getElementById('keyInsightsList');
    if (kiEl) {
      kiEl.innerHTML = '';
      const list = data?.key_insights || [];
      if (!list.length) {
        kiEl.innerHTML = `<div class="sb-muted">No insights yet.</div>`;
      } else {
        const ul = document.createElement('ul');
        ul.className = 'sb-keyinsights-list';
        list.forEach((t) => {
          const li = document.createElement('li');
          li.textContent = t;
          ul.appendChild(li);
        });
        kiEl.appendChild(ul);
      }
    }

    show('sbSkeleton', false);
    show('sbContent', true);
  } catch (e) {
    setText('sbErrorText', e?.message || 'Something went wrong.');
    show('sbSkeleton', false);
    show('sbContent', false);
    show('sbError', true);
  }
}

document.addEventListener('DOMContentLoaded', () => {
  loadDashboard();
});

