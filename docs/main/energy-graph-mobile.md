# Energy Graph Mobile Partial (`main/partials/energy_graph_mobile.php`)

This document describes the mobile Energy Graph card used in `main/charge_schedule_mobile.php`.

## Purpose

Render a mobile-friendly energy section with two tabs:
- `Graph`: bar chart of Wh-per-hour values
- `Daily totals`: compact per-day totals list

The partial provides HTML shell + tab behavior. Data fetching and chart updates are handled in JavaScript.

## Rendered Markup

File: `main/partials/energy_graph_mobile.php`

Main DOM hooks:
- `.energy-graph-mobile` (card container)
- `.energy-graph-mobile-tab[data-tab="graph"]`
- `.energy-graph-mobile-tab[data-tab="daily"]`
- `.energy-graph-mobile-tab-panel[data-tab="graph"]`
- `.energy-graph-mobile-tab-panel[data-tab="daily"]`
- `#energyChartMobile` (Chart.js canvas)
- `.energy-graph-mobile-daily-table` (daily totals content target)

## Behavior

Inline script in the partial:
- toggles tab `active` class
- updates `aria-selected` / `aria-hidden`
- resizes chart when switching back to graph tab (`window.energyChartMobile.resize()`)

## Data + Refresh Flow

Primary JS: `main/assets/js/energy_graph_refresh.js`

Key points:
- Uses `ENERGY_GRAPH_API_URL` when injected by PHP.
- In current page setup, `ENERGY_GRAPH_API_URL` is injected by `main/charge_schedule_mobile.php` as:
  - `api/energy_graph_proxy.php`
- Fallback in JS (if constant missing): `api/energy_graph_api.php`
- Exposes `window.refreshEnergyGraph()`.
- Called from `main/assets/js/charge_schedule.js` during `_refreshScheduleAndPricesInternal()`.

## API Dependency

Proxy endpoint: `main/api/energy_graph_proxy.php`

Config keys used by proxy:
- `wh-per-hourApi`
- `apiBaseUrlPiControl`
- `whPerHourCacheMinutes`
- `baseWh`

Proxy query support:
- optional `days` query parameter
- example: `main/api/energy_graph_proxy.php?days=7`
- invalid values fall back to `3`
- values above `30` are clamped

## Chart Notes

The mobile chart:
- clips displayed values to +/- 800 Wh (with tooltip note when clipped)
- supports tap-to-focus day behavior (tap bar to focus a single date, tap again to reset)
- uses transformed Y scaling for readability while showing original Wh in tooltips/ticks

## Files Involved

- Partial: `main/partials/energy_graph_mobile.php`
- Refresh logic: `main/assets/js/energy_graph_refresh.js`
- Schedule refresh orchestrator: `main/assets/js/charge_schedule.js`
- Proxy API: `main/api/energy_graph_proxy.php`
- Styles: `main/assets/css/charge_schedule_mobile.css` (energy graph mobile classes)
