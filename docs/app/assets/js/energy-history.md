# Battery energy history summary

## Purpose

Render the `/app` four-day battery energy chart and selected-day summary cards. The Net flow card opens an energy-cost dialog with grid cost, battery-flow P&L, stored-energy value change, and estimated battery economic contribution.

## Location

- `app/assets/js/energy-history.js`
- `main/includes/app_energy_history.php`
- `main/api/app_energy_history.php`
- `app/index.php` (Battery energy section)
- `app/partials/energy-money-overview.html` (Energy costs dialog template, rendered into `app/index.php`)

## Inputs / outputs

- Input: `../main/api/app_energy_history.php?days=3`
- Output cards: Charged, Discharged, Net flow
- Charged and Discharged tooltips: consumer and spot valuations of those battery flows
- Net flow dialog: three stacked cards for grid cost, battery flows plus Battery P&L, and stored value plus estimated economic contribution. The battery-flow card includes four classified energy/euro components, an optional conservative unclassified-discharge line, and charging and discharge subtotals.
- Battery-flow money inputs are stored millieuro values from the version 2 daily-report calculation; grid-charging cost is split from the stored total charge cost using the hourly consumer price

## Flow / behavior

1. Load hourly energy, grid-meter flows, prices, and battery-flow attribution. Today's live report and historical hourly rows share the same aggregation path.
2. Summary cards show signed energy: Charged as `+…`, Discharged as `−…`, Net flow as `chargedWh - dischargedWh`.
3. Grid cost sums each hour's grid import at consumer price and grid export at spot price. Net grid cost is import cost minus export value.
4. Battery flow P&L shows grid charging at consumer price, surplus-solar charging at spot opportunity cost, home discharge at consumer price, and battery export at spot price. It uses the report's stored battery-flow P&L total.
5. Discharge is shown as one neutral **Estimated** row. NZ− and other non-fixed schedules value measured discharge at the consumer price. Fixed negative schedules split measured discharge using expected household load, value the remainder at spot price, and optionally cap export with measured `grid_to_wh`.
6. When the Net flow card is clicked, show three stacked cards for the selected day. Charging costs, discharge value, and their Battery P&L result share the middle card; the change in stored value and final economic contribution share the last card. The dialog's day controls refresh all three cards.
7. The server values the midnight-to-latest battery-level change for today, or midnight-to-end-of-day change for historical days, using configured capacity, discharge efficiency, and the day's elapsed-hour average consumer price. The dialog adds this signed stored-value change to complete battery flow P&L for the final economic contribution. No future energy movement is included in today's figure.
8. Battery allocation is estimated by the report: concurrent grid import is attributed to charging up to charged energy, and discharge is allocated to home use first. Battery economic contribution is separate from, and not subtracted again from, net grid cost.

## Edge cases / failure modes

- When a required price or meter reading is missing, then the affected grid total is unavailable (`—`).
- When some elapsed hours have complete version 2 battery-flow attribution and others do not, then the dialog shows the sums from complete hours as **partial**, with the number of covered and excluded hours plus the first affected hour and reason. These are not full-day P&L values.
- When the applicable schedule is missing for an hour with discharge, then that hour and the full-day contribution remain unavailable.
- When measured grid export is missing for fixed discharge, then schedule-and-load allocation remains available with medium confidence.
- When no elapsed hour has complete battery-flow attribution but the measured discharge has both prices, then the conservative discharge value can still be shown. If required charge inputs or prices are missing, then the affected total and whole-day P&L remain unavailable (`—`).
- When today is selected, future placeholder hours are excluded and totals cover elapsed hours through now.
- When an opening or closing battery level, an elapsed hourly consumer price, or the battery configuration is missing, stored value and final economic contribution are unavailable (`—`). Historical value also requires the 23:00 hour; today's figure requires every hour up to the latest elapsed one.
- When spot prices are negative, charging opportunity cost or export revenue can be negative.

## Related files

- `docs/app/energy-costs-and-battery-pnl.md`
- `docs/app/gui-overview.md`
- `docs/daily_report/battery-flow-pnl.md`
- `tools/tests/test_app_energy_history.py`
