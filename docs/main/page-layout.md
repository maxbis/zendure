# Schedule page layout (`/zendure/main/charge_schedule_mobile.php`)

This document describes **all page sections**, in **top-to-bottom render order**, and lists each section’s **dependencies**:

- **PHP dependencies**: required files + included partials
- **Data/config dependencies**: config keys + data files + query params
- **DOM hooks**: key IDs/classes that JS attaches to
- **Client code dependencies**: JS + CSS that implement behavior/styling

Source of truth: `main/charge_schedule_mobile.php` (live at `https://www.wijs.ovh/zendure/main/charge_schedule_mobile.php`).

---

## Global (page-level) server-side bootstrap

Executed before any HTML is emitted.

- **Order**: 0
- **File**: `main/charge_schedule_mobile.php`
- **Dependencies (PHP)**:
  - `login/validate.php` (access control)
  - `main/api/charge_schedule_functions.php` (schedule IO + resolving)
    - Provides: `loadSchedule()`, `resolveScheduleForDateWithConditions()`, `writeScheduleAtomic()` etc.
  - `main/includes/config_loader.php` (centralized config reads)
    - Reads: `config/config.json` or `run_schedule/config/config.json` (fallback)
- **Dependencies (data/config)**:
  - Data file: `main/data/charge_schedule.json`
  - Query param: `?initial_date=YYYYMMDD` (optional)
  - Config keys used:
    - `scheduleApiUrl` (current local value: `http://localhost/zendure/main/data/api/data_api.php?type=schedule&resolved=1`)
    - `priceApiUrl`
    - `calculate_schedule_apiUrl`
    - `zendureFetchApiUrl` (location-aware via `ConfigLoader::getWithLocation`)
- **Computed variables used by partials**:
  - `$schedule` = `loadSchedule(...)`
  - `$today` (from `initial_date` or `date('Ymd')`)
  - `$resolvedToday` = `resolveScheduleForDateWithConditions($schedule, $today, $includeConditions)`
  - `$currentTime` = `date('Hi')`

---

## `<head>` assets (icons + CSS)

- **Order**: 1
- **Rendered by**: `main/charge_schedule_mobile.php`
- **Dependencies (CSS)**:
  - `main/assets/css/general_mobile.css`
  - `main/assets/css/charge_schedule_mobile.css`
  - `main/assets/css/automation_status.css`
  - `main/assets/css/charge_status_defines.css`
  - `main/assets/css/charge_status.css`
- **Dependencies (static assets)**:
  - `main/favicon.ico`, `main/favicon-16x16.png`, `main/favicon-32x32.png`, `main/apple-touch-icon.png`

---

## Header (page title)

- **Order**: 2
- **Rendered by**: `main/charge_schedule_mobile.php`
- **DOM hooks**:
- **CSS**:
  - `main/assets/css/general_mobile.css`

---

## Schedule Panels (two-column layout)

This is the main “editing” area: **Today’s resolved schedule** (left) and **Schedule Entries table** (right).

- **Order**: 3
- **Rendered by (PHP partial)**: `main/partials/schedule_panels_mobile.php`
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
    - `main/assets/js/schedule_renderer.js` (renders `#today-schedule-grid`, `#schedule-table`, and bar graphs)
    - `main/assets/js/components/schedule_panel_component.js` (optional component wrapper; attaches to `.layout`)
  - API operations:
    - `main/assets/js/schedule_api.js` (fetch schedule, save/delete entry, clear old entries, etc.)
  - Main orchestration:
    - `main/assets/js/charge_schedule.js` (wires buttons, refreshes schedule, re-renders)

---

## Edit Modal + Delete Confirm (entry editing UI)

This section is required for adding/editing schedule entries.

- **Order**: 4
- **Rendered by (PHP partial)**: `main/partials/edit_modal.php`
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
  - `main/assets/js/edit_modal.js` (class `EditModal`, attaches to `#edit-modal` and table row clicks)

---

## Generic Confirm Dialog (reusable confirm/alert)

Used for “Clear old entries”, “Auto calculate schedule”, etc.

- **Order**: 5
- **Rendered by (PHP partial)**: `main/partials/confirm_dialog.php`
- **DOM hooks**:
  - `#confirm-dialog-generic`
  - `#confirm-dialog-title`, `#confirm-dialog-message`
  - `#confirm-dialog-close`, `#confirm-dialog-cancel`, `#confirm-dialog-confirm`
- **Client code dependencies**:
  - `main/assets/js/confirm_dialog.js` (class `ConfirmDialog`)
  - Called from: `main/assets/js/charge_schedule.js`

---

## Price Overview Bar Graph (Today + conditional Tomorrow)

Shows electricity prices, color-coded; bars are clickable to edit schedule entries for that hour.

- **Order**: 7
- **Rendered by (PHP partial)**: `main/partials/price_overview_bar_mobile.php`
- **Inputs required from parent**:
  - `$today` (display only)
  - Server-time dependency: shows Tomorrow column only when PHP `date('H') >= 15`
- **DOM hooks**:
  - `#price-graph-today`
  - `#price-graph-tomorrow` (may or may not exist depending on hour)
- **Dependencies (config)**:
  - `PRICE_API_URL` is injected by `main/charge_schedule_mobile.php` from config.
