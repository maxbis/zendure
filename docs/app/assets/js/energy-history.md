# Battery energy history summary

## Purpose

Render the `/app` four-day battery energy chart and selected-day summary cards. The Net flow card opens an energy-cost dialog with grid cost and four-way battery-flow P&L.

## Location

- `app/assets/js/energy-history.js`
- `main/includes/app_energy_history.php`
- `main/api/app_energy_history.php`
- `app/index.php` (Battery energy section)

## Inputs / outputs

- Input: `../main/api/app_energy_history.php?days=3`
- Output cards: Charged, Discharged, Net flow
- Charged and Discharged tooltips: consumer and spot valuations of those battery flows
- Net flow dialog: grid import cost, grid export value, net grid cost, four battery-flow energy and euro components, charging and discharge subtotals, and estimated battery P&L
- Battery-flow money inputs are stored millieuro values from the version 2 daily-report calculation; grid-charging cost is split from the stored total charge cost using the hourly consumer price

## Flow / behavior

1. Load hourly energy, grid-meter flows, prices, and battery-flow attribution. Today's live report and historical hourly rows share the same aggregation path.
2. Summary cards show signed energy: Charged as `+…`, Discharged as `−…`, Net flow as `chargedWh - dischargedWh`.
3. Grid cost sums each hour's grid import at consumer price and grid export at spot price. Net grid cost is import cost minus export value.
4. Battery P&L shows grid charging at consumer price, surplus-solar charging at spot opportunity cost, home discharge at consumer price, and battery export at spot price. It uses the report's stored battery-flow P&L total.
5. When the Net flow card is clicked, show the two stacked cost sections for the selected day. The dialog's day controls refresh both sections.
6. Battery allocation is estimated by the report: concurrent grid import is attributed to charging up to charged energy, and discharge is allocated to home use first. Battery P&L is separate from, and not subtracted again from, net grid cost.

## Edge cases / failure modes

- When a required price or meter reading is missing, then the affected grid total is unavailable (`—`).
- When any elapsed hour lacks complete version 2 battery-flow attribution, then all daily battery-flow values and P&L are unavailable (`—`); the dialog explains the first affected hour and reason.
- When today is selected, future placeholder hours are excluded and totals cover elapsed hours through now.
- When spot prices are negative, charging opportunity cost or export revenue can be negative.

## Related files

- `docs/app/gui-overview.md`
- `docs/daily_report/battery-flow-pnl.md`
- `tools/tests/test_app_energy_history.py`
