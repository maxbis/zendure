#!/usr/bin/env python3
"""
Device Controller - OOP wrapper for Zendure battery control and data reading

This module provides object-oriented interfaces for controlling the Zendure
battery system and reading data from Zendure devices, based on the
functionality in zero_feed_in_controller.py.
"""

import json
import time

from dataclasses import dataclass
from datetime import datetime, date
from pathlib import Path
from typing import Optional, Tuple, Dict, Any, Union, Literal, List
from zoneinfo import ZoneInfo

import requests
from config_loader import load_config as load_config_json


# ============================================================================
# GLOBAL CONSTANTS
# ============================================================================

# NOTE: TEST_MODE is now configurable via config.jsonc (key: "TEST_MODE").
# This global remains for backward compatibility, but is overridden at runtime
# when BaseDeviceController loads the config.
TEST_MODE = False               # If True, operations are simulated but not applied
MIN_CHARGE_LEVEL = 20          # Legacy default min SoC (%) (config key: MIN_CHARGE_LEVEL)
MAX_CHARGE_LEVEL = 90          # Legacy default max SoC (%) (config key: MAX_CHARGE_LEVEL)
MAX_DISCHARGE_POWER = 1000      # Legacy default max discharge power (config key: MAX_DISCHARGE_POWER)
MAX_CHARGE_POWER = 1200        # Legacy default max charge power (config key: MAX_CHARGE_POWER)


# ============================================================================
# SHARED READER (SINGLETON)
# ============================================================================
_SHARED_DEVICE_DATA_READER = None
_SHARED_DEVICE_DATA_READER_CONFIG_PATH: Optional[Path] = None


def get_reader(config_path: Optional[Path] = None) -> "DeviceDataReader":
    """
    Return a shared, long-lived DeviceDataReader instance.

    This avoids re-loading/parsing config.jsonc and recreating readers on every loop.
    Since config hot-reload is not desired, we guard against callers requesting a
    different config path after the reader has been created.
    """
    global _SHARED_DEVICE_DATA_READER, _SHARED_DEVICE_DATA_READER_CONFIG_PATH

    if _SHARED_DEVICE_DATA_READER is None:
        if config_path is None:
            _SHARED_DEVICE_DATA_READER = DeviceDataReader()
            _SHARED_DEVICE_DATA_READER_CONFIG_PATH = _SHARED_DEVICE_DATA_READER.config_path.resolve()
        else:
            _SHARED_DEVICE_DATA_READER = DeviceDataReader(config_path=config_path)
            _SHARED_DEVICE_DATA_READER_CONFIG_PATH = Path(config_path).resolve()
        return _SHARED_DEVICE_DATA_READER

    if config_path is not None and _SHARED_DEVICE_DATA_READER_CONFIG_PATH is not None:
        requested = Path(config_path).resolve()
        if requested != _SHARED_DEVICE_DATA_READER_CONFIG_PATH:
            raise ValueError(
                "Shared DeviceDataReader already initialized with a different config path. "
                f"existing={_SHARED_DEVICE_DATA_READER_CONFIG_PATH}, requested={requested}"
            )

    return _SHARED_DEVICE_DATA_READER


@dataclass
class PowerResult:
    """Result of a power setting operation."""
    success: bool
    power: int
    error: Optional[str] = None


class ReversalRampGuard:
    """
    Apply a simple ramp-to-zero strategy when reversal is detected.

    The guard is intentionally isolated from control math:
    - input: previous power, desired power, and optional reversal hint
    - output: guarded power
    """

    def __init__(self, enabled: bool = True, divisor: int = 2, min_abs_power: int = 30):
        self.enabled = bool(enabled)
        self.divisor = max(2, int(divisor))
        self.min_abs_power = max(0, int(min_abs_power))

    @staticmethod
    def _sign(value: int) -> int:
        if value > 0:
            return 1
        if value < 0:
            return -1
        return 0

    def _ramp_toward_zero(self, previous_power: int) -> int:
        ramped = int(previous_power / self.divisor)
        if abs(ramped) < self.min_abs_power:
            return 0
        return ramped

    def apply(
        self,
        previous_power: Optional[int],
        desired_power: int,
        reversal_hint: bool = False,
    ) -> int:
        if not self.enabled or not isinstance(desired_power, int):
            return desired_power
        if not isinstance(previous_power, int):
            return desired_power

        # Explicit hint allows guarding transitions that intentionally map to 0 in netzero mode.
        if reversal_hint:
            return self._ramp_toward_zero(previous_power)

        # Generic sign flip guard for int->int transitions.
        if self._sign(previous_power) != 0 and self._sign(desired_power) != 0:
            if self._sign(previous_power) != self._sign(desired_power):
                return self._ramp_toward_zero(previous_power)

        return desired_power


