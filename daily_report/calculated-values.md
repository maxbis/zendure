# Daily Report Calculated Values

The canonical daily and monthly reports keep grid cost and estimated battery economic contribution separate.

## Primary calculations

```text
net grid cost = consumer-price import cost - spot-price export value

estimated battery economic contribution
    = schedule-assisted battery flow P&L
    + change in stored-energy value
```

The report uses the existing `hourly_report_inputs` data through `daily_report/includes/report_smart_common.php`. Its shared response builder reuses `appEnergyHistoryBuildPayload()` so the report and the app Energy Costs dialog apply the same method-version-3 schedule-assisted valuation.

The summary and detailed values are rendered by:

- `daily_report/assets/js/daily.js`
- `daily_report/assets/js/monthly.js`

The canonical calculation, coverage, partial-result, and failure behavior is documented in `docs/daily_report/energy-reports.md`. Battery-flow valuation details are documented in `docs/daily_report/battery-flow-pnl.md`.

## Important interpretation

Battery contribution is already reflected in measured grid flows. It must not be added to or subtracted from net grid cost. Fixed fees, battery ownership, and degradation are excluded.
