# Refresh (JS) Functions

Overview of all refresh-related JavaScript functions: where they live, what they update, and **when they are triggered**.

---

## 1. `_refreshDataInternal`

**File:** `schedule/assets/js/charge_schedule.js`

**When triggered:** Never called directly. Invoked only via `refreshData` or `refreshDataImmediate`.

**Updates:**

- Schedule data (today + tomorrow from schedule API)
- App state (`schedule`, `scheduleTomorrow`, loading)
- Schedule panel (or fallback: entries, today/tomorrow, mini timeline, status bar)
- **Bar graph** (today + tomorrow)
- **Price graph** (via `fetchAndRenderPrices`)
- **Schedule calculator**

Does **not** touch automation status or charge status.

---

## 2. `refreshData`

**File:** `schedule/assets/js/charge_schedule.js`

Debounced wrapper (300 ms) around `_refreshDataInternal`.

**When triggered:**

- **Initial page load** — `DOMContentLoaded` in `charge_schedule.js` runs `refreshData()` once to load schedule, bar graph, price graph, etc.
- **Edit modal callback** — Passed as `onSaveCallback` to `EditModal`; used only as fallback if `window.refreshDataImmediate` is missing (e.g. after save/delete).
- **Mobile** — May be wrapped or overridden in `mobile_optimizations.js` for mobile-specific behaviour.

**Updates:** Same as `_refreshDataInternal` (schedule, bar graph, price graph, calculator, state/panel).

---

## 3. `refreshDataImmediate`

**File:** `schedule/assets/js/charge_schedule.js`  
Also exposed as `window.refreshDataImmediate`.

Same as `_refreshDataInternal` but **no debounce**.

**When triggered:**

- **After saving an entry** — `edit_modal.js` `handleSave()` calls it when the API returns success (so the list and graphs update right away).
- **After deleting an entry** — `edit_modal.js` `handleDelete()` calls it when the API returns success.
- **After Clear button** — User confirms “Clear old entries”; `charge_schedule.js` `handleClearClick()` runs it after a successful clear.
- **After Auto button** — User confirms “Auto calculate schedule”; `charge_schedule.js` `handleAutoClick()` runs it after entries are added.

**Updates:** Same as `_refreshDataInternal`. Used whenever schedule/entries change and the UI must update immediately.

---

## 4. `refreshAutomationStatus`

**File:** `schedule/assets/js/automation_status.js`

**When triggered:**

- **Fallback from Automation Refresh** — `performNormalRefresh()` calls it when `refreshAllStatus` is not available.
- **Indirectly via `refreshAllStatus`** — That function fetches automation status and calls `renderAutomationStatus` (same net effect as refreshing automation status).

**Updates:** **Automation status** only (fetch + `renderAutomationStatus`).

Does **not** update schedule, prices, or charge status.

---

## 5. `performNormalRefresh`

**File:** `schedule/assets/js/automation_status.js`

Handles the Automation “Refresh” button: disables button, shows “refreshing” UX, then calls `refreshAllStatus` or (fallback) `refreshAutomationStatus`.

**When triggered:**

- **Desktop** — Click on the Automation “Refresh” button (`#automation-refresh-btn`); handler added in `automation_status.js` on `DOMContentLoaded`.
- **Mobile** — Short click on the same button (no long-press). A 100 ms delayed click handler runs `performNormalRefresh()` so it only runs on tap, not after long-press (which triggers full page reload).

**Updates:** Whatever the callee updates (automation + charge status via `refreshAllStatus`, or automation only via `refreshAutomationStatus`).

---

## 6. `refreshAllStatus`

**File:** `schedule/assets/js/charge_status.js`

**When triggered:**

- **Page load** — If the tab is visible, `startAutoRefresh()` runs once and calls `refreshAllStatus(true)`.
- **Every 20 seconds (auto-refresh)** — `startAutoRefresh()` sets a `setInterval`; each tick calls `refreshAllStatus(true)` only when `!document.hidden`.
- **Tab becomes visible again** — `visibilitychange` handler calls `startAutoRefresh()`, which does one immediate `refreshAllStatus(true)` and then resumes the 20 s interval.
- **Manual Refresh** — Click on Automation “Refresh” button runs `performNormalRefresh()`, which calls `refreshAllStatus()` (with `isAutoRefresh = false`).

