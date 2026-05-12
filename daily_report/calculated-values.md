# Daily Report Calculated Values

This file explains how the summary boxes in `/daily_report` are calculated.

## Data sources

- Today's base hourly values are generated live from `status_updates` by `daily_report/tools/hourly_daily_grid_battery_report.py`.
- Yesterday and older base hourly values are read from MariaDB table `hourly_report_inputs`.
- The summary cards are filled in `daily_report/assets/js/daily_report.js`.
- The second-line `Spot ...` values are recalculated in the frontend from the hourly rows.
- Hourly prices are loaded from MariaDB table `price_ticks` in the `sqlite_replication` database. The daily report does not fall back to `main/data/price` JSON files.
- Production report APIs no longer create daily report JSON files. Saved JSON reports are legacy data only.

## Price definitions

### Consumer price

`price_eur_per_kwh` is the normal hourly consumer price from `price_ticks.consumer_eur_per_kwh`.

If a DB row is missing for an hour, `price_eur_per_kwh` is `null` for that hour. Cost values that depend on price are also `null`.

### Spot price

The frontend derives the spot price from the consumer price with:

```text
spot_price = (consumer_price / vatMultiplier) - supplierMarkupEurPerKwh - energyTaxEurPerKwh
```

This conversion comes from `main/includes/price_conversion.php`.

With the current defaults:

```text
spot_price = (consumer_price / 1.21) - 0.0219 - 0.0898
```

## Box calculations

### Net Cost

Main number:

Per hour:

```text
grid_from_cost = (grid_from_wh / 1000) * consumer_price
grid_to_cost   = -1 * ((grid_to_wh / 1000) * consumer_price)
net_cost_hour  = grid_from_cost + grid_to_cost
```

Daily total:

```text
net_cost = sum(net_cost_hour for all hours)
```

Meaning:

- `grid_from_wh` is import from the grid, so it adds cost.
- `grid_to_wh` is export to the grid, so it is stored as a negative value and reduces cost.

Second line (`Spot ...`):

```text
grid_from_cost_consumer = (grid_from_wh / 1000) * consumer_price
grid_to_cost_spot       = -1 * ((grid_to_wh / 1000) * spot_price)
spot_net_cost_hour      = grid_from_cost_consumer + grid_to_cost_spot
spot_net_cost           = sum(spot_net_cost_hour for all hours)
```

Important: in the current implementation, the `Spot` version only changes the export side to spot price. The import side still uses the consumer price.

### Savings

Main number:

Per hour:

```text
savings_hour = (discharged_wh / 1000) * consumer_price
```

Daily total:

```text
savings = sum(savings_hour for all hours)
```

Meaning:

- This treats every discharged kWh as avoided purchase at the hourly consumer price.
- There is no separate `Spot` line for `Savings`.

### Charge Costs

Main number:

Per hour:

```text
charge_cost_hour = (charged_wh / 1000) * consumer_price
```

Daily total:

```text
charge_cost = sum(charge_cost_hour for all hours)
```

Second line (`Spot ...`):

```text
spot_charge_cost_hour = (charged_wh / 1000) * spot_price
spot_charge_cost      = sum(spot_charge_cost_hour for all hours)
```

### P&L

Main number:

```text
pnl = (charge_cost - savings + net_cost) * -1
```

Equivalent form:

```text
pnl = savings - charge_cost - net_cost
```

Second line (`Spot ...`):

```text
spot_pnl = (spot_charge_cost - savings + spot_net_cost) * -1
```

Equivalent form:

```text
spot_pnl = savings - spot_charge_cost - spot_net_cost
```

Important: in the current implementation, `spot_pnl` still uses the normal `savings` value, not a spot-based savings value.

## Where the underlying energy values come from

- `charged_wh` and `discharged_wh` are calculated by integrating `p1_total_power` over each hour.
- `grid_from_wh` is calculated from the hourly delta of `total_act_x100`, divided by `100`.
- `grid_to_wh` is calculated from the hourly delta of `total_act_ret_x100`, divided by `100`.

## Rounding

- Report totals are summed using raw float values and then rounded in the API payload.
- The stored totals use 4 decimals for euro values.
- The frontend also formats the displayed box values to 4 decimals with the `EUR` prefix.

## Price maintenance

The price table is maintained by:

```text
main/prices/update_price_ticks.php
main/prices/backfill_price_ticks.php
```

See `docs/prices/price-ticks.md` for schema, cron, and backfill details.
