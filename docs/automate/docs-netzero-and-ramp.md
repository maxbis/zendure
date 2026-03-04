## Netzero / Netzero+ behaviour and ramp-down algorithm

This document explains how the automation loop decides battery power in **netzero** and **netzero+** modes, and how the **ReversalRampGuard** prevents sudden direction flips. It walks through concrete example cycles with P1 readings and resulting battery commands.

---

### 1. Key concepts

- **P1 total power (`total_power`)**: grid power as seen by the P1 meter.
  - Positive value → importing from grid.
  - Negative value → exporting to grid (e.g. solar surplus).
- **Battery convention in controller**:
  - Positive power → **charge** (battery takes power from grid/house).
  - Negative power → **discharge** (battery supplies power to grid/house).
- **Modes**:
  - `netzero` → use battery only to **discharge** and reduce grid import to ~0. Never actively charge.
  - `netzero+` → use battery only to **charge** and reduce grid **export** to ~0. Never actively discharge.

Relevant code:

- Mode handling and netzero calculation: `device_controller.py` → `calculate_netzero_power()`.
- Ramp guard logic: `device_controller.py` → `ReversalRampGuard`.
- Battery limit checks and schedule integration: `automate_www.py` → `_check_battery_limits()` and `_apply_power_settings()`.

---

### 2. Control loop overview (per cycle)

Each automation cycle (loop iteration) does, in simplified form:

1. Read **P1 meter** → get `total_power` (grid import/export).
2. Read **Zendure state** → `inputLimit`, `outputLimit`, `electricLevel`.
3. Compute new raw battery settings:
   - `_calculate_new_settings(p1_power, current_input, current_output, electric_level)` → `(new_input, new_output)`.
4. Convert to controller power convention in `calculate_netzero_power(mode, p1_data)`:
   - For `netzero+`:
     - If charging is requested (`new_input > 0`) → return `+new_input`.
     - Otherwise → return `0` (no discharge).
   - For `netzero`:
     - If discharging is requested (`new_output > 0`) → return `-new_output`.
     - Otherwise → return `0` (no charge).
5. Apply **ReversalRampGuard** to smooth large or reversing changes.
6. Call `set_power()` with the guarded value to actually update the device.

This repeats every loop interval (configured in `automate_www.py`).

---

### 3. `_calculate_new_settings` — basic feed math

`_calculate_new_settings` works in a simplified “effective feed” space:

- Effective battery contribution \(F\):
  - \(F > 0\) → discharge (export from battery).
  - \(F < 0\) → charge (import into battery).
- It derives:
  - `effective_current = current_output - current_input`
  - `effective_desired = effective_current + p1_power`

Then it:

- Applies **battery SoC limits** (prevent charge when too full, discharge when too empty).
- Clamps to `[POWER_FEED_MIN, POWER_FEED_MAX]`.
- Applies **minimum absolute threshold**: very small feeds snap to `0`.
- Applies **minimum delta threshold**: very small changes snap back to the current setting.
- Finally it reconstructs `(new_input, new_output)`:
  - `effective_desired > 0` → `new_output = effective_desired`, `new_input = 0` (pure discharge).
  - `effective_desired < 0` → `new_input = abs(effective_desired)`, `new_output = 0` (pure charge).
  - `effective_desired == 0` → both zero.

This returns the “physics-based” suggestion; netzero / netzero+ interpret it differently.

---

### 4. Netzero mode — discharge only

In plain `netzero`:

- The controller **ignores charge suggestions** (`new_input`) and only uses discharge (`new_output`):

```python
raw_target_power = -new_output if new_output > 0 else 0
reversal_hint = new_input > 0
guarded_power = self.reversal_ramp_guard.apply(
    previous_power=self.previous_power,
    desired_power=raw_target_power,
    reversal_hint=reversal_hint,
)
```

- End result:
  - `new_output > 0` → command **negative power** (`-new_output`) = discharge.
  - `new_output == 0` → command `0` (no power).
  - `new_input` is only used as a **reversal hint** for the ramp guard (see below), not as a direct command.

#### Example 4.1 — importing from grid, then stabilising at zero

- Assume:
  - Mode: `netzero`
  - Previous battery power: `0 W`
  - Current P1: `+300 W` (import)

**Cycle 1**

1. `_calculate_new_settings` sees import, decides to discharge:
   - Returns `(new_input=0, new_output=80)` (numbers illustrative).
2. `calculate_netzero_power`:
   - `raw_target_power = -80` → want **-80 W** (discharge).
   - `reversal_hint = (new_input > 0) = False`.
   - Ramp guard likely passes through `-80`.
3. Controller sets battery to `-80 W`.

**Cycle 2**

- P1 power has moved closer to zero (e.g. `+40 W`) due to the discharge.

