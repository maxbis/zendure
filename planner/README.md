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
