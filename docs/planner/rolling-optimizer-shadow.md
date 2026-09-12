# Rolling optimizer shadow mode

## Purpose

The rolling optimizer calculates a cost-minimizing battery schedule from now
through the first local midnight after a minimum 24-hour horizon. The resulting
horizon is between 24 and 48 hours. Every calculation remains available for
comparison, while an authenticated switch determines whether the battery schedule
API serves rules or the latest validated optimizer plan.

## Location

- Optimizer: [`../../planner/rolling_optimizer.py`](../../planner/rolling_optimizer.py)
- Log-only runner: [`../../planner/shadow.py`](../../planner/shadow.py)
- Settings bridge: [`../../planner/config.py`](../../planner/config.py)
- Tests: [`../../planner/tests/test_rolling_optimizer.py`](../../planner/tests/test_rolling_optimizer.py)
- Default output: `planner/data/optimizer_schedule.log`
- Authenticated viewer: [`../../app/optimizer.php`](../../app/optimizer.php)
- Viewer API: [`../../app/api/optimizer_log.php`](../../app/api/optimizer_log.php)
- Mode API: [`../../app/api/optimizer_mode.php`](../../app/api/optimizer_mode.php)
- Schedule selector: [`../../app/includes/optimizer_schedule.php`](../../app/includes/optimizer_schedule.php)
- Cron template: [`../../planner/optimizer.cron.example`](../../planner/optimizer.cron.example)

## Inputs and outputs

The optimizer reads:

- Live battery state of charge from the existing automation status endpoint.
- Consumer prices from the existing price endpoint.
- Spot sale prices derived with the shared price-conversion settings.
- Expected solar production derived from the existing shortwave forecast.
- The shared 24-hour household-usage profile.
- Battery capacity, state-of-charge boundaries, schedule power limits and power step from shared configuration.
- `battery.roundTripEfficiency`, currently 0.85. The legacy one-way `battery.efficiency` remains unchanged.

The runner appends one self-contained JSON object per calculation. A record contains
the inputs that define the run, expected financial result, starting and ending state
of charge, and a decision for each segment in the rolling horizon.

The runner also atomically replaces `planner/data/optimizer_schedule_latest.json`.
This separate file is the only optimizer artifact eligible for schedule execution;
the historical JSON-lines log is never used to control the battery.

The authenticated browser viewer reads recent plan records and compares the selected
plan with the schedule resolved from rules and manual entries. Its active-schedule
control can switch between Rules and Optimizer after confirmation. Direct web access to the raw log is denied by
`planner/data/.htaccess` when Apache directory overrides are enabled.

The four headline cards focus on operational decisions:

- The active Rules, Optimizer or Rules fallback schedule and the latest relevant calculation time.
- The selected forecast horizon and whether its prices are official or provisional.
- The Rules and Optimizer final SoC on the same forecast horizon.
- The Optimizer cash P&L difference versus Rules across the complete forecast, with Optimizer final SoC alongside it.

Energy cost, the terminal-value objective, efficiency and the number of changed
schedule segments remain available under Technical optimizer details instead of
being presented as primary outcomes.

For every selected prediction, the viewer also estimates cash P&L per calendar day for:

- The current resolved schedule.
- The optimizer schedule.
- The difference, where a positive difference favors the optimizer.

Both estimates use the prediction's prices, solar, household load, starting SoC,
battery limits and round-trip efficiency. P&L is sale income minus purchase cost.
Terminal battery value is deliberately excluded from daily P&L; each result therefore
also shows ending SoC so retained energy remains visible.

After the individual calendar-day cards, the viewer shows a matching Complete
forecast card. It totals Rules and Optimizer cash P&L across the whole rolling
horizon, shows their difference, and uses the final calendar day's ending SoC as
the final SoC for each plan.

## Flow and behavior

1. Read current prices, solar forecast and battery state.
2. Establish a minimum 24-hour horizon and extend its end through the following local midnight.
3. Split that horizon at clock-hour boundaries, retaining a partial first hour when needed.
4. Use official prices where available. For an unpublished future hour, use today's price at the same clock hour and mark it `repeat_today`.
5. Evaluate feasible charge, idle and discharge powers in the configured power steps.
6. Select the full-horizon path with the lowest expected purchase cost minus sale income and remaining-energy value.
7. Translate each decision into the existing schedule vocabulary: `netzero+`, `netzero-`, zero or a fixed signed power.
8. Append the plan to the comparison log under a file lock.
9. Atomically publish the same plan as the latest executable optimizer schedule.
10. When optimizer mode is selected, validate freshness, continuity, horizon coverage, supported modes and configured power limits before serving it.
11. Preserve exact dated manual schedule entries over optimizer entries.
12. During the dual-testing period, when validation fails or the plan becomes older than 70 minutes, serve rules automatically.

Run once:

```sh
python3 -m planner.shadow --once
```

Recalculate every five minutes:

```sh
python3 -m planner.shadow --interval-seconds 300
```

For cron, copy the command in `planner/optimizer.cron.example`, replace the checkout
path, and install it for the production web-app user. The runner uses a non-blocking
process lock so two optimizer calculations cannot overlap.

Open the comparison viewer:

```text
http://localhost/zendure/app/optimizer.php
```

## Edge cases and failure modes

- When an upstream source cannot be read, then an error record is appended and the last executable plan is not replaced.
- When tomorrow's prices become available, then the next run automatically replaces repeated-today assumptions with official prices.
- When the minimum horizon already ends exactly at midnight, then no additional day is added.
- When `PLANNER_EXTEND_HORIZON_TO_MIDNIGHT=false`, then the optimizer uses the exact configured horizon instead.
- When the current schedule uses an NZ mode, then the viewer estimates its power from the same forecast solar and household load. Actual P&L can differ because runtime meter readings differ.
- When a current schedule action cannot be modeled, then the viewer treats it as idle and shows a warning for that day.
- When two processes append simultaneously, then a file lock prevents interleaved log records.
- When a malformed or non-plan line occurs, then the viewer skips it instead of failing the whole log.
- When the optimizer cannot find a feasible state path, then the run is logged as an error.
- When Rules is selected, then calculations and logging continue but the resolved schedule endpoint serves the existing rule result.
- When Optimizer is selected, then activation first runs a new calculation and refuses the switch unless the published plan passes validation.
- When the selected optimizer plan becomes missing, malformed, stale, discontinuous, outside the current horizon or outside configured power limits, then the resolved schedule endpoint falls back to rules.
- When an exact dated manual entry applies to an hour, then it retains priority over the optimizer for that hour.
- When a mode changes, then the authenticated API records the event in `planner/data/optimizer_mode_audit.log` and the viewer asks the automation controller to refresh. If that refresh request fails, the controller still obtains the change during its normal polling cycle.
- The historical comparison log is advisory only and is never read by the schedule endpoint.
- Starting the repeating runner after a restart still requires process supervision on the target host.

## Related files

- [`../common/config/system-configuration.md`](../common/config/system-configuration.md): shared configuration contract.
- [`../../planner/README.md`](../../planner/README.md): planner commands and environment overrides.
- [`../data/schedule-agent-api-contract.md`](../data/schedule-agent-api-contract.md): existing schedule compatibility format.
