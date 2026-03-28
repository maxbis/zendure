# `automate_www.py` Documentation

This document describes the automation script with built-in HTTP API for charge schedule monitoring and Zendure battery control. It extends the concepts in [automate-overview.md](automate-overview.md) with an embedded HTTP server for monitoring and remote control.

## Overview

`automate_www.py` is the OOP automation script that runs continuously, checks the charge schedule API, applies power settings to the Zendure battery, and **exposes an HTTP API** on port 1611. It supports interactive keyboard commands for live control.

### Differences from legacy `automate.py`

The current repository only contains `automate_www.py`. Compared with legacy `automate.py`:

- Status updates:
  - Legacy: POST to external `statusApiUrl`.
  - `automate_www.py`: stores in SQLite (`data/status_updates.db`) and in-memory cache.
- HTTP API:
  - Legacy: no built-in HTTP API.
  - `automate_www.py`: serves multiple endpoints on port 1611.
- Wh-per-hour:
  - Legacy: not available.
  - `automate_www.py`: computed from SQLite via `/api/wh_per_hour`.
- Loop interval default:
  - Legacy: 30 seconds.
  - `automate_www.py`: 20 seconds.
- Power step limiting:
  - Legacy: not available.
  - `automate_www.py`: max delta per step (`POWER_FEED_MAX_DELTA`).

## Files and Classes

### Module structure

`automate_www.py` defines:

- **Data classes**: `P1Readings`, `ZendureReadings`, `StatusChange`, `ApiState` — shared state for HTTP API responses.
- **HTTP API**: `compute_wh_per_hour()`, `AutomationTCPServer`, `ApiTestHandler` — serve JSON endpoints.
- **Logger**: Wrapper around device controller logging.
- **StatusApi**: Stores status updates in SQLite and invokes an `on_update` callback (updates `api_state.last_status`); does **not** POST to an external API.
- **InputHandler**: Cross-platform keyboard input.
- **CommandHandler**: Command dispatch dict with `_cmd_*` handlers.
- **AutomationApp**: Main orchestrator; runs the loop, starts the HTTP server, and coordinates all components.

### Configuration constants (module-level)

- `LOOP_INTERVAL_SECONDS`
  - Default: `20`
  - Config override: selected `powerMeter.<type>.loopIntervalSeconds`, otherwise `LOOP_INTERVAL_SECONDS`
  - Meaning: seconds between loop iterations (clamped 5-300)
- `API_REFRESH_INTERVAL_SECONDS`
  - Default: `300`
  - Config override: `API_REFRESH_INTERVAL_SECONDS`
  - Meaning: schedule refresh interval (clamped 60-3600)
- `STANDBY_DELAY_SECONDS`
  - Default: `300`
  - Config override: none
  - Meaning: seconds at continuous 0 power before standby
- `HTTP_API_PORT`
  - Default: `1611`
  - Config override: none
  - Meaning: HTTP API listen port
- `WH_PER_HOUR_TIMEZONE`
  - Default: `"Europe/Amsterdam"`
  - Config override: none
  - Meaning: timezone for Wh-per-hour calculation
- `WH_PER_HOUR_DAYS_DEFAULT`
  - Default: `3`
  - Config override: none
  - Meaning: default days for Wh-per-hour API

Additional config keys used: `dataDir`, `statusUpdatesRetentionDays`, `POWER_FEED_MAX_DELTA` (default 2400).

## HTTP API Endpoints

The HTTP server runs on port 1611 (configurable via `HTTP_API_PORT`). All responses are JSON unless noted.

1. `GET /api/test`
- Health check.
- Returns `{"status": "ok", "message": "API is up and running"}`.

2. `GET /api/p1`
- Latest P1 meter readings with timestamp.
- Optional query params: `max_age` or `maxAge` (default `60` seconds, `0` means always refresh).

3. `GET /api/zendure`
- Latest Zendure device readings with timestamp.
- Optional query params: `max_age` or `maxAge` (default `60` seconds, `0` means always refresh).

4. `GET /api/status`
- Last status change event (`eventType`, `oldValue`, `newValue`, `timestamp`).

5. `GET /api/all`
- Combined response with `p1`, `zendure`, and `status`.

6. `GET /api/automation_status`
- Automation status entries from in-memory last-per-type cache.
- Returns all cached types (no params).

7. `GET /api/wh_per_hour`
- Watt-hours charged/discharged per calendar hour for the last N days.
- Uses SQLite `status_updates` table.
- Returns `{"YYYY-MM-DD": [{"hour": "HH", "charged_wh": float, "discharged_wh": float, "electric_level": int|null}, ...], ...}`.

8. `GET /api/refresh`
- Triggers a schedule refresh: fetch from schedule API and post a `Rescan` status update.
- Returns `{"ok": true}` on success, or `{"ok": false, "error": "..."}` with status 500 on failure.

9. `GET /api/status_updates_delta`
- Returns status update rows from the SQLite `status_updates` table since a given row ID.
- Required query param: `after_id` (integer, 0-based).
- Optional query params: `limit` (default `500`, max `2000`).
- Optional auth: `token` query param or `X-API-Token` header (if configured).
- Returns `{"rows": [...], "max_id_returned": int, "has_more": bool}`.
- Each row contains: `id`, `type`, `old_value`, `new_value`, `p1_total_power`, `electric_level`, `timestamp`.
- Returns 401 if authentication is required but not provided; 400 if `after_id` is missing or invalid; 503 if database is unavailable.

