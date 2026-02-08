# Refresh (JS) Functions

Overview of refresh-related JavaScript in the schedule app: the two main APIs, when they are triggered, and related helpers. These functions are shared by both the desktop schedule page and the mobile schedule page.

---

## 1. Schedule and prices

**File:** `schedule/assets/js/charge_schedule.js`

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

Does **not** touch automation status or charge status.

---

## 2. Status

**File:** `schedule/assets/js/charge_status.js`

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

**File:** `schedule/assets/js/automation_status.js`

Handles the Automation "Refresh" button: disables button, shows "refreshing" UX, then calls `refreshStatus()` only.

**When triggered:** Click on `#automation-refresh-btn` (desktop: normal click; mobile: short tap, not long-press).

---

## 4. `SchedulePanelComponent.refresh`

**File:** `schedule/assets/js/components/schedule_panel_component.js`

**When triggered:** Click on the schedule panel "Refresh" button (`#refresh-schedule-btn`).

**Updates:** Schedule panel only (schedule API for today → `update()`). Does **not** update bar graph, price graph, automation, or charge status.

---

## 5. Helper (no fetch, UI only)

**`updateGraphTimeIndicators`** (`charge_status.js`)

**When triggered:** From `refreshStatus(true)` when `isAutoRefresh === true` (during the 20 s auto-refresh or when the page becomes visible and does an immediate status refresh).

**Updates:** Only the "current hour" highlight on existing price-graph bars and schedule bar-graph bars. No API calls, no re-render of bars or prices.

---

## 6. DataService (internal)

**`DataService._refreshInBackground`** (`schedule/assets/js/data_service.js`)

**When triggered:** Internally by `DataService.fetch()` when the cache entry has expired and stale-while-revalidate is enabled. Not tied to any user action.

**Updates:** DataService cache for that key (re-fetches and notifies subscribers).

---

## Summary Table

| Function | Schedule | Bar graph | Price graph | Automation | Charge status |
|----------|----------|-----------|-------------|------------|---------------|
| `refreshScheduleAndPrices` / `refreshScheduleAndPricesImmediate` | ✓ | ✓ | ✓ | ✗ | ✗ |
| `refreshStatus` | ✗ | indicators only | indicators only | ✓ | ✓ |
| `performNormalRefresh` | ✗ | ✗ | ✗ | ✓ (calls `refreshStatus`) | ✓ |
| `SchedulePanelComponent.refresh` | ✓ (panel only) | ✗ | ✗ | ✗ | ✗ |
| `updateGraphTimeIndicators` | ✗ | indicators only | indicators only | ✗ | ✗ |

Two main refresh flows: **schedule and prices** (debounced + immediate + on visibility) and **status** (20 s interval + visibility + manual button).
