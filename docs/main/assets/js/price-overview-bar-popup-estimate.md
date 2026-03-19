# Price graph popup: estimated battery level

## Purpose

When the user opens the price-bar popup (desktop hover or mobile tap on `charge_schedule_mobile.php`), the **Estimated battery level** block shows a simple forward model: starting from the **current** battery SoC, it walks future price bars in chronological order and, for each future hour, applies the scheduled power to estimate SoC at the **start** and **end** of the selected hour (with special handling for the **current** hour’s remaining time).

This is a **UI estimate only**; it does not drive automation.

## Location

- Implementation: [`main/assets/js/price_overview_bar.js`](../../../../main/assets/js/price_overview_bar.js)
- Primary functions: `getPopupForecastBatteryState`, `getPopupForecastForBar`, `formatPopupForecastHtml`, `powerToCapacityPercent`, `estimateSchedulePowerForPopup`, `getDischargeSocFloorFromRuntimeConditions`
- Live SoC for the model comes from `window.currentBatteryForecastState`, which is set when charge status is rendered (see [`main/assets/js/schedule_renderer.js`](../../../../main/assets/js/schedule_renderer.js) in `renderChargeStatus`)

## Injected configuration (page script)

These globals are **not** defined inside `price_overview_bar.js`; they are emitted by [`main/charge_schedule_mobile.php`](../../../../main/charge_schedule_mobile.php) (inline `<script>` before the JS bundles load):

- `CHARGE_STATUS_MIN_CHARGE_LEVEL` — from config key `MIN_CHARGE_LEVEL` (default 20)
- `CHARGE_STATUS_MAX_CHARGE_LEVEL` — from config key `MAX_CHARGE_LEVEL` (default 90)
- `BASE_WH` — from config key `baseWh` (default 5760), battery energy base in watt-hours for percent conversion

Config is read via `ConfigLoader::get(...)`.

## Constants defined in `price_overview_bar.js`

At the top of the file:

- `POPUP_POWER_EFFICIENCY` (0.9) — applied when converting power to percent change per hour (usable fraction of pack energy per “percent”).
- `POPUP_NETZERO_REFERENCE_W` (200) — discharge power in watts assumed for schedule value `netzero` in the popup model.
- `POPUP_NETZERO_PLUS_REFERENCE_W` (300) — charge power in watts assumed for `netzero+` in the popup model.

Elsewhere in the same file (supporting UI timing, not the physics of the estimate):

- `PRICE_GRAPH_MOBILE_POPUP_NAV_OUT_MS`, `PRICE_GRAPH_MOBILE_POPUP_NAV_IN_MS` — mobile popup hour navigation animation timing.

Runtime rule parsing:

- `POPUP_RUNTIME_BATTERY_FIELDS` — set of condition field names treated as battery SoC for discharge clamping (`electricity_level`, `electric_level`, `electricLevel`).

## Inputs and outputs

**Inputs**

- Latest SoC: `currentBatteryForecastState.electricLevel`
- Min/max SoC envelope: `CHARGE_STATUS_MIN_CHARGE_LEVEL`, `CHARGE_STATUS_MAX_CHARGE_LEVEL` (clamped 0–100)
- Ordered list of `.price-graph-bar` elements with `data-key`, `data-date`, `data-hour` (today and tomorrow, sorted by key)
- Per bar: `data-schedule-value` (numeric W, or `netzero` / `netzero+` mapped to reference watts as above)
- Optional: `data-runtime-conditions` — JSON array copied from resolved schedule `runtime_conditions` for that hour (forward-filled when the graph is built)

**Output (for the clicked bar)**

- `startPercent`, `endPercent`, `deltaPercent`, `estimatedPowerW` (may be 0 when discharge is clamped), `durationHours`, `isCurrentHour`, plus min/max envelope echo for display context

## Algorithm (core)

1. **Starting SoC** — `runningPercent` begins at the live `electricLevel` (clamped).

2. **Bar order** — Bars are sorted by `data-key` (YYYYMMDD + hour) so the walk follows real time across midnight.

3. **Skip past hours** — Any slot whose hour **ends** at or before “now” is skipped (no retrospective modeling).

4. **Duration** — For a **future** full hour, duration is 1 hour. For the **current** hour, duration is the **remaining** fraction of the hour from now until the end of the slot.

5. **Scheduled power** — `estimateSchedulePowerForPopup` maps `data-schedule-value` to watts (numeric as-is; `netzero` → `-POPUP_NETZERO_REFERENCE_W`; `netzero+` → `+POPUP_NETZERO_PLUS_REFERENCE_W`).

6. **Percent delta for the interval** — `powerToCapacityPercent(abs(powerW))` is “percent points per hour” at that power:

   - `baseWh = BASE_WH` from page (fallback 5760 if missing)
   - `onePercentUsableWh = (baseWh / 100) * POPUP_POWER_EFFICIENCY`
   - `percentPerHour = abs(powerW) / onePercentUsableWh`
   - For the interval: `rawDelta = percentPerHour * durationHours`, signed negative for discharge and positive for charge.

7. **Envelope clamp** — Provisional end SoC is `start + signedDelta`, then clamped to `[minChargeLevel, maxChargeLevel]`.

8. **Runtime discharge floor (dynamic rules)** — If the bar carries `data-runtime-conditions`, `getDischargeSocFloorFromRuntimeConditions` looks for battery fields with operators `>` or `>=` and takes the **maximum** right-hand value (tightest lower bound). When scheduled power is **discharge** (negative):

   - When **start ≤ that floor**: set end = start and show **0 W** in the popup (no modeled discharge).
   - When **start > floor**: end = max(floor, envelope-clamped physics end), re-clamp to min/max; if end equals start within a tiny epsilon, show **0 W**.

9. **Chain** — After each future bar, `runningPercent` becomes that bar’s modeled `endPercent` so the next bar starts from the previous estimate.

10. **Display** — `formatPopupForecastHtml` prints start/end, Δ, and `@ … W` using the **effective** power after clamping.

## Edge cases and failure modes

- When `currentBatteryForecastState` is missing or SoC is not a finite number, the estimate block is omitted.
- When there are no qualifying future bars, the forecast is null.
- If `BASE_WH` is invalid, `powerToCapacityPercent` returns null and the delta for that step is treated as 0.
- Runtime conditions use only `>` / `>=` on battery fields for the discharge floor; other operators are ignored for this estimate.

## Related files

- Resolved schedule and `runtime_conditions`: [`main/data/resolve_schedule_conditions.php`](../../../../main/data/resolve_schedule_conditions.php)
- Schedule API / resolved payload consumed when painting bars: [`main/api/charge_schedule_api.php`](../../../../main/api/charge_schedule_api.php) (and related schedule fetch path used by `charge_schedule.js`)
- Mobile price graph card: [`docs/main/energy-graph-mobile.md`](../../energy-graph-mobile.md) (energy graph; distinct from this popup estimate but same page)