class BaseDeviceController:
    """
    Base class for device controllers that share common functionality.

    Provides config loading, logging, and common utilities for device operations.
    """

    # Network settings
    REQUEST_TIMEOUT = 5  # Timeout in seconds for HTTP requests
    _DEFAULT_LOG_LEVEL = "INFO"
    _LOG_LEVEL_PRIORITY = {
        "DEBUG": 10,
        "INFO": 20,
        "SUCCESS": 20,
        "WARNING": 30,
        "ERROR": 40,
    }

    def __init__(self, config_path: Optional[Path] = None):
        """
        Initialize the base controller.

        Args:
            config_path: Optional path to config.jsonc. If None, will search for
                        config in standard locations (automate/config/config.jsonc).

        Raises:
            FileNotFoundError: If config file not found
            ValueError: If config is invalid
        """
        self.config_path = config_path or self._find_config_file()
        self.config = self._load_config(self.config_path)
        raw_log_level = str(self.config.get("LOG_LEVEL", self._DEFAULT_LOG_LEVEL)).upper()
        self.log_level = (
            raw_log_level
            if raw_log_level in self._LOG_LEVEL_PRIORITY
            else self._DEFAULT_LOG_LEVEL
        )

        # Apply config-driven test mode once at initialization.
        # We keep both an instance attribute and the legacy global for existing code paths.
        global TEST_MODE
        self.test_mode = bool(self.config.get("TEST_MODE", TEST_MODE))
        TEST_MODE = self.test_mode

        # Battery SoC limits (single source of truth: config.jsonc)
        # Fallbacks are the legacy defaults (20/90) when keys are missing.
        def _parse_int_config(key: str, default: int) -> int:
            try:
                return int(self.config.get(key, default))
            except (TypeError, ValueError):
                return int(default)

        min_soc = _parse_int_config("MIN_CHARGE_LEVEL", MIN_CHARGE_LEVEL)
        max_soc = _parse_int_config("MAX_CHARGE_LEVEL", MAX_CHARGE_LEVEL)
        max_discharge_power = _parse_int_config("MAX_DISCHARGE_POWER", MAX_DISCHARGE_POWER)
        max_charge_power = _parse_int_config("MAX_CHARGE_POWER", MAX_CHARGE_POWER)

        # Clamp to [0, 100]
        min_soc = max(0, min(100, min_soc))
        max_soc = max(0, min(100, max_soc))

        # Normalize to avoid nonsensical configs
        if min_soc > max_soc:
            max_soc = min_soc

        self.min_charge_level = min_soc
        self.max_charge_level = max_soc
        self.max_discharge_power = max(0, max_discharge_power)
        self.max_charge_power = max(0, max_charge_power)

    def _find_config_file(self) -> Path:
        """
        Find config.jsonc for automate (automate/config/config.jsonc only).

        Returns:
            Path to the config file that exists

        Raises:
            FileNotFoundError: If config file does not exist
        """
        script_dir = Path(__file__).parent
        config_path = script_dir / "config" / "config.jsonc"
        if not config_path.exists():
            raise FileNotFoundError(
                f"Config file not found: {config_path}\n"
                "   Automate uses automate/config/config.jsonc only."
            )
        return config_path

    def _load_config(self, config_path: Path) -> Dict[str, Any]:
        """
        Load configuration from config.jsonc.

        Args:
            config_path: Path to config.jsonc file

        Returns:
            dict: Configuration dictionary

        Raises:
            FileNotFoundError: If config file not found
            ValueError: If config is invalid
        """
        try:
            return load_config_json(config_path)
        except FileNotFoundError:
            raise FileNotFoundError(f"Config file not found: {config_path}")
        except ValueError as e:
            raise e

    def log(self, level: str, message: str, include_timestamp: bool = True, file_path: str = None):
        """
        Log a message with the specified level.

        Args:
            level: Log level ('info', 'debug', 'warning', 'error', 'success')
            message: Log message
            include_timestamp: If True, include timestamp in log output
            file_path: Optional path to log file. If provided, message will also be written to file.
        """
        # Map log levels to emoji
        emoji_map = {
            'info': '',
            'debug': '🔍',
            'warning': '⚠️',
            'error': '❌',
            'success': '✅',
        }

        level_lower = level.lower()
        level_upper = level_lower.upper()
        if level_upper not in self._LOG_LEVEL_PRIORITY:
            level_upper = 'INFO'
            level_lower = 'info'

        # Log level filter from config (LOG_LEVEL).
        # Priority order: DEBUG < INFO/SUCCESS < WARNING < ERROR.
        if self._LOG_LEVEL_PRIORITY[level_upper] < self._LOG_LEVEL_PRIORITY[self.log_level]:
            return

        emoji = emoji_map.get(level_lower, '')

        # Format timestamp if needed
        if include_timestamp:
            tz = ZoneInfo('Europe/Amsterdam')
            timestamp = datetime.now(tz).strftime('%Y-%m-%d %H:%M:%S')
            prefix = f"[{timestamp}]"
        else:
            prefix = ""

        # Format output message
        if emoji:
            output = f"{prefix} {emoji} {message}".strip()
        else:
            output = f"{prefix} {message}".strip() if prefix else message

        # Print to stdout
        print(output)

        # Write to file if specified
        if file_path:
            try:
                log_file = Path(file_path)
                # Create parent directory if it doesn't exist
                log_file.parent.mkdir(parents=True, exist_ok=True)
                # Append to file
                with open(log_file, 'a', encoding='utf-8') as f:
                    f.write(output + '\n')
            except Exception as e:
                # Don't fail if file logging fails, just print error
                print(f"[ERROR] Failed to write to log file {file_path}: {e}")

        # Automatically write all errors to log/error.log
        if level_lower == 'error':
            try:
                # Determine script directory to place log file relative to it
                script_dir = Path(__file__).parent
                error_log_file = script_dir / "log" / "error.log"
                # Create parent directory if it doesn't exist
                error_log_file.parent.mkdir(parents=True, exist_ok=True)
                # Append to error log file
                with open(error_log_file, 'a', encoding='utf-8') as f:
                    f.write(output + '\n')
            except Exception as e:
                # Don't fail if error log file write fails, just print error
                print(f"[ERROR] Failed to write to error log file: {e}")


