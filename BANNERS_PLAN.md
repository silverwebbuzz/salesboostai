## SalesBoost AI — Featured Images (1600×900) Banner Plan

Use this as the single source of truth for your Shopify listing featured images.

### Global design rules (apply to all banners)
- **Canvas**: 1600×900 (16:9)
- **Safe area** (text/logo): keep within **~120px padding** from all edges
- **Layout**: left = headline + subtext, right = product screenshot collage
- **Text limits** (recommended):
  - Title: **4–7 words**
  - Description: **1 sentence**, ~80–110 characters
  - Optional bullets: **max 3**, very short
- **Screenshot style**:
  - Use the same device/frame style across all banners
  - Prefer 1 “hero” screenshot + 1–2 small supporting tiles
  - Make sure the page shows **real numbers** (not blank states)
- **Consistency**:
  - Use the same font sizes/weights across all banners
  - Use the same CTA label shape (even if not clickable): “Action Center”, “Analytics”, etc.

### If store data is not ready (AI-generated mock banners)
If your dev store doesn’t have enough real data for clean screenshots, the marketing/UI team can generate
consistent mock visuals in Gemini/Canva/Figma AI using the prompts below.

**Base style prompt (prepend to all banner prompts)**

```text
Create a 1600x900 (16:9) SaaS marketing banner for a Shopify analytics app called
"SWB SalesBoost AI". Style: premium modern dashboard, teal-to-dark gradient background,
soft glassmorphism panels, rounded cards, subtle borders, minimal shadows, clean
typography. Layout: left side has large headline + one-sentence subtext; right side shows
a realistic dashboard UI screenshot mock inside a clean browser frame. Use colors:
deep teal, emerald green accents, white cards, gray text. No clutter. No emojis.
All text must be sharp and readable. Keep safe margins 120px from edges.
```

---

## Banner 1 — All‑in‑one overview (Hero)
- **Title**: All‑in‑one Sales Intelligence
- **Description**: Track revenue, orders, customers and inventory—then act with clear recommendations.
- **Screenshot(s) to capture**
  - Primary: `app/dashboard.php` (Dashboard)
- **What to show in the screenshot**
  - KPI row (Revenue/Orders/Customers/AOV)
  - Store Health Score card
  - Critical Insights list (at least 1 item)
- **Mock image prompt (if no data for screenshots)**

```text
Headline: "All‑in‑one Sales Intelligence"
Subtext: "Track revenue, orders, customers and inventory—then act with clear insights."

Right-side UI mock: Dashboard page with KPI row cards:
- Total Sales $42,584 (+23%)
- Orders 1,248 (+12%)
- Customers 612 (+9%)
- AOV $156 (+8%)
Include a line chart labeled "Sales Performance (Last 7 days)".
Include a Store Health Score card: 62/100 with 4 breakdown bars (Revenue, Inventory, Customers, Alerts).
Include a small "Critical Insights" list with 2 items.
```

---

## Banner 2 — Action Center (Priorities + actions)
- **Title**: Action Center for Growth
- **Description**: See the most urgent issues, why they matter, and what to do next—ranked by impact.
- **Screenshot(s) to capture**
  - Primary: `app/action-center.php` (Action Center)
- **What to show**
  - Today’s Focus with at least 1 action item
  - Weekly digest paragraph (Growth+) if possible
  - “AI generated” badge visible on at least one action (Premium) if available
- **Mock image prompt**

```text
Headline: "Action Center for Growth"
Subtext: "Prioritize urgent issues and track actions—ranked by impact."

Right-side UI mock: Action Center page with:
- "Today's Focus" stacked action cards with severity badges (HIGH/MEDIUM)
- Each card shows Impact score and Confidence
- Buttons: "View details" and "Mark acted"
Include a small badge on one card: "AI generated"
Include a small Weekly Digest paragraph card.
```

---

## Banner 3 — Analytics (Revenue performance)
- **Title**: Revenue Analytics, Simplified
- **Description**: Visualize sales trends and spot drops early with a clean, merchant‑friendly analytics view.
- **Screenshot(s) to capture**
  - Primary: `app/analytics.php` → Revenue tab
- **What to show**
  - Revenue chart
  - Time range selector (7/30)
  - “Open Reports →” CTA card
- **Mock image prompt**

```text
Headline: "Revenue Analytics, Simplified"
Subtext: "Visualize sales trends and spot drops early with a clean analytics view."

Right-side UI mock: Analytics → Revenue tab with:
- Large revenue line chart
- Range pills: 7 days / 30 days (7 selected)
- KPI tile: "Revenue $42,584"
- A small insight card with 1–2 sentences
- A button-style card: "Open Reports →"
```

---

