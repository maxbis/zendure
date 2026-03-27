# `device_controller.py` Documentation

This file provides a set of Python classes designed to interact with and control a Zendure SuperBase V battery system and associated web APIs for scheduling. Power-meter access is handled separately through the config-driven loader in `power_metere_loader.py`.

## Global Constants

-   `TEST_MODE` (bool): Loaded from `automate/config/config.jsonc` (key: `"TEST_MODE"`). If `True`, control operations are simulated (logged) but not actually sent to the device. Defaults to `False` if the key is missing.
-   `MIN_CHARGE_LEVEL` (int): Config-driven minimum battery percentage below which the system will stop discharging. Falls back to `20` if the key is missing.
-   `MAX_CHARGE_LEVEL` (int): Config-driven maximum battery percentage above which the system will stop charging. Falls back to `90` if the key is missing.
-   `MAX_DISCHARGE_POWER` (int): Config-driven maximum allowed power feed in watts for discharge. Falls back to `800` if the key is missing.
-   `MAX_CHARGE_POWER` (int): Config-driven maximum allowed power feed in watts for charge. Falls back to `1200` if the key is missing.

## `PowerResult` Data Class

A simple data class used to return the result of a power-setting operation.

-   `success` (bool): `True` if the operation was successful, `False` otherwise.
-   `power` (int): The power level that was set or attempted.
-   `error` (Optional[str]): An error message if the operation failed.

---

## `BaseDeviceController`

This is the foundational class that provides common functionality to all other controller classes.

### `__init__(self, config_path: Optional[Path] = None)`

-   **Description**: Initializes the controller. It finds and loads `automate/config/config.jsonc`.
-   **Arguments**:
    -   `config_path` (Optional): An explicit path to the configuration file. If not provided, it uses `automate/config/config.jsonc`.
-   **Raises**: `FileNotFoundError` if the config file cannot be found. `ValueError` if the JSON is invalid.

### `_find_config_file(self) -> Path`

-   **Description**: A helper method that locates `automate/config/config.jsonc`.
-   **Returns**: The `Path` to the found configuration file.

### `_load_config(self, config_path: Path) -> Dict[str, Any]`

-   **Description**: Loads and parses the specified JSONC configuration file.
-   **Returns**: A dictionary containing the configuration settings.

### `log(self, level: str, message: str, include_timestamp: bool = True, file_path: str = None)`

-   **Description**: A flexible logging method that prints messages to the console with timestamps and level-based emojis. It can also write logs to a file. Automatically writes all error-level messages to `log/error.log`.
-   **Arguments**:
    -   `level` (str): The log level (e.g., 'info', 'debug', 'warning', 'error', 'success').
    -   `message` (str): The message to log.
    -   `include_timestamp` (bool): If `True`, include timestamp in log output. Defaults to `True`.
    -   `file_path` (str, Optional): If provided, the log message is appended to this file.

---

## `PowerAccumulator`

A standalone class that handles accumulation and persistence of power/energy related values.
- Accumulates **power feed** energy (watt-hours) over quarter-hour, hour, day, and manual periods.

### `__init__(self, logger=None, log_file_path=None)`

-   **Description**: Initializes the PowerAccumulator for power feed accumulation.
-   **Arguments**:
    -   `logger`: Optional logger object with `log()` method (for logging accumulation events).
    -   `log_file_path`: Optional path to log file for accumulation logs.

### `accumulate_power_feed(self, power_feed: int) -> None`

-   **Description**: Accumulates power feed energy over time into four separate accumulators. Tracks energy (watt-hours) accumulated over quarter-hour periods (resets at 0, 15, 30, 45 minutes), hourly periods (resets at full hour), daily periods (resets at midnight), and a manual accumulator (only resets when explicitly set to 0).
-   **Arguments**:
    -   `power_feed` (int): Power feed value in watts (signed: positive=charge, negative=discharge).

## `AutomateController`

Inherits from `BaseDeviceController`. This class is responsible for the core logic of controlling the Zendure battery's power.

### `__init__(self, config_path: Optional[Path] = None)`

-   **Description**: Initializes the controller, validates that `deviceIp` and `deviceSn` are present in the config, and sets up initial state for power accumulation. Creates a `PowerAccumulator` instance for tracking energy usage.
-   **Raises**: `ValueError` if required config keys are missing.

### `_build_device_properties(self, power_feed: int, stand_by: bool = False) -> Dict[str, Any]`

-   **Description**: Constructs the JSON payload required by the Zendure API to set the battery's mode (charge/discharge) and power limits.
-   **Arguments**:
    -   `power_feed` (int): The desired power in watts. Positive values mean charge, negative values mean discharge, 0 to stop.
    -   `stand_by` (bool): If `True`, forces the device into standby mode (acMode: 0). Defaults to `False`.