class PowerAccumulator:
    """
    Handles accumulation of power values over time periods.

    Tracks energy (watt-hours) for power feed readings across multiple time
    periods: quarter-hour, hour, day, and manual.
    """

    def __init__(self, logger=None, log_file_path=None):
        """
        Initialize the PowerAccumulator.

        Args:
            logger: Logger object with log() method (for logging)
            log_file_path: Optional path to log file for accumulation logs
        """
        self.logger = logger
        self.log_file_path = log_file_path

        self.last_zendure_data: Optional[dict] = None
        # Snapshot of the active schedule entry copied in from the automation loop.
        # Expected shape: {"time": "HHmm", "value": int|"netzero"|"netzero+"|None, "key": str|None}
        self.last_schedule_entry: Optional[Dict[str, Any]] = None

    def _log(self, level: str, message: str):
        """Helper method to log messages using the logger if available."""
        if self.logger:
            self.logger.log(level, message, file_path=self.log_file_path)

class AutomateController(BaseDeviceController):
    """
    Controller class for automating Zendure battery power settings.

    This class handles configuration loading, logging, and power control
    operations for the Zendure battery system.
    """

    # Power limits (W)
    POWER_FEED_MIN = -800  # Minimum effective power feed (discharge)
    POWER_FEED_MAX = 1200   # Maximum effective power feed (charge)

    # Thresholds and battery limits
    POWER_FEED_MIN_THRESHOLD = 30  # Minimum absolute power (W) - if |F_desired| < threshold, set to 0
    POWER_FEED_MIN_DELTA = 50      # Minimum change (W) to actually adjust limits - if |delta| < threshold, keep current

    # Power accumulation log file path (relative to script directory)
    POWER_LOG_FILE = Path(__file__).parent / "log" / "power.log"

    def __init__(self, config_path: Optional[Path] = None):
        """
        Initialize the AutomateController.

        Args:
            config_path: Optional path to config.jsonc. If None, will search for
                        config in standard locations (automate/config/config.jsonc).

        Raises:
            FileNotFoundError: If config file not found
            ValueError: If config is invalid or missing required keys
        """
        super().__init__(config_path)

        # Thresholds (configurable)
        # Fallback to legacy defaults when missing/invalid.
        try:
            self.power_feed_min_threshold = int(self.config.get("POWER_FEED_MIN_THRESHOLD", self.POWER_FEED_MIN_THRESHOLD))
        except (TypeError, ValueError):
            self.power_feed_min_threshold = int(self.POWER_FEED_MIN_THRESHOLD)
        try:
            self.power_feed_min_delta = int(self.config.get("POWER_FEED_MIN_DELTA", self.POWER_FEED_MIN_DELTA))
        except (TypeError, ValueError):
            self.power_feed_min_delta = int(self.POWER_FEED_MIN_DELTA)

        # Normalize to sane non-negative values
        self.power_feed_min_threshold = max(0, self.power_feed_min_threshold)
        self.power_feed_min_delta = max(0, self.power_feed_min_delta)

        # Validate required keys for AutomateController
        device_ip = self.config.get("deviceIp")
        device_sn = self.config.get("deviceSn")

        if not device_ip:
            raise ValueError("deviceIp not found in config.jsonc")
        if not device_sn:
            raise ValueError("deviceSn not found in config.jsonc")

        self.device_ip = device_ip
        self.device_sn = device_sn
        self.previous_power = None  # Track the last successfully set power value (internal convention)
        self.limit_state = 0  # Battery limit state: -1 (MIN), 0 (OK), 1 (MAX)
        try:
            reversal_divisor = int(self.config.get("REVERSAL_RAMP_DIVISOR", 2))
        except (TypeError, ValueError):
            reversal_divisor = 2
        try:
            reversal_min_abs = int(self.config.get("REVERSAL_RAMP_MIN_ABS_POWER", self.power_feed_min_threshold))
        except (TypeError, ValueError):
            reversal_min_abs = self.power_feed_min_threshold
        self.reversal_ramp_guard = ReversalRampGuard(
            enabled=bool(self.config.get("REVERSAL_RAMP_ENABLED", True)),
            divisor=reversal_divisor,
            min_abs_power=reversal_min_abs,
        )

        # Initialize power accumulator
        self.accumulator = PowerAccumulator(
            logger=self,
            log_file_path=str(self.POWER_LOG_FILE)
        )

    def _build_device_properties(self, power_feed: int, stand_by: bool = False) -> Dict[str, Any]:
        """
        Build device properties dict based on power_feed value.

        Args:
            power_feed: Power feed value in watts (positive for charge, negative for discharge, 0 to stop)

        Returns:
            dict: Device properties with acMode, inputLimit, outputLimit, and smartMode
        """

        if self.previous_power is not None and self.previous_power == 1:
            stand_by = True

        if power_feed > 1:
            # Charge mode: acMode 1 = Input
            return {
                "acMode": 1,
                "inputLimit": int(abs(power_feed)),
                "outputLimit": 0,
                "smartMode": 1,
            }
        if power_feed < -1:
            # Discharge mode: acMode 2 = Output
            return {
                "acMode": 2,
                "outputLimit": int(abs(power_feed)),
                "inputLimit": 0,
                "smartMode": 1,
            }
        if stand_by:
            # Go into Stand-by mode
            self.log('info', "Going into Stand-by mode")
            return {
                "acMode": 0,
                "inputLimit": 0,
                "outputLimit": 0,
                "smartMode": 1,
                }
        # zer0 charging
        return {
            "inputLimit": 0,
            "outputLimit": 0,
            "smartMode": 1,
        }


    def check_battery_limits(self) -> None:
        """
        Check battery level against limits and update limit_state property.

        Reads battery level from Zendure device via read_zendure() method.

        Sets limit_state:
            -1: Battery at or below min_charge_level (discharge not allowed)
             0: Battery within acceptable range (no limits) or if read fails
             1: Battery at or above max_charge_level (charge not allowed)
        """
        # Read Zendure data to get battery level
        reader = get_reader(self.config_path)
        zendure_data = reader.read_zendure(update_json=True)

        if not zendure_data:
            self.log('warning', "Failed to read Zendure data for battery limit check, assuming OK")
            self.limit_state = 0
            return

        self.accumulator.last_zendure_data = zendure_data

        # Extract battery level from properties
        props = zendure_data.get("properties", {})
        battery_level = props.get("electricLevel")

        if battery_level is None:
            self.log('warning', "Battery level not found in Zendure data, assuming OK")
            self.limit_state = 0
            return

        # Check limits
        if battery_level <= self.min_charge_level:
            self.limit_state = -1
        elif battery_level >= self.max_charge_level:
            self.limit_state = 1
        else:
            self.limit_state = 0

    def _send_power_feed(self, power_feed: int) -> Tuple[bool, Optional[str], int]:
        """
        Send power_feed value to Zendure device via /properties/write endpoint.

        Args:
            power_feed: Power feed value in watts (positive for charge, negative for discharge, 0 to stop)

        Returns:
            tuple: (success: bool, error_message: str or None, actual_power: int)
                   actual_power is the power value that was actually sent (after limiting/modifications)
        """
        # Store original power for error cases
        original_power = power_feed

        # Check battery limits before processing
        # If charging (power_feed > 0) and at MAX_CHARGE_LEVEL, prevent charge
        if power_feed > 0 and self.limit_state == 1:
            self.log('warning', f"Battery at max_charge_level ({self.max_charge_level}%), preventing charge")
            power_feed = 0

        # If discharging (power_feed < 0) and at MIN_CHARGE_LEVEL, prevent discharge
        if power_feed < 0 and self.limit_state == -1:
            self.log('warning', f"Battery at min_charge_level ({self.min_charge_level}%), preventing discharge")
            power_feed = 0

        if power_feed < -self.max_discharge_power:
            self.log('warning', f"Power feed ({power_feed} W) exceeds MAX_DISCHARGE_POWER ({self.max_discharge_power} W), limiting discharge.")
            power_feed = -self.max_discharge_power
        if power_feed > self.max_charge_power:
            self.log('warning', f"Power feed ({power_feed} W) exceeds MAX_CHARGE_POWER ({self.max_charge_power} W), limiting charge")
            power_feed = self.max_charge_power

        # Check if the new power value is the same as the previous one
        if self.previous_power is not None and power_feed == self.previous_power:
            self.log('debug', f"Power value unchanged ({power_feed} W), skipping device update")
            # Still accumulate since power is being maintained (operation is successful)
            return (True, None, power_feed)

        url = f"http://{self.device_ip}/properties/write"

        # Construct properties based on power_feed value
        properties = self._build_device_properties(power_feed)
        payload = {"sn": self.device_sn, "properties": properties}

        if self.test_mode:
            self.log('debug', f"TEST MODE: Would set power feed to {power_feed} W")
            return (True, None, power_feed)

        try:
            self.log('debug', f"Setting power feed to {power_feed} W...")
            response = requests.post(
                url,
                json=payload,
                timeout=self.REQUEST_TIMEOUT,
                headers={"Content-Type": "application/json"},
            )
            response.raise_for_status()

            # Try to parse JSON response (some devices may not return JSON)
            try:
                response.json()
            except json.JSONDecodeError:
                pass

            self.log('success', f"Successfully set power feed to {power_feed} W")

            # Update previous power only on successful send (in internal convention)
            self.previous_power = power_feed

            return (True, None, power_feed)

        except requests.exceptions.RequestException as e:
            return (False, str(e), original_power)
        except Exception as e:
            return (False, str(e), original_power)

    def _calculate_new_settings(
        self,
        p1_power: int,
        current_input: int,
        current_output: int,
        electric_level: Optional[int],
        ) -> Tuple[int, int]:
        """
        Calculate new inputLimit/outputLimit based on P1 power and current settings.

        Conceptually we work with an effective battery feed value F (W):
          F > 0  => discharging to the grid/house (outputLimit)
          F < 0  => charging from the grid (inputLimit)

        We derive:
          F_current = current_output - current_input
          F_desired = F_current + p1_power

        Additional constraints:
        - Apply battery level limits
        - Clamp to power limits (POWER_FEED_MIN / POWER_FEED_MAX)
        - Apply minimum absolute threshold on |F_desired|
        - Apply minimum delta threshold on |F_desired - F_current|

        Args:
            p1_power: P1 meter power reading (grid status)
            current_input: Current input limit (charge)
            current_output: Current output limit (discharge)
            electric_level: Current battery level (%)

        Returns:
            tuple: (new_input, new_output) in watts
        """
        if current_input is None:
            current_input = 0
        if current_output is None:
            current_output = 0

        # Effective power feed (battery contribution)
        effective_current = current_output - current_input
        effective_desired = effective_current + p1_power

        # Battery constraints applied on desired feed
        if electric_level is not None:
            # Too full to charge
            if electric_level >= self.max_charge_level and effective_desired < 0:
                effective_desired = 0
                self.log('warning', f"Charge level at/above {self.max_charge_level}%, preventing charge")
            # Too empty to discharge
            if electric_level <= self.min_charge_level and effective_desired > 0:
                effective_desired = 0
                self.log('warning', f"Charge level at/below {self.min_charge_level}%, preventing discharge")

        # Clamp effective desired feed
        effective_desired = max(self.POWER_FEED_MIN, min(self.POWER_FEED_MAX, effective_desired))

        # Apply minimum absolute threshold on resulting feed:
        # if the resulting discharge/charge is very small, turn it off.
        if abs(effective_desired) < self.power_feed_min_threshold:
            effective_desired = 0  # Set to 0 to stop, will return 1 in calculate_netzero_power() to avoid standby

        # Apply minimum delta threshold on the CHANGE:
        # if the change is too small, keep current settings to avoid unnecessary adjustments
        effective_delta = effective_desired - effective_current
        if abs(effective_delta) < self.power_feed_min_delta:
            effective_desired = effective_current

        # Reconstruct input/output from clamped effective power:
        # - Positive => discharge (output), negative => charge (input)
        if effective_desired > 0:
            new_output = effective_desired
            new_input = 0
        elif effective_desired < 0:
            new_input = abs(effective_desired)
            new_output = 0
        else:
            new_input = 0
            new_output = 0

        return int(round(new_input)), int(round(new_output))

    def calculate_netzero_power(
        self,
        mode: Literal['netzero', 'netzero+'] = 'netzero',
        p1_data: Optional[Dict[str, Any]] = None,
        ) -> int:
        """
        Calculate the actual power value needed to achieve netzero/netzero+ mode.

        This method uses caller-supplied P1 meter data and current Zendure state,
        then calculates what power setting is needed to achieve zero feed-in.

        Args:
            mode: 'netzero' (can charge or discharge) or 'netzero+' (only charge, no discharge)
            p1_data: Required normalized P1 meter data from the caller.

        Returns:
            int: Power value in watts (positive=charge, negative=discharge, 0=stop)

        Raises:
            ValueError: If P1 meter data is missing/invalid or Zendure data cannot be read
            requests.exceptions.RequestException: On network errors
        """
        if p1_data is None:
            raise ValueError("P1 meter data must be supplied by the caller for dynamic power modes")

        p1_power = p1_data.get("total_power")
        if p1_power is None:
            raise ValueError("P1 meter data supplied by the caller is missing 'total_power'")

        self.log('debug', f"P1 power (grid-status): {p1_power}")

        # Read Zendure state
        reader = get_reader(self.config_path)
        zendure_data = reader.read_zendure(update_json=True)
        if not zendure_data:
            raise ValueError("Failed to read Zendure device data")

        self.accumulator.last_zendure_data = zendure_data

        props = zendure_data.get("properties", {})
        current_input = props.get("inputLimit")
        current_output = props.get("outputLimit")
        electric_level = props.get("electricLevel")

        if current_input is None or current_output is None:
            raise ValueError("Zendure data missing inputLimit or outputLimit")

        # Calculate new settings new_output=discharge, new_input=charge
        new_input, new_output = self._calculate_new_settings(
            p1_power=p1_power,
            current_input=current_input,
            current_output=current_output,
            electric_level=electric_level,
        )

        # Convert to controller convention (positive=charge, negative=discharge)
        # Handle netzero+ mode (no discharge, only charge)
        if mode == 'netzero+':
            # If calculation says to discharge, return 1 (netzero+ doesn't discharge)
            if new_input > 0: # Charging is requested?
                return new_input
            return 0

            # if new_output > 0: # Discharging is requested?
            #     return 0 # Netzero+ doesn't discharge, return 0
            # else:
            #     # Charging or stopped - if stopped (0), return 1 to avoid standby
            #     return new_input if new_input > 0 else 0

        # netzero mode: use discharge when needed, otherwise stop.
        raw_target_power = -new_output if new_output > 0 else 0
        reversal_hint = new_input > 0
        if reversal_hint:
            self.log(
                'warning',
                f"mode: {mode}, new_input: {new_input}, new_output: {new_output}, "
                "reversal detected (possible measurement lag)"
            )

        guarded_power = self.reversal_ramp_guard.apply(
            previous_power=self.previous_power,
            desired_power=raw_target_power,
            reversal_hint=reversal_hint,
        )

        if guarded_power != raw_target_power:
            self.log(
                'warning',
                f"  reversal ramp active: previous={self.previous_power}, "
                f"raw_target={raw_target_power}, guarded_target={guarded_power}"
            )

        return guarded_power

            # # Regular netzero mode
            # if new_output > 0: # Discharging is requested?
            #     # Discharging: return negative value
            #     return -new_output
            # elif new_input > 0: # Charging is requested?
            #     # If this is the case we might be starting oscilating due to readings lagging behind....
            #     # Charging: return positive value
            #     # return new_input, for now netzero mode is not allowed to charge
            #     return 0
            # else:
            #     return 0

    def set_power(
            self,
            value: Union[int, Literal['netzero', 'netzero+'], None] = 'netzero',
            p1_data: Optional[Dict[str, Any]] = None,
        ) -> PowerResult:
        """
        Set power feed to the Zendure battery.

        Args:
            value: Power setting:
                - int: Specific power feed in watts (positive=charge, negative=discharge, 0=stop)
                - 'netzero' or None: Use dynamic zero feed-in calculation (default)
                - 'netzero+': Use dynamic zero feed-in calculation, but only charge (no discharge)
            p1_data: Required normalized P1 meter data when value is netzero/netzero+.

        Returns:
            PowerResult: Result object with success status, power value, and optional error message

        Raises:
            ValueError: If value is invalid
            Exception: On device communication errors

        Note:
            Test mode is controlled by config.jsonc key "TEST_MODE".
            When enabled, operations are simulated but not applied.
        """
        # Handle specific power feed (int), charge is positive, discharge is negative
        if isinstance(value, int):

            # Send power feed
            success, error_msg, actual_power = self._send_power_feed(value)

            if not success:
                return PowerResult(
                    success=False,
                    power=actual_power,
                    error=f"Failed to set power feed: {error_msg}"
                )

            return PowerResult(success=True, power=actual_power)

        # Handle dynamic zero feed-in ('netzero' or None)
        if value == 'netzero' or value == 'netzero+' or value is None:
            # Determine mode (default to 'netzero' if None)
            mode = value if value is not None else 'netzero'
            if p1_data is None:
                return PowerResult(
                    success=False,
                    power=0,
                    error="P1 meter data must be supplied by the caller for dynamic power modes",
                )

            try:
                # Calculate the actual power value needed
                calculated_power = self.calculate_netzero_power(mode=mode, p1_data=p1_data)

                # If test mode, just return the calculated value without applying
                if self.test_mode:
                    return PowerResult(success=True, power=calculated_power)

                # Apply the calculated power
                # calculated_power is already in correct convention (positive=charge, negative=discharge)
                # Send power feed directly without conversion
                success, error_msg, actual_power = self._send_power_feed(calculated_power)

                if not success:
                    return PowerResult(
                        success=False,
                        power=actual_power,
                        error=f"Failed to set power feed: {error_msg}"
                    )

                return PowerResult(success=True, power=actual_power)

            except Exception as e:
                return PowerResult(
                    success=False,
                    power=0,
                    error=f"Zero feed-in calculation failed: {str(e)}"
                )
        raise ValueError(f"Invalid power value: {value}. Must be int, 'netzero', 'netzero+', or None")

    def set_standby_mode(self) -> PowerResult:
        """
        Put the device into standby mode.
        Executes the sequence: 1W -> 2s sleep -> 0W.
        """
        self.log('info', "Initiating standby sequence...")
        # Step 1: Set to 1W to prime the previous_power state
        res1 = self.set_power(1)
        if not res1.success:
            return res1

        # Step 2: Wait for state to settle
        time.sleep(2)

        # Step 3: Set to 0W to trigger standby logic
        return self.set_power(0)


