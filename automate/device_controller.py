#!/usr/bin/env python3
"""
Device Controller - OOP wrapper for Zendure battery control and data reading

This module provides object-oriented interfaces for controlling the Zendure
battery system and reading data from P1 meter and Zendure devices, based on
the functionality in zero_feed_in_controller.py.
"""

import json
import time
from dataclasses import dataclass
from datetime import datetime, date, timedelta
from pathlib import Path
from typing import Optional, Tuple, Dict, Any, Union, Literal, List
from zoneinfo import ZoneInfo

import requests


# ============================================================================
# GLOBAL CONSTANTS
# ============================================================================

# NOTE: TEST_MODE is now configurable via config.json (key: "TEST_MODE").
# This global remains for backward compatibility, but is overridden at runtime
# when BaseDeviceController loads the config.
TEST_MODE = False               # If True, operations are simulated but not applied
MIN_CHARGE_LEVEL = 20          # Legacy default min SoC (%) (config key: MIN_CHARGE_LEVEL)
MAX_CHARGE_LEVEL = 90          # Legacy default max SoC (%) (config key: MAX_CHARGE_LEVEL)
MAX_DISCHARGE_POWER = 800      # Maximum allowed power feed in watts
MAX_CHARGE_POWER = 1200        # Maximum allowed power feed in watts


# ============================================================================
# SHARED READER (SINGLETON)
# ============================================================================
_SHARED_DEVICE_DATA_READER = None
_SHARED_DEVICE_DATA_READER_CONFIG_PATH: Optional[Path] = None


