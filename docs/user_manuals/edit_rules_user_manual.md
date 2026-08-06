# Edit Rules User Manual

This manual explains how to configure condition rules in:

- `[install_dir]/main/edit_rules.php`

Rules are saved to:

- `[install_dir]/main/data/charge_schedule_conditions.json`

## 1. Purpose

`edit_rules` lets you define conditional schedule actions (charge/discharge/netzero) based on price and derived daily metrics.

## 2. Open The Editor

Open:

- `http://localhost/zendure/main/edit_rules.php`

Use the top-right buttons:

- `ℹ️` opens help page
- `Raw JSON` shows/copies current JSON
- `Reload` reloads rules from file

## 3. Rule Priority (Important)

Rules are evaluated top-to-bottom.

- First matching rule wins for that hour.
- Reorder with `Up` / `Down` to change priority.

## 4. Rule Form Fields

Each rule has:

- `name` (required): label shown in editor
- `value`: output action
  - integer (e.g. `800`, `-800`)
  - `netzero`
  - `netzero+`
  - `netzero-`
  - `Target @ solar charge` (`empty_at_solar_charge`)
  - `Target @ next NZ-` (`full_at_netzero_minus`)
- optional top-level filters:
  - `month` (e.g. `10,11,12,1,2,3`)
  - `hour` (e.g. `1,2,17,18`)
  - `min_time` (inclusive lower hour)
  - `max_time` (inclusive upper hour)
  - `min_power` (optional minimum signed watt bound for `netzero` / `netzero+` / `netzero-`)
  - `max_power` (optional maximum signed watt bound for `netzero` / `netzero+` / `netzero-`)
  - `conditions`: condition rows combined with rule relation (`AND` by default, `OR` for static-only rules)
  - `condition_relation`: optional per-rule relation for `conditions[]` rows (`and` or `or`)
- `fallback_value` (optional): fallback power value used by runtime integrations when runtime conditions fail (integer watts, `netzero`, `netzero+`, or `netzero-`)

Notes:

- `min_power` and `max_power` are only used for rules whose primary `value` is `netzero`, `netzero+`, or `netzero-`
- they default to `null`
- they do not apply to `fallback_value`, and fallback values do not inherit primary rule limits
- the editor uses `100 W` steps for these inputs
- `fallback_value` is optional and independent of `value` type

### Target at solar charge

Select this Value Mode when a price or time condition identifies a selling hour and the battery should reach a requested reserve when solar charging starts again.

- `Requested spare level (%)` is required and must stay within the configured battery operating range.
- `Maximum discharge (W)` is optional and limits the calculated action.
- The first future solar-capable net-zero slot is the target anchor: `netzero+`, or `netzero` when its power limits permit positive charging.
- `netzero-` and `netzero` constrained to `max_power <= 0` are discharge-only and do not qualify.
- The rule can use `hour == max_price_hour_pm` to select the highest-priced PM hour.
- The rule stores a symbolic objective, but the final schedule API converts it to fixed watts or a safe fallback.
- A battery-level runtime condition is normally unnecessary because the planner already uses the predicted battery level.
- The fallback remains visible for this mode even when the rule has no runtime conditions. If omitted, planning uses `netzero-` as its safe default.

### Target at next NZ-

Select this Value Mode for cheap or solar-rich hours when the battery should reach the configured maximum level before the first future NZ- period.

- When the rule matches multiple hours before the same NZ-, then all remaining matching hours are treated as one charging group.
- When the current hour is part of the group, then only its remaining minutes count.
- When planning runs, then it uses live battery percentage, configured capacity, configured maximum battery percentage, remaining eligible duration, and configured maximum charge power.
- When calculating the minimum, then it does not use a solar forecast, consumption forecast, or battery-efficiency factor.
- When the raw minimum is calculated, then it is rounded upward to the next `100 W` and clamped to the configured maximum charge power.
- When the target is materialized, then every remaining matching slot becomes `netzero+` with the calculated `min_power` and configured `max_power`.
- When automation refreshes its schedule, then the live calculation runs again. The normal automation refresh interval is five minutes.
- When the configured maximum battery percentage has been reached, then the minimum becomes `0 W`; NZ+ may still absorb solar surplus but cannot discharge.
- When the required minimum exceeds the configured charge maximum, then the planner emits the maximum and reports `best_effort`.
- When battery data or the next NZ- anchor is unavailable, then the planner uses `netzero+` as the safe default fallback unless another fallback is configured.
- When the Prices and Energy Plan is open, then it refreshes the resolved schedule every five minutes and shows the current minimum in the limit badge.

## 5. Condition Fields

Condition row types:

- `Static condition`: evaluated in the PHP resolver and can participate in `AND` or `OR`
- `Runtime condition`: currently `electricity_level`; shown separately in the editor and always forces the rule relation back to `AND`

Supported `field` values:

