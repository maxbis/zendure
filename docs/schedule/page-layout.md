# Schedule page layout (`/zendure/schedule/charge_schedule.php`)

This document describes **all page sections**, in **top-to-bottom render order**, and lists each section’s **dependencies**:

- **PHP dependencies**: required files + included partials
- **Data/config dependencies**: config keys + data files + query params
- **DOM hooks**: key IDs/classes that JS attaches to
- **Client code dependencies**: JS + CSS that implement behavior/styling

Source of truth: `schedule/charge_schedule.php` (live at `https://www.wijs.ovh/zendure/schedule/charge_schedule.php`).

---

## Global (page-level) server-side bootstrap

Executed before any HTML is emitted.

- **Order**: 0
- **File**: `schedule/charge_schedule.php`
- **Dependencies (PHP)**:
  - `login/validate.php` (access control)
  - `schedule/api/charge_schedule_functions.php` (schedule IO + resolving)
    - Provides: `loadSchedule()`, `resolveScheduleForDate()`, `writeScheduleAtomic()` etc.
  - `schedule/includes/status.php` (shared formatting helpers used by multiple partials)
    - Provides: `formatRelativeTime()`, `getSystemStatusInfo()`, `formatAutomationEntryDetails()`, etc.
  - `schedule/includes/config_loader.php` (centralized config reads)
    - Reads: `config/config.json` or `run_schedule/config/config.json` (fallback)
- **Dependencies (data/config)**:
  - Data file: `data/charge_schedule.json`
  - Query param: `?initial_date=YYYYMMDD` (optional)
  - Config keys used:
    - `scheduleApiUrl` (fallback: `api/charge_schedule_api.php`)
    - `priceUrls.get_price` or `priceUrls.get_prices` (backward compatibility)
    - `calculate_schedule_apiUrl`
    - `zendureFetchApiUrl` (location-aware via `ConfigLoader::getWithLocation`)
- **Computed variables used by partials**:
  - `$schedule` = `loadSchedule(...)`
  - `$today` (from `initial_date` or `date('Ymd')`)
  - `$resolvedToday` = `resolveScheduleForDate($schedule, $today)`
  - `$currentTime` = `date('Hi')`

---

## `<head>` assets (icons + CSS)

- **Order**: 1
- **Rendered by**: `schedule/charge_schedule.php`
- **Dependencies (CSS)**:
  - `schedule/assets/css/charge_schedule.css` (base layout + schedule panels + modal base styles)
  - `schedule/assets/css/price_statistics.css`
  - `schedule/assets/css/automation_status.css`
  - `schedule/assets/css/charge_status_defines.css`
  - `schedule/assets/css/charge_status.css`
  - `schedule/assets/css/schedule_calculator.css`
- **Dependencies (static assets)**:
  - `schedule/favicon.ico`, `schedule/favicon-16x16.png`, `schedule/favicon-32x32.png`, `schedule/apple-touch-icon.png`

---

## Header (page title + current server time)

- **Order**: 2
- **Rendered by**: `schedule/charge_schedule.php`
- **DOM hooks**:
  - `#current-time` (server-rendered text; not currently updated by JS in this page)
- **CSS**:
  - `schedule/assets/css/charge_schedule.css` (header + responsive title swap)

---

## Schedule Panels (two-column layout)

This is the main “editing” area: **Today’s resolved schedule** (left) and **Schedule Entries table** (right).

- **Order**: 3
- **Rendered by (PHP partial)**: `schedule/partials/schedule_panels.php`
- **Inputs required from parent**:
  - `$today`, `$resolvedToday`, `$currentTime`, `$schedule`
  - Note: the partial defines local PHP helpers `getTimeClass()` and `getValueLabel()`.
- **DOM hooks**:
  - Left panel:
    - `#today-schedule-grid` (container for schedule items)
    - `.schedule-item`, `.schedule-item-time`, `.schedule-item-value`, `.schedule-item-key`
  - Right panel:
    - `#schedule-table` and `#schedule-table tbody` (entries table)
    - `#status-bar` (entry count text)
    - Buttons: `#clear-entry-btn`, `#auto-entry-btn`, `#add-entry-btn`
