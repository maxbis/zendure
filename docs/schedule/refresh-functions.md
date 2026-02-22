# Refresh (JS) Functions

Overview of refresh-related JavaScript in the schedule app: the two main APIs, when they are triggered, and related helpers. These functions are shared by both the desktop schedule page and the mobile schedule page.

---

## 1. Schedule and prices

**File:** `main/assets/js/charge_schedule.js`

**APIs:**

- **`_refreshScheduleAndPricesInternal()`** — Internal worker. Never called directly; used only by the two public functions below.
- **`refreshScheduleAndPrices()`** — Debounced (300 ms) wrapper. Use for initial load.
- **`refreshScheduleAndPricesImmediate()`** — No debounce. Also exposed as `window.refreshScheduleAndPricesImmediate`.

**When triggered:**

- **Initial page load** — `DOMContentLoaded` runs `refreshScheduleAndPrices()` once.
- **After save/delete** — `edit_modal.js` calls `window.refreshScheduleAndPricesImmediate()` when the API returns success.
- **After Clear button** — `handleClearClick()` runs `refreshScheduleAndPricesImmediate()` after a successful clear.
- **After Auto button** — `handleAutoClick()` runs `refreshScheduleAndPricesImmediate()` after entries are added.
- **Tab or page becomes visible** — A `visibilitychange` listener runs `refreshScheduleAndPricesImmediate()` once when the document goes from hidden to visible (e.g. user returns from another app or tab on mobile or desktop). A `wasSchedulePageHidden` flag avoids running twice on first load.

**Updates:**

- Schedule data (today + tomorrow from schedule API)
- App state (`schedule`, `scheduleTomorrow`, loading)
- Schedule panel (or fallback: entries, today/tomorrow, mini timeline, status bar)
- Bar graph (today + tomorrow)
- Price graph (via `fetchAndRenderPrices`)
- Schedule calculator
- Watt-hours per hour partial (chart + daily table), via `refreshEnergyGraph()` when this refresh runs (including on the 20×20 interval)

Does **not** touch automation status or charge status.

---

## 2. Status

**File:** `main/assets/js/charge_status.js`

**API:** **`refreshStatus(isAutoRefresh = false)`**

**When triggered:**

- **Page load** — If the tab is visible, `startAutoRefresh()` runs once and calls `refreshStatus(true)`.
- **Every 20 seconds (auto-refresh)** — `startAutoRefresh()` sets a `setInterval`; each tick calls `refreshStatus(true)` only when `!document.hidden`.
- **Tab becomes visible again** — `visibilitychange` handler calls `startAutoRefresh()`, which does one immediate `refreshStatus(true)` and then resumes the 20 s interval.
- **Manual refresh** — Click on Automation "Refresh" button runs `performNormalRefresh()` (in `automation_status.js`), which calls `refreshStatus()`.

**Updates:**

- Automation status
- Charge status (Zendure + P1)
- Charge status details (System & Grid) if `renderChargeStatusDetails` exists
- When `isAutoRefresh === true`: current-hour indicators in price graph and schedule bar graph via `updateGraphTimeIndicators`

Does **not** re-fetch or re-render schedule or price data.

---

## 3. `performNormalRefresh`

**File:** `main/assets/js/automation_status.js`

Handles the Automation "Refresh" button: disables button, shows "refreshing" UX, then calls `refreshStatus()` only.

**When triggered:** Click on `#automation-refresh-btn` (desktop: normal click; mobile: short tap, not long-press).

---

## 4. `SchedulePanelComponent.refresh`

**File:** `main/assets/js/components/schedule_panel_component.js`

**When triggered:** Click on the schedule panel "Refresh" button (`#refresh-schedule-btn`).

**Updates:** Schedule panel only (schedule API for today → `update()`). Does **not** update bar graph, price graph, automation, or charge status.

---

## 5. Energy graph refresh

**File:** `main/assets/js/energy_graph_refresh.js`

**API:** **`refreshEnergyGraph()`** (exposed as `window.refreshEnergyGraph`)

**When triggered:** From `_refreshScheduleAndPricesInternal()` in `charge_schedule.js` (and thus whenever the main/prices refresh runs: 20×20 auto-refresh tick, after save/delete, Clear, Auto, or when the tab becomes visible).

**Updates:** Watt-hours per hour chart(s) (desktop and/or mobile) and daily totals table(s) from `api/energy_graph_api.php`. No other partials.

---

## 6. Helper (no fetch, UI only)

**`updateGraphTimeIndicators`** (`charge_status.js`)

**When triggered:** From `refreshStatus(true)` when `isAutoRefresh === true` (during the 20 s auto-refresh or when the page becomes visible and does an immediate status refresh).

**Updates:** Only the "current hour" highlight on existing price-graph bars and schedule bar-graph bars. No API calls, no re-render of bars or prices.

---

## 7. DataService (legacy note)

`main/assets/js/data_service.js` is not part of the current mobile page load order.  
The active refresh path is driven by `main/assets/js/charge_schedule.js` and `main/assets/js/charge_status.js`.

---

## What updates every 20 seconds vs every 20×20 seconds

**Every 20 seconds** (each auto-refresh tick, via `refreshStatus(true)`):

- Automation status partial
- Charge status (Zendure + P1)
- Charge status details (System & Grid)
- Current-hour indicators on price graph and schedule bar graph only (no data re-fetch)

**Every 20×20 seconds** (every 20th tick, when `refreshScheduleAndPricesImmediate()` runs):

- Schedule data (today + tomorrow)
- Schedule panel
- Bar graph (today + tomorrow)
- Price graph
- Schedule calculator
- Watt-hours per hour partial (chart + daily totals table)

---

## Summary Table

| Function | Schedule | Bar graph | Price graph | Wh per hour | Automation | Charge status |
|----------|----------|-----------|-------------|-------------|------------|---------------|
| `refreshScheduleAndPrices` / `refreshScheduleAndPricesImmediate` | ✓ | ✓ | ✓ | ✓ | ✗ | ✗ |
| `refreshStatus` | ✗ | indicators only | indicators only | ✗ | ✓ | ✓ |
| `performNormalRefresh` | ✗ | ✗ | ✗ | ✗ | ✓ (calls `refreshStatus`) | ✓ |
| `SchedulePanelComponent.refresh` | ✓ (panel only) | ✗ | ✗ | ✗ | ✗ | ✗ |
| `updateGraphTimeIndicators` | ✗ | indicators only | indicators only | ✗ | ✗ | ✗ |

Two main refresh flows: **schedule and prices** (debounced + immediate + on visibility, including Wh per hour) and **status** (20 s interval + visibility + manual button).