**Updates:**

- **Automation status**
- **Charge status** (Zendure + P1)
- **Charge status details** (System & Grid) if `renderChargeStatusDetails` exists
- When `isAutoRefresh === true`: **current-hour indicators** in price graph and schedule bar graph via `updateGraphTimeIndicators`

Does **not** re-fetch or re-render schedule or price data.

---

## 7. `refreshChargeStatus`

**File:** `schedule/assets/js/charge_status.js`

**When triggered:** **Not used by any UI in the current codebase.** Charge status is updated via `refreshAllStatus`, which fetches and renders charge status (and details) itself. This function is available for direct use (e.g. from console or future UI).

**Updates:** **Charge status** (Zendure + P1) and **charge status details** (System & Grid).

Does **not** update automation status, schedule, or prices.

---

## 8. `SchedulePanelComponent.refresh`

**File:** `schedule/assets/js/components/schedule_panel_component.js`

**When triggered:** **Click on the schedule panel “Refresh” button** — `#refresh-schedule-btn`. The component’s `setupEventListeners()` attaches a click handler that calls `this.refresh()`.

**Updates:** **Schedule panel only** (schedule API for today → `update()`). Does **not** update bar graph, price graph, automation, or charge status.

---

## 9. `PriceGraphComponent.refresh`

**File:** `schedule/assets/js/components/price_graph_component.js`

**When triggered:** **No button or event calls it in the current code.** Price data is refreshed by `_refreshDataInternal` / `refreshData` / `refreshDataImmediate` via `fetchAndRenderPrices`.

**Updates:** Placeholder implementation: no real price API call, sets empty `{ today: {}, tomorrow: {} }` and calls `update()`. Effectively **no real price refresh**.

---

## 10. `DataService._refreshInBackground`

**File:** `schedule/assets/js/data_service.js`  
Private helper for stale-while-revalidate.

**When triggered:** **Internally by `DataService.fetch()`** when (1) the cache entry for the key has expired, and (2) stale-while-revalidate is enabled. The method returns stale data immediately and calls `_refreshInBackground()` to re-fetch and update the cache (and notify subscribers). Not tied to any user action or UI section.

**Updates:** **DataService cache** for that key (re-fetches via the key’s fetcher, updates cache and subscribers).

---

## Helper (no fetch, UI only)

**`updateGraphTimeIndicators`** (`charge_status.js`)

**When triggered:** From **`refreshAllStatus(true)`** when `isAutoRefresh === true` (i.e. during the 20 s auto-refresh or when the page becomes visible and does an immediate refresh).

**Updates:** Only the **“current hour”** highlight on existing price-graph bars and schedule bar-graph bars (`.price-current`, `.bar-current`). No API calls, no re-render of bars or prices.

---

## Summary Table

| Function | Schedule | Bar graph | Price graph | Automation | Charge status |
|----------|----------|-----------|-------------|------------|---------------|
| `_refreshDataInternal` / `refreshData` / `refreshDataImmediate` | ✓ | ✓ | ✓ | ✗ | ✗ |
| `refreshAllStatus` | ✗ | indicators only | indicators only | ✓ | ✓ |
| `refreshAutomationStatus` | ✗ | ✗ | ✗ | ✓ | ✗ |
| `refreshChargeStatus` | ✗ | ✗ | ✗ | ✗ | ✓ |
| `performNormalRefresh` | ✗ | ✗ | ✗ | ✓ (via `refreshAllStatus`) | ✓ |
| `SchedulePanelComponent.refresh` | ✓ (panel only) | ✗ | ✗ | ✗ | ✗ |
| `PriceGraphComponent.refresh` | ✗ | ✗ | ✗ (placeholder) | ✗ | ✗ |

There are **10 refresh-related functions** (including the private `_refreshInBackground` and the placeholder `PriceGraphComponent.refresh`), plus **`updateGraphTimeIndicators`** which only updates current-hour highlights.