- **Client code dependencies**:
  - Rendering/updates:
    - `schedule/assets/js/schedule_renderer.js` (renders `#today-schedule-grid`, `#schedule-table`, and bar graphs)
    - `schedule/assets/js/components/schedule_panel_component.js` (optional component wrapper; attaches to `.layout`)
  - API operations:
    - `schedule/assets/js/schedule_api.js` (fetch schedule, save/delete entry, clear old entries, etc.)
  - Main orchestration:
    - `schedule/assets/js/charge_schedule.js` (wires buttons, refreshes schedule, re-renders)

---

## Edit Modal + Delete Confirm (entry editing UI)

This section is required for adding/editing schedule entries.

- **Order**: 4
- **Rendered by (PHP partial)**: `schedule/partials/edit_modal.php`
- **DOM hooks**:
  - Modal:
    - `#edit-modal` (modal backdrop)
    - `#modal-title`, `#modal-close`
    - `#inp-date`, `#inp-time`, `#inp-watts`
    - `input[name="val-mode"]` (fixed / netzero / netzero+)
    - `#btn-save`, `#btn-cancel`, `#btn-delete`
  - Delete confirmation (specific to edit modal):
    - `#confirm-dialog`, `#confirm-message`, `#confirm-cancel`, `#confirm-delete`
- **Client code dependencies**:
  - `schedule/assets/js/edit_modal.js` (class `EditModal`, attaches to `#edit-modal` and table row clicks)

---

## Generic Confirm Dialog (reusable confirm/alert)

Used for “Clear old entries”, “Auto calculate schedule”, etc.

- **Order**: 5
- **Rendered by (PHP partial)**: `schedule/partials/confirm_dialog.php`
- **DOM hooks**:
  - `#confirm-dialog-generic`
  - `#confirm-dialog-title`, `#confirm-dialog-message`
  - `#confirm-dialog-close`, `#confirm-dialog-cancel`, `#confirm-dialog-confirm`
- **Client code dependencies**:
  - `schedule/assets/js/confirm_dialog.js` (class `ConfirmDialog`)
  - Called from: `schedule/assets/js/charge_schedule.js`

---

## Schedule Overview Bar Graph (Today + Tomorrow)

Clickable “hour bars” that open the edit modal for that hour key.

- **Order**: 6
- **Rendered by (PHP partial)**: `schedule/partials/schedule_overview_bar.php`
- **Inputs required from parent**:
  - `$today` (used only to display formatted date)
- **DOM hooks**:
  - `#bar-graph-today`
  - `#bar-graph-tomorrow`
- **Client code dependencies**:
  - `schedule/assets/js/schedule_renderer.js`
    - Provides: `renderBarGraph(...)` which renders bars into these containers and binds click-to-edit.
  - `schedule/assets/js/charge_schedule.js`
    - Calls `renderBarGraph(...)` after fetching schedule data.
  - `schedule/assets/js/charge_status.js`
    - Updates “current hour” highlight classes without full re-render (`updateGraphTimeIndicators()`).

---

## Price Overview Bar Graph (Today + conditional Tomorrow)

Shows electricity prices, color-coded; bars are clickable to edit schedule entries for that hour.

- **Order**: 7
- **Rendered by (PHP partial)**: `schedule/partials/price_overview_bar.php`
- **Inputs required from parent**:
  - `$today` (display only)
  - Server-time dependency: shows Tomorrow column only when PHP `date('H') >= 15`
- **DOM hooks**:
  - `#price-graph-today`
  - `#price-graph-tomorrow` (may or may not exist depending on hour)
- **Dependencies (config)**:
  - `PRICE_API_URL` is injected by `schedule/charge_schedule.php` from config.
- **Client code dependencies**:
  - `schedule/assets/js/price_overview_bar.js`
    - Fetches prices and renders bars (handles hiding/showing tomorrow card based on current hour).
  - `schedule/assets/js/components/price_graph_component.js` (optional component wrapper; attaches to `.price-graph-wrapper`)
  - Orchestrated by `schedule/assets/js/charge_schedule.js` (calls `fetchAndRenderPrices(...)` when `PRICE_API_URL` is available).