class DeviceDataReader(BaseDeviceController):
    """
    Class for reading Zendure battery device data via API calls.

    This class handles reading device data and automatically storing it via API endpoints.
    """

    # Config keys
    CONFIG_KEY_DEVICE_IP = "deviceIp"

    # API endpoints
    API_ENDPOINT_PROPERTIES_REPORT = "/properties/report"

    # Data field names
    FIELD_TIMESTAMP = "timestamp"
    FIELD_PROPERTIES = "properties"
    FIELD_PACK_DATA = "packData"

    def __init__(self, config_path: Optional[Path] = None):
        """
        Initialize the DeviceDataReader.

        Args:
            config_path: Optional path to config.jsonc. If None, will search for
                        config in standard locations (automate/config/config.jsonc).

        Raises:
            FileNotFoundError: If config file not found
            ValueError: If config is invalid or missing required keys
        """
        super().__init__(config_path)

        self.device_ip = self.config.get(self.CONFIG_KEY_DEVICE_IP)
        self.last_zendure_data: Optional[dict] = None

    def read_zendure(self, update_json: bool = True) -> Optional[dict]:
        """
        Read data from Zendure battery device via API call.

        Args:
            update_json: Ignored (kept for API compatibility).

        Returns:
            dict: Raw Zendure device data from device, or None on error
        """
        _ = update_json
        if not self.device_ip:
            self.log('error', f"{self.CONFIG_KEY_DEVICE_IP} not found in config.jsonc")
            return None

        # Read from Zendure device directly
        url = f"http://{self.device_ip}{self.API_ENDPOINT_PROPERTIES_REPORT}"

        try:
            response = requests.get(url, timeout=self.REQUEST_TIMEOUT)
            response.raise_for_status()
            data = response.json()

            self.last_zendure_data = data

            # Return the raw device data
            return data

        except requests.exceptions.RequestException as e:
            self.log('error', f"Error reading from Zendure device at {self.device_ip}: {e}")
            return None
        except (json.JSONDecodeError, KeyError) as e:
            self.log('error', f"Error parsing Zendure response: {e}")
            return None
        except Exception as e:
            self.log('error', f"Unexpected error reading Zendure data: {e}")
            return None


