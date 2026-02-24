# Automation Scripts for Zendure Battery

This directory contains Python scripts for automating the control of a Zendure SuperBase V battery system. The scripts work together to read a charge/discharge schedule from a web API and apply the corresponding power settings to the battery.

## Files

### `device_controller.py`

This module provides object-oriented wrappers for interacting with the Zendure battery and related devices (like a P1 meter). It abstracts the low-level API calls into a set of reusable classes.

- **`BaseDeviceController`**: A base class that handles common tasks like loading the `config.json` file and logging.

- **`PowerAccumulator`**: A standalone class that tracks energy (watt-hours) for both power feed and P1 meter readings across multiple time periods (quarter-hour, hour, day, and manual). Automatically handles period boundary crossings and rollovers.

- **`AutomateController`**: The main class for controlling the Zendure battery's power settings. It can:
    - Set specific charge or discharge power levels.
    - Implement a "net-zero" feed-in mode, where the battery charges or discharges to keep the grid power usage close to zero.
    - Implement "net-zero+" mode, which only charges (never discharges to the grid).
    - Respect battery charge level limits (e.g., not charging above 90% or discharging below 20%).
    - Use `PowerAccumulator` to track and log power usage over time.
    - Put the device into standby mode when appropriate.

- **`DeviceDataReader`**: A class for reading data from:
    - A P1 meter (to get real-time grid power information).
    - The Zendure battery itself (to get its current state, like charge level).
    - Automatically stores readings via API endpoints for historical tracking.

- **`ScheduleController`**: A class responsible for fetching a charge/discharge schedule from a web API. It determines the desired power setting for the current time based on this schedule, with caching support to minimize API calls.

For detailed API documentation, see [device-controller.md](device-controller.md).

### `automate_www.py`

This is the active automation script with a built-in HTTP API:

- Exposes an HTTP API on port 1611 for monitoring (P1, Zendure, status, Wh-per-hour) and remote refresh.
- Stores status updates in SQLite (`data/status_updates.db`) instead of POSTing to an external status API.
- Uses a slightly different loop interval default (20 seconds) and supports power step delta limiting.

For full documentation, see [automate-www.md](automate-www.md).

### Legacy note

Older documentation and deployments may refer to `automate.py`. In this repository, `automate_www.py` is the active runtime script.

## How it Works

1.  **Configuration**: The scripts read their configuration from a `config.json` file. This file must contain details like the IP addresses of the Zendure battery and P1 meter, and the URLs for the schedule and status APIs. The script looks for this file in `../config/config.json` or `./config/config.json`.

2.  **Scheduling**: An external web service provides a schedule in JSON format. The `ScheduleController` fetches this schedule. The schedule defines what the battery should be doing at different times of the day (e.g., charge at 1000W, discharge at 500W, or run in net-zero mode).

3.  **Execution**: The `automate_www.py` script runs continuously. It periodically checks the schedule and determines the correct power setting for the current time.

4.  **Control**: Using the `AutomateController`, the script sends the appropriate commands to the Zendure battery to set its charge or discharge rate.

5.  **Monitoring**: The script sends status updates to a monitoring API, which can be used to track the automation's health and activity.

6.  **Power Accumulation**: The `PowerAccumulator` tracks energy usage over time, maintaining separate counters for quarter-hourly, hourly, daily, and manual periods.

## Usage

To run the automation, execute `automate_www.py`:

```bash
python3 /path/to/your/project/automate/automate_www.py
```

The script will run in the foreground. To run it as a background service, you can use a process manager like `systemd` or `supervisor`.

### Keyboard Commands

While the script is running, you can interact with it using keyboard commands:

- **`h` or `help`**: Show available commands
- **`s` or `status`**: Show current status (power, battery, schedule)
- **`a` or `accumulators`**: Print accumulator status (power feed and P1 meter energy tracking)
- **`r` or `refresh`**: Force refresh schedule from API
- **`p <value>`**: Set power manually (e.g., `p 500` or `p netzero`)
- **`z` or `zero`**: Set power to 0
- **`nz` or `netzero`**: Set power to netzero mode
- **`nzp` or `netzero+`**: Set power to netzero+ mode
- **`q` or `quit`**: Quit gracefully