- `price`: current hour price (cents/kWh)
- `ranking`: daily rank 1..24 (sorted by price asc, then hour asc)
- `min_price`: lowest daily price (cents/kWh)
- `max_price`: highest daily price (cents/kWh)
- `spread_price`: daily spread (`max_price - min_price` in cents/kWh)
- `min_price_hour`: hour (0-23) when min price occurs (first occurrence)
- `max_price_hour`: hour (0-23) when max price occurs (first occurrence)
- `max_price_hour_am`: hour (0-11) when the AM half-day max price occurs (first occurrence)
- `max_price_hour_pm`: hour (12-23) when the PM half-day max price occurs (first occurrence)
- `month`: current month (1-12)
- `hour`: current hour (0-23)
- `min_time`: lower bound hour (inclusive, equivalent to `hour >= value`)
- `max_time`: upper bound hour (inclusive, equivalent to `hour <= value`)
- `sunrise_hour`: sunrise hour derived per rendered date using configured latitude/longitude (floored)
- `sunset_hour`: sunset hour derived per rendered date using configured latitude/longitude (ceiled)
- `sunrise_offset_hour`: compares current hour to `sunrise_hour + offset`. Provide offset as numeric `value`
- `sunset_offset_hour`: compares current hour to `sunset_hour + offset`. Provide offset as numeric `value`
- `electricity_level`: battery SoC percent condition for runtime evaluation (stored in rules and exposed as runtime metadata; static resolver does not evaluate this field)

Supported operators:

- `>` `>=` `<` `<=` `==` `!=` `in`

## 6. value vs value_ref

A condition can use:

- `value`: literal number/string
- `value_ref`: dynamic reference to a calculated field
- `value` + `value_ref`: dynamic reference plus numeric offset

Supported `value_ref`:

- `min_price`: lowest daily price
- `max_price`: highest daily price
- `spread_price`: daily price spread
- `min_price_hour`: hour of lowest daily price
- `max_price_hour`: hour of highest daily price
- `max_price_hour_am`: hour of highest AM price (`00..11`)
- `max_price_hour_pm`: hour of highest PM price (`12..23`)
- `sunrise_hour`: sunrise hour for the date
- `sunset_hour`: sunset hour for the date

**Note**: `sunrise_offset_hour` and `sunset_offset_hour` cannot be used as `value_ref`. They must use literal `value` for the offset.

If `value_ref` is present, it is used as the right-hand side operand in the comparison.

If both `value_ref` and a numeric `value` are present, `value` is added as an offset:

- `value_ref=max_price` and `value=-1` means `max_price - 1`
- `value_ref=min_price` and `value=1` means `min_price + 1`
- `field=hour`, `op=<`, `value_ref=min_price_hour`, `value=1` means `hour < min_price_hour + 1`

## 7. Condition Relation

- `condition_relation=and` means all static condition rows must match
- `condition_relation=or` means any static condition row may match
- top-level filters `month`, `hour`, `min_time`, `max_time` always remain AND filters
- if a rule contains `electricity_level`, the relation is forced to `and`

## 8. Save Behavior

There is one save flow.

- `Save Rule` updates the rule and writes file immediately.
- `Duplicate`, `Delete`, `Up`, `Down` also write file immediately.
- File write is atomic (temp file + rename).

## 9. Effective Schedule Priority

At API merge time (`data_api.php`):

1. Base schedule resolves first
2. Conditions can overwrite wildcard/empty slots
3. Manual non-wildcard slots keep priority and are not overwritten by conditions

## 10. Example Rules

```json
[
  {
    "name": "Sell at PM maximum before solar",
    "value": "empty_at_solar_charge",
    "target_soc_percent": 15,
    "target_anchor": "next_solar_capable_netzero",
    "max_discharge_power": 1600,
    "fallback_value": "netzero-",
    "conditions": [
      { "field": "hour", "op": "==", "value_ref": "max_price_hour_pm" },
      { "field": "spread_price", "op": ">=", "value": 16 }
    ]
  },
  {
    "name": "Fill during cheap solar hours before discharge",
    "value": "full_at_netzero_minus",
    "target_anchor": "next_netzero_minus",
    "fallback_value": "netzero+",
    "conditions": [
      { "field": "ranking", "op": "<=", "value": 4 },
      { "field": "price", "op": "<=", "value": 5 }
    ]
  },
  {
    "name": "Free Power",
    "value": 800,
    "conditions": [
      { "field": "price", "op": "<=", "value": 0 }
    ]
  },
  {
    "name": "Top3 February",
    "value": "netzero",
    "month": "2",
    "conditions": [
      { "field": "ranking", "op": ">=", "value": 22 }
    ]
  },
  {
    "name": "Charge Cheapest On Spread",
    "value": 800,
    "condition_relation": "and",
    "conditions": [
      { "field": "spread_price", "op": ">=", "value": 12 },
      { "field": "ranking", "op": "<=", "value": 2 }
    ]
  },
  {
    "name": "Extreme Price Hours",
    "value": -800,
    "condition_relation": "or",
    "conditions": [
      { "field": "price", "op": "<", "value_ref": "min_price", "value": 1 },
      { "field": "price", "op": ">", "value_ref": "max_price", "value": -1 }
    ]
  },
  {
    "name": "Discharge At 23 On Spread",
    "value": -800,
    "conditions": [
      { "field": "spread_price", "op": ">=", "value": 12 },
      { "field": "hour", "op": "==", "value": 23 }
    ]
  }
]
```

## 11. Validation Tips

- Ensure JSON is valid (no trailing commas)
- Always provide `name`
- For hour-like fields use 0..23
- For `min_power` / `max_power`, use integer watt values
- Use `Raw JSON` + `Copy` to review/export config quickly

## 12. Related Manuals

- `[install_dir]/docs/user_manuals/min-max-values_user_manual.md`