class ScheduleController(BaseDeviceController):
    """
    Class for reading charge schedules from API and determining desired power settings.

    This class handles fetching schedule data, caching it, and finding the current
    schedule value based on the current time.
    """

    # Config keys
    CONFIG_KEY_SCHEDULE_API_URL = "apiUrl"

    # Timezone
    TIMEZONE = 'Europe/Amsterdam'

    def __init__(self, config_path: Optional[Path] = None):
        """
        Initialize the ScheduleController.

        Args:
            config_path: Optional path to config.jsonc. If None, will search for
                        config in standard locations (automate/config/config.jsonc).

        Raises:
            FileNotFoundError: If config file not found
            ValueError: If config is invalid or missing required keys
        """
        super().__init__(config_path)
        self.schedule_data: Optional[List[Dict[str, Any]]] = None
        self.schedule_date: Optional[date] = None
        # Snapshot of the active resolved schedule entry at the last lookup.
        # Expected shape from the schedule API: {"time": "HHmm", "value": int|"netzero"|"netzero+"|None, "key": str|None}
        self.last_schedule_entry: Optional[Dict[str, Any]] = None

    def _get_current_time_str(self) -> str:
        """
        Get current time in HHMM format using Europe/Amsterdam timezone.

        Returns:
            Current time as string in "HHMM" format (e.g., "1902")
        """
        tz = ZoneInfo('Europe/Amsterdam')
        now = datetime.now(tz=tz)
        return now.strftime('%H%M')

    def fetch_schedule(self) -> Dict[str, Any]:
        """
        Fetch schedule from API and store in class properties.

        Returns:
            dict: API response data with schedule information

        Raises:
            ValueError: If API URL not found in config or API response is invalid
            requests.exceptions.RequestException: On network errors
            json.JSONDecodeError: On JSON parsing errors
        """
        api_url = self.config.get(self.CONFIG_KEY_SCHEDULE_API_URL)
        if not api_url:
            raise ValueError(f"{self.CONFIG_KEY_SCHEDULE_API_URL} not found in config.jsonc")

        try:
            response = requests.get(api_url, timeout=self.REQUEST_TIMEOUT)
            response.raise_for_status()
            data = response.json()

            if not data.get("success"):
                error_msg = data.get('error', 'Unknown error')
                raise ValueError(f"API returned success=false: {error_msg}")

            # Extract and store only the resolved array
            resolved = data.get('resolved')
            if resolved is None:
                raise ValueError("API response missing 'resolved' field")

            # Store resolved array and date
            self.schedule_data = resolved
            tz = ZoneInfo('Europe/Amsterdam')
            self.schedule_date = datetime.now(tz=tz).date()

            current_time_str = self._get_current_time_str()
            self.log('info', f"Schedule fetched successfully. Current time: {current_time_str}, Resolved entries: {len(resolved)}")

            return data

        except requests.exceptions.RequestException as e:
            self.log('error', f"Error fetching schedule API: {e} (URL: {api_url})")
            raise
        except json.JSONDecodeError as e:
            self.log('error', f"Error parsing JSON response: {e}")
            raise
        except ValueError:
            # Re-raise ValueError (already logged if from our code)
            raise
        except Exception as e:
            self.log('error', f"Unexpected error calling schedule API: {e}")
            raise

    def _find_current_schedule_value(
        self,
        resolved: List[Dict[str, Any]],
        current_time: str
        ) -> Optional[Union[int, Literal['netzero', 'netzero+']]]:
        """
        Find the schedule value for the current time.

        Finds the resolved entry with the largest time that is still <= current_time.

        Args:
            resolved: List of resolved schedule entries, each with 'time' and 'value' keys
            current_time: Current time in "HHMM" format (e.g., "1811" or "2300")

        Returns:
            The value from the matching entry (int, 'netzero', 'netzero+'), or None if no match found

        Raises:
            ValueError: If current_time format is invalid
        """
        try:
            current_time_int = int(current_time)

            # Filter entries where time <= current_time
            valid_entries = [
                entry for entry in resolved
                if isinstance(entry, dict) and 'time' in entry and isinstance(entry['time'], (str, int))
            ]

            # Convert time to int for comparison
            valid_entries_with_int_time = []
            for entry in valid_entries:
                try:
                    time_int = int(entry['time'])
                    if time_int <= current_time_int:
                        valid_entries_with_int_time.append((time_int, entry))
                except (ValueError, TypeError):
                    continue

            if not valid_entries_with_int_time:
                self.log('warning', f"No valid entries found for current time {current_time}")
                self.last_schedule_entry = None
                return None

            _, matching_entry = max(valid_entries_with_int_time, key=lambda x: x[0])
            # Store a compact snapshot of the matching entry for other components
            # (e.g. PowerAccumulator and runtime-condition evaluation in automate_www.py).
            self.last_schedule_entry = {
                'time': matching_entry.get('time'),
                'value': matching_entry.get('value'),
                'key': matching_entry.get('key'),
                'runtime_conditions': matching_entry.get('runtime_conditions'),
                'fallback_value': matching_entry.get('fallback_value'),
            }

            return matching_entry.get('value')

        except ValueError as e:
            raise ValueError(f"Invalid current_time format '{current_time}': {e}")
        except Exception as e:
            self.log('error', f"Error finding current schedule value: {e}")
            raise

    def get_desired_power(
        self,
        refresh: bool = False
        ) -> Optional[Union[int, Literal['netzero', 'netzero+']]]:
        """
        Determine desired power setting based on current schedule.

        Args:
            refresh: If True, fetch fresh data from API; if False, use cached data

        Returns:
            Desired power value (int, 'netzero', 'netzero+', or None)

        Raises:
            ValueError: If schedule data is invalid or missing required fields
            requests.exceptions.RequestException: On network errors when refresh=True
        """
        # Fetch schedule if refresh requested or no cached data
        if refresh or self.schedule_data is None:
            self.fetch_schedule()

        if not self.schedule_data:
            raise ValueError("Schedule data is not available")

        # Compute current time locally
        current_time_str = self._get_current_time_str()

        # Find the current schedule value
        desired_power = self._find_current_schedule_value(self.schedule_data, current_time_str)

        return desired_power
