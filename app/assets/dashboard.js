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

function renderSyncNotice(sync) {
  const state = sync?.state || 'ready';
  const pending = Number(sync?.pending || 0);
  const inProgress = Number(sync?.in_progress || 0);
  const error = Number(sync?.error || 0);

  if (state === 'ready') {
    show('sbSyncNotice', false);
    return;
  }

  if (state === 'needs_sync') {
    setText('sbSyncTitle', 'Import your store data');
    setText('sbSyncText', 'We have not synced products and orders yet. Use Sync Now on the dashboard, then refresh.');
    show('sbSyncNotice', true);
    return;
  }

  if (state === 'error') {
    setText('sbSyncTitle', 'Store sync needs attention');
    setText('sbSyncText', `Some sync tasks failed (${error}). Data may be incomplete. Please retry sync job and refresh.`);
    show('sbSyncNotice', true);
    return;
  }

  setText('sbSyncTitle', 'Store sync in progress');
  setText('sbSyncText', `We are importing your products/orders in background (${pending} pending, ${inProgress} in progress). Dashboard will improve as sync completes.`);
  show('sbSyncNotice', true);
}

function shouldGateDashboard(sync) {
  const state = sync?.state || 'ready';
  return state === 'needs_sync' || state === 'syncing' || state === 'error';
}

function renderSyncGate(sync) {
  const state = sync?.state || 'ready';
  const pending = Number(sync?.pending || 0);
  const inProgress = Number(sync?.in_progress || 0);
  const error = Number(sync?.error || 0);

  if (state === 'ready') {
    show('sbSyncGate', false);
    show('sbDashboardBody', true);
    return;
  }

  show('sbSyncGate', true);
  show('sbDashboardBody', false);
  show('btnRefreshDashboard', false);

  if (state === 'needs_sync') {
    setText('sbSyncGateTitle', 'Welcome — sync your store first');
    setText(
      'sbSyncGateText',
      'Your dashboard stays empty until we import products and orders. This avoids showing misleading numbers from an empty database.'
    );
    setText('sbSyncGateMeta', 'No sync tasks found yet, or import has not started.');
    setText('sbSyncGateHint', 'Click "Sync Now" to run import steps, then refresh.');
    return;
  }

  if (state === 'error') {
    setText('sbSyncGateTitle', 'Sync needs attention');
    setText('sbSyncGateText', 'Some sync tasks failed. Please run sync again.');
    setText('sbSyncGateMeta', `Error tasks: ${error}`);
    setText('sbSyncGateHint', 'You can retry safely. Existing data remains intact.');
    return;
  }

  setText('sbSyncGateTitle', 'Sync your store data');
  setText('sbSyncGateText', 'Sync your store data before using your agents. We need your products and orders to generate insights.');
  setText('sbSyncGateMeta', `Pending: ${pending} · In progress: ${inProgress}`);
  setText('sbSyncGateHint', 'Click "Sync Now" to run immediate background steps.');
}

async function runSyncNow() {
  const shop = getQueryParam('shop');
  const host = getQueryParam('host');
  const btn = document.getElementById('btnRunSync');
  const refreshBtn = document.getElementById('btnRefreshDashboard');
  if (btn) {
    btn.disabled = true;
    btn.textContent = 'Syncing...';
  }
  setText('sbSyncGateHint', 'Running sync steps, please wait...');

  try {
    const doFetch = window.authFetch || fetch;
    const res = await doFetch(`/app/api/sync/run?shop=${encodeURIComponent(shop)}&host=${encodeURIComponent(host)}&steps=12`, {
      method: 'POST',
      headers: { Accept: 'application/json' }
    });
    const data = await res.json();
    if (!res.ok || !data?.ok) {
      throw new Error(data?.error || 'Sync run failed');
    }

    renderSyncGate(data.sync_status || null);
    if ((data?.sync_status?.state || 'ready') === 'ready') {
      setText('sbSyncGateHint', 'Sync completed. Click “Display dashboard” to load your data.');
      if (refreshBtn) show('btnRefreshDashboard', true);
    } else {
      setText('sbSyncGateHint', 'Sync is still running. You can click Sync Now again.');
    }
  } catch (e) {
    setText('sbSyncGateHint', (e && e.message) ? e.message : 'Sync failed. Please try again.');
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.textContent = 'Sync Now';
    }
  }
}