---

## Price Statistics (min/max/avg/current cards)

Pure “placeholder DOM” server-side; JS fetches prices and fills in values.

- **Order**: 8
- **Rendered by (PHP partial)**: `schedule/partials/price_statistics.php`
- **DOM hooks**:
  - `#price-stat-min-value`, `#price-stat-min-detail`
  - `#price-stat-max-value`, `#price-stat-max-detail`
  - `#price-stat-avg-value`, `#price-stat-avg-detail`
  - `#price-stat-current-value`, `#price-stat-current-detail`
- **Dependencies (config)**:
  - `PRICE_API_URL` (same injected constant as above)
- **Client code dependencies**:
  - `schedule/assets/js/price_statistics.js` (fetches price data and renders into these IDs)
- **CSS**:
  - `schedule/assets/css/price_statistics.css`

---

## Schedule Calculator (Wh sums)

This section is **server-rendered** initially (PHP fetch + compute), and also has **client-side updates** after schedule refresh.

- **Order**: 9
- **Rendered by (PHP partial)**: `schedule/partials/calculate.php`
- **Server-side dependencies**:
  - Uses `ConfigLoader::get('scheduleApiUrl')` as the fetch URL for resolved schedule data (fallbacks to a hardcoded `data_api.php?type=schedule&resolved=1` URL if missing).
  - Performs HTTP fetches via `file_get_contents()` and calculates totals in Wh.
- **DOM hooks**:
  - Header: `#calculator-current-time`
  - Today full-day:
    - `#calc-today-full-total`, `#calc-today-full-positive`, `#calc-today-full-negative`
  - Today from “now”:
    - `#calc-today-from-now-total`, `#calc-today-from-now-positive`, `#calc-today-from-now-negative`
  - Tomorrow full-day:
    - `#calc-tomorrow-full-total`, `#calc-tomorrow-full-positive`, `#calc-tomorrow-full-negative`
- **Client code dependencies**:
  - `schedule/assets/js/schedule_calculator.js`
    - Provides: `renderScheduleCalculator(todayResolved, tomorrowResolved, currentTime)`
  - Called from: `schedule/assets/js/charge_schedule.js` after schedule data refresh.
- **CSS**:
  - `schedule/assets/css/schedule_calculator.css`

---

## Automation Status (recent automation events)

Server tries to fetch and render initial state; JS supports refresh + expand/collapse.

- **Order**: 10
- **Rendered by (PHP partial)**: `schedule/partials/automation_status.php`
- **Server-side dependencies**:
  - Injects: `AUTOMATION_STATUS_API_URL` (inline `<script>`), built from:
    - Config key `statusApiUrl` / `statusApiUrl-local` (via `ConfigLoader::getWithLocation`)
    - Fallback to `schedule/api/automation_status_api.php?type=all&limit=20`
  - Uses helper functions from parent include `schedule/includes/status.php`:
    - `formatRelativeTime()`, `formatAutomationEntryDetails()`, `getAutomationEntryTypeClass()`, `getAutomationEntryTypeLabel()`
- **DOM hooks**:
  - Refresh button: `#automation-refresh-btn`
  - Entries container (server-rendered or re-rendered by JS): `#automation-entries-wrapper`, `#automation-entries-list`
- **Client code dependencies**:
  - `schedule/assets/js/automation_status.js` (refresh button + toggle behavior)
  - `schedule/assets/js/schedule_renderer.js` (contains `renderAutomationStatus(...)` used by refresh flows)
  - `schedule/assets/js/charge_status.js` (exports `refreshAllStatus()` which the refresh button calls; refreshes automation + charge status together)
- **CSS**:
  - `schedule/assets/css/automation_status.css`

---

## Charge/Discharge (core summary)

Server renders the initial Zendure/P1 snapshot; JS can refresh live.