## Power Control Logic & Behavior

The `AutomateController` handles various power setting scenarios with specific behaviors:

### 1. Manual Power Setting (Specific Value)

When a specific integer power value is set (e.g., via schedule or manual command):

*   **Charge (> 0)**:
    *   **Device Command:** `{"acMode": 1, "inputLimit": <value>, "outputLimit": 0, "smartMode": 1}`
    *   **Result:** Device switches to **Input Mode** (Charge).
*   **Discharge (< 0)**:
    *   **Device Command:** `{"acMode": 2, "inputLimit": 0, "outputLimit": <abs(value)>, "smartMode": 1}`
    *   **Result:** Device switches to **Output Mode** (Discharge).
*   **Stop (0)**:
    *   **Device Command:** `{"acMode": 0, "inputLimit": 0, "outputLimit": 0, "smartMode": 1}`
    *   **Result:** Device switches to **Standby Mode** (`acMode: 0`).

### 2. NetZero & NetZero+ Modes

In **NetZero** modes, the system dynamically calculates the required power based on the P1 meter reading.

*   **Calculated Power > 0 (Charge)**:
    *   **Device Command:** `{"acMode": 1, "inputLimit": <value>, "outputLimit": 0, "smartMode": 1}`
*   **Calculated Power < 0 (Discharge)**:
    *   **Device Command:** `{"acMode": 2, "inputLimit": 0, "outputLimit": <abs(value)>, "smartMode": 1}`
*   **Calculated Power is 0 or very low (Deadband)**:
    *   When the calculated power is exactly 0 or within the small "deadband" (e.g., -10W where threshold is 30W), the system targets 0W.
    *   **Device Command:** `{"inputLimit": 0, "outputLimit": 0, "smartMode": 1}`
    *   **Critical Detail:** **`acMode` is NOT included** in the payload. This is intentional to prevent the device from switching to Standby Mode (acMode 0), allowing it to stay in its current mode (e.g., Output) but with 0 limits, which often results in a smoother response when power is needed again.

**NetZero+ Mode**: Similar to NetZero, but the battery will only charge (never discharge to the grid). If the calculation indicates discharge is needed, the system sets power to 0 instead.

### 3. Charging Limits (Battery Protection)

When the battery reaches its **Maximum Charge Level** (e.g., 90%):

*   **Scenario A: NetZero Mode (Calculated Charge) or NetZero+**:
    *   Logic detects that charging is required but not permitted due to the limit.
    *   **Action:** The system forces the power setpoint to `0`.
    *   **Device Command:** `{"acMode": 0, "inputLimit": 0, "outputLimit": 0, "smartMode": 1}`
    *   **Result:** Device enters **Standby Mode** (`acMode: 0`).
*   **Scenario B: NetZero Mode (Calculated Discharge)**:
    *   Logic allows discharge even if battery is full (unless minimum limit is reached).
    *   **Result:** Functionality continues normally (`acMode: 2`).

When the battery reaches its **Minimum Charge Level** (e.g., 20%):

*   **Scenario A: Any Mode (Calculated Discharge)**:
    *   Logic detects that discharging is required but not permitted due to the limit.
    *   **Action:** The system forces the power setpoint to `0`.
    *   **Result:** Device stops discharging to protect battery.

### 4. Standby Mode

When the device has been at 0 power for a configurable number of consecutive iterations (default: 10), the system automatically puts the device into standby mode using a sequence: 1W → 2s sleep → 0W. This ensures the device properly enters standby state.

## API Calls Used by `automate_www.py`

When `automate_www.py` runs, it (and the `device_controller.py` components it uses) performs the following API calls. Config keys refer to `config.json` unless noted.

### 1. Schedule API (GET)

- Config key: `apiUrl`
- Method: `GET`
- Used by: `ScheduleController.fetch_schedule()` in `device_controller.py`
- Purpose: fetch the charge/discharge schedule (resolved time slots) that defines target power by time.
- When: on startup, every N minutes (default 5; `API_REFRESH_INTERVAL_SECONDS`), and on `refresh` keyboard command.
- Expected response: JSON with `success`, `resolved` (array of `{time, value, key}` entries).

