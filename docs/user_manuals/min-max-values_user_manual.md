# Min/Max Values User Manual

This manual explains how to use `min_power` and `max_power` for dynamic schedule modes in Zendure:

- schedule editor: `/Users/maxbisschop/dev/www/zendure/main/charge_schedule_mobile.php`
- rules editor: `/Users/maxbisschop/dev/www/zendure/main/edit_rules.php`

## 1. What `min_power` and `max_power` do

`min_power` and `max_power` are optional signed watt bounds for dynamic modes:

- `netzero` (discharge-only)
- `netzero+` (charge-only)
- `netzero-` (discharge-only net-zero correction)

They do not apply to fixed watt values or fallback values.

They are interpreted directly as signed power limits:

- negative values = discharge
- positive values = charge
- `min_power` = lower signed bound
- `max_power` = upper signed bound

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
    "min_power": -700,
    "max_power": -100
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
  "min_power": -700,
  "max_power": -100
}
```

## 3. `netzero` behavior

For `netzero`, the algorithm still computes the raw result first. Then runtime clamps that result into the signed range.

Examples:

- raw result `-50`, `min_power=-700`, `max_power=-100` -> runtime applies `-100`
- raw result `-900`, `min_power=-700`, `max_power=-100` -> runtime applies `-700`
- raw result `150`, `min_power=-300`, `max_power=300` -> runtime applies `150`

## 4. `netzero+` behavior

For `netzero+`, the algorithm still computes the raw result first. Then runtime clamps that result into the signed range.

Examples:

- raw result `50`, `min_power=100`, `max_power=700` -> runtime applies `100`
- raw result `900`, `min_power=100`, `max_power=700` -> runtime applies `700`
- raw result `-50`, `min_power=-300`, `max_power=300` -> runtime applies `-50`

## 5. Fallback behavior

Fallback values are runtime replacements used when a runtime-only condition, such as `electricity_level`, does not match.

- Power limits apply only to the primary rule value.
- Power limits do not apply to `fallback_value`.
- Fallback values do not inherit primary rule limits.
- If fallback is `netzero-`, it performs normal discharge-only net-zero correction and can return `0 W` when no discharge is needed.

Example:

- primary value `netzero-`, `min_power=-1600`, `max_power=-1000`, fallback `netzero-`
- when the condition matches, raw `0` is clamped to `-1000`
- when the condition fails, fallback `netzero-` uses raw `0` unchanged

## 6. Reversal protection

The Python runtime uses `ReversalRampGuard` to avoid abrupt sign changes.

This matters mostly for `netzero`:

- if a real charge/discharge reversal is detected, reversal protection wins for that cycle
- `min_power` / `max_power` is temporarily deferred
- once the reversal settles, min/max handling resumes normally

So signed bounds are not always enforced immediately during a sign flip.

## 7. Battery and device safety limits

Battery SoC protection and hardware/device caps still have higher priority than `min_power` / `max_power`.

That means:

- a configured `min_power` may still result in `0` if the battery must not charge/discharge
- a configured `max_power` may still be further reduced by device limits

## 8. How to set them

### In the schedule editor

Open:

- `http://localhost/zendure/main/charge_schedule_mobile.php`

When editing a `netzero`, `netzero+`, or `netzero-` entry:

- the modal shows `Min Power Limit (W)`
- the modal shows `Max Power Limit (W)`
- both fields use `100 W` steps

### In the rules editor

Open:

- `http://localhost/zendure/main/edit_rules.php`

For rules with `value = netzero`, `value = netzero+`, or `value = netzero-`:

- `Min Value (optional)`
- `Max Value (optional)`

These default to `null`.

They do not apply to `fallback_value`, and fallback values do not inherit these limits.

## 9. Recommended usage

Use `min_power` when:

- very small dynamic corrections are not useful
- you want more decisive charging or discharging
- you want `netzero` / `netzero+` / `netzero-` to avoid drifting near zero

Use `max_power` when:

- you want a hard cap on dynamic response
- you want gentler battery behavior
- you want to reduce charge/discharge intensity in certain hours

## 10. Related Manuals

- `/Users/maxbisschop/dev/www/zendure/docs/user_manuals/charge_schedule_mobile_user_manual.md`
- `/Users/maxbisschop/dev/www/zendure/docs/user_manuals/edit_rules_user_manual.md`
- `/Users/maxbisschop/dev/www/zendure/docs/data/schedule-resolution-technical.md`