- **Order**: 11
- **Rendered by (PHP partial)**: `schedule/partials/charge_status.php`
- **Server-side dependencies**:
  - Includes shared bootstrap: `schedule/partials/charge_status_data.php`
    - Includes:
      - `schedule/includes/formatters.php` (e.g. temperature conversions)
      - `schedule/includes/colors.php` (color helpers)
      - `schedule/includes/config_loader.php`
    - Reads config keys:
      - `dataApiUrl` / `dataApiUrl-local` (via `getWithLocation`)
      - `MIN_CHARGE_LEVEL`, `MAX_CHARGE_LEVEL`
    - Injects JS constants (inline `<script>`):
      - `CHARGE_STATUS_ZENDURE_API_URL` (data API for `type=zendure`)
      - `CHARGE_STATUS_P1_API_URL` (data API for `type=zendure_p1`) when available
      - `CHARGE_STATUS_MIN_CHARGE_LEVEL`, `CHARGE_STATUS_MAX_CHARGE_LEVEL`
  - Uses helper functions from parent include `schedule/includes/status.php`:
    - `getSystemStatusInfo()` for charging/standby/discharging labeling
    - `formatRelativeTime()` for “Last update”
- **DOM hooks**:
  - Containers: `#charge-status-content`, `#charge-status-error`, `#charge-status-empty`
- **Client code dependencies**:
  - `schedule/assets/js/charge_status.js`
    - Fetches from injected API URLs and updates DOM via renderer functions.
  - `schedule/assets/js/schedule_renderer.js`
    - Contains renderers for charge status and details (used by refresh).
- **CSS**:
  - `schedule/assets/css/charge_status_defines.css`
  - `schedule/assets/css/charge_status.css`

---

## System & Grid (charge status details, collapsible)

Additional detail view with a toggle button.

- **Order**: 12
- **Rendered by (PHP partial)**: `schedule/partials/charge_status_details.php`
- **Server-side dependencies**:
  - Also includes `schedule/partials/charge_status_data.php` (guarded to run once)
- **DOM hooks**:
  - Main details container: `#charge-status-details-content`
  - Collapsible area: `#charge-status-details-collapsible`
  - Toggle button: `#charge-details-toggle` (calls `toggleChargeStatusDetails()`)
- **Client code dependencies**:
  - `schedule/assets/js/charge_status.js`
    - Provides: `toggleChargeStatusDetails()` and unified refresh logic.
  - `schedule/assets/js/schedule_renderer.js`
    - Provides: `renderChargeStatusDetails(...)` for live updates.

---

## Client-side bootstrapping (inline constants + script load order)

### Inline constants (injected by PHP)

Rendered by: `schedule/charge_schedule.php`

- `API_URL` (schedule CRUD/resolve endpoint; used by schedule JS)
- `PRICE_API_URL` (price endpoint; used by price graph + stats JS)
- `CALCULATE_SCHEDULE_API_URL` (auto-schedule endpoint; used by “Auto” button logic)

Additional inline constants are injected by partials:

- `AUTOMATION_STATUS_API_URL` (from `schedule/partials/automation_status.php`)
- `CHARGE_STATUS_*` constants (from `schedule/partials/charge_status_data.php`)

### Script order (as loaded by the page)

Rendered by: `schedule/charge_schedule.php`

1. **Core modules (must load first)**:
   - `schedule/assets/js/api_client.js`
   - `schedule/assets/js/notification_service.js`
   - `schedule/assets/js/state_manager.js`
   - `schedule/assets/js/data_service.js`
   - `schedule/assets/js/utils_performance.js`
   - `schedule/assets/js/component_base.js`
   - `schedule/assets/js/schedule_utils.js`
   - `schedule/assets/js/schedule_api.js`
   - `schedule/assets/js/schedule_renderer.js`
2. **UI components**:
   - `schedule/assets/js/edit_modal.js`
   - `schedule/assets/js/confirm_dialog.js`
3. **Component modules**:
   - `schedule/assets/js/components/schedule_panel_component.js`
   - `schedule/assets/js/components/price_graph_component.js`
4. **Feature modules**:
   - `schedule/assets/js/price_overview_bar.js`
   - `schedule/assets/js/price_statistics.js`
   - `schedule/assets/js/schedule_calculator.js`
   - `schedule/assets/js/automation_status.js`
   - `schedule/assets/js/charge_status.js`
5. **Main application (must load last)**:
   - `schedule/assets/js/charge_schedule.js`