function formatInsight(insight) {
  const map = {
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
  const type = map[insight?.type] ? insight.type : 'medium';
  const cfg = map[type];
  return `
    <div style="display:flex;flex-direction:column;gap:6px;">
      <span style="display:inline-block;align-self:flex-start;padding:4px 8px;border-radius:999px;border:1px solid ${cfg.border};background:${cfg.bg};color:${cfg.color};font-size:12px;font-weight:600;">
        ${cfg.label}
      </span>
      <span style="font-size:14px;color:#1f2937;">${highlightNumbers(insight?.message || '')}</span>
    </div>
  `;
}

function generateInsight(data) {
  const revenueChange = Number(data?.revenue_change ?? NaN);
  const topShare = Number(data?.top_product_share ?? NaN);
  const aov = Number(data?.aov ?? NaN);
  const returningRate = Number(data?.customers?.returning_rate ?? NaN);

  if (!Number.isNaN(revenueChange) && revenueChange === 0) {
    return { type: 'high', message: 'Sales are not growing. Try running a promotion this week.' };
  }
  if (!Number.isNaN(revenueChange) && revenueChange > 10) {
    return { type: 'positive', message: 'Sales are increasing. Keep promoting your top products.' };
  }
  if (!Number.isNaN(topShare) && topShare > 0.4) {
    return { type: 'high', message: 'Most sales come from one product. Promote other items.' };
  }
  if (!Number.isNaN(aov) && aov < 50) {
    return { type: 'medium', message: 'Customers buy small orders. Add bundles or upsells.' };
  }
  if (!Number.isNaN(returningRate) && returningRate < 0.3) {
    return { type: 'high', message: 'Customers are not returning. Try follow-up campaigns.' };
  }
  return { type: 'positive', message: 'Sales look steady. Keep promoting your best products.' };
}

function getTopProductShare(topProducts) {
  const items = topProducts || [];
  if (!items.length) return 0;
  const total = items.reduce((acc, p) => acc + Number(p?.revenue_estimate ?? p?.revenue ?? 0), 0);
  const top = Number(items[0]?.revenue_estimate ?? items[0]?.revenue ?? 0);
  if (total <= 0) return 0;
  return top / total;
}

function getDashboardSummary(data) {
  const charts = data?.charts || {};
  const rev = charts?.revenue || [];
  const last7 = rev.slice(-7).reduce((a, b) => a + Number(b || 0), 0);
  const prev7 = rev.slice(-14, -7).reduce((a, b) => a + Number(b || 0), 0);
  const revChange = prev7 > 0 ? ((last7 - prev7) / prev7) * 100 : 0;
  return generateInsight({ revenue_change: Math.round(revChange) });
}

function normalizeAiInsightType(type) {
  const t = String(type || '').toLowerCase();
  if (t === 'high') return { cls: 'ai-summary-item--action', label: 'Action needed', icon: '!' };
  if (t === 'positive') return { cls: 'ai-summary-item--good', label: 'Good performance', icon: '✓' };
  return { cls: 'ai-summary-item--opportunity', label: 'Opportunity', icon: 'i' };
}

function renderAiSummaryCards(data) {
  const grid = document.getElementById('aiSummaryGrid');
  if (!grid) return;

  const defaults = [
    { type: 'high', message: 'Most sales come from one product. Promote other items.' },
    { type: 'high', message: 'Customers are not returning. Try follow-up campaigns.' },
    { type: 'positive', message: 'Sales look steady. Keep promoting your best products.' },
    { type: 'medium', message: 'Some winning items may run out. Restock soon.' }
  ];

  const dynamic = getDashboardKeyInsights(data);
  const merged = [];
  const used = new Set();
  [...dynamic, ...defaults].forEach((item) => {
    const msg = String(item?.message || '').trim();
    if (!msg || used.has(msg) || merged.length >= 4) return;
    used.add(msg);
    merged.push({ type: item.type, message: msg });
  });
  while (merged.length < 4) merged.push(defaults[merged.length]);

  grid.innerHTML = merged.map((item) => {
    const t = normalizeAiInsightType(item.type);
    return `
      <div class="ai-summary-item ${t.cls}">
        <div class="ai-summary-item-head">
          <span class="ai-summary-icon">${t.icon}</span>
          <span class="ai-summary-label">${t.label}</span>
        </div>
        <div class="ai-summary-text">${escapeHtml(item.message)}</div>
      </div>
    `;
  }).join('');
}

function getDashboardKeyInsights(data) {
  const out = [];
  const topProducts = data?.insights?.top_products || [];
  const customers = data?.insights?.high_value_customers || [];
  const lowStock = data?.insights?.low_stock || [];
  const aov = Number(data?.kpi?.aov || 0);
  const totalCustomers = Number(data?.kpi?.customers || 0);
  const returningRate = totalCustomers > 0 ? customers.length / totalCustomers : 0;

  out.push(generateInsight({ top_product_share: getTopProductShare(topProducts) }));
  out.push(generateInsight({ customers: { returning_rate: returningRate } }));
  out.push(generateInsight({ aov }));

  if (lowStock.length > 0) {
    out.push({ type: 'medium', message: 'Some winning items may run out. Restock soon.' });
  }

  const uniqueMap = new Map();
  out.filter(Boolean).forEach((item) => {
    const key = `${item.type}:${item.message}`;
    if (!uniqueMap.has(key)) uniqueMap.set(key, item);
  });
  const unique = Array.from(uniqueMap.values());
  return unique.slice(0, 4);
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
    el.textContent = 'No prior week data';
    el.className = 'kpi-trend';
    return;
  }
  const sign = pct >= 0 ? '+' : '';
  el.textContent = `${sign}${pct.toFixed(0)}% from last week ${pct >= 0 ? '↑' : '↓'}`;
  el.className = `kpi-trend ${pct >= 0 ? 'kpi-trend--up' : 'kpi-trend--down'}`;
}