11. `GET /api/pause`
- Returns the current pause-override state.

12. `POST /api/pause?state=on|off`
- Enables or disables pause override.
- When pause is enabled, desired power is forced to `0`.

13. `GET /api/loglevel`
- Returns the current runtime log level and the allowed values.

14. `POST /api/loglevel?level=DEBUG|INFO|WARNING|ERROR`
- Changes the runtime log level immediately.

15. `POST /api/restart`
- Requests a graceful restart of the automation process.

Endpoints `/api/p1`, `/api/zendure`, `/api/status`, and `/api/all` require `api_state` to be initialized; otherwise they return 503.

## How It Works

1. **Configuration**: Reads `automate/config/config.jsonc`.

2. **Initialization**: Creates `AutomateController`, `ScheduleController`, `Logger`, `StatusApi` (SQLite + callback), `InputHandler`, `CommandHandler`, initializes the shared power-meter reader, sets up signal handlers, and loads loop config via `_load_loop_config()`.

3. **HTTP server**: Starts `AutomationTCPServer` in a daemon thread; shares `api_state`, `db_path`, `schedule_controller`, and `status_api` with the request handler.

4. **Main loop**:
   - Sleep with interrupt for input
   - Read power-meter data and update `api_state.last_p1`
   - Check keyboard input and process via `CommandHandler`
   - Refresh schedule if interval elapsed
   - Compute desired power from schedule
   - Apply battery limits
   - Apply power step delta limiting (max change per iteration)
   - Apply power settings via `AutomateController`
   - Post status updates to `StatusApi` (SQLite + callback)
   - Check standby delay (time at 0 power)
   - Update `api_state.last_zendure` and `api_state.last_status` as applicable

5. **Status storage**: `StatusApi` writes to `{dataDir}/status_updates.db` and invokes `on_update`, which updates `api_state.last_status` for HTTP consumers. Data is retained for `statusUpdatesRetentionDays` (default 7).

## Usage

Run the script from the `automate/` directory:

```bash
python3 automate/automate_www.py
```

The script runs in the foreground. Use a process manager (e.g. systemd, supervisor) for background operation.

### Keyboard commands

Supported commands:

- `h`, `help`: show available commands
- `s`, `status`: show current status (power, battery, schedule)
- `a`, `accumulators`: print accumulator status
- `r`, `refresh`: force refresh schedule from API
- `p <value>`: set power manually (example: `p 500`, `p netzero`)
- `z`, `zero`: set power to 0
- `nz`, `netzero`: set power to netzero mode
- `nzp`, `netzero+`: set power to netzero+ mode
- `pause on|off|status`: pause automation at 0W or resume schedule control
- `resume`, `unpause`: resume schedule control
- `q`, `quit`: quit gracefully

Dynamic commands (`p netzero`, `nz`, `nzp`) first read the configured power meter in the app/runtime layer and then pass the normalized reading into `AutomateController`.

`netzero` behavior depends on `NETZERO_BI_DIRECTIONAL` in `automate/config/config.jsonc`:

- `false`: `netzero` will not actively charge
- `true`: `netzero` may charge and discharge

When the active schedule slot contains signed `min_power` / `max_power`, the runtime clamps the dynamic result into that signed range before battery/device safety limits are applied.

### Example HTTP usage

```bash
# Health check
curl http://localhost:1611/api/test

# Get latest P1 and Zendure data
curl http://localhost:1611/api/all

# Get Wh per hour
curl http://localhost:1611/api/wh_per_hour

# Trigger schedule refresh
curl http://localhost:1611/api/refresh

# Pause automation at 0W
curl -X POST "http://localhost:1611/api/pause?state=on"

# Resume schedule control
curl -X POST "http://localhost:1611/api/pause?state=off"

# Show current log level
curl http://localhost:1611/api/loglevel

# Change runtime log level
curl -X POST "http://localhost:1611/api/loglevel?level=DEBUG"
```

## API Calls (External)

`automate_www.py` uses the same external APIs for the Zendure device, P1 meter, schedule, and optional data storage as described in the overview, except for the **automation status API**. Status updates are stored locally in SQLite; there is no external status POST for start/stop/change/Rescan events.

See [automate-overview.md](automate-overview.md#api-calls-used-by-automate_wwwpy) for the full list of schedule, Zendure, P1, and data API calls. The status API (item 2 in that list) does **not** apply to `automate_www.py`.

## Power Control Logic

See [automate-overview.md](automate-overview.md#power-control-logic--behavior) for manual power, NetZero/NetZero+ modes, battery limits, and standby behavior.

## Wh-per-hour Calculation

The `/api/wh_per_hour` endpoint computes charged and discharged watt-hours per calendar hour from the `status_updates` SQLite table. It uses step integration (power assumed constant between consecutive change readings). The timezone and number of days are configurable via module constants (`WH_PER_HOUR_TIMEZONE`, `WH_PER_HOUR_DAYS_DEFAULT`).
