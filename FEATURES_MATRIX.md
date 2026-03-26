## Plan × AI features × display location

| AI feature | free | starter | growth | premium | Displays on |
|---|---:|---:|---:|---:|---|
| **AI anomaly explanation** (“Why did this change?”) | ✗ | ✗ | ✓ | ✓ | `dashboard.php` (KPI cards) + `action-center.php` (header button) |
| **Weekly AI digest paragraph** | ✗ | ✗ | ✓ | ✓ | `action-center.php` → Reports tab → Weekly Plan card (`#acWeeklyDigest`) |
| **Dynamic AI actions (3 actions/day)** | ✗ | ✗ | ✗ | ✓ | Stored into per-store `action_items` by `jobs/ai_dynamic_actions.php`; shown in Action Center “Today’s Focus” + “Next-best actions” (and Dashboard Action Center section if enabled) |
| **AI agent reports (Generate/View)** | (demo only today) | (demo only today) | (demo only today) | (demo only today) | `ai-agents.php` + `agent-report.php` (real Anthropic-backed agent generation planned via `api/ai/agent_run.php`) |
| **Sales Boost personalised AI suggestions** | planned | planned | planned | planned | `sales-boost.php` (planned in AI Batch 4) |
| **Executive summary AI report** | planned | planned | planned | planned | `reports.php` (planned in AI Batch 5) |

> Notes:
> - “planned” means it’s documented in `AI_PLAN.md` but not implemented yet.
> - “demo only today” means the UI uses `ai_agents`/`ai_reports` storage, but the generation is currently a dummy report path (no Anthropic).

---

## Plan × core (non‑AI) features × display location (current)

| Feature | free | starter | growth | premium | Displays on |
|---|---:|---:|---:|---:|---|
| **Dashboard core KPIs** | ✓ | ✓ | ✓ | ✓ | `dashboard.php` |
| **Dashboard inventory insights** | ✗ | ✓ | ✓ | ✓ | `dashboard.php` (Inventory card; locked overlay when not allowed) |
| **Dashboard critical insights (full)** | ✗ (limited) | ✓ | ✓ | ✓ | `dashboard.php` |
| **Dashboard top lists (full)** | ✗ (limited) | ✓ | ✓ | ✓ | `dashboard.php` |
| **Dashboard Action Center block** | ✗ | ✓ | ✓ | ✓ | `dashboard.php` (Action Center section; uses `action_items`) |
| **Analytics: Revenue tab** | ✓ | ✓ | ✓ | ✓ | `analytics.php` (`api/analytics/revenue.php`) |
| **Analytics: Products tab** | ✓ (preview limits) | ✓ | ✓ | ✓ | `analytics.php` (`api/analytics/products.php`) |
| **Analytics: Customers tab** | ✓ (preview limits) | ✓ | ✓ | ✓ | `analytics.php` (`api/analytics/customers.php`) |
| **Analytics: AOV tab** | ✓ (preview) | ✓ | ✓ | ✓ | `analytics.php` (`api/analytics/aov.php`) |
| **Retention cohorts depth** | ✗ | ✓ (limited by `cohort_months`) | ✓ | ✓ | surfaced via `api/analytics/customers.php` + Reports |
| **Funnel** | ✗ | ✓ | ✓ | ✓ | Reports (and derived tables) |
| **Attribution** | ✗ | ✗ | ✓ | ✓ | Reports (`api/reports/summary.php`) |
| **Inventory forecasting** | ✗ | ✓ | ✓ | ✓ | Dashboard + Reports + derived tables (`forecasts`) |
| **Goals tracking** | ✗ | ✓ | ✓ | ✓ | Dashboard + Reports |
| **Reports page (tabs + summaries)** | ✓ (some tabs locked) | ✓ | ✓ | ✓ | `reports.php` (`api/reports/summary.php`) |
| **Reports export (stub)** | ✗ | ✗ | ✓ | ✓ | `reports.php` (calls `api/reports/export.php`) |
| **Reports scheduling (stub)** | ✗ | ✗ | ✓ | ✓ | `reports.php` (calls `api/reports/schedule.php`) |
| **Alerts: inventory alerts** | ✗ | ✗ | ✓ | ✓ | `alerts.php` |
| **Sales Boost recommendations (quota)** | ✗ (locked/limited) | ✓ | ✓ | ✓ | `sales-boost.php` + usage tracking |