function clamp(n, min, max) {
  return Math.max(min, Math.min(max, n));
}

function computeStoreHealth(data) {
  const issues = data?.critical_issues || [];

  // 1) Revenue score (30)
  const rev = data?.charts?.revenue || [];
  const last7Rev = rev.slice(-7).reduce((a, b) => a + Number(b || 0), 0);
  const prev7Rev = rev.slice(-14, -7).reduce((a, b) => a + Number(b || 0), 0);
  const revTrend = computeTrend(last7Rev, prev7Rev);
  let revenueScore = 30;
  if (revTrend !== null && revTrend < 0) {
    const drop = Math.abs(revTrend);
    if (drop >= 20) revenueScore = 10;
    else revenueScore = 20;
  }

  // 2) Inventory score (25)
  const inv = data?.inventory_metrics || {};
  const deadStock = Number(inv.dead_stock_value || 0);
  const restockNeeded = Number(inv.restock_needed_value || 0);
  let inventoryScore = 25;
  if (deadStock > 1000) {
    inventoryScore = 10; // High dead stock
  } else if (deadStock > 0 || restockNeeded > 0) {
    inventoryScore = 18; // Some dead stock / stock pressure
  }

  // 3) Customer score (25)
  // Repeat-rate proxy from existing metrics:
  // if orders ~= customers => low repeat, if orders much higher => stronger repeat.
  const totalOrders = Number(data?.kpi?.orders || 0);
  const totalCustomers = Number(data?.kpi?.customers || 0);
  const repeatRate = totalCustomers > 0
    ? clamp((totalOrders - totalCustomers) / totalCustomers, 0, 1)
    : 0;
  let customerScore = 25;
  if (repeatRate < 0.2) customerScore = 10;
  else if (repeatRate < 0.3) customerScore = 18;

  // 4) Alert score (20)
  const criticalCount = issues.filter((i) => String(i?.severity || '').toLowerCase() === 'high').length;
  let alertScore = 20;
  if (criticalCount >= 3) alertScore = 5;
  else if (criticalCount >= 1) alertScore = 12;

  const score = clamp(Math.round(revenueScore + inventoryScore + customerScore + alertScore), 0, 100);
  if (!Number.isFinite(score)) {
    return {
      score: 0,
      status: 'Needs Attention',
      biggestIssue: 'No health data available yet.',
      breakdown: { revenue: 0, inventory: 0, customers: 0, alerts: 0 }
    };
  }
  let status = 'Critical';
  if (score >= 80) status = 'Good';
  else if (score >= 50) status = 'Needs Attention';

  let biggestIssue = 'No major issue detected.';
  if (revenueScore <= 10) {
    biggestIssue = 'Revenue dropped by more than 20% versus last week.';
  } else if (alertScore <= 12 && criticalCount > 0) {
    const highIssue = issues.find((i) => String(i?.severity || '').toLowerCase() === 'high');
    biggestIssue = (highIssue?.description || highIssue?.title || 'Critical alerts need immediate action.');
  } else if (inventoryScore <= 18) {
    biggestIssue = deadStock > 1000 ? 'High dead stock is tying up inventory value.' : 'Some inventory is not moving or needs restock.';
  } else if (customerScore <= 18) {
    biggestIssue = 'Repeat customer rate is low.';
  }

  return {
    score,
    status,
    biggestIssue,
    breakdown: {
      revenue: revenueScore,
      inventory: inventoryScore,
      customers: customerScore,
      alerts: alertScore
    }
  };
}