1. `_calculate_new_settings` may now propose smaller discharge or zero, say `(0, 20)`.
2. `calculate_netzero_power` → `raw_target_power = -20`.
3. Guard may pass through or slightly reduce the step.
4. Battery moves toward `-20 W`.

**Cycle 3+**

- Eventually P1 is ~`0 W`, `_calculate_new_settings` returns `(0, 0)`, and `netzero` maps that to:
  - `raw_target_power = 0`.
  - Guard passes through → battery goes to `0 W`.

So `netzero` behaves as: “discharge just enough to cancel grid import, otherwise sit at 0”.

#### Example 4.2 — solar ramps up while still discharging

Now suppose:

- Mode: `netzero`
- Battery currently at `-80 W` (decided in a previous cycle while importing).
- Suddenly solar output increases between cycles, and the **next** P1 reading is `-400 W` (export).

**Cycle N (before ramp)**

- Already described: P1 import → controller chose `-80 W`.

**Between cycles**

- Solar ramps up; by the time the next loop runs, P1 shows export (`-400 W`), but the battery is **still at -80 W** (it only changes when the next command is sent).

**Cycle N+1 (after ramp)**

1. `_calculate_new_settings` sees export:
   - Effective desired feed trends toward charging or 0.
   - It may return `(new_input>0, new_output=0)` (i.e. *“you could charge now”*).
2. In `netzero`:
   - `raw_target_power = 0` (because `new_output == 0`).
   - `reversal_hint = (new_input > 0) = True`.
3. ReversalRampGuard is invoked with:
   - `previous_power = -80`
   - `desired_power = 0`
   - `reversal_hint = True`.
4. Because of the hint, the guard does **not** jump directly from `-80` to `0`. Instead, it ramps toward zero:
   - e.g. with divisor=2: `ramp_toward_zero(-80)` → `-40`.
5. Battery command for this cycle becomes `-40 W` (smaller discharge).

**Cycle N+2**

- P1 may now show something like `-360 W` export (since the battery is discharging less).
- `_calculate_new_settings` again prefers charge/0 → `(new_input>0, new_output=0)`.
- `netzero` again maps to `raw_target_power = 0`, with `reversal_hint = True`.
- Guard ramps `previous_power=-40` toward zero → `-20 W`.

**Subsequent cycles**

- Guard keeps halving the magnitude (or following its divisor rule) until the absolute value drops below `min_abs_power`, at which point it returns `0`.
- Visually you see the battery:
  - `-80 W → -40 W → -20 W → 0 W`, while P1 remains in export.

This explains why you may briefly see **discharge while the grid is exporting**: the controller is in the middle of a **guarded ramp-down** toward 0, based on older decisions and the reversal guard.

---

### 5. Netzero+ mode — charge only

In `netzero+` the interpretation is flipped:

```python
if mode == 'netzero+':
    # If calculation says to discharge, return 1 (netzero+ doesn't discharge)
    if new_input > 0:  # Charging is requested?
        return new_input
    return 0
```

- `new_input > 0` → command **positive** power = charge.
- Any situation where `_calculate_new_settings` suggests discharge (`new_output > 0`) or 0 results in `0 W` from `calculate_netzero_power`.
- This mode is meant for:
  - **Absorbing solar export** (charging to reduce negative P1 values).
  - Never discharging into the grid.

If you want solar surplus to fill the battery instead of going to the grid, `netzero+` is the appropriate mode; plain `netzero` will not actively charge.

---

### 6. ReversalRampGuard details

`ReversalRampGuard` is intentionally small and independent. Its job is to **smooth reversals**:

- Configuration (from controller init):

```python
self.reversal_ramp_guard = ReversalRampGuard(
    enabled=True,
    divisor=2,
    min_abs_power=30,
)
```

- Core behaviour:
  - If `reversal_hint` is `True` (e.g. netzero saw a would-be charge situation), it ramps:

    ```python
    ramped = int(previous_power / divisor)
    if abs(ramped) < min_abs_power:
        return 0
    return ramped
    ```

  - Otherwise, if both previous and desired powers are non-zero and their **sign flips**, it also ramps instead of jumping directly to the new sign.
  - If no conditions trigger, it simply returns `desired_power`.

This protects against oscillations when P1 readings flicker around zero or when the schedule suddenly switches direction.

---

### 7. Summary

- **Netzero (`"netzero"`)**
  - Only discharges the battery to reduce **grid import** to ~0.
  - Ignores opportunities to charge from solar export; in export situations it ramps discharge back to 0 using `ReversalRampGuard`.
- **Netzero+ (`"netzero+"`)**
  - Only charges the battery to reduce **grid export** to ~0.
  - Never actively discharges.
- **ReversalRampGuard**
  - When the control logic wants to reverse direction (e.g. from discharge to stop/charge), it **ramps down the previous power toward zero** across multiple cycles.
  - This is why you may see brief periods of continued discharge even while the grid is exporting: the system is intentionally stepping down instead of flipping direction instantly.

