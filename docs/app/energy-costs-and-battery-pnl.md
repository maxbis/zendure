# Energy costs and battery P&L in the app

## Purpose

The **Net flow** card opens the app's **Energy costs** dialog for the selected day. It shows an estimated net grid cost and a separate estimate of the battery's economic contribution. The two figures answer different questions and must **not** be added or subtracted from each other: battery activity is already reflected in the measured grid flows.

The dialog stacks three boxes: **Grid cost**; **Battery flows**, containing charging costs, discharge value, and their **Battery P&L** result; and **Stored value & contribution**, containing the stored-energy adjustment and final economic contribution.

These are variable-energy estimates, not a supplier invoice. The battery figure includes the day's **change** in stored-energy value, not the value of the whole battery balance. Fixed fees, taxes or charges not included in the stored prices, battery purchase and degradation remain outside this P&L.

## Location

- Dialog and display: `app/index.php` and `app/assets/js/energy-history.js`.
- App API: `main/api/app_energy_history.php` and `main/includes/app_energy_history.php`.
- Hourly calculation: `daily_report/tools/hourly_daily_grid_battery_report.py`.
- Historical persistence: `daily_report/tools/update_hourly_report_inputs.py`.

## Inputs and outputs

The app reads these sources, using local calendar dates and hours in **Europe/Amsterdam**:

- Battery charge/discharge and battery percentage: battery-power and level records in `sqlite_replication.status_updates`. The report integrates power over time; chart bars are not solar measurements.
- Grid import/export: cumulative meter counters `status_updates.total_act_x100` and `status_updates.total_act_ret_x100`. The report derives hourly energy from counter differences, interpolating hour boundaries where permitted.
- Consumer and spot prices: `sqlite_replication.price_ticks`. Historical `hourly_report_inputs` rows store copies of the relevant hourly prices.
- Solar production: `enphase_history.production_hourly.energy_wh`, filtered by the configured system ID and source (defaults: `5053376`, `production_micro`). Production is needed to estimate home load and therefore to classify battery discharge as home use or export. The Enphase source table is `production_hourly`, not merely the `enphase_history` database name.
- Historical calculated values and audit status: `sqlite_replication.hourly_report_inputs`, one row per local date/hour. Its `battery_pnl_status` and `battery_pnl_method_version` indicate whether the four-way battery attribution is usable.

The API returns per-hour chart values and per-day `gridPriceTotals`, `batteryFlowTotals`, and `storedEnergyValue`. Battery-flow energy is stored in whole Wh, and financial components are stored in millieuros before display as euros. `storedEnergyValue` uses the configured battery capacity and round-trip efficiency from `common/config/system.json`.

## Flow and behavior

### Grid cost

- **Imported · consumer price** = sum of hourly grid-import kWh × that hour's consumer price.
- **Exported · spot price** = sum of hourly grid-export kWh × that hour's spot price. A negative spot price can make this value negative.
- **Estimated net grid cost** = imported cost − exported value. A negative result indicates estimated net revenue from variable grid exchange, not a negative supplier bill.

The grid figures use grid-meter flows; they do not require solar production to split battery discharge. A missing meter total or required hourly price makes the affected full-day grid figure unavailable (`—`), rather than treating missing data as zero.

### Battery P&L: classified hours

Version 2 of the hourly report attributes charging and discharging as follows:

1. Charging concurrent with grid import is attributed to **From grid · consumer price**, up to the charged energy. Remaining charge is **From surplus solar · spot price**: the spot price represents the opportunity cost of not exporting that energy.
2. Estimated home load is `max(0, solar production + grid import − grid export + battery discharge − battery charge)` for the hour.
3. Battery discharge is assigned to **Used at home · consumer price** up to estimated home load. Any remainder is **Exported · spot price**.
4. **Battery flow P&L** = home-use value + battery-export value − grid-charging cost − surplus-solar charging opportunity cost.

The home/export discharge split is an **estimate derived from an energy balance**, not a separately metered battery-export reading. Likewise, the four-way battery P&L is not an additional saving to subtract from net grid cost.

### Battery P&L: conservative fallback

If an hour has measured battery discharge but lacks a usable home/export split, the app does **not** report that discharge as zero or call it export revenue. It shows a separately highlighted **Unclassified** row at the same level as confirmed home use and export, with the sublabel **Conservative · lower hourly price**. The app values that hour's discharge at `min(consumer price, spot price)`. This is a provisional conservative value; a negative spot price can make it negative. Both hourly prices must exist for this fallback. The confirmed exported amount is not increased by unclassified discharge.