-   **Returns**: A dictionary with `acMode`, `inputLimit`, `outputLimit`, and `smartMode` properties.

### `check_battery_limits(self) -> None`

-   **Description**: Reads the current battery level from the Zendure device and updates the internal `limit_state` property. This state is used to prevent charging a full battery or discharging an empty one. Sets `limit_state` to `-1` (MIN), `0` (OK), or `1` (MAX).

### `_send_power_feed(self, power_feed: int) -> Tuple[bool, Optional[str], int]`

-   **Description**: Sends the final, calculated power command to the Zendure device via an HTTP POST request. It respects the `TEST_MODE` flag, applies battery limits, and clamps power values to `MAX_DISCHARGE_POWER` and `MAX_CHARGE_POWER`. Automatically accumulates power feed energy via the `PowerAccumulator`.
-   **Returns**: A tuple containing `(success: bool, error_message: Optional[str], actual_power: int)`. The `actual_power` is the power value that was actually sent (after limiting/modifications).

### `_calculate_new_settings(self, p1_power: int, current_input: int, current_output: int, electric_level: Optional[int]) -> Tuple[int, int]`

-   **Description**: This is the core logic for the "net-zero" algorithm. It takes the current grid power (from the P1 meter) and the battery's current settings to calculate the new charge/discharge rate needed to bring the grid feed to zero. It includes thresholds to prevent rapid, small adjustments and respects battery level limits.
-   **Arguments**:
    -   `p1_power` (int): P1 meter power reading (grid status).
    -   `current_input` (int): Current input limit (charge).
    -   `current_output` (int): Current output limit (discharge).
    -   `electric_level` (Optional[int]): Current battery level (%).
-   **Returns**: A tuple of `(new_input, new_output)` power values in watts.

### `calculate_netzero_power(self, mode: Literal['netzero', 'netzero+'] = 'netzero', p1_data: Optional[Dict[str, Any]] = None) -> int`

-   **Description**: Orchestrates the net-zero calculation by using caller-supplied P1 meter data plus the latest Zendure device state, then calculating what power setting is needed to achieve zero feed-in.
-   **Arguments**:
    -   `mode` ('netzero' or 'netzero+'): In 'netzero' mode, the battery only discharges to reduce grid import. In 'netzero+' mode, it only charges to reduce grid export. Defaults to 'netzero'.
    -   `p1_data` (Optional[Dict[str, Any]]): Required normalized P1 meter data supplied by the caller for dynamic power modes. Must contain `total_power`.
-   **Returns**: The calculated power value to set (positive for charge, negative for discharge, 0 for stop).
-   **Raises**: `ValueError` if caller-supplied P1 meter data is missing/invalid or Zendure data cannot be read. `requests.exceptions.RequestException` on network errors.

### Runtime `min_power` / `max_power`

When the active resolved schedule slot includes `min_power` or `max_power`, `AutomateController` applies them after the raw dynamic result is calculated:

- Bounds are applied as a direct signed clamp
- Missing or `null` bounds leave the runtime behavior unchanged
- Negative values represent discharge
- Positive values represent charge
- If both bounds are present, runtime clamps into the inclusive signed range `[min_power, max_power]`

`ReversalRampGuard` still wins during true sign reversals. When the guard changes the raw result, signed-bound handling is deferred for that cycle and resumes once the reversal settles.

### `set_power(self, value: Union[int, Literal['netzero', 'netzero+'], None] = 'netzero', p1_data: Optional[Dict[str, Any]] = None) -> PowerResult`

-   **Description**: The main public method for setting the battery's power. It can accept a specific integer power value or a dynamic mode like 'netzero'.
-   **Arguments**:
    -   `value`: An integer power value (in watts, positive=charge, negative=discharge, 0=stop), 'netzero', 'netzero+', or `None`. If `None`, defaults to 'netzero'.
    -   `p1_data` (Optional[Dict[str, Any]]): Required normalized P1 meter data when `value` is `netzero`, `netzero+`, or `None`.
-   **Returns**: A `PowerResult` object indicating the outcome.
-   **Raises**: `ValueError` if `value` is invalid.
-   **Note**: Test mode is controlled by `config.jsonc` key `"TEST_MODE"`. When enabled, operations are simulated but not applied.

During dynamic modes, `set_power()` also emits debug/info logs when:
- bounds are present on the active slot
- signed bounds clamp a dynamic result
- reversal protection defers bound handling
- battery or device limits override the bounded result

