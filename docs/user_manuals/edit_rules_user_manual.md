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
- optional top-level filters:
  - `month` (e.g. `10,11,12,1,2,3`)
  - `hour` (e.g. `1,2,17,18`)
  - `min_time` (inclusive lower hour)
  - `max_time` (inclusive upper hour)
  - `min_power` (optional minimum signed watt bound for `netzero` / `netzero+`)
  - `max_power` (optional maximum signed watt bound for `netzero` / `netzero+`)
  - `conditions`: all conditions are ANDed
- `fallback_value` (optional): fallback power value used by runtime integrations when runtime conditions fail (integer watts, `netzero`, or `netzero+`)

Notes:

- `min_power` and `max_power` are only used for rules whose `value` is `netzero` or `netzero+`
- they default to `null`
- they do not apply to `fallback_value`
- the editor uses `100 W` steps for these inputs
- `fallback_value` is optional and independent of `value` type

## 5. Condition Fields

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

## 7. Save Behavior

There is one save flow.

- `Save Rule` updates the rule and writes file immediately.
- `Duplicate`, `Delete`, `Up`, `Down` also write file immediately.
- File write is atomic (temp file + rename).

## 8. Effective Schedule Priority

At API merge time (`data_api.php`):

1. Base schedule resolves first
2. Conditions can overwrite wildcard/empty slots
3. Manual non-wildcard slots keep priority and are not overwritten by conditions

## 9. Example Rules

```json
[
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
    "conditions": [
      { "field": "spread_price", "op": ">=", "value": 12 },
      { "field": "ranking", "op": "<=", "value": 2 }
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

## 10. Validation Tips

- Ensure JSON is valid (no trailing commas)
- Always provide `name`
- For hour-like fields use 0..23
- For `min_power` / `max_power`, use integer watt values
- Use `Raw JSON` + `Copy` to review/export config quickly

## 11. Related Manuals

- `[install_dir]/docs/user_manuals/min-max-values_user_manual.md`
