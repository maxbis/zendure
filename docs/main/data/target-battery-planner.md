# Target battery planner

## Purpose

The target battery planner converts symbolic battery objectives into supported schedule values before the resolved schedule is returned to automation.

- `empty_at_solar_charge` calculates a fixed discharge action that aims to reach a requested reserve at the first future solar-capable net-zero slot.
- `full_at_netzero_minus` continuously calculates an NZ+ minimum that aims to reach the configured maximum battery percentage at the first future `netzero-` slot.

Automation does not evaluate either symbolic mode. It continues receiving integers and the existing net-zero modes.

## Location

- Planner calculation: [`main/data/target_battery_planner.php`](../../../main/data/target_battery_planner.php)
- Rule resolver: [`main/data/resolve_schedule_conditions.php`](../../../main/data/resolve_schedule_conditions.php)
- Final schedule API: [`main/data/api/data_api.php`](../../../main/data/api/data_api.php)
- Rule editor: [`main/edit_rules.php`](../../../main/edit_rules.php)
- Planner tests: [`tools/tests/target_battery_planner_test.php`](../../../tools/tests/target_battery_planner_test.php)

## Inputs and outputs

The discharge-target source rule contains:

- `value: "empty_at_solar_charge"`
- `target_soc_percent`: required requested reserve percentage
- `target_anchor: "next_solar_capable_netzero"`
- `max_discharge_power`: optional positive watt cap
- `fallback_value`: optional existing schedule value; `netzero-` is the planner default
- Normal static and runtime conditions

The charge-target source rule contains:

- `value: "full_at_netzero_minus"`
- `target_anchor: "next_netzero_minus"`
- `fallback_value`: optional existing schedule value; `netzero+` is the planner default
- A stable `rule_id`, used to group all matching hours before the same anchor
- Normal static and runtime conditions

The planner reads:

- The merged base and conditional schedule for today and tomorrow.
- Current live battery percentage.
- Shared capacity and minimum/maximum battery percentages.
- Shared `battery.efficiency`.
- Shared `forecast.defaultHouseholdUsageWByHour`.
- Shared signed schedule range plus an optional rule-specific discharge cap.
- Shared `schedule.powerStepW`.

The schedule API prefers a fresh local Zendure snapshot. Remote live-status results are cached for 30 seconds in ignored runtime data so repeated schedule requests do not repeatedly call the controller.

The resolved discharge-target slot replaces the symbolic value with:

- A negative integer when calculated discharge is needed and possible.
- The configured fallback when the target is already satisfied or cannot be calculated safely.
- A `planning` object used by the app tooltip and ignored by automation.

The resolved charge-target slots replace the symbolic value with:

- `value: "netzero+"`.
- A non-negative `min_power` selected by forecasting stepped candidates through the NZ- anchor.
- The configured maximum charge power as `max_power`.
- A `planning` object containing live SoC, predicted first-slot SoC, baseline and planned anchor SoC, target, next NZ- anchor, remaining eligible duration, calculated minimum, cap, status, and explanation.

## Flow and behavior

1. Resolve the base schedule and merge conditional rules for today and tomorrow.
2. Find slots whose source rule value is `empty_at_solar_charge`.
3. Find the first later solar-capable net-zero slot. `netzero+` always qualifies; `netzero` qualifies unless its maximum power is zero or negative; `full_at_netzero_minus` qualifies because it materializes to NZ+ later in the same planning run.
4. Forecast the baseline battery percentage at that solar-charge start.
5. When the baseline is above the requested target, calculate the additional output energy needed.
6. Convert that energy to fixed discharge watts for the target-rule interval.
7. Clamp the result to the system and optional rule discharge caps.
8. Re-run the forecast with the calculated value.
9. Emit status `achievable`, `best_effort`, `already_satisfied`, `unavailable`, or `past` in planning metadata.

For `full_at_netzero_minus`:

1. Find the first future resolved NZ- slot.
2. Group all remaining matching slots from the same rule before that anchor.
3. Add their usable duration; for the current hour, count only remaining minutes.
4. Forecast the complete schedule to the first matching slot and to the NZ- anchor with the target slots represented as NZ+ with a `0 W` minimum.
5. Test charge minimums from the shared power step through the configured maximum, applying each candidate to every remaining matching slot.
6. Forecast each candidate through the anchor using fixed actions, household-use assumptions, runtime fallbacks, battery efficiency, partial hours, and battery limits.
7. Select the lowest candidate whose anchor prediction reaches the configured maximum battery percentage within tolerance.
8. When no candidate reaches the target, report `best_effort` and select the lowest candidate that produces the strongest anchor forecast; use `0 W` when the baseline forecast already reaches it.
9. Materialize all remaining grouped slots as NZ+ with the selected minimum and configured maximum.
10. Recalculate when the schedule API is fetched again. Automation normally fetches every five minutes.
11. Emit status `achievable`, `best_effort`, `already_satisfied`, `unavailable`, or `past` plus the forecast inputs and results in planning metadata.

The current hour uses only its remaining minutes. Manual exact schedule entries continue to take priority over conditional rules during the merge.

During target planning, unbounded NZ± slots are forecast as battery-neutral (`0 W`) because their future charge or discharge direction is unknown without a solar forecast. Explicit NZ± minimum and maximum power bounds still clamp that neutral baseline. NZ- continues to use forecast household consumption.

## Edge cases and failure modes

- When live battery percentage is unavailable, then emit the fallback and `unavailable` planning status.
- When no future solar-capable net-zero slot exists within today and tomorrow, then emit the fallback and explain that the anchor is unavailable.
- `netzero-` and `netzero` with `max_power <= 0` never qualify as solar-charge anchors.
- When a future slot contains `full_at_netzero_minus`, then treat its start as solar-capable before the charge-target pass materializes it to NZ+.
- When the baseline forecast is already at or below the target, then emit the fallback with `already_satisfied` status.
- When the required discharge exceeds a power cap, then emit the capped fixed value and `best_effort` status.
- When the target rule hour has ended, then emit the fallback with `past` status.
- When a runtime condition is present, then automation can still select the rule fallback at runtime; the editor should avoid redundant battery-level conditions for this mode.
- When household usage differs from the fixed profile, then actual battery percentage can differ from the forecast.
- When live battery percentage is unavailable for a charge target, then emit its configured fallback or unbounded NZ+ and report `unavailable`.
- When no future NZ- exists within today and tomorrow, then emit the charge fallback and explain that the anchor is unavailable.
- When the baseline anchor forecast already reaches the charge target, emit NZ+ with `min_power = 0` so surplus may still charge but discharge remains impossible.
- When the target is unreachable, emit the lowest stepped minimum that produces the strongest anchor forecast and report `best_effort`; do not increase the minimum when higher candidates produce the same result because the battery has already saturated.
- When matching target-charge hours are non-contiguous, then count only those matching slots as eligible duration.
- When repeated requests occur between automation refreshes, then the shared upward quantization prevents insignificant limit changes.
- When shared configuration is missing or invalid, then the planner fails instead of using embedded efficiency, demand-profile, power-cap or step defaults.

## Related files

- [Edit Rules user manual](../../user_manuals/edit_rules_user_manual.md)
- [Schedule resolution technical reference](../../data/schedule-resolution-technical.md)
- [Prices and Energy Plan](../../app/assets/js/price-plan.md)
- [Battery level forecast](../../app/assets/js/battery-forecast.md)
