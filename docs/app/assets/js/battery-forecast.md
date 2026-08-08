# Battery level forecast

## Purpose

The battery forecast module predicts the battery percentage at the start and end of every remaining schedule hour shown by the new GUI. It is a display-only model for the Prices & Energy Plan tooltip and never changes the automation schedule or sends battery commands.

The initial household-usage profile and efficiency are injected from the shared system configuration so a future usage-prediction API can replace the data source without replacing the forecast calculation.

The server-side target battery planner uses the same initial hourly assumptions for both symbolic target modes. For `full_at_netzero_minus`, it forecasts the complete schedule with stepped charge candidates and selects the lowest minimum whose NZ- anchor prediction reaches the target. The PHP planner remains authoritative for automation, while this JavaScript module supplies the display-only per-hour estimate.

## Location

- Forecast engine: [`app/assets/js/battery-forecast.js`](../../../../app/assets/js/battery-forecast.js)
- Tooltip integration: [`app/assets/js/price-plan.js`](../../../../app/assets/js/price-plan.js)
- Live battery-state publisher: [`app/assets/js/current-energy-status.js`](../../../../app/assets/js/current-energy-status.js)
- Tooltip styles: [`app/assets/css/app.css`](../../../../app/assets/css/app.css)
- Script loading and shared battery configuration: [`app/index.php`](../../../../app/index.php)
- Calculation checks: [`tools/tests/app_battery_forecast_test.js`](../../../../tools/tests/app_battery_forecast_test.js)

## Inputs and outputs

The forecast receives:

- The latest live battery percentage.
- The configured battery capacity in watt-hours.
- The configured minimum and maximum battery percentages.
- Whether the live reading is stale.
- The resolved schedule slots for today and tomorrow.
- The current local date and time.
- A household-usage value for each hour of the day.
- The shared battery efficiency factor.

`forecast.defaultHouseholdUsageWByHour` in `common/config/system.json` contains 24 validated values:

- When the hour starts from 00:00 through 07:00, then expected household usage is 100 W.
- When the hour starts from 08:00 through 23:00, then expected household usage is 220 W.

`buildForecast()` returns an object keyed by the full schedule key `YYYYMMDDHH00`. Each predicted hour contains:

- Start and end battery percentages.
- Applied percentage-point change.
- Effective estimated battery power.
- Mode and assumption source.
- Interval duration.
- Whether it is the partial current hour.
- The forecast assumption source, including `runtime_condition_conservative` for runtime-conditioned slots.
- Primary and fallback candidate powers used to select the least guaranteed discharge.

## Flow and behavior

1. Validate the live battery state, capacity, operating range, and shared efficiency factor.
2. Start the running percentage at the latest live battery percentage.
3. Skip schedule hours that have already ended.
4. When processing the current hour, use only the minutes remaining until the next whole hour.
5. When processing a later hour, use a one-hour duration.
6. When a slot has a battery-level runtime condition, convert its primary and fallback actions separately into forecast power.
7. Apply dynamic `min_power` and `max_power` limits only to the primary net-zero action; the fallback does not inherit them.
8. For a runtime-conditioned slot, use the smaller discharge magnitude when both outcomes discharge; otherwise use `0 W` so the forecast assumes neither uncertain discharge nor uncertain charging.
9. When a resolved slot contains calculated Target @ solar or Target @ next NZ- planning metadata, use its calculated primary action even when that target rule retains a runtime guard.
10. When a slot has no runtime condition, convert its resolved static action directly into forecast power.
11. Convert power and duration into a battery percentage change.
12. Clamp the final result to the configured battery operating range.
13. Use the predicted end percentage as the next hour's start percentage.

### Action assumptions

- When the action is a fixed number, then use that signed wattage as scheduled power.
- When the action is `netzero-`, then use negative expected household usage.
- When the action is unbounded `netzero` (NZ±), then forecast `0 W` and preserve battery SoC because future solar generation and net household balance are unknown.
- When NZ± has explicit power bounds, apply those bounds to the neutral `0 W` baseline, so a bound that forces charging or discharging remains effective in the forecast.
- When the action is `netzero+`, then assume 0 W because no solar/export forecast is available; explicit dynamic limits can still produce a bounded charge value.
- When the action is standby, then use 0 W.
- When the action is automatic or unknown, then use 0 W and label the assumption as unknown.
- When a runtime condition can select `-1000 W` or NZ- at a `200 W` household estimate, then forecast `-200 W` as the least guaranteed discharge.
- When a runtime condition can select charging or a neutral action, then forecast `0 W` rather than relying on uncertain charging.

### Energy conversion

For discharge, household energy is divided by the efficiency factor before converting it to battery percentage:

```text
percentage change = -(absolute watts × hours ÷ efficiency ÷ capacity Wh) × 100
```

For charge, incoming energy is multiplied by the efficiency factor:

```text
percentage change = (watts × hours × efficiency ÷ capacity Wh) × 100
```

## Edge cases and failure modes

- When the live battery reading is missing or stale, then return no predictions and let the tooltip explain that the forecast is unavailable.
- When capacity, operating limits, or efficiency are invalid, then return no predictions.
- When the shared configuration is invalid, then the PHP entry point does not render the normal application.
- When the battery is already at or below its configured minimum, then do not predict additional discharge.
- When the battery is already at or above its configured maximum, then do not predict additional charge.
- When predicted SoC is above or below a runtime threshold, then do not select a branch from that predicted value; use the least guaranteed discharge across both possible outcomes.
- When both runtime outcomes discharge equally, then retain that shared discharge value.
- When primary bounds force a direction but the fallback is neutral, then use `0 W` because that forced primary action is not guaranteed to run.
- When live automation evaluates the runtime condition, then it can still use the more aggressive primary action; the forecast policy does not change runtime control.
- When a household profile entry is missing or invalid, then use 0 W for that hour.
- The model does not predict solar generation, unexpected household loads, controller ramping, or schedule changes made after calculation.

## Related files

- [Prices and energy plan](price-plan.md)
- [Current energy status and battery details](current-energy-status.md)
- [New GUI shared system configuration](../../shared-system-configuration.md)
- [Legacy price-popup battery estimate](../../../main/assets/js/price-overview-bar-popup-estimate.md)
- [Target battery planner](../../../main/data/target-battery-planner.md)