function getHealthStatusClass(status) {
  const value = String(status || '').toLowerCase();
  if (value === 'good') return 'health-status-good';
  if (value === 'critical') return 'health-status-critical';
  return 'health-status-needs-attention';
}

function renderStoreHealth(health) {
  const statusEl = document.getElementById('storeHealthStatus');
  const scoreEl = document.getElementById('storeHealthScoreValue');
  const breakdownEl = document.getElementById('storeHealthBreakdown');
  const issueEl = document.getElementById('storeHealthIssue');

  if (statusEl) {
    statusEl.textContent = health.status || 'Needs Attention';
    statusEl.classList.remove('health-status-good', 'health-status-needs-attention', 'health-status-critical');
    statusEl.classList.add(getHealthStatusClass(health.status));
  }

  if (scoreEl) {
    scoreEl.textContent = String(Number(health.score || 0));
  }

  if (issueEl) {
    issueEl.textContent = `Biggest Issue: ${health.biggestIssue || 'No major issue detected.'}`;
  }

  if (!breakdownEl) return;
  const revenue = Number(health?.breakdown?.revenue || 0);
  const inventory = Number(health?.breakdown?.inventory || 0);
  const customers = Number(health?.breakdown?.customers || 0);
  const alerts = Number(health?.breakdown?.alerts || 0);

  const rows = [
    { icon: '💵', label: 'Revenue', value: revenue, max: 30, cls: 'health-fill-revenue' },
    { icon: '📦', label: 'Inventory', value: inventory, max: 25, cls: 'health-fill-inventory' },
    { icon: '👥', label: 'Customers', value: customers, max: 25, cls: 'health-fill-customers' },
    { icon: '🚨', label: 'Alerts', value: alerts, max: 20, cls: 'health-fill-alerts' }
  ];

  breakdownEl.innerHTML = rows.map((row) => {
    const pct = row.max > 0 ? Math.max(0, Math.min(100, Math.round((row.value / row.max) * 100))) : 0;
    return `
      <div class="store-health-row">
        <div class="store-health-row-label"><span class="store-health-icon">${row.icon}</span>${row.label}</div>
        <div class="store-health-row-bar"><span class="${row.cls}" style="width:${pct}%"></span></div>
        <div class="store-health-row-value">${Math.round(row.value)}/${row.max}</div>
      </div>
    `;
  }).join('');
}