### 2. Automation status API (POST)

- Config key: `statusApiUrl` (or `statusApiUrl-local` when `location` is `"local"`)
- Method: `POST`
- Used by: `StatusApi.post_update()` in `automate_www.py`
- Purpose: report automation lifecycle and power changes so the schedule UI can show current status (running, last power, P1 power).
- When: on start (`type: 'start'`), stop (`type: 'stop'`), power change (`type: 'change'`), and schedule rescan (`type: 'Rescan'`).
- Payload: `type`, `timestamp`, `oldValue`, `newValue`; optionally `p1TotalPower` (W).

### 3. Zendure device – read (GET)

- URL: `http://{deviceIp}/properties/report`
- Config key: `deviceIp`
- Method: `GET`
- Used by: `DeviceDataReader.read_zendure_data()` in `device_controller.py`
- Purpose: read current device state (charge level, input/output limits, etc.) for control logic, net-zero calculation, and status display.
- When: every loop iteration (interval set by `LOOP_INTERVAL_SECONDS`).

### 4. Zendure device – write (POST)

- URL: `http://{deviceIp}/properties/write`
- Config keys: `deviceIp`, `deviceSn`
- Method: `POST`
- Used by: `AutomateController.set_power_feed()` in `device_controller.py`
- Purpose: apply charge/discharge power by writing `acMode`, `inputLimit`, `outputLimit`, `smartMode` to the device.
- When: only when desired power differs from current setting (schedule change, manual command, or net-zero update).
- Payload: `{"sn": "<deviceSn>", "properties": { ... }}` (see Power Control Logic above).

### 5. P1 meter (GET)

- URL: `http://{p1Meter.ip}{p1Meter.endpoint}` (example: `http://192.168.2.5/api/v1/data` or `.../properties/report`)
- Config: `p1Meter` object (`ip`, `endpoint`, `totalPowerPath`) or legacy `p1MeterIp` (endpoint defaults to `/properties/report`)
- Method: `GET`
- Used by: `DeviceDataReader.read_p1_meter()` in `device_controller.py`
- Purpose: get current grid power (and optional cumulative kWh) for net-zero calculation and for attaching `p1TotalPower` to status updates.
- When: every loop iteration when P1 is configured.

### 6. Data API – store P1 readings (POST)

- URL: `{dataApiUrl}` or `{dataApiUrl-local}` with `?type=zendure_p1`
- Config key: `dataApiUrl` or `dataApiUrl-local` (chosen by `location`)
- Method: `POST`
- Used by: `DeviceDataReader.read_p1_meter()` -> `_store_data_via_api()` in `device_controller.py`
- Purpose: persist P1 meter readings for historical tracking.
- When: after each successful P1 read when the data API URL is set.

### 7. Data API – store Zendure readings (POST)

- URL: `{dataApiUrl}` or `{dataApiUrl-local}` with `?type=zendure`
- Config key: `dataApiUrl` or `dataApiUrl-local` (chosen by `location`)
- Method: `POST`
- Used by: `DeviceDataReader.read_zendure_data()` -> `_store_data_via_api()` in `device_controller.py`
- Purpose: persist Zendure device snapshots for historical tracking.
- When: after each successful Zendure read when `update_json=True` and the data API URL is set.

### Summary

1. Schedule: `GET` via `apiUrl` to fetch charge/discharge schedule.
2. Automation status: `POST` via `statusApiUrl` / `statusApiUrl-local` to report start/stop/change/rescan.
3. Zendure read: `GET` on `http://{deviceIp}/properties/report` for device state.
4. Zendure write: `POST` on `http://{deviceIp}/properties/write` to set charge/discharge power.
5. P1 meter: `GET` via `p1Meter.ip` + `p1Meter.endpoint` for grid power.
6. Data API (P1): `POST` via `dataApiUrl?type=zendure_p1` to store P1 readings.
7. Data API (Zendure): `POST` via `dataApiUrl?type=zendure` to store Zendure snapshots.
