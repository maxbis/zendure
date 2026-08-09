#!/usr/bin/env python3
"""
Device Controller - OOP wrapper for Zendure battery control and data reading

This module provides object-oriented interfaces for controlling the Zendure
battery system and reading data from Zendure devices, based on the
functionality in zero_feed_in_controller.py.
"""

import json
import time

from collections import deque
from dataclasses import dataclass
from datetime import datetime, date
from pathlib import Path
from typing import Optional, Tuple, Dict, Any, Union, Literal, List
from zoneinfo import ZoneInfo

import requests
from config_loader import (
    SYSTEM_CONFIG_PATH,
    load_config as load_config_json,
    load_system_config,
)


# ============================================================================
# GLOBAL CONSTANTS
# ============================================================================

# TEST_MODE remains automation-local and is overridden from config.jsonc.
TEST_MODE = False               # If True, operations are simulated but not applied


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
    requested_power: Optional[int] = None
    target_power: Optional[int] = None
    max_delta_limited: bool = False
    reversal_ramp_active: bool = False


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
    _RECENT_LOG_WINDOW = 3
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
        self.system_config = load_system_config()
        self.system_config_path = SYSTEM_CONFIG_PATH
        self.timezone_name = str(self.system_config["installation"]["timezone"])
        self.timezone = ZoneInfo(self.timezone_name)
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

        # Battery limits and power caps are authoritative in common/config/system.json.
        def _parse_int_config(key: str, default: int) -> int:
            try:
                return int(self.config.get(key, default))
            except (TypeError, ValueError):
                return int(default)

        battery_config = self.system_config["battery"]
        min_soc = int(battery_config["minChargePercent"])
        max_soc = int(battery_config["maxChargePercent"])
        max_discharge_power = int(battery_config["maxDischargePowerW"])
        max_charge_power = int(battery_config["maxChargePowerW"])
        netzero_target_w = _parse_int_config("NETZERO_TARGET_W", 0)
        slow_charge_start_level_raw = self.config.get("SLOW_CHARGE_START_LEVEL")
        slow_charge_max_power_raw = self.config.get("SLOW_CHARGE_MAX_POWER")

        self.min_charge_level = min_soc
        self.max_charge_level = max_soc
        self.max_discharge_power = max_discharge_power
        self.max_charge_power = max_charge_power
        self.netzero_target_w = netzero_target_w
        self.slow_charge_start_level = None
        self.slow_charge_max_power = None

        try:
            if slow_charge_start_level_raw is not None and slow_charge_max_power_raw is not None:
                self.slow_charge_start_level = max(0, min(100, int(slow_charge_start_level_raw)))
                self.slow_charge_max_power = max(0, int(slow_charge_max_power_raw))
        except (TypeError, ValueError):
            self.slow_charge_start_level = None
            self.slow_charge_max_power = None

        # Track the last N emitted keyed log entries to suppress repeats.
        self._recent_log_messages = deque(maxlen=self._RECENT_LOG_WINDOW)

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

    def log(
        self,
        level: str,
        message: str,
        include_timestamp: bool = True,
        file_path: str = None,
        message_key: Optional[str] = None,
    ):
        """
        Log a message with the specified level.

        Args:
            level: Log level ('info', 'debug', 'warning', 'error', 'success')
            message: Log message
            include_timestamp: If True, include timestamp in log output
            file_path: Optional path to log file. If provided, message will also be written to file.
            message_key: Optional event-style key used to deduplicate repeated
                        non-debug log entries within the recent window.
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

        log_key = None
        if level_upper != 'DEBUG' and message_key:
            log_key = (level_upper, str(message_key))
        if log_key is not None and log_key in self._recent_log_messages:
            return

        emoji = emoji_map.get(level_lower, '')

        # Format timestamp if needed
        if include_timestamp:
            timestamp = datetime.now(self.timezone).strftime('%Y-%m-%d %H:%M:%S')
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

        if log_key is not None:
            self._recent_log_messages.append(log_key)

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
        # Expected shape: {"time": "HHmm", "value": int|"netzero"|"netzero+"|"netzero-"|None, "key": str|None}
        self.last_schedule_entry: Optional[Dict[str, Any]] = None

    def _log(self, level: str, message: str, message_key: Optional[str] = None):
        """Helper method to log messages using the logger if available."""
        if self.logger:
            self.logger.log(level, message, file_path=self.log_file_path, message_key=message_key)

class AutomateController(BaseDeviceController):
    """
    Controller class for automating Zendure battery power settings.

    This class handles configuration loading, logging, and power control
    operations for the Zendure battery system.
    """

    # Power limits (W) for the effective feed used inside _calculate_new_settings:
    # positive = discharge, negative = charge.
    POWER_FEED_MIN = -1200  # Minimum effective power feed (charge)
    POWER_FEED_MAX = 800    # Maximum effective power feed (discharge)

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
        try:
            self.power_feed_max_delta = int(self.config.get("POWER_FEED_MAX_DELTA", 300))
        except (TypeError, ValueError):
            self.power_feed_max_delta = 300
        self.power_feed_max_delta = max(0, self.power_feed_max_delta)

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
        self._last_dynamic_power_context: Dict[str, Any] = {}
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


    def check_battery_limits(self, zendure_data: Optional[dict] = None) -> None:
        """
        Check battery level against limits and update limit_state property.

        Reads battery level from caller-supplied Zendure data or via read_zendure().

        Sets limit_state:
            -1: Battery at or below min_charge_level (discharge not allowed)
             0: Battery within acceptable range (no limits) or if read fails
             1: Battery at or above max_charge_level (charge not allowed)
        """
        reader = get_reader(self.config_path)
        if zendure_data is None:
            zendure_data = reader.read_zendure(update_json=True)

        if not zendure_data:
            device_ip = reader.config.get(reader.CONFIG_KEY_DEVICE_IP) if hasattr(reader, "config") else None
            device_url = (
                f"http://{device_ip}{reader.API_ENDPOINT_PROPERTIES_REPORT}"
                if device_ip
                else "configured Zendure device endpoint"
            )
            self.log(
                'warning',
                f"Failed to read Zendure device data from {device_url} during battery limit check, assuming OK",
                message_key='battery_limit_read_failed',
            )
            self.limit_state = 0
            return

        self.accumulator.last_zendure_data = zendure_data

        # Extract battery level from properties
        props = zendure_data.get("properties", {})
        battery_level = props.get("electricLevel")

        if battery_level is None:
            self.log(
                'warning',
                "Battery level not found in Zendure data, assuming OK",
                message_key='battery_level_missing',
            )
            self.limit_state = 0
            return

        # Check limits
        if battery_level <= self.min_charge_level:
            self.limit_state = -1
        elif battery_level >= self.max_charge_level:
            self.limit_state = 1
        else:
            self.limit_state = 0

        if self.limit_state == -1:
            state_label = "MIN"
            charge_allowed = "yes"
            discharge_allowed = "no"
        elif self.limit_state == 1:
            state_label = "MAX"
            charge_allowed = "no"
            discharge_allowed = "yes"
        else:
            state_label = "OK"
            charge_allowed = "yes"
            discharge_allowed = "yes"

        self.log(
            'info',
            f"Battery limit check: level={battery_level}% min={self.min_charge_level}% "
            f"max={self.max_charge_level}% state={state_label} "
            f"charge_allowed={charge_allowed} discharge_allowed={discharge_allowed}",
        )

    @staticmethod
    def _extract_live_power_feed(zendure_data: Optional[dict]) -> Optional[int]:
        """Return signed live power from a Zendure snapshot when limits are available."""
        if not isinstance(zendure_data, dict):
            return None
        props = zendure_data.get("properties", {})
        if not isinstance(props, dict):
            return None

        input_limit = props.get("inputLimit")
        output_limit = props.get("outputLimit")
        try:
            live_input = int(input_limit)
            live_output = int(output_limit)
        except (TypeError, ValueError):
            return None

        return live_input - live_output

    def _send_power_feed(self, power_feed: int, zendure_data: Optional[dict] = None) -> Tuple[bool, Optional[str], int]:
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
            self.log(
                'warning',
                f"Battery at max_charge_level ({self.max_charge_level}%), preventing charge",
                message_key='battery_max_charge_block',
            )
            power_feed = 0

        # If discharging (power_feed < 0) and at MIN_CHARGE_LEVEL, prevent discharge
        if power_feed < 0 and self.limit_state == -1:
            self.log(
                'warning',
                f"Battery at min_charge_level ({self.min_charge_level}%), preventing discharge",    
                message_key='battery_min_discharge_block',
            )
            power_feed = 0

        if power_feed < -self.max_discharge_power:
            self.log(
                'warning',
                f"Power feed ({power_feed} W) exceeds MAX_DISCHARGE_POWER ({self.max_discharge_power} W), limiting discharge.",
                message_key='max_discharge_power_limit',
            )
            power_feed = -self.max_discharge_power
        if power_feed > self.max_charge_power:
            self.log(
                'warning',
                f"Power feed ({power_feed} W) exceeds MAX_CHARGE_POWER ({self.max_charge_power} W), limiting charge",
                message_key='max_charge_power_limit',
            )
            power_feed = self.max_charge_power

        live_power_feed = self._extract_live_power_feed(zendure_data)

        # Check if the new power value is the same as the previous one
        if self.previous_power is not None and power_feed == self.previous_power:
            if live_power_feed is None or live_power_feed == power_feed:
                self.log('debug', f"Power value unchanged ({power_feed} W), skipping device update")
                # Still accumulate since power is being maintained (operation is successful)
                return (True, None, power_feed)

            props = zendure_data.get("properties", {}) if isinstance(zendure_data, dict) else {}
            live_input = props.get("inputLimit")
            live_output = props.get("outputLimit")
            self.log(
                'warning',
                f"Stale device state detected for dedupe: requested={power_feed} W, previous={self.previous_power} W, "
                f"live={live_power_feed} W, inputLimit={live_input}, outputLimit={live_output}; resending command",
                message_key='stale_device_state_detected',
            )

        url = f"http://{self.device_ip}/properties/write"

        # Construct properties based on power_feed value
        properties = self._build_device_properties(power_feed)
        payload = {"sn": self.device_sn, "properties": properties}

        if self.test_mode:
            self.log('debug', f"TEST MODE: Would set power feed to {power_feed} W")
            self.previous_power = power_feed
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
                self.log(
                    'warning',
                    f"Charge level at/above {self.max_charge_level}%, actual level {electric_level}%, preventing charge",
                    message_key='battery_max_charge_block',
                )
            # Too empty to discharge
            if electric_level <= self.min_charge_level and effective_desired > 0:
                effective_desired = 0
                self.log(
                    'warning',
                    f"Charge level at/below {self.min_charge_level}%, actual level {electric_level}%, preventing discharge",
                    message_key='battery_min_discharge_block',
                )

        effective_desired = self._apply_dynamic_slow_charge_limit(
            effective_desired,
            electric_level,
        )

        # Clamp effective desired feed using the configured power caps.
        effective_desired = max(-self.max_charge_power, min(self.max_discharge_power, effective_desired))

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

    def _apply_dynamic_slow_charge_limit(
        self,
        effective_desired: int,
        electric_level: Optional[int],
    ) -> int:
        """Cap dynamic charging near full SoC without affecting fixed commands."""
        if electric_level is None:
            return effective_desired
        if self.slow_charge_start_level is None or self.slow_charge_max_power is None:
            return effective_desired
        if electric_level < self.slow_charge_start_level or effective_desired >= 0:
            return effective_desired

        limited_effective = max(effective_desired, -self.slow_charge_max_power)
        if limited_effective != effective_desired:
            self.log(
                'info',
                f"Dynamic slow-charge cap active at {electric_level}%: "
                f"{effective_desired} W -> {limited_effective} W "
                f"(threshold={self.slow_charge_start_level}%, cap={self.slow_charge_max_power} W)",
                message_key='dynamic_slow_charge_limit',
            )
        return limited_effective

    def calculate_netzero_power(
        self,
        mode: Literal['netzero', 'netzero+', 'netzero-'] = 'netzero',
        p1_data: Optional[Dict[str, Any]] = None,
        schedule_entry: Optional[Dict[str, Any]] = None,
        zendure_data: Optional[Dict[str, Any]] = None,
        p1_source: Optional[str] = None,
        ) -> int:
        """
        Calculate the actual power value needed to achieve a dynamic netzero mode.

        This method uses caller-supplied P1 meter data and current Zendure state,
        then calculates what power setting is needed to achieve zero feed-in.

        Args:
            mode: 'netzero' (bidirectional), 'netzero+' (charge only), or
                'netzero-' (discharge only)
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

        raw_netzero_target_w = getattr(self, "netzero_target_w", None)
        if raw_netzero_target_w is None:
            config = getattr(self, "config", {})
            if isinstance(config, dict):
                raw_netzero_target_w = config.get("NETZERO_TARGET_W", 0)
            else:
                raw_netzero_target_w = 0
        try:
            netzero_target_w = int(raw_netzero_target_w)
        except (TypeError, ValueError):
            netzero_target_w = 0
        adjusted_p1_power = p1_power - netzero_target_w

        source_label = str(p1_source).strip() if p1_source is not None else ""
        if not source_label:
            source_label = "unknown"
        self.log('debug', f"P1 power (grid-status, source={source_label}): {p1_power}")
        if netzero_target_w != 0:
            self.log(
                'debug',
                f"Adjusted P1 power for target {netzero_target_w} W: {adjusted_p1_power}",
            )

        # Read Zendure state unless the caller already supplied this iteration's snapshot.
        if zendure_data is None:
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
            p1_power=adjusted_p1_power,
            current_input=current_input,
            current_output=current_output,
            electric_level=electric_level,
        )

        # Convert to controller convention (positive=charge, negative=discharge).
        if mode == 'netzero+':
            raw_target_power = new_input if new_input > 0 else 0
        elif mode == 'netzero-':
            raw_target_power = -new_output if new_output > 0 else 0
        else:
            raw_target_power = -new_output + new_input

        self._last_dynamic_power_context = {
            'mode': mode,
            'p1_power': p1_power,
            'netzero_target_w': netzero_target_w,
            'adjusted_p1_power': adjusted_p1_power,
            'current_input': current_input,
            'current_output': current_output,
            'electric_level': electric_level,
            'new_input': new_input,
            'new_output': new_output,
            'raw_power': raw_target_power,
            'bounded_power': raw_target_power,
            'final_power': raw_target_power,
            'guarded_power': raw_target_power,
            'guard_active': False,
            'reversal_hint': False,
        }

        return raw_target_power

    @staticmethod
    def _normalize_schedule_bound(schedule_entry: Optional[Dict[str, Any]], field_name: str) -> Optional[int]:
        if not isinstance(schedule_entry, dict):
            return None

        raw_value = schedule_entry.get(field_name)
        if raw_value is None or raw_value == '':
            return None

        if isinstance(raw_value, bool):
            raise ValueError(f"Schedule field '{field_name}' must be an integer when provided")

        if isinstance(raw_value, int):
            return raw_value

        if isinstance(raw_value, float):
            if not raw_value.is_integer():
                raise ValueError(f"Schedule field '{field_name}' must be an integer when provided")
            return int(raw_value)

        if isinstance(raw_value, str):
            trimmed = raw_value.strip()
            if trimmed == '' or not trimmed.lstrip('-').isdigit():
                raise ValueError(f"Schedule field '{field_name}' must be an integer when provided")
            return int(trimmed)

        try:
            return int(raw_value)
        except (TypeError, ValueError) as exc:
            raise ValueError(f"Schedule field '{field_name}' must be an integer when provided") from exc

    def _get_dynamic_power_context(self) -> Dict[str, Any]:
        context = getattr(self, '_last_dynamic_power_context', None)
        return context if isinstance(context, dict) else {}

    def _resolve_power_target(
        self,
        value: Union[int, Literal['netzero', 'netzero+', 'netzero-'], None],
        p1_data: Optional[Dict[str, Any]] = None,
        schedule_entry: Optional[Dict[str, Any]] = None,
        zendure_data: Optional[Dict[str, Any]] = None,
        p1_source: Optional[str] = None,
    ) -> int:
        if isinstance(value, int):
            return value

        if value in ('netzero', 'netzero+', 'netzero-') or value is None:
            mode = value if value is not None else 'netzero'
            if p1_data is None:
                raise ValueError("P1 meter data must be supplied by the caller for dynamic power modes")

            calculated_power = self.calculate_netzero_power(
                mode=mode,
                p1_data=p1_data,
                schedule_entry=schedule_entry,
                zendure_data=zendure_data,
                p1_source=p1_source,
            )
            runtime_context = self._get_dynamic_power_context()
            bounded_power = self._apply_schedule_power_bounds(
                calculated_power,
                mode=mode,
                schedule_entry=schedule_entry,
                runtime_context=runtime_context,
            )
            if self.previous_power is not None:
                reversal_hint = self.previous_power * bounded_power < 0
            else:
                reversal_hint = False

            if reversal_hint:
                self.log(
                    'warning',
                    f"mode: {mode}, bounded_target={bounded_power}, raw_target={calculated_power}, "
                    "reversal detected after bounds",
                    message_key='reversal_detected',
                )

            final_power = self.reversal_ramp_guard.apply(
                previous_power=self.previous_power,
                desired_power=bounded_power,
                reversal_hint=reversal_hint,
            )

            if final_power != bounded_power:
                self.log(
                    'warning',
                    f"  reversal ramp active: previous={self.previous_power}, "
                    f"bounded_target={bounded_power}, final_target={final_power}",
                    message_key='reversal_ramp_active',
                )

            updated_context = dict(runtime_context)
            updated_context.update({
                'mode': mode,
                'bounded_power': bounded_power,
                'final_power': final_power,
                'guarded_power': final_power,
                'guard_active': final_power != bounded_power,
                'reversal_hint': reversal_hint,
            })
            self._last_dynamic_power_context = updated_context

            self.log(
                'debug',
                "Netzero calc: "
                f"mode={mode}, p1_power={updated_context.get('p1_power')}, "
                f"current_input={updated_context.get('current_input')}, "
                f"current_output={updated_context.get('current_output')}, "
                f"electric_level={updated_context.get('electric_level')}, "
                f"new_input={updated_context.get('new_input')}, "
                f"new_output={updated_context.get('new_output')}, "
                f"raw_target={updated_context.get('raw_power')}, "
                f"bounded_target={bounded_power}, final_target={final_power}, "
                f"previous_power={self.previous_power}, reversal_hint={reversal_hint}",
                message_key='netzero_calc_debug',
            )

            return final_power

        raise ValueError(f"Invalid power value: {value}. Must be int, 'netzero', 'netzero+', 'netzero-', or None")

    def _apply_power_feed_max_delta(self, target_power: int) -> int:
        if not isinstance(target_power, int):
            return target_power
        if not isinstance(self.previous_power, int):
            return target_power

        delta = target_power - self.previous_power
        if abs(delta) <= self.power_feed_max_delta:
            return target_power

        limited_power = self.previous_power + (
            self.power_feed_max_delta if delta > 0 else -self.power_feed_max_delta
        )
        self.log(
            'warning',
            f"Max delta limit hit: previous={self.previous_power}, target={target_power}, "
            f"power_feed_max_delta={self.power_feed_max_delta}, limited={limited_power}",
            message_key='power_feed_max_delta_limit',
        )
        return limited_power

    def _apply_schedule_power_bounds(
        self,
        power_value: int,
        mode: Literal['netzero', 'netzero+', 'netzero-'],
        schedule_entry: Optional[Dict[str, Any]] = None,
        runtime_context: Optional[Dict[str, Any]] = None,
    ) -> int:
        runtime_context = runtime_context if isinstance(runtime_context, dict) else {}
        slot_key = None
        slot_time = None
        if isinstance(schedule_entry, dict):
            slot_key = schedule_entry.get('key')
            slot_time = schedule_entry.get('time')
            if schedule_entry.get('runtime_fallback_active') is True:
                fallback_value = schedule_entry.get('runtime_fallback_value')
                self.log(
                    'info',
                    f"Runtime fallback active for {mode} slot {slot_time or '?'} ({slot_key or 'no-key'}): "
                    f"using fallback={fallback_value}; ignoring primary power limits "
                    f"min={schedule_entry.get('min_power')} max={schedule_entry.get('max_power')}",
                    message_key='runtime_fallback_ignores_power_limits',
                )
                return power_value

        try:
            min_power = self._normalize_schedule_bound(schedule_entry, 'min_power')
        except ValueError as exc:
            self.log('debug', f"Ignoring invalid min_power for slot {slot_time or '?'} ({slot_key or 'no-key'}): {exc}")
            min_power = None
        try:
            max_power = self._normalize_schedule_bound(schedule_entry, 'max_power')
        except ValueError as exc:
            self.log('debug', f"Ignoring invalid max_power for slot {slot_time or '?'} ({slot_key or 'no-key'}): {exc}")
            max_power = None

        if min_power is None and max_power is None:
            return power_value

        if min_power is not None and max_power is not None and min_power > max_power:
            self.log(
                'debug',
                f"Ignoring schedule bounds for slot {slot_time or '?'} ({slot_key or 'no-key'}): "
                f"min_power {min_power} is greater than max_power {max_power}"
            )
            return power_value

        raw_power = int(runtime_context.get('raw_power', power_value))

        self.log(
            'debug',
            f"Dynamic bounds active for {mode} slot {slot_time or '?'} ({slot_key or 'no-key'}): "
            f"raw={raw_power}, current={power_value}, min={min_power}, max={max_power}"
        )

        bounded_power = int(power_value)
        original_power = bounded_power

        if min_power is not None:
            bounded_power = max(bounded_power, min_power)
        if max_power is not None:
            bounded_power = min(bounded_power, max_power)

        if bounded_power != original_power:
            self.log(
                'debug',
                f"Signed power bounds clamped {mode} slot {slot_time or '?'} "
                f"({slot_key or 'no-key'}): {original_power} -> {bounded_power}"
            )

        if bounded_power != power_value:
            self.log(
                'info',
                f"Applied schedule bounds for {mode} slot {slot_time or '?'} ({slot_key or 'no-key'}): "
                f"raw={raw_power}, bounded={bounded_power}, min={min_power}, max={max_power}",
                message_key='schedule_bounds_applied',
            )

        return bounded_power

    def set_power(
            self,
            value: Union[int, Literal['netzero', 'netzero+', 'netzero-'], None] = 'netzero',
            p1_data: Optional[Dict[str, Any]] = None,
            schedule_entry: Optional[Dict[str, Any]] = None,
            zendure_data: Optional[Dict[str, Any]] = None,
            p1_source: Optional[str] = None,
        ) -> PowerResult:
        """
        Set power feed to the Zendure battery.

        Args:
            value: Power setting:
                - int: Specific power feed in watts (positive=charge, negative=discharge, 0=stop)
                - 'netzero' or None: Use bidirectional dynamic zero feed-in calculation (default)
                - 'netzero+': Use dynamic zero feed-in calculation, but only charge (no discharge)
                - 'netzero-': Use dynamic zero feed-in calculation, but only discharge (no charge)
            p1_data: Required normalized P1 meter data when value is netzero/netzero+/netzero-.

        Returns:
            PowerResult: Result object with success status, power value, and optional error message

        Raises:
            ValueError: If value is invalid
            Exception: On device communication errors

        Note:
            Test mode is controlled by config.jsonc key "TEST_MODE".
            When enabled, operations are simulated but not applied.
        """
        try:
            target_power = self._resolve_power_target(
                value=value,
                p1_data=p1_data,
                schedule_entry=schedule_entry,
                zendure_data=zendure_data,
                p1_source=p1_source,
            )
        except ValueError as exc:
            error_message = str(exc)
            dynamic_mode = value in ('netzero', 'netzero+', 'netzero-') or value is None
            if dynamic_mode and "must be supplied by the caller" not in error_message:
                error_message = f"Zero feed-in calculation failed: {error_message}"
            return PowerResult(success=False, power=0, error=error_message)
        except Exception as exc:
            return PowerResult(
                success=False,
                power=0,
                error=f"Zero feed-in calculation failed: {str(exc)}"
            )

        requested_power = target_power
        runtime_context = self._get_dynamic_power_context()
        reversal_ramp_active = bool(runtime_context.get('guard_active', False))
        target_power = self._apply_power_feed_max_delta(target_power)
        max_delta_limited = target_power != requested_power

        if value in ('netzero', 'netzero+', 'netzero-') or value is None:
            raw_target = runtime_context.get('raw_power', requested_power)
            if max_delta_limited:
                reason = 'max_delta'
            elif reversal_ramp_active:
                reason = 'reversal_ramp'
            elif requested_power != raw_target:
                reason = 'schedule_bounds'
            else:
                reason = 'direct'
            self.log(
                'debug',
                f"Netzero target: raw={raw_target} final={target_power} reason={reason}",
                message_key='netzero_target_summary',
            )

        success, error_msg, actual_power = self._send_power_feed(target_power, zendure_data=zendure_data)

        if actual_power != target_power:
            min_power = None
            max_power = None
            try:
                min_power = self._normalize_schedule_bound(schedule_entry, 'min_power')
            except ValueError:
                min_power = None
            try:
                max_power = self._normalize_schedule_bound(schedule_entry, 'max_power')
            except ValueError:
                max_power = None
            if min_power is not None or max_power is not None:
                mode_label = value if value in ('netzero', 'netzero+', 'netzero-') else 'fixed'
                slot_time = schedule_entry.get('time') if isinstance(schedule_entry, dict) else None
                slot_key = schedule_entry.get('key') if isinstance(schedule_entry, dict) else None
                self.log(
                    'debug',
                    f"Battery/device limits overrode bounded result for {mode_label} slot {slot_time or '?'} "
                    f"({slot_key or 'no-key'}): bounded={target_power}, applied={actual_power}, "
                    f"min={min_power}, max={max_power}"
                )

        if not success:
            return PowerResult(
                success=False,
                power=actual_power,
                error=f"Failed to set power feed: {error_msg}",
                requested_power=requested_power,
                target_power=target_power,
                max_delta_limited=max_delta_limited,
                reversal_ramp_active=reversal_ramp_active,
            )

        return PowerResult(
            success=True,
            power=actual_power,
            requested_power=requested_power,
            target_power=target_power,
            max_delta_limited=max_delta_limited,
            reversal_ramp_active=reversal_ramp_active,
        )

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
            self.log('error', f"Error reading Zendure device state from {url}: {e}")
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
        # Expected shape from the schedule API: {"time": "HHmm", "value": int|"netzero"|"netzero+"|"netzero-"|None, "key": str|None}
        self.last_schedule_entry: Optional[Dict[str, Any]] = None

    def _get_current_time_str(self) -> str:
        """
        Get current time in HHMM format using the shared installation timezone.

        Returns:
            Current time as string in "HHMM" format (e.g., "1902")
        """
        now = datetime.now(tz=self.timezone)
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
            self.schedule_date = datetime.now(tz=self.timezone).date()

            current_time_str = self._get_current_time_str()
            self.log(
                'info',
                f"Schedule fetched successfully. Current time: {current_time_str}, Resolved entries: {len(resolved)}",
                message_key='schedule_fetch_success',
            )

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
        ) -> Optional[Union[int, Literal['netzero', 'netzero+', 'netzero-']]]:
        """
        Find the schedule value for the current time.

        Finds the resolved entry with the largest time that is still <= current_time.

        Args:
            resolved: List of resolved schedule entries, each with 'time' and 'value' keys
            current_time: Current time in "HHMM" format (e.g., "1811" or "2300")

        Returns:
            The value from the matching entry (int, 'netzero', 'netzero+', 'netzero-'), or None if no match found

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
                self.log(
                    'warning',
                    f"No valid entries found for current time {current_time}",
                    message_key='schedule_no_valid_entries',
                )
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
                'min_power': matching_entry.get('min_power'),
                'max_power': matching_entry.get('max_power'),
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
        ) -> Optional[Union[int, Literal['netzero', 'netzero+', 'netzero-']]]:
        """
        Determine desired power setting based on current schedule.

        Args:
            refresh: If True, fetch fresh data from API; if False, use cached data

        Returns:
            Desired power value (int, 'netzero', 'netzero+', 'netzero-', or None)

        Raises:
            ValueError: If schedule data is invalid or missing required fields
            requests.exceptions.RequestException: On network errors when refresh=True
        """
        today = datetime.now(tz=self.timezone).date()

        # Invalidate the cached schedule when the local day changes so the first
        # lookup after midnight always uses the newly resolved API data.
        if self.schedule_date is not None and self.schedule_date != today:
            self.log(
                'info',
                f"Schedule cache date rollover detected ({self.schedule_date} -> {today}); refreshing schedule",
                message_key='schedule_date_rollover_refresh',
            )
            refresh = True

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
