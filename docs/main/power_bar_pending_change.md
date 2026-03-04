## Power bar and “Pending…” behaviour

This document explains how the **Power** bar in the web UI is built, what it visualises, and how the **“⏳ Pending…”** state works. It also clarifies why the display can lag **one automation cycle** behind the actual control loop.

File references:

- Frontend:
  - `main/assets/js/schedule_renderer.js` (markup for the power bar).
  - `main/assets/js/charge_status.js` (applies pending state).
  - `main/assets/css/charge_status.css` (layout and colours).
  - `main/assets/css/charge_status_defines.css` (pending styles).
- Backend:
  - `automate/automate_www.py` (automation loop and status API).
  - `automate/device_controller.py` (actual power commands).

---

### 1. What the Power bar shows

The Power box in the UI has two main pieces:

1. **Numeric power readout**
   - Shows the latest measured device power in watts (e.g. `-526 W`).
   - The colour and label reflect the high‑level state (Charging, Discharging, Standby).
2. **Horizontal bar**
   - Represents the allowed power range from **minimum** (left) to **maximum** (right).
   - Centre marker (`0`) splits discharge (left / negative) and charge (right / positive).
   - A coloured segment (the **fill**) visualises the current power magnitude and direction.

The bar is constructed in `schedule_renderer.js` roughly as:

- A container: `.charge-power-bar-container`.
- A centre line: `.charge-power-bar-center`.
- A fill element: `#charge-power-bar-fill.charge-power-bar-fill.{charging|discharging}` with a **width proportional** to the current power.
- Numeric labels at the left, centre, and right for min, 0, max (e.g. `-800`, `0`, `800`).

Direction:

- **Discharging**: negative power → fill extends to the **left** from centre, in red.
- **Charging**: positive power → fill extends to the **right** from centre, in green.

---

### 2. Where the Power value comes from

The frontend does not compute power itself. It receives data from the automation backend via HTTP APIs:

- `automate_www.py` periodically:
  - Reads P1 meter and Zendure device via `DeviceDataReader`.
  - Computes the **desired power** from the schedule and conditions.
  - Calls `DeviceController.set_power(...)` to apply the command.
  - Publishes summary status through the status API.
- The web UI polls this status API and:
  - Renders the **actual device power** (not just desired power).
  - Stores some values in `data-*` attributes (e.g. `data-actual-power`) for further logic.

Because of this separation:

- The bar reflects the **last known state** from the backend at the time of the latest poll.
- The automation loop, the P1 meter, and the UI polling all run on their own timers.

That timing gap is what creates the **one-cycle lag**, described later.

---

### 3. The “Pending…” state: what it means

When you change power (e.g. schedule causes a new setpoint, or a manual override), the command is **not applied instantaneously** at every layer:

1. Automation decides on a new desired power (or mode like `netzero` / `netzero+`).
2. It issues a command to the device controller.
3. The device takes some time to respond and stabilise at the new level.
4. A later status update confirms the actual power value.

The frontend uses this lag to show a **“Pending…”** visual:

- The intent is: “A command was issued recently, but the device’s actual power has not yet matched the commanded power (within a tolerance).”

Implementation is in `charge_status.js` → `applyPendingPowerState(automationData)`:

- It looks at `automationData.lastChanges` (a history of automation status changes).
- Finds the **latest** entry of type `"change"`.
- Computes how long ago the change happened (`entryAge`).
- Compares the **commanded power** (from the change) with the **actual power** (from DOM / status API).

Only if all of the following are true does it show the pending state:

1. There is at least one `"change"` in `lastChanges`.
2. The latest change is **recent**, i.e. `entryAge <= PENDING_WINDOW_SECONDS` (default ~35 s).
3. The commanded power and actual power differ by more than a tolerance (`PENDING_MATCH_TOLERANCE_W`, e.g. 50 W).

If those conditions hold:

- The bar is marked **pending** (see styles below).
- A small **“⏳ Pending…”** label appears next to the numeric power value.

---

### 4. How the pending state is drawn in the UI

The visual parts are:

1. **Bar dimming (your simplified implementation)**

   In `charge_status_defines.css`:

   ```css
   .charge-power-bar-fill.power-pending {
       opacity: 0.35;
   }
   ```

   When the class `power-pending` is present on the fill element, the bar segment simply becomes more transparent. This makes it obvious that the current shown power is not yet “confirmed”.

