# SalesBoost AI — Anthropic AI Implementation Plan

This document captures the agreed plan for adding Anthropic-powered AI features to the existing SalesBoost AI app, so we can execute consistently and avoid forgetting decisions.

## 0) Security (non‑negotiable)

- **Never** place Anthropic keys in code, commits, screenshots, or client-side JS.
- Store the key as an environment variable/server secret:
  - `ANTHROPIC_API_KEY`
- All Anthropic calls must be **server-side only**.
- If a key is ever pasted/shared, assume compromised and **rotate immediately**.

## 1) Canonical plan keys (slugs)

Use these exact plan slugs everywhere (Shopify + DB + code):

- `free`
- `starter`
- `growth`
- `premium`

## 2) Plan mapping for the reference doc (Growth/Pro)

The reference spec (`ai_feature_io_reference.html`) uses “Growth+” and “Pro only”.
We map those to our plan slugs as:

- **Growth+** → `growth` and `premium`
- **Pro only** → `premium`

## 3) Avoiding unnecessary Anthropic calls (even in Premium)

Premium being “unlimited” means **no weekly quota cap**, not “call AI on every change”.

Every AI feature must be controlled by:

- **TTL**: time-based caching window
- **Fingerprint**: change-based invalidation using local DB signals
- **Manual regenerate**: optional, but rate-limited

### Fingerprint (shop data signature)

Fingerprint is computed from cheap DB stats (counts + last timestamps). Example inputs:

- Orders: `COUNT(*)`, `MAX(COALESCE(updated_at, created_at, fetched_at))`
- Customers: `COUNT(*)`, `MAX(COALESCE(updated_at, created_at, fetched_at))`
- Inventory: `COUNT(*)`, `MAX(fetched_at)` (or updated timestamp if available)

Fingerprint string example:

`orders:1023@2026-03-26T10:15:00Z|customers:210@...|inv:500@...`

Rules:
- If fingerprint unchanged → serve cached output, **do not call** Anthropic.
- If fingerprint changed → only call Anthropic when TTL expired (or user explicitly “regenerates”).

## 4) Storage strategy (where AI outputs live)

We will prefer existing per-store `*_analytics` caching for most AI outputs:

- Use existing cache helpers:
  - `sbm_cache_get($shop, $metricKey, $ttlSec)`
  - `sbm_cache_set($shop, $metricKey, $payloadArray)`

Use `ai_reports` only when we need long, structured, versioned, “agent-like” reports with history.

## 4.1) AI Agents — how agent clicks work (important)

The app already uses:
- `ai_agents`: agent definitions (name, prompt, model, version, etc.)
- `ai_reports`: stored outputs per shop + agent (+ version)

**Key rule**: agent reports are **not** just a “combination of already-run AI calls”.

Instead, when a merchant clicks **Generate Report** for an agent, we run an **agent-specific AI generation** (server-side), with caching + fingerprinting so it doesn’t call Anthropic repeatedly.

### Agent report generator flow

When user clicks an agent:

1. Load agent definition from `ai_agents` (prompt template, model, version, config)
2. Build the **agent-specific input payload** from local DB metrics (orders/customers/inventory/derived tables)
3. Optionally reuse existing cached AI artifacts if present (weekly digest, anomaly explains, etc.)
4. Enforce **TTL + fingerprint + rate-limit**:
   - if cached report is fresh and fingerprint unchanged → serve cached
   - otherwise call Anthropic once, then cache/store
5. Store output as a row in `ai_reports`:
   - `shop`, `agent_id`, `agent_version`, `status`, `report_json`, `created_at`
6. `agent-report.php` reads the latest stored report and renders it (no dummy/demo output in production mode)

### Planned endpoint for agent generation

- `app/api/ai/agent_run.php?shop=...&agent_id=...`
  - Requires `requireSessionTokenAuth($shop)`
  - Validates agent exists + plan access (if agent is premium-only)
  - Applies caching/fingerprint rules
  - Writes the result into `ai_reports`

## 5) Feature-by-feature plan

### Feature 1 — Anomaly explanation (Growth+)

- **Trigger**: on-demand click (“Why did this drop?”)
- **Output**: 2 sentences max (plain text)
- **Model**: cheaper/smaller model (reference: “Haiku” class)
- **TTL**: 1 hour per metric per day
- **Storage**: per-store `*_analytics` row via `sbm_cache_set`

**Cache key format**

`ai_anomaly_explain:{metric}:{period_days}:{date_key}`

Example: `ai_anomaly_explain:revenue:7:2026-03-26`

**Inputs sourced from local DB (no Shopify calls)**

- current/previous value + pct change
- top products
- low stock / stockout risks
- returning rate proxy
- refund rate proxy (if available; otherwise omit)

### Feature 2 — Weekly AI digest paragraph (Growth+)

- **Trigger**: cron weekly (Sunday night) + optional manual generate
- **Output**: 1 paragraph (3–4 sentences)
- **TTL**: 7 days
- **Storage**: `*_analytics` cache

**Cache key**

`ai_weekly_digest:{week_key}` e.g. `ai_weekly_digest:2026-W13`

### Feature 3 — Dynamic action recommendations (Pro-only → Premium)

- **Trigger**: cron daily (not on every page load)
- **Output**: JSON array of 3 actions (action, reason, impact)
- **TTL**: 24 hours
- **Storage**:
  - Primary: write into per-store `action_items` (so the UI remains consistent)
  - Meta: store generation metadata in `*_analytics` (optional)

### Feature 6 — Personalised Sales Boost suggestions (Pro-only → Premium)

