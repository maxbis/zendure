## Netzero+ mode behaviour and examples

This document explains how **`netzero+`** works in the automation loop: how it turns P1 meter readings into a concrete battery power setpoint, how it differs from plain `netzero`, and how the **ReversalRampGuard** shapes transitions. It focuses on the **“charge-only”** behaviour meant to absorb solar export.

---

### 1. Purpose of netzero+

`netzero+` is designed to:

- **Absorb solar surplus**: when the P1 meter reports **export** (negative `total_power`), the battery charges to bring grid export closer to 0 W.
- **Never discharge**: it will *not* use the battery to cover house load; discharge is left to other modes or schedule entries.

In short:

- Plain **`netzero`** → only **discharge** to cancel **import**.
- **`netzero+`** → only **charge** to cancel **export**.

---

### 2. Where netzero+ is handled in code

Key locations:

- `automate/automate_www.py`
  - Converts schedule entries like `"netzero+"` into a desired power mode.
  - Calls `_check_battery_limits()` to block charge when SoC is at max.
  - Calls `_apply_power_settings()` which delegates to `DeviceController.set_power()`.

- `automate/device_controller.py`
  - `DeviceController.set_power(value, p1_data=None)` — accepts `"netzero+"` as a mode.
  - `DeviceController.calculate_netzero_power(mode, p1_data)` — turns `"netzero+"` plus P1/Zendure data into an **integer power in watts**.
  - `_calculate_new_settings(p1_power, current_input, current_output, electric_level)` — core control math for effective feed.
  - `ReversalRampGuard` — smooths direction changes and ramps toward zero when needed.

---

### 3. From schedule to device: high-level flow

For a time slot whose resolved schedule value is `"netzero+"`:

1. **Schedule resolution** (in PHP) yields `"netzero+"` for that hour.
2. `automate_www.py` reads the resolved value as `desired_power = "netzero+"`.
3. `_check_battery_limits(desired_power, prechecked=False)` maps `"netzero+"` to a **validation power**:

   ```python
   if desired_power == POWER_MODE_NETZERO_PLUS:
       validation_power = POWER_MODE_NETZERO_PLUS_VALIDATION_W  # typically +250 W
   ```

   This is only used to decide:
   - “Would this be a charge (>0) while at max SoC?” → then override to 0.

4. `_apply_power_settings(desired_power, p1_data)` decides whether we need to send a new command:

   ```python
   should_apply = (
       self.old_value != desired_power
       or (desired_power in [POWER_MODE_NETZERO, POWER_MODE_NETZERO_PLUS])
   )
   ```

   For `netzero+`, **every loop** is allowed to recompute power, even if the mode string hasn’t changed.

5. If `should_apply` is `True`, it calls:

   ```python
   result = self.controller.set_power(desired_power, p1_data=p1_data)
   ```

6. `DeviceController.set_power("netzero+", p1_data)` routes to:

   ```python
   calculated_power = self.calculate_netzero_power(mode="netzero+", p1_data=p1_data)
   ```

   and then applies that power to the device (unless in test mode).

---

### 4. `_calculate_new_settings` — common control math

`_calculate_new_settings` is **mode-agnostic**: it just computes what the effective battery feed \(F\) should be.

Definitions:

- **P1**: `p1_power = total_power`
  - Positive → importing from grid.
  - Negative → exporting to grid (solar surplus).
- **Battery effective feed**:
  - \(F > 0\) → discharge (battery sends power out).
  - \(F < 0\) → charge (battery pulls power in).

Steps:

1. Reconstruct current effective feed from limits:

   ```python
   effective_current = current_output - current_input
   effective_desired = effective_current + p1_power
   ```

2. Apply battery SoC limits:
   - Too full to charge → clamp negative `effective_desired` up to 0.
   - Too empty to discharge → clamp positive `effective_desired` down to 0.

3. Clamp to controller power bounds `[POWER_FEED_MIN, POWER_FEED_MAX]`.

4. Apply **minimum absolute threshold**:
   - If `|effective_desired| < power_feed_min_threshold`, snap to 0 to avoid tiny trickle flows.

5. Apply **minimum delta threshold**:
   - If `|effective_desired - effective_current|` is too small, keep `effective_desired = effective_current` to avoid constant tiny adjustments.

6. Convert back into concrete input/output limits:

   ```python
   if effective_desired > 0:
       new_output = effective_desired  # discharge
       new_input = 0
   elif effective_desired < 0:
       new_input = abs(effective_desired)  # charge
       new_output = 0
   else:
       new_input = new_output = 0
   ```

For `netzero+`, the important case is when this returns `new_input > 0` (charging suggestion based on solar export).

---

### 5. How `netzero+` interprets `_calculate_new_settings`

In `calculate_netzero_power`:

```python
new_input, new_output = self._calculate_new_settings(...)

if mode == 'netzero+':
    # If calculation says to discharge, return 1 (netzero+ doesn't discharge)
    if new_input > 0:  # Charging is requested?
        return new_input
    return 0
```

