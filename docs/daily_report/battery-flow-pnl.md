# Battery Flow PnL

## Purpose

Battery flow PnL measures the hourly economic contribution of battery charging and discharging. It separates household consumption from grid exchange before applying consumer and spot prices.

It does not represent the complete household electricity bill. It excludes fixed supplier charges, battery degradation, inverter standby consumption, and opening or closing battery inventory valuation.

## Location

The calculation and persistence paths are:

- `daily_report/tools/hourly_daily_grid_battery_report.py`
- `daily_report/tools/update_hourly_report_inputs.py`
- `daily_report/includes/report_smart_common.php`
- `daily_report/includes/report_pnl_common.php`
- `daily_report/api/monthly_report_data.php`
- `daily_report/sql/add_battery_flow_pnl_columns.sql`

## Inputs and outputs

Inputs come from MariaDB database `sqlite_replication`:

- `status_updates.new_value` supplies signed battery power.
- `status_updates.total_act_x100` supplies cumulative grid import.
- `status_updates.total_act_ret_x100` supplies cumulative grid export.
- `price_ticks.consumer_eur_per_kwh` supplies the consumer price.
- `price_ticks.spot_eur_per_kwh` supplies the spot price.

Hourly production comes from MariaDB database `enphase_history`:

- `production_hourly.energy_wh` supplies production for the configured Enphase system and source.
- The defaults match Energy Calendar: system `5053376` and source `production_micro`.
- `ENPHASE_SYSTEM_ID`, `ENPHASE_PRODUCTION_SOURCE`, `ENPHASE_HISTORY_DATABASE`, and `ENPHASE_PRODUCTION_TABLE` can override those defaults.

Physical output fields use whole Wh:

- `estimated_home_load_wh`
- `battery_charge_grid_wh`
- `battery_charge_surplus_wh`
- `battery_discharge_home_wh`
- `battery_discharge_export_wh`

Financial output fields use signed millieuros. One millieuro is EUR 0.001 or 0.1 cent:

- `battery_charge_cost_milli_eur`
- `battery_home_savings_milli_eur`
- `battery_export_revenue_milli_eur`
- `battery_flow_pnl_milli_eur`

Audit fields are:

- `battery_pnl_status`
- `battery_pnl_method_version`

## Flow and behavior

Method version 2 keeps interval-level charge attribution and allocates discharge home-first at hourly level.

1. When the battery charges while the meter imports, then up to the charged energy is classified as grid charge.
2. When charged energy exceeds grid import, then the remainder is classified as surplus charge.
3. Estimated hourly home load is derived from the hourly energy balance:

```text
estimated home load Wh = round(max(0,
    production Wh
  + grid import Wh
  - grid export Wh
  + battery discharge Wh
  - battery charge Wh
))
```

4. Battery discharge is allocated to home first, up to estimated home load.
5. Any remaining battery discharge is classified as export.
6. When a battery-power interval crosses an hourly boundary, then it is split before hourly totals and prices are applied.

Whole-Wh components reconcile exactly:

```text
battery_charge_grid_wh + battery_charge_surplus_wh = round(charged_wh)
battery_discharge_home_wh + battery_discharge_export_wh = round(discharged_wh)
```

Financial components are calculated from the stored whole-Wh values:

```text
charge cost =
    charge grid Wh * consumer EUR/kWh
  + charge surplus Wh * spot EUR/kWh

home savings = discharge home Wh * consumer EUR/kWh
export revenue = discharge export Wh * spot EUR/kWh

flow PnL = home savings + export revenue - charge cost
```

The Wh-to-kWh and EUR-to-millieuro factors cancel. Financial results are rounded to the nearest millieuro with half values rounded away from zero. PnL is calculated from the stored integer components, so the integer identity is exact.

Negative spot prices are preserved. They can make surplus charging cost negative or exported discharge revenue negative.

## Status contract

- When all physically and financially relevant inputs exist, then status is `complete`.
- When no battery energy moves, then missing prices or counters are not required and the complete PnL is zero.
- When the battery state before an hour cannot be established, then status is `missing_boundary_sample`.
- When a required import or export counter boundary is absent, then status is `missing_grid_counters`.
- When production or an hourly grid total needed to estimate home load is absent during discharge, then status is `missing_home_load`.
- When a PnL-only backfill reconstructs a battery total that differs materially from the existing stored hourly total, then status is `energy_total_mismatch`; legacy energy fields are not silently rewritten.
- When household discharge or grid charging needs a missing consumer price, then status is `missing_consumer_price`.
- When surplus charging or battery export needs a missing spot price, then status is `missing_spot_price`.
- When the hour has not elapsed, then status is `not_calculated` and the method version is null.
- When any financially relevant elapsed hour is incomplete, then the day or month PnL is incomplete rather than a partial sum presented as complete.

## Backfill

Use the same updater and method as the regular production calculation.

Dry-run a historical range:

```bash
python3 daily_report/tools/update_hourly_report_inputs.py \
  --start-date 2026-04-06 \
  --end-date YYYY-MM-DD \
  --pnl-only \
  --dry-run
```

Populate the estimated home-load, attribution, PnL, status, and version columns:

```bash
python3 daily_report/tools/update_hourly_report_inputs.py \
  --start-date 2026-04-06 \
  --end-date YYYY-MM-DD \
  --pnl-only
```

The inspected development copy has consumer and spot prices from 1 April 2026 and cumulative import/export counters from late 5 April 2026. Complete automatic backfill therefore begins on 6 April 2026. Production must also exist in `enphase_history.production_hourly`. Earlier values remain incomplete unless a separately versioned estimation method is introduced.

## Edge cases and failure modes

- Counter resets are normalized before interval deltas are calculated.
- Missing inputs produce null financial fields and an explanatory status; they are never silently converted to zero during battery activity.
- The preceding battery change record is fetched with each date so the state at midnight is known.
- Backfill mode commits one date at a time and is idempotent.
- `--pnl-only` changes only estimated home load, attribution, PnL, status, and version columns.
- `--dry-run` performs no schema or data writes.
- The existing `(local_date, local_hour)` identity cannot represent both occurrences of the repeated autumn DST hour. This is a pre-existing hourly-report limitation.

## Related files

- `docs/daily-report-operations.md`
- `docs/prices/price-ticks.md`
- `daily_report/calculated-values.md`
