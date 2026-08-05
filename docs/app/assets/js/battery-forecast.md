# Battery level forecast

## Purpose

The battery forecast module predicts the battery percentage at the start and end of every remaining schedule hour shown by the new GUI. It is a display-only model for the Prices & Energy Plan tooltip and never changes the automation schedule or sends battery commands.

The initial household-usage profile is deliberately isolated behind one hourly array so a future usage-prediction API can replace the data source without replacing the forecast calculation.

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

`DEFAULT_HOUSEHOLD_USAGE_W_BY_HOUR` contains 24 frozen values:

- When the hour starts from 00:00 through 07:00, then expected household usage is 100 W.
- When the hour starts from 08:00 through 23:00, then expected household usage is 220 W.

`buildForecast()` returns an object keyed by the full schedule key `YYYYMMDDHH00`. Each predicted hour contains:

- Start and end battery percentages.
- Applied percentage-point change.
- Effective estimated battery power.
- Mode and assumption source.
- Interval duration.
- Whether it is the partial current hour.
- Whether a runtime fallback was selected.
- Primary and fallback powers and durations when the action changes at a battery threshold during the hour.

## Flow and behavior

1. Validate the live battery state, capacity, operating range, and 90% efficiency factor.
2. Start the running percentage at the latest live battery percentage.
3. Skip schedule hours that have already ended.
4. When processing the current hour, use only the minutes remaining until the next whole hour.
5. When processing a later hour, use a one-hour duration.
6. Evaluate battery-level runtime conditions against the predicted percentage at the start of the hour.
7. When the runtime conditions fail, use the slot's fallback value without inheriting the primary action's power limits.
8. Convert the effective schedule action to forecast power.
9. Apply dynamic `min_power` and `max_power` limits to primary net-zero actions.
10. Convert power and duration into a battery percentage change.
11. When the primary action reaches a runtime battery threshold before the hour ends, calculate the time spent on the primary action.
12. Apply the configured fallback action for the remainder of that hour without inheriting the primary action's power limits.
13. Clamp the final result to the configured battery operating range.
14. Use the predicted end percentage as the next hour's start percentage.

### Action assumptions

- When the action is a fixed number, then use that signed wattage as scheduled power.
- When the action is `netzero-`, then use negative expected household usage.
- When the action is `netzero`, then use negative expected household usage and assume no solar generation.
- When the action is `netzero+`, then assume 0 W because no solar/export forecast is available; explicit dynamic limits can still produce a bounded charge value.
- When the action is standby, then use 0 W.
- When the action is automatic or unknown, then use 0 W and label the assumption as unknown.

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
- When the battery is already at or below its configured minimum, then do not predict additional discharge.
- When the battery is already at or above its configured maximum, then do not predict additional charge.
- When a discharging primary rule reaches its lower battery threshold, then switch to the configured fallback for the remaining time; only a zero-power fallback keeps the prediction at the threshold.
- When a charging primary rule reaches its upper battery threshold, then switch to the configured fallback for the remaining time; only a zero-power fallback keeps the prediction at the threshold.
- When the predicted hour starts exactly on an inclusive threshold and the primary action would immediately cross it, then give the primary action zero duration and use the fallback for the full interval.
- When the primary action and fallback both operate during one hour, then expose both powers and durations so the tooltip can show the transition.
- When a household profile entry is missing or invalid, then use 0 W for that hour.
- The model does not predict solar generation, unexpected household loads, controller ramping, or schedule changes made after calculation.

## Related files

- [Prices and energy plan](price-plan.md)
- [Current energy status and battery details](current-energy-status.md)
- [New GUI shared system configuration](../../shared-system-configuration.md)
- [Legacy price-popup battery estimate](../../../main/assets/js/price-overview-bar-popup-estimate.md)
