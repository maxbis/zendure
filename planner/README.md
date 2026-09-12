# Planner

Standalone profit-oriented battery schedule planner.

## What it does

This module is fully separate from the existing automation and web app code.

It:

- reads upstream data in read-only mode
- accepts planner-owned load forecast input
- derives a PV forecast from shortwave radiation
- generates a profit-oriented schedule
- exposes the compatibility endpoint expected by the current automation runtime

## Endpoints

- `GET /schedule/resolved`
- `POST /planner/load-forecast`
- `GET /planner/plan`
- `GET /planner/health`

## Run

From the repository root:

```bash
python3 -m planner
```

Optional:

```bash
python3 -m planner --host 0.0.0.0 --port 8765
```

## Load forecast input

Post one forecast document per date.

If no date-specific forecast has been posted yet, the planner falls back to
`planner/data/load_forecast_default.json` when that template file is present.

Example:

```json
{
  "date": "2026-04-28",
  "timezone": "Europe/Amsterdam",
  "baseline_load_w_by_hour": {
    "00": 250,
    "01": 220,
    "02": 210,
    "03": 210,
    "04": 220,
    "05": 250,
    "06": 400,
    "07": 550,
    "08": 500,
    "09": 350,
    "10": 300,
    "11": 280,
    "12": 300,
    "13": 280,
    "14": 290,
    "15": 320,
    "16": 380,
    "17": 520,
    "18": 750,
    "19": 900,
    "20": 850,
    "21": 700,
    "22": 450,
    "23": 320
  },
  "incidentals": {
    "morning": 600,
    "afternoon": 400,
    "evening": 1200,
    "night": 150
  }
}
```

`baseline_load_w_by_hour` is average power in watts by hour. `incidentals` are additive watt-hour totals per fixed day part and are spread evenly across those hours.

## Upstream sources

The planner reads:

- prices from `main/prices/get_prices_v6.php`
- live battery state from automation `/api/all`
- shortwave radiation from `main/api/shortwave_radiation_api.php`

The planner does not modify those systems.

## Log-only rolling optimizer

The shadow optimizer calculates a globally selected schedule that starts now,
retains at least a rolling 24-hour horizon, and continues through the following
local midnight. The schedule is therefore between 24 and 48 hours long. It
only appends the result to a local JSON-lines log. It does not update
`charge_schedule.json`, replace the active schedule API, or send device commands.

Run one calculation:

```bash
python3 -m planner.shadow --once
```

Each run appends the comparison log and atomically publishes
`planner/data/optimizer_schedule_latest.json`. The authenticated optimizer page can
select this plan as the active schedule. During the dual-testing period, plans older than 70 minutes or failing
schedule safety validation automatically fall back to rules.

To refresh with cron every five minutes, use `planner/optimizer.cron.example` after
replacing its production checkout path. Keep the automation controller's existing
schedule URL; schedule-source selection happens inside that endpoint.

Keep recalculating every five minutes:

```bash
python3 -m planner.shadow --interval-seconds 300
```

The default log is `planner/data/optimizer_schedule.log`. Each line is a complete
plan snapshot, so later runs never overwrite earlier plans. Use `--output` or
`PLANNER_SHADOW_LOG_PATH` to select another path.

The optimizer uses the shared battery limits, schedule power step, household
profile and price conversion. Its first-version defaults are:

- minimum 24-hour rolling horizon, extended to the following local midnight
- 85% round-trip battery efficiency
- 2,640 Wp solar capacity with the existing configurable PV derate
- 50 Wh state discretization
- today's same-clock-hour price for an unknown future price

Relevant environment overrides are:

- `PLANNER_HORIZON_HOURS`
- `PLANNER_EXTEND_HORIZON_TO_MIDNIGHT` (`true` by default; set to `false` for an exact horizon)
- `PLANNER_ROUND_TRIP_EFFICIENCY`
- `PLANNER_SOC_STEP_WH`
- `PLANNER_TERMINAL_VALUE_FACTOR`
- `PLANNER_PV_SYSTEM_CAPACITY_W`
- `PLANNER_PV_DERATE_FACTOR`
- `PLANNER_PV_OUTPUT_CLIP_W`
- `PLANNER_SHADOW_LOG_PATH`

The repeated-today price is marked `repeat_today` in the log. Official prices
replace it automatically on the next calculation when they become available.