def get_reader(config_path: Optional[Path] = None) -> "DeviceDataReader":
    """
    Return a shared, long-lived DeviceDataReader instance.

    This avoids re-loading/parsing config.json and recreating readers on every loop.
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


class BaseDeviceController:
    """
    Base class for device controllers that share common functionality.
    
    Provides config loading, logging, and common utilities for device operations.
    """
    
    # Network settings
    REQUEST_TIMEOUT = 5  # Timeout in seconds for HTTP requests
    
    def __init__(self, config_path: Optional[Path] = None):
        """
        Initialize the base controller.
        
        Args:
            config_path: Optional path to config.json. If None, will search for
                        config in standard locations (project root or local).
        
        Raises:
            FileNotFoundError: If config file not found
            ValueError: If config is invalid
        """
        self.config_path = config_path or self._find_config_file()
        self.config = self._load_config(self.config_path)

        # Apply config-driven test mode once at initialization.
        # We keep both an instance attribute and the legacy global for existing code paths.
        global TEST_MODE
        self.test_mode = bool(self.config.get("TEST_MODE", TEST_MODE))
        TEST_MODE = self.test_mode

        # Battery SoC limits (single source of truth: config.json)
        # Fallbacks are the legacy defaults (20/90) when keys are missing.
        def _parse_soc(key: str, default: int) -> int:
            try:
                return int(self.config.get(key, default))
            except (TypeError, ValueError):
                return int(default)

        min_soc = _parse_soc("MIN_CHARGE_LEVEL", MIN_CHARGE_LEVEL)
        max_soc = _parse_soc("MAX_CHARGE_LEVEL", MAX_CHARGE_LEVEL)

        # Clamp to [0, 100]
        min_soc = max(0, min(100, min_soc))
        max_soc = max(0, min(100, max_soc))

        # Normalize to avoid nonsensical configs
        if min_soc > max_soc:
            max_soc = min_soc

        self.min_charge_level = min_soc
        self.max_charge_level = max_soc
        
    def _find_config_file(self) -> Path:
        """
        Find config.json file with fallback logic.
        Checks project root config first, then local config.
        
        Returns:
            Path to the config file that exists
        
        Raises:
            FileNotFoundError: If neither config file exists
        """
        script_dir = Path(__file__).parent
        root_config = script_dir.parent / "config" / "config.json"
        local_config = script_dir / "config" / "config.json"
        
        if root_config.exists():
            return root_config
        elif local_config.exists():
            return local_config
        else:
            raise FileNotFoundError(
                f"Config file not found in either location:\n"
                f"  1. {root_config}\n"
                f"  2. {local_config}"
            )
    
    def _load_config(self, config_path: Path) -> Dict[str, Any]:
        """
        Load configuration from config.json.
        
        Args:
            config_path: Path to config.json file
        
        Returns:
            dict: Configuration dictionary
        
        Raises:
            FileNotFoundError: If config file not found
            ValueError: If config is invalid
        """
        try:
            with open(config_path, "r") as f:
                config = json.load(f)
        except FileNotFoundError:
            raise FileNotFoundError(f"Config file not found: {config_path}")
        except json.JSONDecodeError as e:
            raise ValueError(f"Invalid JSON in config file {config_path}: {e}")
        
        return config
    
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
    
    Tracks energy (watt-hours) for both power feed and P1 meter readings
    across multiple time periods: quarter-hour, hour, day, and manual.
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
        
        # P1 hourly energy tracking (for total_power_import_kwh and total_power_export_kwh)
        self.p1_hourly_reference: Optional[Dict[str, float]] = None  # Reference values: {'import_kwh': X, 'export_kwh': Y}
        self.p1_hourly_last_reset_hour: Optional[int] = None  # Last hour when reference was reset (0-23)
        # JSON file path for hourly energy data (in data/ directory)
        script_dir = Path(__file__).parent
        data_dir = script_dir.parent / "data"
        self.p1_hourly_json_path = data_dir / "p1_hourly_energy.json"
        self.p1_hourly_data: Dict[str, Dict[str, Dict[str, float]]] = {}  # {date: {hour: {import_delta_kwh, export_delta_kwh}}}
        self.last_zendure_data: Optional[dict] = None
        
        # Load persisted data on initialization
        self._load_p1_hourly_data()
    
    def _log(self, level: str, message: str):
        """Helper method to log messages using the logger if available."""
        if self.logger:
            self.logger.log(level, message, file_path=self.log_file_path)
    
    def _load_p1_hourly_data(self) -> None:
        """
        Load P1 hourly reference values and JSON data from file on startup.
        
        Loads reference values and hourly data from JSON file if it exists.
        If file doesn't exist or is invalid, starts with empty state.
        """
        if not self.p1_hourly_json_path.exists():
            # File doesn't exist yet, start fresh
            return
        
        try:
            with open(self.p1_hourly_json_path, 'r') as f:
                data = json.load(f)
            
            # Load reference values from _metadata key if present
            metadata = data.get('_metadata', {})
            if metadata:
                # Preferred (new) format: values stored in kWh
                ref_import = metadata.get('reference_import_kwh')
                ref_export = metadata.get('reference_export_kwh')

                # Backward compatibility: older files stored Wh as integers
                if ref_import is None and metadata.get('reference_import_wh') is not None:
                    ref_import = float(metadata.get('reference_import_wh')) / 1000.0
                if ref_export is None and metadata.get('reference_export_wh') is not None:
                    ref_export = float(metadata.get('reference_export_wh')) / 1000.0
                last_reset_hour = metadata.get('last_reset_hour')
                
                if ref_import is not None and ref_export is not None:
                    self.p1_hourly_reference = {
                        'import_kwh': float(ref_import),
                        'export_kwh': float(ref_export)
                    }
                if last_reset_hour is not None:
                    self.p1_hourly_last_reset_hour = int(last_reset_hour)
            
            # Load hourly data (exclude _metadata key)
            self.p1_hourly_data = {
                date_str: hour_data
                for date_str, hour_data in data.items()
                if date_str != '_metadata'
            }
            
        except (json.JSONDecodeError, KeyError, ValueError, OSError) as e:
            # File exists but is invalid, start fresh
            self.p1_hourly_data = {}
            self.p1_hourly_reference = None
            self.p1_hourly_last_reset_hour = None
    
    def _save_p1_hourly_data(self) -> None:
        """
        Save P1 hourly reference values and JSON data to file.
        
        Creates data directory if it doesn't exist and saves both
        reference values (in _metadata) and hourly data.
        """
        try:
            # Create data directory if it doesn't exist
            self.p1_hourly_json_path.parent.mkdir(parents=True, exist_ok=True)
            
            # Prepare data structure with metadata and hourly data
            data_to_save = {
                '_metadata': {
                    # Store reference values in kWh (preferred format; matches loader expectations)
                    'reference_import_kwh': float(self.p1_hourly_reference.get('import_kwh')) if self.p1_hourly_reference else None,
                    'reference_export_kwh': float(self.p1_hourly_reference.get('export_kwh')) if self.p1_hourly_reference else None,
                    'last_reset_hour': self.p1_hourly_last_reset_hour,
                }
            }
            # Add hourly data
            data_to_save.update(self.p1_hourly_data)
            
            # Write to file
            with open(self.p1_hourly_json_path, 'w') as f:
                json.dump(data_to_save, f, indent=2)
            
        except (OSError, json.JSONEncodeError):
            # Don't crash if persistence fails
            pass
     
    def accumulate_p1_reading_hourly(self, import_kwh: float, export_kwh: float) -> Tuple[float, float]:
        """
        Accumulate P1 meter hourly energy deltas from cumulative kWh readings.
        
        Tracks hourly energy changes (deltas) from P1 meter cumulative readings
        (total_power_import_kwh and total_power_export_kwh). Maintains reference
        values that reset at the start of each hour and stores hourly delta
        measurements in a JSON file organized by date.
        
        Args:
            import_kwh: Cumulative import energy in kWh from P1 meter
            export_kwh: Cumulative export energy in kWh from P1 meter

        Returns:
            Tuple[float, float]: (import_delta_kwh, export_delta_kwh) for the current hour
        """
        # Get current time in Europe/Amsterdam timezone
        tz = ZoneInfo('Europe/Amsterdam')
        now = datetime.now(tz=tz)
        current_hour = now.hour
        current_date_str = now.strftime('%Y-%m-%d')
        current_hour_str = now.strftime('%H')
        
        # Initialize reference if needed (first call or reference is None/0)
        if (self.p1_hourly_reference is None or 
            self.p1_hourly_reference.get('import_kwh', 0) == 0 or 
            self.p1_hourly_reference.get('export_kwh', 0) == 0):
            # Set first measurement as reference
            self.p1_hourly_reference = {
                'import_kwh': float(import_kwh),
                'export_kwh': float(export_kwh)
            }
            self.p1_hourly_last_reset_hour = current_hour
            self._log('info', f"P1 hourly reference set: import={import_kwh:.3f} kWh, export={export_kwh:.3f} kWh")
            # Save initial state
            self._save_p1_hourly_data()
            return 0.0, 0.0
        
        # Calculate deltas from reference
        import_delta = float(import_kwh) - self.p1_hourly_reference['import_kwh']
        export_delta = float(export_kwh) - self.p1_hourly_reference['export_kwh']
        
        # Detect hour boundary: check if we're at or past a new hour
        # Only reset once per hour - if last_reset_hour differs from current_hour, we need to reset
        should_reset = False
        if self.p1_hourly_last_reset_hour is None:
            # First time tracking, reset now
            should_reset = True
        elif self.p1_hourly_last_reset_hour != current_hour:
            # Different hour - we're at or past a new hour, reset now
            should_reset = True
        
        if should_reset:
            # Store last hour's measurement (delta values) before resetting reference
            # Calculate what the last hour's delta was (before reset)
            last_hour_delta_import = float(import_kwh) - self.p1_hourly_reference['import_kwh']
            last_hour_delta_export = float(export_kwh) - self.p1_hourly_reference['export_kwh']
            
            # Determine which date/hour to store this measurement in
            # If we just crossed the hour boundary, store in the previous hour
            # But if last_reset_hour is None, this is the first reset, so store in current hour
            if self.p1_hourly_last_reset_hour is not None:
                # We crossed an hour boundary - store in the previous hour
                # Calculate previous hour and potentially previous date
                prev_hour = self.p1_hourly_last_reset_hour
                store_date_str = current_date_str
                # Handle date boundary (if we went from 23 to 0)
                if current_hour == 0 and self.p1_hourly_last_reset_hour == 23:
                    # Went back a day
                    store_date_str = (now - timedelta(days=1)).strftime('%Y-%m-%d')
                store_hour_str = f"{prev_hour:02d}"
            else:
                # First reset ever, store in current hour (though this is unusual)
                store_date_str = current_date_str
                store_hour_str = current_hour_str
            
            # Initialize date entry if needed
            if store_date_str not in self.p1_hourly_data:
                self.p1_hourly_data[store_date_str] = {}
            
            electric_level = None
            if self.last_zendure_data:
                props = self.last_zendure_data.get("properties", {})
                electric_level = props.get("electricLevel")

            # Store the last hour's delta values
            self.p1_hourly_data[store_date_str][store_hour_str] = {
                'import_delta_wh': int(last_hour_delta_import*1000),
                'export_delta_wh': int(last_hour_delta_export*1000),
                'electric_level': electric_level,
            }
            
            self._log('info', f"Hourly measurement stored for {store_date_str} {store_hour_str}:00 - "
                    f"import_delta={int(last_hour_delta_import*1000)} Wh, export_delta={int(last_hour_delta_export*1000)} Wh")
            
            # Reset reference values to current values
            self.p1_hourly_reference = {
                'import_kwh': float(import_kwh),
                'export_kwh': float(export_kwh)
            }
            self.p1_hourly_last_reset_hour = current_hour
            
            self._log('info', f"P1 hourly reference reset at {current_hour:02d}:00 - "
                    f"new reference: import={import_kwh:.3f} kWh, export={export_kwh:.3f} kWh")
            
            # Save data after reset
            self._save_p1_hourly_data()
        # Note: We don't save on every call, only when reference resets to avoid excessive I/O

        return import_delta, export_delta
    
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
            config_path: Optional path to config.json. If None, will search for
                        config in standard locations (project root or local).
        
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
            raise ValueError("deviceIp not found in config.json")
        if not device_sn:
            raise ValueError("deviceSn not found in config.json")
        
        self.device_ip = device_ip
        self.device_sn = device_sn
        self.previous_power = None  # Track the last successfully set power value (internal convention)
        self.limit_state = 0  # Battery limit state: -1 (MIN), 0 (OK), 1 (MAX)
        
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
        elif power_feed < -1:
            # Discharge mode: acMode 2 = Output
            return {
                "acMode": 2,
                "outputLimit": int(abs(power_feed)),
                "inputLimit": 0,
                "smartMode": 1,
            }
        elif stand_by:
            # Go into Stand-by mode
            self.log('info', "Going into Stand-by mode")
            return {
                "acMode": 0,
                "inputLimit": 0,
                "outputLimit": 0,
                "smartMode": 1,
                }
        else:
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

        if power_feed < -MAX_DISCHARGE_POWER:
            self.log('warning', f"Power feed ({power_feed} W) exceeds MAX_DISCHARGE_POWER ({MAX_DISCHARGE_POWER} W), limiting discharge.")
            power_feed = -MAX_DISCHARGE_POWER
        if power_feed > MAX_CHARGE_POWER:
            self.log('warning', f"Power feed ({power_feed} W) exceeds MAX_CHARGE_POWER ({MAX_CHARGE_POWER} W), limiting charge")
            power_feed = MAX_CHARGE_POWER
        
        # Check if the new power value is the same as the previous one
        if self.previous_power is not None and power_feed == self.previous_power:
            self.log('info', f"Power value unchanged ({power_feed} W), skipping device update")
            # Still accumulate since power is being maintained (operation is successful)
            return (True, None, power_feed)
        
        url = f"http://{self.device_ip}/properties/write"
    
        # Construct properties based on power_feed value
        properties = self._build_device_properties(power_feed)
        payload = {"sn": self.device_sn, "properties": properties}
        
        if self.test_mode:
            self.log('info', f"TEST MODE: Would set power feed to {power_feed} W")
            return (True, None, power_feed)
        
        try:
            self.log('info', f"Setting power feed to {power_feed} W...")
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
        
        This method reads P1 meter data and current Zendure state, then calculates
        what power setting is needed to achieve zero feed-in.
        
        Args:
            mode: 'netzero' (can charge or discharge) or 'netzero+' (only charge, no discharge)
            p1_data: Optional pre-read P1 meter data. If provided, will be used instead of reading again.
        
        Returns:
            int: Power value in watts (positive=charge, negative=discharge, 0=stop)
        
        Raises:
            ValueError: If P1 meter or Zendure data cannot be read
            requests.exceptions.RequestException: On network errors
        """
        # Use DeviceDataReader to get current data
        reader = get_reader(self.config_path)
        
        # Read P1 meter data if not provided
        if p1_data is None:
            p1_data = reader.read_p1_meter(update_json=True)
            if not p1_data:
                raise ValueError("Failed to read P1 meter data")
        else:
            # If P1 data was provided, still update JSON to ensure it's stored
            reader.read_p1_meter(update_json=True)
        
        p1_power = p1_data.get("total_power")
        if p1_power is None:
            raise ValueError("P1 meter data missing 'total_power' field")
        
        self.log('debug', f"P1 power (grid-status): {p1_power}")
        
        # Read Zendure state
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
        
        # Calculate new settings
        new_input, new_output = self._calculate_new_settings(
            p1_power=p1_power,
            current_input=current_input,
            current_output=current_output,
            electric_level=electric_level,
        )
        
        # Convert to CLI convention (positive=charge, negative=discharge)
        # Handle netzero+ mode (no discharge, only charge)
        if mode == 'netzero+':
            # If calculation says to discharge, return 1 (netzero+ doesn't discharge)
            if new_output > 0:
                return 0
            else:
                # Charging or stopped - if stopped (0), return 1 to avoid standby
                return new_input if new_input > 0 else 0
        else:
            # Regular netzero mode
            if new_output > 0:
                # Discharging: return negative value
                return -new_output
            elif new_input > 0:
                # Charging: return positive value
                return new_input
            else:
                return 0
    
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
            p1_data: Optional pre-read P1 meter data. If provided and value is netzero/netzero+,
                     will be used instead of reading P1 meter again.
        
        Returns:
            PowerResult: Result object with success status, power value, and optional error message
        
        Raises:
            ValueError: If value is invalid
            Exception: On device communication errors
        
        Note:
            Test mode is controlled by config.json key "TEST_MODE".
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
        elif value == 'netzero' or value == 'netzero+' or value is None:
            # Determine mode (default to 'netzero' if None)
            mode = value if value is not None else 'netzero'
            
            try:
                # Calculate the actual power value needed
                # Pass p1_data if provided to avoid reading P1 meter again
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
        
        else:
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
    Class for reading data from P1 meter and Zendure battery devices via API calls.
    
    This class handles reading device data and automatically storing it via API endpoints.
    """
    
    # Config keys
    CONFIG_KEY_P1_METER_IP = "p1MeterIp"
    CONFIG_KEY_P1_METER = "p1Meter"
    CONFIG_KEY_DEVICE_IP = "deviceIp"
    
    # API endpoints
    API_ENDPOINT_PROPERTIES_REPORT = "/properties/report"
    
    # Data field names
    FIELD_TOTAL_POWER = "total_power"
    FIELD_TIMESTAMP = "timestamp"
    FIELD_PROPERTIES = "properties"
    FIELD_PACK_DATA = "packData"
    
    def __init__(self, config_path: Optional[Path] = None):
        """
        Initialize the DeviceDataReader.
        
        Args:
            config_path: Optional path to config.json. If None, will search for
                        config in standard locations (project root or local).
        
        Raises:
            FileNotFoundError: If config file not found
            ValueError: If config is invalid or missing required keys
        """
        super().__init__(config_path)
        
        # Load P1 meter config (new structure with ip/endpoint/path)
        p1_meter_config = self.config.get(self.CONFIG_KEY_P1_METER, {})
        if p1_meter_config and "ip" in p1_meter_config:
            # New config structure: p1Meter object with ip, endpoint, totalPowerPath
            self.p1_meter_ip = p1_meter_config.get("ip")
            self.p1_meter_endpoint = p1_meter_config.get("endpoint", self.API_ENDPOINT_PROPERTIES_REPORT)
            self.p1_total_power_path = p1_meter_config.get("totalPowerPath", self.FIELD_TOTAL_POWER)
        else:
            # Backward compatibility: old config structure with p1MeterIp at top level
            self.p1_meter_ip = self.config.get(self.CONFIG_KEY_P1_METER_IP)
            self.p1_meter_endpoint = self.API_ENDPOINT_PROPERTIES_REPORT
            self.p1_total_power_path = self.FIELD_TOTAL_POWER
        
        self.device_ip = self.config.get(self.CONFIG_KEY_DEVICE_IP)
    
    def _store_data_via_api(
        self,
        api_url: Optional[str],
        data: dict,
        data_type: str = "data",
        ) -> bool:
        """
        Store data via data_api.php endpoint.
        
        Args:
            api_url: API endpoint URL (from config)
            data: Data dictionary to store
            data_type: Type of data for logging (e.g., "P1 meter data", "Zendure data")
        
        Returns:
            bool: True if storage was successful, False otherwise (warnings logged, doesn't raise)
        """
        if not api_url:
            self.log('warning', f"{data_type} API URL not found in config.json, skipping storage")
            return False
        
        try:
            store_response = requests.post(
                api_url,
                json=data,
                timeout=self.REQUEST_TIMEOUT,
                headers={"Content-Type": "application/json"},
            )
            store_response.raise_for_status()
            store_result = store_response.json()
            
            if store_result.get("success", False):
                # self.log('info', f"{data_type} stored via API: {store_result.get('file', 'data.json')}")
                return True
            else:
                error_msg = store_result.get("error", "Unknown API error")
                self.log('warning', f"API returned error when storing {data_type}: {error_msg}")
                return False
        except Exception as e:
            # Log warning but don't fail - reading was successful
            self.log('warning', f"Failed to store {data_type} via API: {e}")
            return False
    
    def _get_json_value(self, data: dict, path: str):
        """
        Navigate nested JSON structure using dot notation.
        
        Args:
            data: JSON dictionary to navigate
            path: Dot-separated path (e.g., "data.total_power" or "total_power")
        
        Returns:
            Value at path, or None if path doesn't exist
        """
        keys = path.split('.')
        value = data
        for key in keys:
            if isinstance(value, dict):
                value = value.get(key)
            else:
                return None
            if value is None:
                return None
        return value
    
    def _get_p1_api_url(self) -> Optional[str]:
        """
        Get the P1 meter API URL from config.
        
        Supports both new config structure (p1Meter object) and old structure (p1MeterIp).
        
        Returns:
            Full API URL string, or None if not configured
        """
        if not self.p1_meter_ip:
            return None
        
        return f"http://{self.p1_meter_ip}{self.p1_meter_endpoint}"
    
    def read_p1_meter(self, update_json: bool = True) -> Optional[dict]:
        """
        Read data from P1 meter device via API call.
        
        Args:
            update_json: If True, store data via API endpoint (default: True)
        
        Returns:
            dict: Raw P1 meter data from device, or None on error
        """
        url = self._get_p1_api_url()
        if not url:
            self.log('error', "P1 meter configuration not found in config.json (check p1Meter or p1MeterIp)")
            return None
        
        try:
            response = requests.get(url, timeout=self.REQUEST_TIMEOUT)
            response.raise_for_status()
            data = response.json()
            
            # Extract total_power using configured JSON path
            total_power = self._get_json_value(data, self.p1_total_power_path)
            
            # Debug: log if extraction fails
            if total_power is None:
                self.log('warning', f"Failed to extract total_power using path '{self.p1_total_power_path}'. "
                          f"Available keys in response: {list(data.keys())[:10]}")  # Show first 10 keys
            
            # Store via API if requested
            if update_json:
                # Prepare reading data with timestamp
                reading_data = {
                    self.FIELD_TIMESTAMP: datetime.now().isoformat(),
                    self.FIELD_TOTAL_POWER: total_power,
                }
                
                # Store via data_api.php endpoint (non-fatal)
                # Derive p1StoreApiUrl from dataApiUrl based on location
                location = self.config.get("location", "remote")
                if location == "local":
                    base_url = self.config.get("dataApiUrl-local")
                else:
                    base_url = self.config.get("dataApiUrl")
                
                if base_url:
                    api_url = base_url + ("&" if "?" in base_url else "?") + "type=zendure_p1"
                else:
                    api_url = None
                
                self._store_data_via_api(api_url, reading_data, "P1 meter data")
            
            # Add total_power to returned data for use by accumulation code
            # Return the raw device data with total_power added
            result = data.copy()
            result[self.FIELD_TOTAL_POWER] = total_power
            return result
        
        except requests.exceptions.RequestException as e:
            self.log('error', f"Error reading from P1 meter at {url}: {e}")
            return None
        except (json.JSONDecodeError, KeyError) as e:
            self.log('error', f"Error parsing P1 response: {e}")
            return None
    
    def read_zendure(self, update_json: bool = True) -> Optional[dict]:
        """
        Read data from Zendure battery device via API call.
        
        Args:
            update_json: If True, store data via API endpoint (default: True)
        
        Returns:
            dict: Raw Zendure device data from device, or None on error
        """
        if not self.device_ip:
            self.log('error', f"{self.CONFIG_KEY_DEVICE_IP} not found in config.json")
            return None
        
        # Read from Zendure device directly
        url = f"http://{self.device_ip}{self.API_ENDPOINT_PROPERTIES_REPORT}"
        
        try:
            response = requests.get(url, timeout=self.REQUEST_TIMEOUT)
            response.raise_for_status()
            data = response.json()
            
            # Extract properties and pack data
            props = data.get(self.FIELD_PROPERTIES, {})
            packs = data.get(self.FIELD_PACK_DATA, [])

            self.last_zendure_data = data
            
            # Store via API if requested
            if update_json:
                # Prepare reading data with timestamp
                reading_data = {
                    self.FIELD_TIMESTAMP: datetime.now().isoformat(),
                    self.FIELD_PROPERTIES: props,
                    self.FIELD_PACK_DATA: packs,
                }
                
                # Store via data_api.php endpoint (non-fatal)
                # Derive zendureStoreApiUrl from dataApiUrl based on location
                location = self.config.get("location", "remote")
                if location == "local":
                    base_url = self.config.get("dataApiUrl-local")
                else:
                    base_url = self.config.get("dataApiUrl")
                
                if base_url:
                    api_url = base_url + ("&" if "?" in base_url else "?") + "type=zendure"
                else:
                    api_url = None
                
                self._store_data_via_api(api_url, reading_data, "Zendure data")
            
            # Return the raw device data (not the stored format)
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
            config_path: Optional path to config.json. If None, will search for
                        config in standard locations (project root or local).
        
        Raises:
            FileNotFoundError: If config file not found
            ValueError: If config is invalid or missing required keys
        """
        super().__init__(config_path)
        self.schedule_data: Optional[List[Dict[str, Any]]] = None
        self.schedule_date: Optional[date] = None
    
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
            raise ValueError(f"{self.CONFIG_KEY_SCHEDULE_API_URL} not found in config.json")
        
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
                if 'time' in entry and isinstance(entry['time'], (str, int))
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
                return None
            
            # Find the entry with the maximum time (closest but not exceeding)
            max_time, matching_entry = max(valid_entries_with_int_time, key=lambda x: x[0])
            
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
    
