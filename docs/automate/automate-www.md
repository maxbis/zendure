# `automate_www.py` Documentation

This document describes the automation script with built-in HTTP API for charge schedule monitoring and Zendure battery control. It extends the concepts in [automate-overview.md](automate-overview.md) with an embedded HTTP server for monitoring and remote control.

## Overview

`automate_www.py` is the OOP automation script that runs continuously, checks the charge schedule API, applies power settings to the Zendure battery, and **exposes an HTTP API** on port 1611. It supports the same interactive keyboard commands as `automate.py`.

### Differences from `automate.py`

| Aspect | `automate.py` | `automate_www.py` |
|--------|---------------|-------------------|
| **Status updates** | POST to external `statusApiUrl` | Stores in SQLite (`data/status_updates.db`) + in-memory; no external POST |
| **HTTP API** | None | Built-in server on port 1611 with multiple endpoints |
| **Wh-per-hour** | N/A | Computed from SQLite for `/api/wh_per_hour` |
| **Loop interval default** | 30 seconds | 20 seconds |
| **Power step limiting** | N/A | Max delta per step (configurable `POWER_FEED_MAX_DELTA`) |

## Files and Classes

### Module structure

`automate_www.py` defines:

- **Data classes**: `P1Readings`, `ZendureReadings`, `StatusChange`, `ApiState` — shared state for HTTP API responses.
- **HTTP API**: `compute_wh_per_hour()`, `AutomationTCPServer`, `ApiTestHandler` — serve JSON endpoints.
- **Logger**: Same as `automate.py` — wrapper around device controller logging.
- **StatusApi**: Stores status updates in SQLite and invokes an `on_update` callback (updates `api_state.last_status`); does **not** POST to an external API.
- **InputHandler**: Same as `automate.py` — cross-platform keyboard input.
- **CommandHandler**: Same commands as `automate.py`, implemented via a command dispatch dict and `_cmd_*` methods.
- **AutomationApp**: Main orchestrator; runs the loop, starts the HTTP server, and coordinates all components.

### Configuration constants (module-level)

| Constant | Default | Config key override | Description |
|----------|---------|---------------------|-------------|
| `LOOP_INTERVAL_SECONDS` | 20 | `LOOP_INTERVAL_SECONDS` | Seconds between loop iterations (clamped 5–300) |
| `API_REFRESH_INTERVAL_SECONDS` | 300 | `API_REFRESH_INTERVAL_SECONDS` | Schedule refresh interval (clamped 60–3600) |
| `ZERO_COUNT_THRESHOLD_STANDBY` | 21 | `ZERO_COUNT_THRESHOLD_STANDBY` | Consecutive 0-power iterations before standby (clamped 1–100) |
| `HTTP_API_PORT` | 1611 | — | HTTP API listen port |
| `WH_PER_HOUR_TIMEZONE` | `"Europe/Amsterdam"` | — | Timezone for Wh-per-hour calculation |
| `WH_PER_HOUR_DAYS_DEFAULT` | 3 | — | Default days for Wh-per-hour API |

Additional config keys used: `dataDir`, `statusUpdatesRetentionDays`, `POWER_FEED_MAX_DELTA` (default 2400).

## HTTP API Endpoints

The HTTP server runs on port 1611 (configurable via `HTTP_API_PORT`). All responses are JSON unless noted.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/test` | GET | Health check. Returns `{"status": "ok", "message": "API is up and running"}`. |
| `/api/p1` | GET | Latest P1 meter readings with timestamp. |
| `/api/zendure` | GET | Latest Zendure device readings with timestamp. |
| `/api/status` | GET | Last status change event (eventType, oldValue, newValue, timestamp). |
| `/api/all` | GET | Combined response: `p1`, `zendure`, and `status`. |
| `/api/wh_per_hour` | GET | Watt-hours charged/discharged per calendar hour for the last N days. Uses SQLite `status_updates` table. Returns `{"YYYY-MM-DD": [{"hour": "HH", "charged_wh": float, "discharged_wh": float}, ...], ...}`. |
| `/api/refresh` | GET | Triggers a schedule refresh: fetches from schedule API and posts a Rescan status update. Returns `{"ok": true}` on success, `{"ok": false, "error": "..."}` on failure (500). |

Endpoints `/api/p1`, `/api/zendure`, `/api/status`, and `/api/all` require `api_state` to be initialized; otherwise they return 503.

## How It Works

1. **Configuration**: Reads `config.json` from `../config/config.json` or `./config/config.json` (same as `automate.py`).

2. **Initialization**: Creates `AutomateController`, `ScheduleController`, `Logger`, `StatusApi` (SQLite + callback), `InputHandler`, `CommandHandler`, sets up signal handlers, and loads loop config via `_load_loop_config()`.

3. **HTTP server**: Starts `AutomationTCPServer` in a daemon thread; shares `api_state`, `db_path`, `schedule_controller`, and `status_api` with the request handler.

4. **Main loop** (same overall flow as `automate.py`):
   - Sleep with interrupt for input
   - Accumulate P1 data and update `api_state.last_p1`
   - Check keyboard input and process via `CommandHandler`
   - Refresh schedule if interval elapsed
   - Compute desired power from schedule
   - Apply battery limits
   - Apply power step delta limiting (max change per iteration)
   - Apply power settings via `AutomateController`
   - Post status updates to `StatusApi` (SQLite + callback)
   - Check standby threshold (consecutive zeros)
   - Update `api_state.last_zendure` and `api_state.last_status` as applicable

5. **Status storage**: `StatusApi` writes to `{dataDir}/status_updates.db` and invokes `on_update`, which updates `api_state.last_status` for HTTP consumers. Data is retained for `statusUpdatesRetentionDays` (default 7).

## Usage

Run the script from the `automate/` directory:

```bash
python3 automate/automate_www.py
```

The script runs in the foreground. Use a process manager (e.g. systemd, supervisor) for background operation.

### Keyboard commands

Same as `automate.py`:

| Command | Description |
|---------|-------------|
| `h`, `help` | Show available commands |
| `s`, `status` | Show current status (power, battery, schedule) |
| `a`, `accumulators` | Print accumulator status |
| `r`, `refresh` | Force refresh schedule from API |
| `p <value>` | Set power manually (e.g. `p 500`, `p netzero`) |
| `z`, `zero` | Set power to 0 |
| `nz`, `netzero` | Set power to netzero mode |
| `nzp`, `netzero+` | Set power to netzero+ mode |
| `q`, `quit` | Quit gracefully |

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
```

## API Calls (External)

`automate_www.py` uses the same external APIs as `automate.py` for the Zendure device, P1 meter, schedule, and data storage — except for the **automation status API**. Status updates are stored locally in SQLite; there is no POST to `statusApiUrl` for start/stop/change/Rescan events.

See [automate-overview.md](automate-overview.md#api-calls-used-by-automatpy) for the full list of schedule, Zendure, P1, and data API calls. The status API (item 2 in that list) does **not** apply to `automate_www.py`.

## Power Control Logic

Identical to `automate.py`. See [automate-overview.md](automate-overview.md#power-control-logic--behavior) for manual power, NetZero/NetZero+ modes, battery limits, and standby behavior.

## Wh-per-hour Calculation

The `/api/wh_per_hour` endpoint computes charged and discharged watt-hours per calendar hour from the `status_updates` SQLite table. It uses step integration (power assumed constant between consecutive change readings). The timezone and number of days are configurable via module constants (`WH_PER_HOUR_TIMEZONE`, `WH_PER_HOUR_DAYS_DEFAULT`).
