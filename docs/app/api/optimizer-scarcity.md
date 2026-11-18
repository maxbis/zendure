# Optimizer scarcity API

## Purpose

Expose the latest planner-calculated scarcity values without duplicating the calculation in PHP.

## Location

- Endpoint: `app/api/optimizer_scarcity.php`
- Planner calculation: `planner/scarcity.py`
- Published source: `planner/data/optimizer_schedule_latest.json`

## Inputs/Outputs

The authenticated endpoint accepts `GET` only. It returns schedule freshness plus the published scarcity object, including:

- Master-switch state and calculation availability.
- Historical price source, range, sample count, and weighting half-life.
- Normal terminal price and cheap-grid threshold.
- Current forecast refill capability, calculation timestamp, and forecast horizon used for activation.
- Current schedule starting SoC, maximum scheduled SoC, scheduled gain, and diagnostic normalized refill.
- Required, solar, cheap-grid, and total refill opportunities used to calculate activation capability.
- Each configured band's reference price, additional scarcity value, activation boundary, active state, and applied state.

## Flow/Behavior

1. Read the latest atomically published optimizer schedule.
2. Extract the scarcity object calculated by Python, including forecast activation capability and scheduled-refill diagnostics.
3. Calculate schedule age and stale state.
4. Return the data with `Cache-Control: no-store`.

The PHP endpoint never recalculates price percentiles, activation, or refill.

## Edge Cases/Failure Modes

- When no published schedule exists, return HTTP 404.
- When the latest plan predates scarcity support, return `calculation_available: false` with `not_calculated`.
- When the latest plan predates forecast-refill activation support, expose its published legacy scarcity payload until the next local optimizer calculation replaces it.
- When historical inputs are insufficient, return the planner's unavailable reason and no applied bands.
- When the master switch is off, bands may still be active diagnostically but are not applied.

## Related Files

- `app/includes/optimizer_schedule.php`
- `app/api/optimizer_log.php`
- `common/config/system.json`
- `docs/planner/rolling-optimizer-shadow.md`
