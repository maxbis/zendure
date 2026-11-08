# Energy Reports

## Purpose

The daily and monthly energy reports present grid cost and estimated battery economic contribution as separate outcomes. They replace the former desktop report interface while preserving its reporting inputs and compatibility APIs.

The reports use the Graphite Signal Dark design system. They do not create a second database, report updater, or valuation method.

## Location

- Daily page: `daily_report/index.php`
- Monthly page: `daily_report/monthly.php`
- Daily API: `daily_report/api/daily.php`
- Monthly API: `daily_report/api/monthly.php`
- Shared response builder: `daily_report/includes/report2_common.php`
- Styles: `daily_report/assets/css/report.css`
- Browser behavior: `daily_report/assets/js/daily.js` and `daily_report/assets/js/monthly.js`

The production routes are `/daily_report/` and `/daily_report/monthly.php`. The former `/daily_report2/` routes redirect to these canonical locations and preserve query parameters for existing bookmarks.

Both report headers provide an `Energy Manager` link back to the main application at `/app/`. Daily and monthly navigation remains in the separate report-view switch.

## Inputs and outputs

The report reads the existing `hourly_report_inputs` data through `daily_report/includes/report_smart_common.php`.

The shared response builder reuses `appEnergyHistoryBuildPayload()` so the preview and the app Energy Costs dialog use the same method-version-3 schedule-assisted battery valuation.

The daily response contains:

- Hourly battery, grid, battery-level and price data.
- Net grid cost, using consumer price for imports and spot price for exports.
- Battery charging cost, estimated discharge value and battery flow P&L.
- Change in stored-energy value.
- Estimated battery economic contribution.
- Coverage, estimation basis, confidence and completeness metadata.

The monthly response contains:

- Daily rows for the included month.
- Energy and grid-cost totals.
- A complete or partial battery contribution from all available valued-hour and stored-value components.
- Valued and missing hour counts, affected-day counts, and unvalued discharged energy.
- Coverage and the lowest confidence among the available estimates.

## Flow and behavior

1. The page requests its selected day or month from the canonical report API.
2. The API loads the existing smart daily report data.
3. The response builder maps elapsed hours to the app energy-history row contract.
4. The shared app calculation produces grid-cost, schedule-assisted battery-flow and stored-energy results.
5. The API adds coverage and presentation metadata.
6. The browser renders the summary, coordinated chart, economics breakdown and detailed rows.

The principal calculations are:

```text
net grid cost = consumer-price import cost - spot-price export value

estimated battery economic contribution
    = schedule-assisted battery flow P&L
    + change in stored-energy value
```

When the monthly contribution is complete, then it is the sum of complete daily operational estimates. When one or more hours are missing, then the displayed value is a partial accounted subtotal: available components are included and missing hours are not treated as zero. Neither result is a formal month-end inventory revaluation.

## Edge cases and failure modes

- When a selected date or month is in the future, then the API rejects it.
- When no financial component is available, then the browser displays an unavailable value rather than coercing it to zero.
- When some hourly valuations are available, then the daily and monthly pages display a partial subtotal with explicit coverage rather than hiding all available value.
- When a monthly subtotal is partial, then the page shows valued hours, missing hours, affected days, unvalued battery discharge, and affected dates.
- When the current day or month is selected, then the page labels the result as complete so far or partial rather than as a completed period.
- When schedule, price, battery-level or grid data is missing, then coverage and confidence metadata explain the limitation.
- Battery contribution is already reflected in measured grid flows. It must not be added to or subtracted from net grid cost.

## Cutover and compatibility

- The canonical desktop pages are the redesigned daily and monthly reports.
- The retired desktop CSS and JavaScript bundles are not loaded or retained in the active report path.
- The existing reporting data, updater tools and compatibility APIs remain available for integrations and the mobile report.
- Requests to the former `/daily_report2/` preview routes receive a temporary redirect to the corresponding canonical report.

## Related files

- `main/includes/app_energy_history.php`
- `daily_report/includes/report_smart_common.php`
- `daily_report/tools/hourly_daily_grid_battery_report.py`
- `docs/app/energy-costs-and-battery-pnl.md`
- `docs/daily_report/battery-flow-pnl.md`
- `themes/graphite-signal-dark/assets/css/theme.css`
- `themes/graphite-signal-dark/assets/css/components.css`