function renderInventoryInsights(inv) {
  const cash = Number(inv?.cash_in_inventory || 0);
  const dead = Number(inv?.dead_stock_value || 0);
  const restock = Number(inv?.restock_needed_value || 0);

  setText('kpiCashInventory', fmtCurrency(cash));
  setText('kpiDeadStock', fmtCurrency(dead));
  setText('kpiRestockValue', fmtCurrency(restock));

  const deadBadge = document.getElementById('deadStockBadge');
  const restockBadge = document.getElementById('restockBadge');
  const deadMetric = document.getElementById('deadStockMetric');

  if (deadBadge) {
    deadBadge.textContent = dead > 0 ? 'High' : 'Low';
    deadBadge.className = dead > 0 ? 'inventory-pill inventory-pill--danger' : 'inventory-pill inventory-pill--neutral';
  }
  if (deadMetric) {
    deadMetric.classList.toggle('inventory-metric--dead-alert', dead > 0);
  }
  if (restockBadge) {
    if (restock <= 0) {
      restockBadge.textContent = 'Healthy';
      restockBadge.className = 'inventory-pill inventory-pill--healthy';
    } else {
      restockBadge.textContent = 'Needs restock';
      restockBadge.className = 'inventory-pill inventory-pill--info';
    }
  }
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

async function loadDashboard(opts = {}) {
  const shop = getQueryParam('shop');
  const host = getQueryParam('host');
  const nocache = opts.nocache ? '&nocache=1' : '';

  show('sbSkeleton', true);
  show('sbContent', false);
  show('sbError', false);

  try {
    const doFetch = window.authFetch || fetch;
    const res = await doFetch(
      `/app/api/dashboard?shop=${encodeURIComponent(shop)}&host=${encodeURIComponent(host)}${nocache}`,
      {
        headers: { Accept: 'application/json' }
      }
    );
    const data = await res.json();
    if (!res.ok) throw new Error(data?.error || 'Failed to load dashboard.');

    renderSyncNotice(data?.sync_status || null);
    renderSyncGate(data?.sync_status || null);
    if (shouldGateDashboard(data?.sync_status || null)) {
      const btnSync = document.getElementById('btnRunSync');
      const btnRefresh = document.getElementById('btnRefreshDashboard');
      if (btnSync) btnSync.onclick = runSyncNow;
      if (btnRefresh) btnRefresh.onclick = () => loadDashboard({ nocache: true });
      show('sbSkeleton', false);
      show('sbContent', true);
      return;
    }

    // KPIs
    setText('kpiRevenue', fmtCurrency(data?.kpi?.revenue || 0));
    setText('kpiOrders', fmtNumber(data?.kpi?.orders || 0));
    setText('kpiAov', fmtCurrency(data?.kpi?.aov || 0));
    setText('kpiCustomers', fmtNumber(data?.kpi?.customers || 0));
    setText('sbOrdersStat', fmtNumber(data?.kpi?.orders || 0));
    setText('sbHvCustomersStat', fmtNumber((data?.insights?.high_value_customers || []).length || 0));

    // AI summary
    renderAiSummaryCards(data);

    // Store health score
    const health = computeStoreHealth(data);
    renderStoreHealth(health);

    // Inventory KPIs
    const inv = data?.inventory_metrics || {};
    renderInventoryInsights(inv);

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