2. **Flashing “Pending…” label**

   Also in `charge_status_defines.css`:

   ```css
   .charge-power-bar-pending-label {
       display: inline-block;
       margin-left: 6px;
       font-size: 0.72rem;
       color: #ffd54f;
       font-weight: 500;
       vertical-align: middle;
       animation: pending-label-pulse 1.4s ease-in-out infinite;
   }
   ```

   And the animation:

   ```css
   @keyframes pending-label-pulse {
       0%, 100% { opacity: 1; }
       50% { opacity: 0.45; }
   }
   ```

   The JS inserts this label dynamically:

   ```js
   const powerValueEl = document.querySelector('.charge-power-value');
   if (powerValueEl && !powerValueEl.querySelector('.charge-power-bar-pending-label')) {
       const lbl = document.createElement('span');
       lbl.className = 'charge-power-bar-pending-label';
       lbl.textContent = '⏳ Pending…';
       powerValueEl.appendChild(lbl);
   }
   ```

3. **Class toggling logic**

   In `applyPendingPowerState`:

   - `setPendingState()` adds `.power-pending` to `#charge-power-bar-fill` and inserts the label.
   - `clearPendingState()` removes the class and the label.

   The pending state is re‑evaluated on every status refresh.

---

### 5. Why the Power bar can be “one cycle behind”

There are three distinct clocks involved:

1. **Automation loop cadence**
   - `automate_www.py` runs a loop at a fixed interval (e.g. every N seconds).
   - Each cycle it reads P1, evaluates the schedule, and may issue a new power command.
2. **Device and P1 response time**
   - The battery hardware and P1 meter take some time to react and report new readings.
   - There can be several seconds of delay between command and stable measurement.
3. **Frontend polling interval**
   - The browser periodically polls the status API (or receives updated JSON from an existing fetch).
   - The latest successful poll is what the Power bar renders.

Because these are not perfectly synchronised, you often see this pattern:

1. **Cycle T0 (backend)**
   - Automation decides new power (e.g. change from `0 W` to `-500 W`).
   - Posts a `"change"` entry with `newValue = -500`.
   - Sends a command to the device.
2. **Shortly after T0 (frontend)**
   - UI polls the status:
     - Change entry is visible (recent).
     - **Actual power** is still close to the old value (e.g. `-80 W` or `0 W`).
   - Frontend:
     - Sees a **recent change**.
     - Commanded vs actual differ by more than the tolerance.
     - → Marks bar as **pending** and shows “⏳ Pending…”.
3. **Cycle T1 (backend, next loop)**
   - Device has now moved closer to `-500 W`.
   - New status update posts actual power near the commanded value.
4. **Shortly after T1 (frontend)**
   - UI polls again:
     - The latest `"change"` is still within the `PENDING_WINDOW_SECONDS`, but now:
       - Actual power ≈ commanded power (within tolerance).
     - → `applyPendingPowerState` clears the pending state.
   - Power bar shows the **new value without dimming**, and the “Pending…” label disappears.

From the user’s perspective:

- At the moment you change something, the bar may still show the **previous loop’s actual power**, but with:
  - dimmed fill (`.power-pending`),
  - flashing “⏳ Pending…” text.
- After one or more cycles (depending on loop interval and polling), the bar “catches up”:
  - Fill width and colour reflect the new actual power.
  - Pending visuals are removed.

This **one-cycle offset** is intentional: it distinguishes “command has been sent but not yet confirmed” from “device is already at the new level”.

---

### 6. How to recognise the cycle lag in the GUI

The GUI gives you a few clues about where you are in this process:

1. **Pending state present**
   - You see:
     - Dimmed Power bar segment (reduced opacity).
     - Flashing “⏳ Pending…” label next to the power value.
   - Interpretation:
     - Backend has recently issued a new command.
     - Device’s last reported power has not yet matched the commanded value.

2. **Pending state cleared, power changed**
   - The bar fill width/colour and numeric power update to a new steady value.
   - The “Pending…” label disappears.
   - Interpretation:
     - Device has reached the new power level (within tolerance).
     - Automation has confirmed this via a later status update.

3. **Momentary mismatches between Grid and Power**
   - Because the bar uses **device power**, and Grid uses **P1 total power**:
     - You can briefly see, for example, discharging while the grid appears to export, or vice versa.
   - This usually happens between backend cycles when:
     - A new command is still ramping via `ReversalRampGuard`.
     - P1 measurement has already moved, but the device command is from a previous cycle.
   - The Pending state helps you identify that you are in such a transient phase.

By watching:

- the **Pending label**,
- the **opacity** of the bar,
- and the **Grid vs Power** readings together,

you can infer which stage of the automation loop you are seeing in the UI: pre‑command, pending command, or confirmed new state.