Behaviour:

- If `_calculate_new_settings` suggests **charging** (`new_input > 0`):
  - `netzero+` returns a **positive** power equal to `new_input`.
  - The controller will set **charge** power (battery absorbs energy).
- If `_calculate_new_settings` suggests **discharge** (`new_output > 0`) or **no action** (`new_input == new_output == 0`):
  - `netzero+` returns `0`.
  - No discharge is allowed.

There is no extra ramp guard logic in this branch: the returned value is directly used as the desired power (subject to `_check_battery_limits` and SoC protection higher up).

---

### 6. Example cycles — absorbing solar export

Assumptions for the examples:

- Mode: `netzero+`.
- Battery SoC is in a safe range (not blocked by min/max limits).
- Guard thresholds and power limits are such that small adjustments are allowed.

#### 6.1. Export grows and the battery starts charging

Initial state:

- P1 `total_power`: `-200 W` (export).
- Current battery power: `0 W` (idle).

**Cycle 1**

1. `p1_power = -200`.
2. `_calculate_new_settings`:
   - `effective_current ≈ 0` (output - input).
   - `effective_desired = 0 + (-200) = -200` → suggests **charging**.
   - After thresholds/clamps, say it returns `(new_input=200, new_output=0)`.
3. `calculate_netzero_power("netzero+")`:
   - `new_input > 0` → returns `+200`.
4. Controller sets battery to `+200 W` (charge),
   - P1 export should reduce toward `0 W`.

**Cycle 2**

- With the new charge power, suppose P1 reading is now `-50 W` (much less export).

1. `_calculate_new_settings` re-runs with updated `p1_power` and `current_input`:
   - It may now suggest smaller charge or even 0, e.g. `(new_input=80, new_output=0)`.
2. `netzero+` returns `+80 W`.
3. Battery charge command is reduced toward `+80 W`.

**Cycle 3+**

- As export shrinks, `_calculate_new_settings` will converge toward `(0, 0)`,
  and `netzero+` will respond by commanding `0 W`.
- Visually: charge bar decreases until the grid export is small or zero.

#### 6.2. Export suddenly disappears (cloud passes, house load rises)

Suppose at some moment:

- Mode: `netzero+`.
- Battery currently charging at `+250 W` (previous cycles), helping absorb export.
- A cloud passes or house load increases; the next P1 reading is now `+100 W` (import).

**Cycle N (before change)**

- P1 export → `_calculate_new_settings` suggested `(new_input>0, new_output=0)`,
  and `netzero+` set a positive charge power.

**Cycle N+1 (after change)**

1. New P1: `p1_power = +100` (import).
2. `_calculate_new_settings` with this input will often produce either:
   - `(new_input≈0, new_output≈0)`, or
   - a small discharge suggestion `(new_input=0, new_output>0)` depending on thresholds.
3. In `netzero+`:
   - If it suggests discharge: `new_input == 0`, `new_output > 0` → function returns `0`.
   - If it suggests 0: `new_input == 0`, `new_output == 0` → also returns `0`.
4. Result: **battery command goes back to 0 W** in a single step; there is no “discharge overshoot” because discharge is forbidden.

So in `netzero+`, when export disappears, the battery simply **stops charging**; it does not actively flip into discharging.

---

### 7. Interaction with SoC limits

Before `set_power("netzero+")` is called, `automate_www.py` runs `_check_battery_limits`:

```python
validation_power = desired_power
if desired_power == POWER_MODE_NETZERO_PLUS:
    validation_power = POWER_MODE_NETZERO_PLUS_VALIDATION_W  # e.g. +250

if isinstance(validation_power, int):
    if validation_power > 0 and self.controller.limit_state == 1:
        self.logger.warning("Battery at MAX_CHARGE_LEVEL, preventing charge")
        return 0
```

- When the battery is at or above **MAX_CHARGE_LEVEL**, `limit_state == 1` and any **positive** validation power is blocked to `0`.
- That means:
  - Even if `_calculate_new_settings` and `netzero+` think charging is ideal, the higher-level limit check can force a **0 W** command to protect the battery.

---

### 8. Summary

- `netzero+` is a **charge-only** mode:
  - It interprets `_calculate_new_settings` and only uses the **charge suggestion** (`new_input>0`).
  - Discharge suggestions are converted to `0 W`.
- Typical behaviour:
  - When P1 shows export, it commands **positive power** to reduce export.
  - When export shrinks or disappears, it reduces charge toward **0 W** and never flips into discharge.
- Safety and smoothing:
  - SoC limits in `automate_www.py` prevent charging when the battery is full.
  - Because netzero+ never commands negative power, it does not need the same reversal ramp-down logic that `netzero` uses when switching from discharge toward zero.

This makes `netzero+` suitable for “**use the battery as a solar sponge**” scenarios: it fills the battery from surplus generation but does not use the battery to supply the house when there is no export.

