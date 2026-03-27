# Automate config reference

This file documents every key in `config.jsonc`. The config is used only by the **automate** scripts (primarily `automate_www.py`, plus `device_controller.py` and `dump_status_updates.py`).

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
| **TEST_MODE** | boolean | If `true`, the automation runs in test mode: power/charge actions are simulated and not applied to the device. | `device_controller.py` (`BaseDeviceController`): sets `test_mode` at init; Zendure and P1 logic respect it. Startup messages in `automate_www.py` show test mode. |
| **LOG_LEVEL** | string | Minimum log severity to emit. Supported values: `DEBUG`, `INFO`, `WARNING`, `ERROR` (default `INFO`). | `device_controller.py` (`BaseDeviceController.log`): filters all controller/app logs; `SUCCESS` is treated as `INFO`. |
| **deviceIp** | string | IP address of the Zendure device on the local network. | `device_controller.py`: `ZendureDeviceController` (device control) and `DeviceDataReader` (device data + P1 meter API base). Required. |
| **deviceSn** | string | Serial number of the Zendure device. | `device_controller.py`: `ZendureDeviceController` only. Required. |

---

## Battery SoC limits

| Key | Type | Description | Where used |
|-----|------|-------------|------------|
| **MIN_CHARGE_LEVEL** | number | Minimum state-of-charge (%), 0–100. Below this, discharge is prevented. | `device_controller.py`: `BaseDeviceController` (`min_charge_level`). Used in `automate_www.py` when deciding charge/discharge limits. |
| **MAX_CHARGE_LEVEL** | number | Maximum state-of-charge (%), 0–100. Above this, charge is prevented. | Same as `MIN_CHARGE_LEVEL`. |

---

## Power caps

| Key | Type | Description | Where used |
|-----|------|-------------|------------|
| **MAX_DISCHARGE_POWER** | number | Maximum allowed discharge power in watts. Outgoing negative power-feed commands are clamped to this limit. | `device_controller.py`: `BaseDeviceController` (`max_discharge_power`) and `ZendureDeviceController.send_power_feed()`. |
| **MAX_CHARGE_POWER** | number | Maximum allowed charge power in watts. Outgoing positive power-feed commands are clamped to this limit. | Same as `MAX_DISCHARGE_POWER`. |

---

## Power feed tuning

| Key | Type | Description | Where used |
|-----|------|-------------|------------|
| **NETZERO_BI_DIRECTIONAL** | boolean | Controls whether `netzero` may actively charge as well as discharge. `false` preserves discharge-only behavior; `true` allows bidirectional netzero control. | `device_controller.py`: `AutomateController.calculate_netzero_power()`. |
| **POWER_FEED_MIN_THRESHOLD** | number | Minimum absolute power (W). If desired power is below this, it is treated as 0. | `device_controller.py`: `ZendureDeviceController`. |
| **POWER_FEED_MIN_DELTA** | number | Minimum change (W) to apply a new power limit; smaller deltas are ignored. | `device_controller.py`: `ZendureDeviceController`. |
| **POWER_FEED_MAX_DELTA** | number | Maximum allowed step (W) for a single power-feed change. | `automate_www.py`: main loop when applying power feed updates. |

---

## Loop timing

| Key | Type | Description | Where used |
|-----|------|-------------|------------|
| **LOOP_INTERVAL_SECONDS** | number | Fallback seconds between main automation loop iterations when the selected power meter does not define its own interval. | `automate_www.py`: main loop sleep interval. |
| **API_REFRESH_INTERVAL_SECONDS** | number | How often (seconds) to refresh data from external APIs (e.g. schedule) within the loop. | `automate_www.py`: throttles API calls. |

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
| **apiUrl** | string | Schedule API URL: returns the resolved schedule (times and values). | `device_controller.py`: `ScheduleController.fetch_schedule()`; `automate_www.py`: fetching schedule. |

---

## Power meter

| Key | Type | Description | Where used |
|-----|------|-------------|------------|
| **powerMeter** | object | Required power meter configuration block. | `power_metere_loader.py`: selects the concrete reader module. |
| **powerMeter.type** | string | Power meter type selector. The value must match a module suffix in `power_metere_<identifier>.py` (for example `"p1_hw"` or `"shelly"`). | `power_metere_loader.py`: `get_power_meter_reader()`. |
| **powerMeter.p1_hw** | object | P1 hardware meter connection and JSON path. Required when `powerMeter.type` is `"p1_hw"`. | `power_metere_p1_hw.py`: `P1PowerMeterReader`. |
| **powerMeter.p1_hw.ip** | string | IP (or host) of the P1 meter / gateway. | Used to build the P1 API URL. |
| **powerMeter.p1_hw.endpoint** | string | HTTP path for the P1 data (e.g. `"/api/v1/data"` or `"/properties/report"`). Defaults to `"/properties/report"` when omitted. | Appended to `http://<ip><endpoint>` for reading. |
| **powerMeter.p1_hw.totalPowerPath** | string | Dot-notation path to total power in the P1 JSON (e.g. `"active_power_w"` or `"total_power"`). Defaults to `"total_power"` when omitted. | Used to read the power value from the P1 response. |
| **powerMeter.p1_hw.loopIntervalSeconds** | number | Optional loop interval override used when `powerMeter.type` is `"p1_hw"`. | `automate_www.py`: `_load_loop_config()`. |
| **powerMeter.shelly** | object | Shelly meter connection and JSON path. | `power_metere_shelly.py`: `ShellyPowerMeterReader`. |
| **powerMeter.shelly.ip** | string | IP (or host) of the Shelly meter. | Used to build the Shelly API URL. |
| **powerMeter.shelly.endpoint** | string | HTTP path for the Shelly data (for example `"/rpc/EM.GetStatus?id=0"`). Defaults to `"/properties/report"` when omitted. | Appended to `http://<ip><endpoint>` for reading. |
| **powerMeter.shelly.totalPowerPath** | string | Dot-notation path to total power in the Shelly JSON (for example `"total_act_power"`). Defaults to `"total_power"` when omitted. | Used to read the power value from the Shelly response. |
| **powerMeter.shelly.loopIntervalSeconds** | number | Optional loop interval override used when `powerMeter.type` is `"shelly"`. | `automate_www.py`: `_load_loop_config()`. |
