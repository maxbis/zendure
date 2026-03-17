# Min/Max Values User Manual

This manual explains how to use `min_value` and `max_value` for dynamic schedule modes in Zendure:

- schedule editor: `/Users/maxbisschop/dev/www/zendure/main/charge_schedule_mobile.php`
- rules editor: `/Users/maxbisschop/dev/www/zendure/main/edit_rules.php`

## 1. What `min_value` and `max_value` do

`min_value` and `max_value` are optional watt bounds for dynamic modes:

- `netzero` (discharge-only)
- `netzero+` (charge-only)

They do not apply to fixed watt values.

They are interpreted as watt magnitudes:

- `min_value` = minimum watt magnitude while the dynamic mode is active
- `max_value` = maximum watt magnitude while the dynamic mode is active

The runtime compares them using `abs(...)`.

If both are empty or `null`, behavior stays the same as before and no extra runtime clamp is applied.

## 2. Where they are stored

### Manual schedule entries

Saved in:

- `/Users/maxbisschop/dev/www/zendure/main/data/charge_schedule.json`

Example:

```json
{
  "********1800": {
    "value": "netzero",
    "min_value": 100,
    "max_value": 700
  }
}
```

### Rule entries

Saved in:

- `/Users/maxbisschop/dev/www/zendure/main/data/charge_schedule_conditions.json`

Example:

```json
{
  "name": "Evening netzero",
  "value": "netzero",
  "min_time": "18",
  "max_time": "22",
  "min_value": 100,
  "max_value": 700
}
```

## 3. `netzero` behavior

For `netzero`, `min_value` means minimum discharge when the calculated dynamic result is too small.

Examples:

- raw result `0`, `min_value=100` -> runtime applies `-100`
- raw result `-50`, `min_value=100` -> runtime applies `-100`
- raw result `-300`, `max_value=200` -> runtime applies `-200`

If the raw result is already inside the range, runtime keeps the normal `netzero` result.

## 4. `netzero+` behavior

For `netzero+`, runtime never discharges.

Examples:

- raw result `0`, `min_value=100` -> runtime applies `+100`
- raw result `+50`, `min_value=100` -> runtime applies `+100`
- raw result `+300`, `max_value=200` -> runtime applies `+200`

## 5. Reversal protection

The Python runtime uses `ReversalRampGuard` to avoid abrupt sign changes.

This matters mostly for `netzero`:

- if a real charge/discharge reversal is detected, reversal protection wins for that cycle
- `min_value` / `max_value` is temporarily deferred
- once the reversal settles, min/max handling resumes normally

So `min_value` is not always an immediate guarantee during a sign flip.

## 6. Battery and device safety limits

Battery SoC protection and hardware/device caps still have higher priority than `min_value` / `max_value`.

That means:

- a configured `min_value` may still result in `0` if the battery must not charge/discharge
- a configured `max_value` may still be further reduced by device limits

## 7. How to set them

### In the schedule editor

Open:

- `http://localhost/zendure/main/charge_schedule_mobile.php`

When editing a `netzero` or `netzero+` entry:

- the modal shows `Min Power Limit (W)`
- the modal shows `Max Power Limit (W)`
- both fields use `100 W` steps

### In the rules editor

Open:

- `http://localhost/zendure/main/edit_rules.php`

For rules with `value = netzero` or `value = netzero+`:

- `Min Value (optional)`
- `Max Value (optional)`

These default to `null`.

They do not apply to `fallback_value`.

## 8. Recommended usage

Use `min_value` when:

- very small dynamic corrections are not useful
- you want more decisive charging or discharging
- you want `netzero` / `netzero+` to avoid drifting near zero

Use `max_value` when:

- you want a hard cap on dynamic response
- you want gentler battery behavior
- you want to reduce charge/discharge intensity in certain hours

## 9. Related Manuals

- `/Users/maxbisschop/dev/www/zendure/docs/user_manuals/charge_schedule_mobile_user_manual.md`
- `/Users/maxbisschop/dev/www/zendure/docs/user_manuals/edit_rules_user_manual.md`
- `/Users/maxbisschop/dev/www/zendure/docs/data/schedule-resolution-technical.md`