## Banner 4 — Products (Top performers + risk)
- **Title**: Product Performance Insights
- **Description**: Identify best sellers, weak performers, and inventory risk so you can optimize quickly.
- **Screenshot(s) to capture**
  - Primary: `app/analytics.php` → Products tab
  - Optional small tile: `app/reports.php?tab=inventory` (Inventory report)
- **What to show**
  - Product Revenue (Top 5) chart
  - “Open Reports →” CTA
  - If possible: inventory forecast list visible in a tile (Reports Inventory)
- **Mock image prompt**

```text
Headline: "Product Performance Insights"
Subtext: "See best sellers, weak performers, and inventory risk in one place."

Right-side UI mock: Analytics → Products tab with:
- Bar chart titled "Product Revenue (Top 5)"
- List titled "Top Products" with 5 rows and revenue amounts
- Small tile titled "Inventory Forecast" with 3 rows: SKU + days to stockout
```

---

## Banner 5 — Customers (Segments + retention)
- **Title**: Customer Segments & Retention
- **Description**: Understand new vs returning, top customers, and retention signals to improve lifetime value.
- **Screenshot(s) to capture**
  - Primary: `app/analytics.php` → Customers tab
- **What to show**
  - New vs Returning chart + counts
  - Top Customers list
  - Retention preview card (even if limited on Free)
- **Mock image prompt**

```text
Headline: "Customer Segments & Retention"
Subtext: "Understand new vs returning, top customers, and retention signals."

Right-side UI mock: Analytics → Customers tab with:
- Doughnut chart "New vs Returning"
- Top Customers list (5 rows) with total spent
- Retention preview card with cohort rows (Month + %)
- Small LTV card (blurred/locked overlay) with "Upgrade to Starter" button
```

---

## Banner 6 — Reports (Executive summaries)
- **Title**: Executive Reports in Minutes
- **Description**: Get clear summaries across revenue, customers and inventory—built for fast decisions.
- **Screenshot(s) to capture**
  - Primary: `app/reports.php` → Revenue tab
  - Secondary tile: `app/reports.php?tab=customers` or `...tab=inventory`
- **What to show**
  - Summary bullets + Critical Insights + Actions
  - Export/Schedule buttons visible (even if “coming soon” or gated)
- **Mock image prompt**

```text
Headline: "Executive Reports in Minutes"
Subtext: "Clear summaries across revenue, customers, and inventory—built for decisions."

Right-side UI mock: Reports page with tabs (Revenue, Customers, Inventory, Funnel, Attribution).
Show Revenue tab active:
- Summary bullets list
- Critical Insights list
- Actions list
Top-right buttons: "Export" and "Schedule" (can show locked state).
```

---

## Banner 7 — Alerts (Know what needs attention)
- **Title**: Alerts That Drive Action
- **Description**: Surface critical issues and opportunities so you can fix problems before revenue is hit.
- **Screenshot(s) to capture**
  - Primary: `app/alerts.php`
- **What to show**
  - “Critical Alerts” section with at least 1 card
  - “View Details” button visible
- **Mock image prompt**

```text
Headline: "Alerts That Drive Action"
Subtext: "Surface critical issues and opportunities before revenue is hit."

Right-side UI mock: Alerts page with:
- Section "Critical Alerts" with 2 alert cards (red accent)
- Section "Warnings" with 2 cards (amber accent)
Each card has a "View Details" button.
```

---

## Banner 8 — Sales Boost (Recommendations)
- **Title**: Sales Boost Recommendations
- **Description**: Turn insights into growth with actionable product recommendations and quick wins.
- **Screenshot(s) to capture**
  - Primary: `app/sales-boost.php`
  - Optional small tile: `app/action-center.php` Recommendations tab
- **What to show**
  - Recommendations usage row
  - At least one recommendation block visible
- **Mock image prompt**

```text
Headline: "Sales Boost Recommendations"
Subtext: "Turn insights into growth with actionable product recommendations."

Right-side UI mock: Sales Boost page with:
- "Recommendations usage this week: 1/2"
- Recommendation cards with: suggested bundle, reason, timing badge
- A small tile: "Quick Wins" with 3 bullets
```

---

### Optional “AI Agents” banner (only if you want 9 images)
- **Title**: AI Agents for Your Store
- **Description**: Generate focused reports for inventory, revenue and products using your store’s data.
- **Screenshot(s) to capture**
  - `app/ai-agents.php` (agent cards grid)
  - Optional: `app/agent-report.php?agent_id=...` (one report page)
- **What to show**
  - 4 agent cards
  - “Generate Report” / “View Report” button visible

- **Mock image prompt**

```text
Headline: "AI Agents for Your Store"
Subtext: "Generate focused reports for inventory, revenue and products using store data."

Right-side UI mock: AI Agents page with 4 agent cards in a grid.
Each card has: Agent name, short description, status badge, and a "Generate Report" button.
Optional small tile: one report page snippet with Summary + Key points + Actions.
```