Charging cost in such an hour is still included when its grid/surplus Wh split and the relevant hourly prices are available. The **Battery P&L** result at the bottom of the Battery flows card is numeric only when every elapsed hour has both a covered charging cost and a covered discharge value. The unclassified sublabel is the visible indication that this number is conservative; there is no separate heading badge or explanatory banner for that fallback. Otherwise the flow details remain visible but the whole-day flow P&L is unavailable. If a required hourly price or charging input is missing, the sublabel says so. Classified home/export rows remain separate from the unclassified fallback row.

The discharge details omit a classified home-use or export row when its energy is exactly zero. A non-zero kWh row stays visible even when its euro amount rounds to €0.00. When there is no measured discharge, the details instead say **No battery discharge recorded**; the total remains visible. Missing classified values are not turned into confirmed zeroes when only unclassified discharge is available.

When all hours have complete version-2 attribution, the **Battery P&L** result uses the classified values. A **Partial** label means only some hours have complete attribution; a partial sum must not be read as a full-day result.

### Stored energy and final contribution

- **Change in stored value** uses battery percentage at local midnight and the latest battery percentage for today, or end-of-day battery percentage for a past day. It does not count the battery's entire opening balance as today's gain.
- Percentage change becomes stored Wh using configured capacity. Multiplication by `sqrt(roundTripEfficiency)` estimates the energy deliverable from that change, matching the app's optimizer valuation convention. Signed deliverable kWh are valued at the arithmetic average of that day's **elapsed hourly consumer prices**. For today, future hours and predicted future charge are excluded; for completed days, all 24 hourly prices are used.
- **Estimated battery economic contribution** = complete battery flow P&L + change in stored value. It is shown only when both components are available. A partially classified flow can still contribute when the conservative fallback covers every elapsed hour. Missing midnight or closing battery readings, hourly prices, or battery configuration make stored value and the final estimate unavailable rather than zero.
- This is a modeled variable-energy contribution under the app's pricing and attribution assumptions, **not measured bill savings or total battery return**. It must not be added to or subtracted from net grid cost.

## Timing and freshness

1. **Today:** On page load or the Battery energy refresh button, the browser requests `main/api/app_energy_history.php`. The API runs the daily-report generator for today against current `status_updates`, `price_ticks`, and available `production_hourly` rows. It counts only elapsed hours, including the current partial hour. It does not wait for the midnight updater. If this live calculation fails, the API falls back to today's stored `hourly_report_inputs` and marks the response stale.
2. **Yesterday and older days:** The API reads stored `hourly_report_inputs`; opening or refreshing the dialog does not recompute those historical rows. The updater recomputes **yesterday and today** by default, so a later successful run can correct yesterday after late-arriving meter or Enphase data appears.
3. **Observed qool timing during the September 2026 investigation:** the production updater was observed running about **00:01 Europe/Amsterdam**, once per day. That run included the previous day, but an Enphase hourly production row was observed arriving around **01:00** afterward. Consequently, the 00:01 historical snapshot could say `missing_home_load` even though the production row was visible later. This is an observed deployment schedule and incident, not a timing guarantee encoded in this repository. Verify the current production scheduler and ingestion logs before assuming those times still apply.
4. **Recovery:** Rerun `python3 daily_report/tools/update_hourly_report_inputs.py --date YYYY-MM-DD` **after** the relevant production and price rows have arrived, then refresh the app. For a normal daily run, omitting `--date` recomputes yesterday and today. Running it at about 02:00 is useful only if ingestion has finished by then; the condition is source-data completeness, not the clock time itself.

The repository's `docs/daily-report-operations.md` gives a more frequent **example** cron (`*/15`); it is not evidence that qool currently uses that schedule. The app's own refresh updates its view but cannot repair stored historical attribution without a successful updater run.

## Edge cases and failure modes

- An absent `production_hourly` row means **unknown production**, not zero solar. A recorded `energy_wh = 0` is an observed zero and can support home-load attribution.
- Production is needed for the classified home/export split, but **not** for the conservative lower-price value of measured discharge. Other missing inputs, including battery boundary samples, grid counters, or prices, can still prevent a complete battery P&L.
- An hour with no battery energy movement can have complete zero battery P&L without requiring otherwise irrelevant prices or production.
- A price or production row arriving after the historical updater does not automatically change `hourly_report_inputs`; a rerun is required.
- Currency displayed to cents is rounded from hourly millieuro components. Subtracting displayed rounded subtotals can therefore differ by one cent from the displayed P&L.
- Per-day stored-energy changes use each day's own average consumer price. Summing daily economic-contribution figures across days is not a formal inventory revaluation or audited multi-day return.
- The current `(local_date, local_hour)` aggregate key cannot represent both occurrences of the repeated autumn daylight-saving hour. This is a known limitation of the hourly report.

## Related files

- [Battery-flow calculation and statuses](../daily_report/battery-flow-pnl.md)
- [Daily report operations and updater commands](../daily-report-operations.md)
- [Energy-history UI behavior](assets/js/energy-history.md)
- [Price-tick source](../prices/price-ticks.md)
