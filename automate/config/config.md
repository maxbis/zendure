# Automate config reference

This file documents every key in `config.jsonc`. The config is used only by the **automate** scripts (e.g. `automate.py`, `automate_www.py`, `device_controller.py`, `dump_status_updates.py`).

## Comments in config.jsonc

You can add comments to `config.jsonc`; they are stripped when the file is read:

- **Line comments:** any line that (after trimming) starts with `//` is removed.
- **Block comments:** `/* ... */` (single or multi-line) are removed and replaced with a space.

Example:

```json
{
  // Run without applying power changes
  "TEST_MODE": true,
  "deviceIp": "192.168.2.93",
  /* Optional: "deviceSn": "HOA1NAN9N385989" */
  "apiUrl": "https://example.com/schedule"
}
```

Do not put block comments between a key and its value (e.g. `"key": /* comment */ "value"`). Line comments and URLs containing `//` are fine (comments are only stripped on lines that *start* with `//`).

---

## Device & test mode

| Key | Type | Description | Where used |
|-----|------|-------------|------------|
| **TEST_MODE** | boolean | If `true`, the automation runs in test mode: power/charge actions are simulated and not applied to the device. | `device_controller.py` (`BaseDeviceController`): sets `test_mode` at init; Zendure and P1 logic respect it. Startup messages in `automate.py` / `automate_www.py` show test mode. |
| **LOG_LEVEL** | string | Minimum log severity to emit. Supported values: `DEBUG`, `INFO`, `WARNING`, `ERROR` (default `INFO`). | `device_controller.py` (`BaseDeviceController.log`): filters all controller/app logs; `SUCCESS` is treated as `INFO`. |
| **deviceIp** | string | IP address of the Zendure device on the local network. | `device_controller.py`: `ZendureDeviceController` (device control) and `DeviceDataReader` (device data + P1 meter API base). Required. |
| **deviceSn** | string | Serial number of the Zendure device. | `device_controller.py`: `ZendureDeviceController` only. Required. |

---

## Battery SoC limits

| Key | Type | Description | Where used |
|-----|------|-------------|------------|
| **MIN_CHARGE_LEVEL** | number | Minimum state-of-charge (%), 0–100. Below this, discharge is prevented. | `device_controller.py`: `BaseDeviceController` (`min_charge_level`). Used in `automate.py` / `automate_www.py` when deciding charge/discharge limits. |
| **MAX_CHARGE_LEVEL** | number | Maximum state-of-charge (%), 0–100. Above this, charge is prevented. | Same as `MIN_CHARGE_LEVEL`. |

---

## Power feed tuning

| Key | Type | Description | Where used |
|-----|------|-------------|------------|
| **POWER_FEED_MIN_THRESHOLD** | number | Minimum absolute power (W). If desired power is below this, it is treated as 0. | `device_controller.py`: `ZendureDeviceController`. |
| **POWER_FEED_MIN_DELTA** | number | Minimum change (W) to apply a new power limit; smaller deltas are ignored. | `device_controller.py`: `ZendureDeviceController`. |
| **POWER_FEED_MAX_DELTA** | number | Maximum allowed step (W) for a single power-feed change. | `automate.py`, `automate_www.py`: main loop when applying power feed updates. |

---

## Loop timing

| Key | Type | Description | Where used |
|-----|------|-------------|------------|
| **LOOP_INTERVAL_SECONDS** | number | Seconds between main automation loop iterations. | `automate.py`, `automate_www.py`: main loop sleep interval. |
| **API_REFRESH_INTERVAL_SECONDS** | number | How often (seconds) to refresh data from external APIs (e.g. schedule) within the loop. | `automate.py`, `automate_www.py`: throttles API calls. |
| **ZERO_COUNT_THRESHOLD_STANDBY** | number | Number of consecutive “zero” readings before treating the system as standby. | `automate.py`, `automate_www.py`: standby detection logic. |

---

## Paths and retention

| Key | Type | Description | Where used |
|-----|------|-------------|------------|
| **dataDir** | string | Directory for local data (e.g. `status_updates.db`). Default `"./data/"`. | `automate_www.py`: path for status-updates DB and retention; `dump_status_updates.py`: DB path when loading from config. |
| **statusUpdatesRetentionDays** | number | Days to keep rows in the status_updates SQLite DB; older rows are pruned. | `automate_www.py`: retention cleanup. |

---

## API URLs

| Key | Type | Description | Where used |
|-----|------|-------------|------------|
| **apiUrl** | string | Schedule API URL: returns the resolved schedule (times and values). | `device_controller.py`: `ScheduleController.fetch_schedule()`; `automate.py`, `automate_www.py`: fetching schedule. |

---

## P1 meter

| Key | Type | Description | Where used |
|-----|------|-------------|------------|
| **p1Meter** | object | P1 meter connection and JSON path. | `device_controller.py`: `DeviceDataReader`. |
| **p1Meter.ip** | string | IP (or host) of the P1 meter / gateway. | Used to build the P1 API URL. |
| **p1Meter.endpoint** | string | HTTP path for the P1 data (e.g. `"/api/v1/data"` or `"/properties/report"`). | Appended to `http://<ip><endpoint>` for reading. |
| **p1Meter.totalPowerPath** | string | Dot-notation path to total power in the P1 JSON (e.g. `"active_power_w"` or `"total_power"`). | Used to read the power value from the P1 response. |

*Legacy: a top-level **p1MeterIp** string is still supported if `p1Meter` is missing or has no `ip`.*
