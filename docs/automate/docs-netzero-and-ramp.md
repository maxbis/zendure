## Netzero / Netzero+ pipeline and reversal guard

This document explains how the automation loop decides battery power in `netzero` and `netzero+` modes, and where `ReversalRampGuard` fits in the current pipeline.

---

### 1. Key concepts

- P1 `total_power`
  - Positive: importing from grid
  - Negative: exporting to grid
- Controller sign convention
  - Positive power: charge
  - Negative power: discharge
- Modes
  - `netzero`: discharge-only when `NETZERO_BI_DIRECTIONAL=false`; charge and discharge are both possible when `true`
  - `netzero+`: charge-only, never actively discharges

Relevant code:

- Raw dynamic calculation: `device_controller.py` -> `calculate_netzero_power()`
- Signed schedule clamp: `device_controller.py` -> `_apply_schedule_power_bounds()`
- Reversal smoothing: `device_controller.py` -> `ReversalRampGuard`
- Final device safety clamps: `device_controller.py` -> `_send_power_feed()`

---

### 2. Current control order

For a dynamic command (`netzero`, `netzero+`, or `None`), the controller resolves power in this order:

1. Read P1 meter data from the app/runtime layer.
2. Read Zendure state (`inputLimit`, `outputLimit`, `electricLevel`).
3. `_calculate_new_settings()` computes `(new_input, new_output)` from current state and P1 power.
4. `calculate_netzero_power()` converts that into a raw signed target:
   - `netzero+`: positive charge or `0`
   - `netzero`: negative discharge or `0`, unless `NETZERO_BI_DIRECTIONAL=true`, in which case it may be positive or negative
5. `_apply_schedule_power_bounds()` clamps the raw target into signed `min_power` / `max_power`.
6. `ReversalRampGuard` compares `previous_power` to the bounded target and ramps only if the bounded target reverses sign.
7. `_apply_power_feed_max_delta()` limits the step size.
8. `_send_power_feed()` reapplies battery protection and `MAX_DISCHARGE_POWER` / `MAX_CHARGE_POWER` before sending the final command to the device.

The important detail is that signed schedule bounds are now evaluated before reversal handling.

---

### 3. `_calculate_new_settings()` and config caps

`_calculate_new_settings()` works in an effective feed space:

- `effective_current = current_output - current_input`
- `effective_desired = effective_current + p1_power`

Then it applies:

- battery SoC checks
- `MAX_DISCHARGE_POWER` / `MAX_CHARGE_POWER`
- minimum absolute threshold
- minimum delta threshold

Finally it reconstructs `(new_input, new_output)`.

This means config power caps are enforced twice:

1. inside the dynamic calculation
2. again inside `_send_power_feed()` as final safety clamps

---

### 4. Bounds before reversal

When the active schedule slot contains signed `min_power` / `max_power`, the controller clamps the raw dynamic target first.

Example:

- `previous_power = -200`
- raw dynamic target = `+327`
- slot bounds = `min_power=-1200`, `max_power=-400`

The bounded target becomes `-400`. Because the bounded target is still negative, there is no sign reversal relative to `previous_power`, so `ReversalRampGuard` does not ramp to `0`.

This avoids the old oscillation pattern where a positive raw target could trigger reversal handling before the slot bounds forced it back into a negative discharge range.

---

### 5. When reversal still happens

`ReversalRampGuard` still applies when the bounded target truly crosses sign relative to `previous_power`.

Example:

- `previous_power = -200`
- raw target = `+50`
- slot bounds = `min_power=100`, `max_power=700`

The bounded target becomes `+100`. That is a real sign change relative to `-200`, so the guard ramps toward zero instead of jumping directly to `+100`.

With the default divisor-based guard, that becomes `-100` for the next command.

---

### 6. Summary

- `calculate_netzero_power()` produces the raw dynamic intent.
- Signed slot bounds (`min_power` / `max_power`) are hard constraints on that raw result.
- `ReversalRampGuard` smooths only the bounded target.
- `power_feed_max_delta` then limits the step size.
- Final battery/device safety checks and `MAX_DISCHARGE_POWER` / `MAX_CHARGE_POWER` are applied again before the device write.

This ordering makes bounded slots authoritative for sign and prevents reversal oscillation when the schedule forbids the sign proposed by the raw netzero calculation.
