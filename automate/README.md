# Automation Scripts for Zendure Battery

This directory contains Python scripts for automating the control of a Zendure SuperBase V battery system. The scripts work together to read a charge/discharge schedule from a web API and apply the corresponding power settings to the battery.

## Files

### `device_controller.py`

This module provides object-oriented wrappers for interacting with the Zendure battery and related devices (like a P1 meter). It abstracts the low-level API calls into a set of reusable classes.

- **`BaseDeviceController`**: A base class that handles common tasks like loading the `config.json` file and logging.

- **`AutomateController`**: The main class for controlling the Zendure battery's power settings. It can:
    - Set specific charge or discharge power levels.
    - Implement a "net-zero" feed-in mode, where the battery charges or discharges to keep the grid power usage close to zero.
    - Respect battery charge level limits (e.g., not charging above 95% or discharging below 20%).
    - Accumulate and log power usage over time.

- **`DeviceDataReader`**: A class for reading data from:
    - A P1 meter (to get real-time grid power information).
    - The Zendure battery itself (to get its current state, like charge level).

- **`ScheduleController`**: A class responsible for fetching a charge/discharge schedule from a web API. It determines the desired power setting for the current time based on this schedule.

### `automate2.py`

This is the main automation script that runs continuously to control the battery based on the schedule.

- It runs in an infinite loop.
- In each iteration, it uses `ScheduleController` to fetch the desired power setting from the schedule API.
- It then uses `AutomateController` to apply this power setting to the Zendure battery.
- The script refreshes the schedule data periodically (e.g., every 5 minutes).
- It includes signal handling for graceful shutdown (e.g., when you press `Ctrl+C`), ensuring that it can set the battery to a safe state (power off) before exiting.
- It posts status updates (start, stop, power changes) to a web API to monitor the automation's status.

## How it Works

1.  **Configuration**: The scripts read their configuration from a `config.json` file. This file must contain details like the IP addresses of the Zendure battery and P1 meter, and the URLs for the schedule and status APIs. The script looks for this file in `../config/config.json` or `./config/config.json`.

2.  **Scheduling**: An external web service provides a schedule in JSON format. The `ScheduleController` fetches this schedule. The schedule defines what the battery should be doing at different times of the day (e.g., charge at 1000W, discharge at 500W, or run in net-zero mode).

3.  **Execution**: The `automate2.py` script runs continuously. It periodically checks the schedule and determines the correct power setting for the current time.

4.  **Control**: Using the `AutomateController`, the script sends the appropriate commands to the Zendure battery to set its charge or discharge rate.

5.  **Monitoring**: The script sends status updates to a monitoring API, which can be used to track the automation's health and activity.

## Usage

To run the automation, simply execute the `automate2.py` script:

```bash
python3 /path/to/your/project/automate/automate2.py
```

The script will run in the foreground. To run it as a background service, you can use a process manager like `systemd` or `supervisor`.

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

### 3. Charging Limits (Battery Protection)

When the battery reaches its **Maximum Charge Level** (e.g., 95%):

*   **Scenario A: NetZero Mode (Calculated Charge) or NetZero+**:
    *   Logic detects that charging is required but not permitted due to the limit.
    *   **Action:** The system forces the power setpoint to `0`.
    *   **Device Command:** `{"acMode": 0, "inputLimit": 0, "outputLimit": 0, "smartMode": 1}`
    *   **Result:** Device enters **Standby Mode** (`acMode: 0`).
*   **Scenario B: NetZero Mode (Calculated Discharge)**:
    *   Logic allows discharge even if battery is full (unless minimum limit is reached).
    *   **Result:** Functionality continues normally (`acMode: 2`).

