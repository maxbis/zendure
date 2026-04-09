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
| **SLOW_CHARGE_START_LEVEL** | number | Optional SoC threshold (%), 0–100, where dynamic charging starts being capped near full battery. Only applies to dynamic modes (`netzero`, `netzero+`). | `device_controller.py`: `BaseDeviceController` (`slow_charge_start_level`), applied in `_calculate_new_settings()`. |
| **SLOW_CHARGE_MAX_POWER** | number | Optional maximum dynamic charge power (W) once `SLOW_CHARGE_START_LEVEL` is reached. Disabled unless both slow-charge keys are present and valid. Explicit fixed power commands are not affected. | `device_controller.py`: `BaseDeviceController` (`slow_charge_max_power`), applied in `_calculate_new_settings()`. |

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
| **NETZERO_TARGET_W** | number | Signed grid target in watts for dynamic modes. `0` preserves the current exact-netzero target, negative values prefer slight export, and positive values prefer slight import. Dynamic calculation uses `adjusted_p1_power = p1_power - NETZERO_TARGET_W`. | `device_controller.py`: `AutomateController.calculate_netzero_power()`. |
| **POWER_FEED_MIN_THRESHOLD** | number | Minimum absolute power (W). If desired power is below this, it is treated as 0. | `device_controller.py`: `ZendureDeviceController`. |
| **POWER_FEED_MIN_DELTA** | number | Minimum change (W) to apply a new power limit; smaller deltas are ignored. | `device_controller.py`: `ZendureDeviceController`. |
| **POWER_FEED_MAX_DELTA** | number | Maximum allowed step (W) for a single power-feed change. | `automate_www.py`: main loop when applying power feed updates. |

---

## Loop timing

| Key | Type | Description | Where used |
|-----|------|-------------|------------|
| **LOOP_INTERVAL_SECONDS** | number | Fallback seconds between main automation loop iterations when the selected power meter does not define its own interval. | `automate_www.py`: main loop sleep interval. |
| **FAST_LOOP_INTERVAL_SECONDS** | number | Optional shorter sleep interval used after a control run when `POWER_FEED_MAX_DELTA` or the reversal ramp guard constrained the requested power. Default `2`; clamped to `1..10`. | `automate_www.py`: adaptive fast-loop retry cadence; `automate_mqtt.py`: same adaptive fast-loop behavior. |
| **API_REFRESH_INTERVAL_SECONDS** | number | How often (seconds) to refresh data from external APIs (e.g. schedule) within the loop. | `automate_www.py`: throttles API calls. |

---

## Paths and retention

| Key | Type | Description | Where used |
|-----|------|-------------|------------|
| **dataDir** | string | Directory for local data (e.g. `status_updates.db`). Default `"./data/"`. | `automate_www.py`: path for status-updates DB and retention; `dump_status_updates.py`: DB path when loading from config. |
| **statusUpdatesRetentionDays** | number | Days to keep rows in the status_updates SQLite DB; older rows are pruned by periodic in-process cleanup. Exact deletion timing is approximate because cleanup is loop-gated instead of running on every insert. | `automate_www.py`, `automate_mqtt.py`: retention cleanup via the shared store. |

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

### Optional MQTT overlay for `automate_mqtt.py`

`automate_www.py` continues using the normal configured `powerMeter` reader. The copied `automate_mqtt.py` can optionally subscribe to Shelly MQTT messages and only fall back to the existing HTTP reader when MQTT is stale or disabled.

| Key | Type | Description | Where used |
|-----|------|-------------|------------|
| **mqttPowerMeter** | object | Optional MQTT settings block used only by `automate_mqtt.py`. If omitted or disabled, `automate_mqtt.py` behaves like HTTP-only power meter reading. | `automate_mqtt.py`, `power_meter_mqtt_subscriber.py` |
| **mqttPowerMeter.enabled** | boolean | Enable the MQTT subscriber/cache. Default `false`. | `automate_mqtt.py`: start/skip MQTT helper. |
| **mqttPowerMeter.brokerHost** | string | MQTT broker host or IP. Required when MQTT is enabled. | `power_meter_mqtt_subscriber.py`: broker connection. |
| **mqttPowerMeter.brokerPort** | number | MQTT broker TCP port. Default `1883`. | `power_meter_mqtt_subscriber.py`: broker connection. |
| **mqttPowerMeter.username** | string | Optional broker username. | `power_meter_mqtt_subscriber.py`: MQTT authentication. |
| **mqttPowerMeter.password** | string | Optional broker password. | `power_meter_mqtt_subscriber.py`: MQTT authentication. |
| **mqttPowerMeter.topic** | string | Topic carrying the Shelly status payload to consume (for example `"shellypro3em-841fe890decc/status/em:0"`). Required when MQTT is enabled. | `power_meter_mqtt_subscriber.py`: subscription topic. |
| **mqttPowerMeter.totalPowerPath** | string | Dot-notation path to total power within the MQTT JSON payload. Default `"total_act_power"`. | `power_meter_mqtt_subscriber.py`: payload parsing. |
| **mqttPowerMeter.staleAfterSeconds** | number | Max allowed age for the latest MQTT message before `automate_mqtt.py` falls back to the normal HTTP read. Default `55`. | `automate_mqtt.py`: stale detection / HTTP fallback. |
| **mqttPowerMeter.periodicControlIntervalSeconds** | number | Run the full control pipeline at least this often when MQTT is fresh and no thresholded MQTT change triggered a run. Default `60`. Schedule-slot boundary changes can still trigger an earlier control pass. | `automate_mqtt.py`: periodic housekeeping control fallback. |
| **mqttPowerMeter.changeThresholdWatts** | number | Minimum absolute change in MQTT power before a power-change event is raised. Default `0`. | `power_meter_mqtt_subscriber.py`: event generation. |

### MQTT Shelly cumulative counters

When Shelly MQTT payloads also include cumulative energy counters such as `total_act` and `total_act_ret`, `automate_mqtt.py` caches the latest valid values and attaches them to every stored status event. They are stored internally in SQLite as scaled integer columns (`total_act_x100`, `total_act_ret_x100`) and exposed through `/api/status_updates_delta` as decimal `total_act` and `total_act_ret` fields.
