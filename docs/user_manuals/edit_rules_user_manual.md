# Edit Rules User Manual

This manual explains how to configure condition rules in:

- `/Users/maxbisschop/dev/www/zendure/main/edit_rules.php`

Rules are saved to:

- `/Users/maxbisschop/dev/www/zendure/main/data/charge_schedule_conditions.json`

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
- `conditions`: all conditions are ANDed

## 5. Condition Fields

Supported `field` values:

- `price`: current hour price (cents/kWh)
- `ranking`: daily rank 1..24 (sorted by price asc, then hour asc)
- `min_price`
- `max_price`
- `spread_price` (`max_price - min_price`)
- `min_price_hour`
- `max_price_hour`
- `month`
- `hour`
- `min_time`
- `max_time`

Supported operators:

- `>` `>=` `<` `<=` `==` `!=` `in`

## 6. value vs value_ref

A condition can use:

- `value`: literal number/string
- `value_ref`: dynamic reference

Supported `value_ref`:

- `min_price`
- `max_price`
- `spread_price`
- `min_price_hour`
- `max_price_hour`

If `value_ref` is present, it is used as the comparison target.

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
- Use `Raw JSON` + `Copy` to review/export config quickly