### `set_standby_mode(self) -> PowerResult`

-   **Description**: Puts the device into standby mode by executing a sequence: 1W → 2s sleep → 0W. This sequence is necessary to properly trigger the device's standby logic.
-   **Returns**: A `PowerResult` object indicating the outcome of the standby sequence.

---

## `DeviceDataReader`

Inherits from `BaseDeviceController`. This class is used to read data from the Zendure device.

### `__init__(self, config_path: Optional[Path] = None)`

-   **Description**: Initializes the DeviceDataReader. It finds and loads `automate/config/config.jsonc` and extracts the Zendure device IP from the configuration.
-   **Arguments**:
    -   `config_path` (Optional): An explicit path to the configuration file. If not provided, it uses the standard automate config location.
-   **Raises**: `FileNotFoundError` if the config file cannot be found. `ValueError` if the JSON is invalid.

### `_store_data_via_api(self, api_url: Optional[str], data: dict, data_type: str = "data") -> bool`

-   **Description**: A helper method that sends data (for example a Zendure state snapshot) to a specified web API endpoint for storage. This is used to keep a historical record of device states. Logs warnings on failure but does not raise exceptions.
-   **Arguments**:
    -   `api_url` (Optional[str]): API endpoint URL (from config). If `None`, operation is skipped.
    -   `data` (dict): Data dictionary to store.
    -   `data_type` (str): Type of data for logging (e.g., "Zendure data"). Defaults to "data".
-   **Returns**: `True` if storage was successful, `False` otherwise.

### `read_zendure(self, update_json: bool = True) -> Optional[dict]`

-   **Description**: Fetches the latest data from the Zendure battery via its local API.
-   **Arguments**:
    -   `update_json` (bool): If `True`, the new reading is also sent to the storage API. Defaults to `True`.
-   **Returns**: A dictionary with the Zendure device data (including `properties` and `packData`), or `None` on error.

---

## `ScheduleController`

Inherits from `BaseDeviceController`. This class is responsible for managing the charge/discharge schedule by fetching schedule data from a web API and determining the desired power setting for the current time.

### `__init__(self, config_path: Optional[Path] = None)`

-   **Description**: Initializes the controller. It finds and loads `automate/config/config.jsonc` and sets up for schedule management. The timezone is set to 'Europe/Amsterdam' for schedule calculations.
-   **Arguments**:
    -   `config_path` (Optional): An explicit path to the configuration file. If not provided, it uses the standard automate config location.
-   **Raises**: `FileNotFoundError` if the config file cannot be found. `ValueError` if the JSON is invalid.

### `fetch_schedule(self) -> Dict[str, Any]`

-   **Description**: Fetches the charge schedule from the configured web API and caches it in the class. The schedule data includes resolved entries with time and power values.
-   **Returns**: A dictionary containing the API response data with `success`, `resolved` entries, and `currentTime` or `currentHour`.
-   **Raises**: `ValueError` if API URL not found in config or API response is invalid. `requests.exceptions.RequestException` on network errors. `json.JSONDecodeError` on JSON parsing errors.

### `_find_current_schedule_value(self, resolved: List[Dict[str, Any]], current_time: str) -> Optional[Union[int, Literal['netzero', 'netzero+']]]`

-   **Description**: A helper method that parses the "resolved" schedule entries from the API and finds the correct power `value` for the current time. It finds the most recent schedule entry that is not in the future (largest time that is still <= current_time).
-   **Arguments**:
    -   `resolved` (List[Dict[str, Any]]): List of resolved schedule entries, each with 'time' and 'value' keys.
    -   `current_time` (str): Current time in "HHMM" format (e.g., "1811" or "2300").
-   **Returns**: The value from the matching entry (int, 'netzero', 'netzero+'), or `None` if no match found.
-   **Raises**: `ValueError` if `current_time` format is invalid.

The matching entry snapshot stored in `last_schedule_entry` also carries `key`, `runtime_conditions`, `fallback_value`, `min_power`, and `max_power` so the automation layer can apply runtime metadata without re-querying the API.

### `get_desired_power(self, refresh: bool = False) -> Optional[Union[int, Literal['netzero', 'netzero+']]]`

-   **Description**: The main public method that determines the desired power setting based on the schedule.
-   **Arguments**:
    -   `refresh` (bool): If `True`, it forces a new fetch from the schedule API. If `False`, it uses cached data from the last fetch. Defaults to `False`.
-   **Returns**: The desired power value, which can be an integer, 'netzero', 'netzero+', or `None`.
-   **Raises**: `ValueError` if schedule data is invalid or missing required fields. `requests.exceptions.RequestException` on network errors when `refresh=True`.