- **Trigger**: weekly cache (or “missing/expired on tab load”)
- **Output**: JSON array of 3 suggestions (suggestion, product, reason, timing)
- **TTL**: 7 days
- **Storage**: `*_analytics` cache

### Feature 7 — Executive summary report (Pro-only → Premium)

- **Trigger**: monthly cache or on-demand generation
- **Output**: 3 paragraphs, richer text (reference: “Sonnet” class)
- **TTL**: 30 days
- **Storage choice**
  - Phase 1: store in `*_analytics` (simpler, no history)
  - Phase 2 (optional): store in `ai_reports` with an “executive_summary” agent row if we want report history/versioning

## 6) Usage / quotas

We will use the existing weekly usage tracking for paid gates.

Premium “unlimited”:
- Set plan limits to `-1` for the relevant usage keys
- Still enforce TTL + fingerprint + optional rate-limit

Proposed usage metric keys:
- `ai_anomaly_explain`
- `ai_weekly_digest`
- `ai_dynamic_actions`
- `ai_sales_boost_suggestions`
- `ai_exec_report`

## 7) API endpoints to add (server-side)

Planned endpoints:

- `app/api/ai/anomaly_explain.php` (Growth+)
- `app/api/ai/weekly_digest.php` (Growth+, optional manual trigger)
- `app/api/ai/dynamic_actions.php` (Premium, optional manual trigger; cron recommended)
- `app/api/ai/sales_boost_suggestions.php` (Premium)
- `app/api/ai/executive_report.php` (Premium)
- `app/api/ai/agent_run.php` (Agent reports; plan depends on agent)

All endpoints must:
- Require `shop`
- Use `requireSessionTokenAuth($shop)`
- Check entitlements + usage + caching
- Return JSON `{ ok: true, ... }` or `{ ok: false, error: "..." }`

## 8) Pages/UI where we will add AI buttons + calls

### Dashboard
- File(s): `app/dashboard.php`, `app/assets/dashboard.js`
- Add UI:
  - “Why did this drop?” button on KPI anomaly states (Revenue/Orders/AOV)
- Calls:
  - `api/ai/anomaly_explain.php`
- Result:
  - Show text in a small modal/toast/inline expanded row

### Action Center
- File(s): `app/action-center.php`, `app/assets/action-center.js`
- Add UI:
  - Anomaly explanation action on relevant KPI blocks
  - Weekly digest paragraph card (Growth+)
  - Dynamic action recommendations are shown via `action_items` (Premium)

### Reports
- File(s): `app/reports.php`, `app/assets/reports.js`
- Add UI:
  - Executive summary “Generate report” button (Premium)
  - Optional anomaly explain buttons for summary deltas

### Sales Boost
- File(s): `app/sales-boost.php`
- Add UI:
  - “AI Suggestions” section (Premium) fed from weekly cached suggestions endpoint/cache

### AI Agents / Agent Report
- Existing tables already used:
  - `ai_agents`, `ai_reports`
- No new buttons required for Phase 1, but AI features can later populate reports.

## 9) MySQL changes required

### Phase 1 (recommended): **No new tables required**

We will reuse:
- Per-store `*_analytics` table for cached AI outputs and metadata
- Existing `ai_agents` / `ai_reports` for agent report storage (already in use)
- Existing `store_usage_limits` for quota tracking
- Existing `action_items` for Action Center items (AI-generated actions write here)

### Optional Phase 2 (only if needed)

If we want an audit trail of every Anthropic call, we can add a fixed table like:
- `ai_request_log` (shop, feature_key, model, tokens_in/out, latency_ms, status, created_at)

This is optional and not required to ship the first AI features.

## 10) Implementation order (recommended)

1. Add Anthropic server client wrapper (single place)
2. Add fingerprint helper + caching wrapper
3. Ship Feature 1 (Anomaly explanation) end-to-end (Dashboard + Action Center)
4. Ship weekly digest (cron + Action Center UI)
5. Ship dynamic actions (daily cron → writes `action_items`)
6. Add Premium-only sales boost suggestions
7. Add executive report generator (Premium)

## Appendix — Production cronjobs (your server)

Documented from your final server cron screenshot (PHP path + app path).

> Note: these jobs are safe to run frequently. They only call Anthropic when eligibility + TTL/fingerprint rules require regeneration.

- `run_sync.php` (sync runner):
  - Command: `/usr/bin/php /home/silverwebbuzz_in/public_html/silverwebbuzzcom/salesboost/app/jobs/run_sync.php >/dev/null 2>&1`

- `process_webhooks.php` (webhook processing):
  - Command: `/usr/bin/php /home/silverwebbuzz_in/public_html/silverwebbuzzcom/salesboost/app/jobs/process_webhooks.php >/dev/null 2>&1`

- `ai_weekly_digest.php` (AI weekly digest):
  - Schedule shown: `minute=0, hour=0,12` (twice daily)
  - Command: `/usr/bin/php /home/silverwebbuzz_in/public_html/silverwebbuzzcom/salesboost/app/jobs/ai_weekly_digest.php >/dev/null 2>&1`

- `ai_dynamic_actions.php` (AI dynamic actions):
  - Schedule shown: `minute=0, hour=*` (hourly)
  - Command: `/usr/bin/php /home/silverwebbuzz_in/public_html/silverwebbuzzcom/salesboost/app/jobs/ai_dynamic_actions.php >/dev/null 2>&1`

---

## Quick reference — feature matrix

See `FEATURES_MATRIX.md` for:
- plan × AI features × where they show
- plan × non‑AI features × where they show