- **Client code dependencies**:
  - `main/assets/js/price_overview_bar.js`
    - Fetches prices and renders bars (handles hiding/showing tomorrow card based on current hour).
  - `main/assets/js/components/price_graph_component.js` (optional component wrapper; attaches to `.price-graph-wrapper`)
  - Orchestrated by `main/assets/js/charge_schedule.js` (calls `fetchAndRenderPrices(...)` when `PRICE_API_URL` is available).

---

## Energy Graph (Wh per hour)

- **Order**: 8
- **Rendered by (PHP partial)**: `main/partials/energy_graph_mobile.php`
- **DOM hooks**:
  - chart canvas and wrappers defined by the partial
- **Client code dependencies**:
  - `main/assets/js/energy_graph_refresh.js`
- **Server-side dependencies**:
  - same-origin proxy `main/api/energy_graph_proxy.php`

---

## Automation Status (recent automation events)

Server tries to fetch and render initial state; JS supports refresh + expand/collapse.

- **Order**: 9
- **Rendered by (PHP partial)**: `main/partials/automation_status.php`
- **Server-side dependencies**:
  - Injects: `AUTOMATION_STATUS_API_URL` (inline `<script>`), built from:
    - same-origin proxy `main/api/automation_status_proxy.php`
    - proxy config keys `automationStatusApi` and `apiBaseUrlPiControl`
- **DOM hooks**:
  - Refresh button: `#automation-refresh-btn`
  - Entries container (server-rendered or re-rendered by JS): `#automation-entries-wrapper`, `#automation-entries-list`
- **Client code dependencies**:
  - `main/assets/js/automation_status.js` (refresh button + toggle behavior)
  - `main/assets/js/schedule_renderer.js` (contains `renderAutomationStatus(...)` used by refresh flows)
  - `main/assets/js/charge_status.js` (exports `refreshAllStatus()` which the refresh button calls; refreshes automation + charge status together)
- **CSS**:
  - `main/assets/css/automation_status.css`

---

## Charge/Discharge (core summary)

Server renders the mobile shell; JS fetches Zendure/P1 and updates live.

- **Order**: 10
- **Rendered by (PHP partial)**: `main/partials/charge_status_mobile.php`
- **Server-side dependencies**:
  - Uses same-origin proxy `main/api/charge_status_all_proxy.php` via `CHARGE_STATUS_ALL_API_URL` injected by `main/charge_schedule_mobile.php`.
  - Reads threshold/grid constants injected by `main/charge_schedule_mobile.php`.
- **DOM hooks**:
  - Containers: `#charge-status-content`, `#charge-status-error`, `#charge-status-empty`
- **Client code dependencies**:
  - `main/assets/js/charge_status.js`
    - Fetches from injected API URLs and updates DOM via renderer functions.
  - `main/assets/js/schedule_renderer.js`
    - Contains renderers for charge status and details (used by refresh).
- **CSS**:
  - `main/assets/css/charge_status_defines.css`
  - `main/assets/css/charge_status.css`

---

## System & Grid (charge status details, collapsible)

Additional detail view with a toggle button.

- **Order**: 11
- **Rendered by (PHP partial)**: `main/partials/charge_status_details_mobile.php`
- **Server-side dependencies**:
  - Uses same data source as charge status (`CHARGE_STATUS_ALL_API_URL`).
- **DOM hooks**:
  - Main details container: `#charge-status-details-content`
  - Collapsible area: `#charge-status-details-collapsible`
  - Toggle button: `#charge-details-toggle` (calls `toggleChargeStatusDetails()`)
- **Client code dependencies**:
  - `main/assets/js/charge_status.js`
    - Provides: `toggleChargeStatusDetails()` and unified refresh logic.
  - `main/assets/js/schedule_renderer.js`
    - Provides: `renderChargeStatusDetails(...)` for live updates.

---

## Client-side bootstrapping (inline constants + script load order)

### Inline constants (injected by PHP)

Rendered by: `main/charge_schedule_mobile.php`

- `API_URL` (schedule CRUD/resolve endpoint; used by schedule JS)
- `PRICE_API_URL` (price endpoint; used by the price graph)
- `CALCULATE_SCHEDULE_API_URL` (auto-schedule endpoint; used by “Auto” button logic)

Additional inline constants are injected by partials:

- `AUTOMATION_STATUS_API_URL` (from `main/partials/automation_status.php`)
- `CHARGE_STATUS_*` constants (injected by `main/charge_schedule_mobile.php`)

### Script order (as loaded by the page)

Rendered by: `main/charge_schedule_mobile.php`

1. **Core modules (must load first)**:
   - `main/assets/js/api_client.js`
   - `main/assets/js/notification_service.js`
   - `main/assets/js/state_manager.js`
   - `main/assets/js/utils_performance.js`
   - `main/assets/js/component_base.js`
   - `main/assets/js/schedule_utils.js`
   - `main/assets/js/schedule_api.js`
   - `main/assets/js/schedule_renderer.js`
2. **UI components**:
   - `main/assets/js/edit_modal.js`
   - `main/assets/js/confirm_dialog.js`
3. **Component modules**:
   - `main/assets/js/components/schedule_panel_component.js`
   - `main/assets/js/components/price_graph_component.js`
4. **Feature modules**:
   - `main/assets/js/price_overview_bar.js`
   - `main/assets/js/automation_status.js`
   - `main/assets/js/charge_status.js`
   - `main/assets/js/energy_graph_refresh.js`
5. **Main application (must load last)**:
   - `main/assets/js/charge_schedule.js`
