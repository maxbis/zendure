# `device_controller.py` Documentation

This file provides a set of Python classes designed to interact with and control a Zendure SuperBase V battery system, a P1 smart meter, and associated web APIs for scheduling. It is structured using Object-Oriented principles to create a clear and reusable interface for automation tasks.

## Global Constants

-   `TEST_MODE` (bool): If `True`, control operations are simulated (logged to the console) but not actually sent to the device. Defaults to `True`.
-   `MIN_CHARGE_LEVEL` (int): The battery percentage below which the system will stop discharging.
-   `MAX_CHARGE_LEVEL` (int): The battery percentage above which the system will stop charging.

## `PowerResult` Data Class

A simple data class used to return the result of a power-setting operation.

-   `success` (bool): `True` if the operation was successful, `False` otherwise.
-   `power` (int): The power level that was set or attempted.
-   `error` (Optional[str]): An error message if the operation failed.

---

## `BaseDeviceController`

This is the foundational class that provides common functionality to all other controller classes.

### `__init__(self, config_path: Optional[Path] = None)`

-   **Description**: Initializes the controller. It finds and loads the `config.json` file.
-   **Arguments**:
    -   `config_path` (Optional): An explicit path to the configuration file. If not provided, it searches for `config.json` in the project's root `config/` directory and then in the local `automate/config/` directory.
-   **Raises**: `FileNotFoundError` if the config file cannot be found. `ValueError` if the JSON is invalid.

### `_find_config_file(self) -> Path`

-   **Description**: A helper method that locates the `config.json` file. It prioritizes the root directory's config file over the local one.
-   **Returns**: The `Path` to the found configuration file.

### `_load_config(self, config_path: Path) -> Dict[str, Any]`

-   **Description**: Loads and parses the specified JSON configuration file.
-   **Returns**: A dictionary containing the configuration settings.

### `log(self, level: str, message: str, ...)`

-   **Description**: A flexible logging method that prints messages to the console with timestamps and level-based emojis. It can also write logs to a file.
-   **Arguments**:
    -   `level` (str): The log level (e.g., 'info', 'debug', 'warning', 'error').
    -   `message` (str): The message to log.
    -   `file_path` (str, Optional): If provided, the log message is appended to this file.

---

## `AutomateController`

Inherits from `BaseDeviceController`. This class is responsible for the core logic of controlling the Zendure battery's power.

### `__init__(self, config_path: Optional[Path] = None)`

-   **Description**: Initializes the controller, validates that `deviceIp` and `deviceSn` are present in the config, and sets up initial state for power accumulation.

### `_build_device_properties(self, power_feed: int) -> Dict[str, Any]`

-   **Description**: Constructs the JSON payload required by the Zendure API to set the battery's mode (charge/discharge) and power limits.
-   **Arguments**:
    -   `power_feed` (int): The desired power in watts. Positive values mean charge, negative values mean discharge.

### `check_battery_limits(self) -> None`

-   **Description**: Reads the current battery level from the Zendure device and updates the internal `limit_state` property. This state is used to prevent charging a full battery or discharging an empty one.

### `_accumulate_power_feed(self, power_feed: int) -> None`

-   **Description**: Calculates and accumulates the energy (in Watt-hours) being fed to or from the battery. It maintains separate counters for quarter-hourly, hourly, daily, and manual periods, automatically handling rollovers.

### `print_accumulators(self) -> None`

-   **Description**: A debug method that logs the current values of all power accumulators in a human-readable format.

### `_send_power_feed(self, power_feed: int) -> Tuple[bool, Optional[str]]`

-   **Description**: Sends the final, calculated power command to the Zendure device via an HTTP POST request. It respects the `TEST_MODE` flag.
-   **Returns**: A tuple containing a success boolean and an optional error message.

### `_calculate_new_settings(...) -> Tuple[int, int]`

-   **Description**: This is the core logic for the "net-zero" algorithm. It takes the current grid power (from the P1 meter) and the battery's current settings to calculate the new charge/discharge rate needed to bring the grid feed to zero. It includes thresholds to prevent rapid, small adjustments.
-   **Returns**: A tuple of `(new_input, new_output)` power values in watts.

### `calculate_netzero_power(self, mode: Literal['netzero', 'netzero+']) -> int`

-   **Description**: Orchestrates the net-zero calculation by fetching the latest data from the P1 meter and the Zendure device.
-   **Arguments**:
    -   `mode` ('netzero' or 'netzero+'): In 'netzero' mode, the battery can charge or discharge. In 'netzero+' mode, it can only charge (it will not discharge to the grid).
-   **Returns**: The calculated power value to set (positive for charge, negative for discharge).

### `set_power(self, value: Union[int, Literal['netzero', 'netzero+'], None]) -> PowerResult`

-   **Description**: The main public method for setting the battery's power. It can accept a specific integer power value or a dynamic mode like 'netzero'.
-   **Arguments**:
    -   `value`: An integer power value (in watts), 'netzero', or 'netzero+'.
-   **Returns**: A `PowerResult` object indicating the outcome.

---

## `DeviceDataReader`

Inherits from `BaseDeviceController`. This class is used to read data from the P1 meter and the Zendure device.

### `_store_data_via_api(...) -> bool`

-   **Description**: A helper method that sends data (e.g., a new P1 reading) to a specified web API endpoint for storage. This is used to keep a historical record of device states.

### `read_p1_meter(self, update_json: bool = True) -> Optional[dict]`

-   **Description**: Fetches the latest data from the P1 meter via its local API.
-   **Arguments**:
    -   `update_json` (bool): If `True`, the new reading is also sent to the storage API.
-   **Returns**: A dictionary with the P1 meter data, or `None` on error.

### `read_zendure(self, update_json: bool = True) -> Optional[dict]`

-   **Description**: Fetches the latest data from the Zendure battery via its local API.
-   **Arguments**:
    -   `update_json` (bool): If `True`, the new reading is also sent to the storage API.
-   **Returns**: A dictionary with the Zendure device data, or `None` on error.

---

## `ScheduleController`

Inherits from `BaseDeviceController`. This class is responsible for managing the charge/discharge schedule.

### `__init__(self, config_path: Optional[Path] = None)`

-   **Description**: Initializes the controller and sets the timezone to 'Europe/Amsterdam'.

### `fetch_schedule(self) -> Dict[str, Any]`

-   **Description**: Fetches the charge schedule from the configured web API.
-   **Returns**: A dictionary containing the API response data.

### `_find_current_schedule_value(...) -> Optional[Union[int, str]]`

-   **Description**: A helper method that parses the "resolved" schedule entries from the API and finds the correct power `value` for the current time. It finds the most recent schedule entry that is not in the future.

### `get_desired_power(self, refresh: bool = False) -> Optional[Union[int, str]]`

-   **Description**: The main public method that determines the desired power setting based on the schedule.
-   **Arguments**:
    -   `refresh` (bool): If `True`, it forces a new fetch from the schedule API. If `False`, it uses cached data from the last fetch.
-   **Returns**: The desired power value, which can be an integer, 'netzero', 'netzero+', or `None`.
