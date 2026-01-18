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

TEST_MODE = True               # Global test mode flag - if True, operations are simulated but not applied
MIN_CHARGE_LEVEL = 20          # Minimum battery level (%) - stop discharging below this
MAX_CHARGE_LEVEL = 95          # Maximum battery level (%) - stop charging above this
MAX_DISCHARGE_POWER = 800      # Maximum allowed power feed in watts
MAX_CHARGE_POWER = 1200        # Maximum allowed power feed in watts


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
        
        # Power feed accumulation state
        self.power_feed_accumulators: Dict[str, float] = {
            'quarter': 0.0,  # Quarter-hour accumulator (resets at 0, 15, 30, 45 min)
            'hour': 0.0,     # Hourly accumulator (resets at full hour)
            'day': 0.0,      # Daily accumulator (resets at midnight)
            'manual': 0.0,   # Manual accumulator (only resets when set to 0)
        }
        self.power_feed_tracking: Dict[str, Any] = {
            'last_power_feed': None,      # Last power_feed value sent
            'last_update_time': None,     # Timestamp of last update
            'last_quarter': None,         # Last quarter-hour period (0, 15, 30, 45)
            'last_hour': None,            # Last hour (0-23)
            'last_date': None,            # Last date
        }
        
        # P1 meter accumulation state
        self.p1_accumulators: Dict[str, float] = {
            'quarter': 0.0,  # Quarter-hour accumulator (resets at 0, 15, 30, 45 min)
            'hour': 0.0,     # Hourly accumulator (resets at full hour)
            'day': 0.0,      # Daily accumulator (resets at midnight)
            'manual': 0.0,   # Manual accumulator (only resets when set to 0)
        }
        self.p1_tracking: Dict[str, Any] = {
            'last_p1_power': None,        # Last P1 power value read
            'last_update_time': None,     # Timestamp of last update
            'last_quarter': None,         # Last quarter-hour period (0, 15, 30, 45)
            'last_hour': None,            # Last hour (0-23)
            'last_date': None,            # Last date
        }
        
        # P1 hourly energy tracking (for total_power_import_kwh and total_power_export_kwh)
        self.p1_hourly_reference: Optional[Dict[str, float]] = None  # Reference values: {'import_kwh': X, 'export_kwh': Y}
        self.p1_hourly_last_reset_hour: Optional[int] = None  # Last hour when reference was reset (0-23)
        # JSON file path for hourly energy data (in data/ directory)
        script_dir = Path(__file__).parent
        data_dir = script_dir.parent / "data"
        self.p1_hourly_json_path = data_dir / "p1_hourly_energy.json"
        self.p1_hourly_data: Dict[str, Dict[str, Dict[str, float]]] = {}  # {date: {hour: {import_delta_kwh, export_delta_kwh}}}
        
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
                ref_import = metadata.get('reference_import_kwh')
                ref_export = metadata.get('reference_export_kwh')
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
            
            self._log('info', f"Loaded P1 hourly data from {self.p1_hourly_json_path}")
        except (json.JSONDecodeError, KeyError, ValueError, OSError) as e:
            # File exists but is invalid, start fresh
            self._log('warning', f"Failed to load P1 hourly data from {self.p1_hourly_json_path}: {e}. Starting fresh.")
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
                    'reference_import_kwh': self.p1_hourly_reference.get('import_kwh') if self.p1_hourly_reference else None,
                    'reference_export_kwh': self.p1_hourly_reference.get('export_kwh') if self.p1_hourly_reference else None,
                    'last_reset_hour': self.p1_hourly_last_reset_hour
                }
            }
            # Add hourly data
            data_to_save.update(self.p1_hourly_data)
            
            # Write to file
            with open(self.p1_hourly_json_path, 'w') as f:
                json.dump(data_to_save, f, indent=2)
            
        except (OSError, json.JSONEncodeError) as e:
            # Log error but don't crash
            self._log('warning', f"Failed to save P1 hourly data to {self.p1_hourly_json_path}: {e}")
    
    def _accumulate_with_boundary(
        self,
        accumulators: Dict[str, float],
        tracking: Dict[str, Any],
        value_key: str,
        accumulator_key: str,
        last_period_key: str,
        current_period: Union[int, date],
        boundary_time_func,
        total_time_delta: float,
        period_name: str
        ) -> Union[int, date]:
        """
        Accumulate energy handling period boundary crossing.
        
        Args:
            accumulators: Dictionary of accumulator values
            tracking: Dictionary of tracking state
            value_key: Key in tracking dict for the last value (e.g., 'last_power_feed' or 'last_p1_power')
            accumulator_key: Key in accumulators dict (e.g., 'quarter', 'hour', 'day')
            last_period_key: Key in tracking dict for last period (e.g., 'last_quarter')
            current_period: Current period value
            boundary_time_func: Function to calculate next boundary time
            total_time_delta: Time delta in hours since last update
            period_name: Name of the period for logging (e.g., 'hour')
        
        Returns:
            Updated period value
        """
        last_period = tracking[last_period_key]
        if last_period is None:
            # First time, just accumulate and update period
            energy_wh = tracking[value_key] * total_time_delta
            accumulators[accumulator_key] += energy_wh
            return current_period
        
        if last_period != current_period:
            # Boundary crossed - split accumulation
            boundary_time = boundary_time_func(tracking['last_update_time'])
            time_to_boundary = (boundary_time - tracking['last_update_time']).total_seconds() / 3600.0
            
            # Accumulate up to boundary for old period
            energy_to_boundary = tracking[value_key] * time_to_boundary
            accumulators[accumulator_key] += energy_to_boundary
            
            # For hour boundary, log the accumulated power for the last hour before reset
            if accumulator_key == 'hour':
                accumulated_wh = accumulators[accumulator_key]
                value_type = "power feed" if value_key == 'last_power_feed' else "P1 meter"
                self._log(
                    'info',
                    f"Hour boundary crossed. Accumulated {value_type} for last hour: {accumulated_wh:+.2f} Wh"
                )
            
            # Reset for new period
            accumulators[accumulator_key] = 0.0
            
            # Accumulate remaining time in new period
            remaining_time = total_time_delta - time_to_boundary
            if remaining_time > 0:
                energy_remaining = tracking[value_key] * remaining_time
                accumulators[accumulator_key] += energy_remaining
            
            return current_period
        else:
            # No boundary crossed, accumulate normally
            energy_wh = tracking[value_key] * total_time_delta
            accumulators[accumulator_key] += energy_wh
            return last_period
    
    def _accumulate_value(
        self,
        accumulators: Dict[str, float],
        tracking: Dict[str, Any],
        value_key: str,
        value: int,
        label: str
        ) -> None:
        """
        Generic method to accumulate power values over time periods.
        
        Args:
            accumulators: Dictionary of accumulator values (quarter, hour, day, manual)
            tracking: Dictionary of tracking state (last_update_time, last_quarter, etc.)
            value_key: Key name for the last value in tracking (e.g., 'last_power_feed')
            value: Current power value in watts
            label: Label for logging (e.g., 'Power feed' or 'P1 meter')
        """
        # Get current time in Europe/Amsterdam timezone
        tz = ZoneInfo('Europe/Amsterdam')
        now = datetime.now(tz=tz)
        current_quarter = (now.minute // 15) * 15  # 0, 15, 30, or 45
        current_hour = now.hour
        current_date = now.date()
        
        # First call - initialize timestamps and don't accumulate
        if tracking['last_update_time'] is None:
            tracking[value_key] = value
            tracking['last_update_time'] = now
            tracking['last_quarter'] = current_quarter
            tracking['last_hour'] = current_hour
            tracking['last_date'] = current_date
            # Log first call
            self._log(
                'info',
                f"{label} accumulation started. Initial power: {value} W"
            )
            return
        
        # Calculate total time delta in hours
        total_time_delta = (now - tracking['last_update_time']).total_seconds() / 3600.0
        
        # Handle quarter-hour boundary
        def quarter_boundary_time(last_time):
            """Calculate next quarter-hour boundary time."""
            last_minute = last_time.minute
            last_quarter = (last_minute // 15) * 15
            if last_quarter == 45:
                # Next boundary is at next hour
                return (last_time.replace(minute=0, second=0, microsecond=0) + timedelta(hours=1))
            else:
                # Next boundary is at next quarter
                return last_time.replace(minute=last_quarter + 15, second=0, microsecond=0)
        
        tracking['last_quarter'] = self._accumulate_with_boundary(
            accumulators,
            tracking,
            value_key,
            'quarter',
            'last_quarter',
            current_quarter,
            quarter_boundary_time,
            total_time_delta,
            "quarter"
        )
        
        # Handle hourly boundary
        def hour_boundary_time(last_time):
            """Calculate next hour boundary time."""
            return (last_time.replace(minute=0, second=0, microsecond=0) + timedelta(hours=1))
        
        tracking['last_hour'] = self._accumulate_with_boundary(
            accumulators,
            tracking,
            value_key,
            'hour',
            'last_hour',
            current_hour,
            hour_boundary_time,
            total_time_delta,
            "hour"
        )
        
        # Handle daily boundary
        def day_boundary_time(last_time):
            """Calculate next day boundary (midnight) time."""
            midnight = last_time.replace(hour=0, minute=0, second=0, microsecond=0)
            return midnight + timedelta(days=1)
        
        tracking['last_date'] = self._accumulate_with_boundary(
            accumulators,
            tracking,
            value_key,
            'day',
            'last_date',
            current_date,
            day_boundary_time,
            total_time_delta,
            "day"
        )
        
        # Manual accumulator always accumulates full time delta (never resets automatically)
        energy_wh = tracking[value_key] * total_time_delta
        accumulators['manual'] += energy_wh
        
        # Update tracking variables
        tracking[value_key] = value
        tracking['last_update_time'] = now
    
    def accumulate_power_feed(self, power_feed: int) -> None:
        """
        Accumulate power feed energy over time into four separate accumulators.
        
        Tracks energy (watt-hours) accumulated over:
        - Quarter-hour periods (resets at 0, 15, 30, 45 minutes)
        - Hourly periods (resets at full hour)
        - Daily periods (resets at midnight)
        - Manual accumulator (only resets when explicitly set to 0)
        
        Args:
            power_feed: Power feed value in watts (signed: positive=charge, negative=discharge)
        """
        self._accumulate_value(
            self.power_feed_accumulators,
            self.power_feed_tracking,
            'last_power_feed',
            power_feed,
            'Power feed'
        )
    
    def accumulate_p1_reading(self, p1_power: int) -> None:
        """
        Accumulate P1 meter power readings over time into four separate accumulators.
        
        Tracks energy (watt-hours) accumulated over:
        - Quarter-hour periods (resets at 0, 15, 30, 45 minutes)
        - Hourly periods (resets at full hour)
        - Daily periods (resets at midnight)
        - Manual accumulator (only resets when explicitly set to 0)
        
        Args:
            p1_power: P1 meter power value in watts (signed: positive=consumption, negative=production)
        """
        self._accumulate_value(
            self.p1_accumulators,
            self.p1_tracking,
            'last_p1_power',
            p1_power,
            'P1 meter'
        )
    
    def accumulate_p1_reading_hourly(self, import_kwh: float, export_kwh: float, total_power: int) -> None:
        """
        Accumulate P1 meter hourly energy deltas from cumulative kWh readings.
        
        Tracks hourly energy changes (deltas) from P1 meter cumulative readings
        (total_power_import_kwh and total_power_export_kwh). Maintains reference
        values that reset at the start of each hour and stores hourly delta
        measurements in a JSON file organized by date.
        
        Args:
            import_kwh: Cumulative import energy in kWh from P1 meter
            export_kwh: Cumulative export energy in kWh from P1 meter
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
            return
        
        # Calculate deltas from reference
        import_delta = float(import_kwh) - self.p1_hourly_reference['import_kwh']
        export_delta = float(export_kwh) - self.p1_hourly_reference['export_kwh']
        
        # Log deltas
        self._log('info', f"P1 hourly deltas: import_delta={import_delta:.3f} kWh, export_delta={export_delta:.3f} kWh, actual_power={total_power} W")
        
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
            
            # Store the last hour's delta values
            self.p1_hourly_data[store_date_str][store_hour_str] = {
                'import_delta_kwh': last_hour_delta_import,
                'export_delta_kwh': last_hour_delta_export
            }
            
            self._log('info', f"Hourly measurement stored for {store_date_str} {store_hour_str}:00 - "
                    f"import_delta={last_hour_delta_import:+.3f} kWh, export_delta={last_hour_delta_export:+.3f} kWh")
            
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
    
    def print_accumulators(self) -> None:
        """
        Debug method to log accumulator values and tracking information in a readable format.
        Displays both power feed and P1 meter accumulators.
        """
        if self.logger:
            self.logger.log('info', "=" * 60)
            self.logger.log('info', "Power Feed Accumulators Debug Information")
            self.logger.log('info', "=" * 60)
            
            # Log power feed accumulator values
            self.logger.log('info', "Power Feed Accumulators (Wh):")
            self.logger.log('info', f"  Quarter-hour: {self.power_feed_accumulators['quarter']:+.2f} Wh")
            self.logger.log('info', f"  Hour:         {self.power_feed_accumulators['hour']:+.2f} Wh")
            self.logger.log('info', f"  Day:          {self.power_feed_accumulators['day']:+.2f} Wh")
            self.logger.log('info', f"  Manual:       {self.power_feed_accumulators['manual']:+.2f} Wh")
            
            # Log power feed tracking information
            self.logger.log('info', "")
            self.logger.log('info', "Power Feed Tracking State:")
            last_power = self.power_feed_tracking['last_power_feed']
            last_time = self.power_feed_tracking['last_update_time']
            last_quarter = self.power_feed_tracking['last_quarter']
            last_hour = self.power_feed_tracking['last_hour']
            last_date = self.power_feed_tracking['last_date']
            
            self.logger.log('info', f"  Last power feed:  {last_power} W" if last_power is not None else "  Last power feed:  None")
            
            if last_time is not None:
                time_str = last_time.strftime("%Y-%m-%d %H:%M:%S %Z")
                self.logger.log('info', f"  Last update time: {time_str}")
            else:
                self.logger.log('info', "  Last update time: None")
            
            self.logger.log('info', f"  Last quarter:     {last_quarter}" if last_quarter is not None else "  Last quarter:     None")
            self.logger.log('info', f"  Last hour:        {last_hour:02d}" if last_hour is not None else "  Last hour:        None")
            self.logger.log('info', f"  Last date:        {last_date}" if last_date is not None else "  Last date:        None")
            
            # P1 meter accumulators section
            self.logger.log('info', "")
            self.logger.log('info', "=" * 60)
            self.logger.log('info', "P1 Meter Accumulators Debug Information")
            self.logger.log('info', "=" * 60)
            
            # Log P1 accumulator values
            self.logger.log('info', "P1 Meter Accumulators (Wh):")
            self.logger.log('info', f"  Quarter-hour: {self.p1_accumulators['quarter']:+.2f} Wh")
            self.logger.log('info', f"  Hour:         {self.p1_accumulators['hour']:+.2f} Wh")
            self.logger.log('info', f"  Day:          {self.p1_accumulators['day']:+.2f} Wh")
            self.logger.log('info', f"  Manual:       {self.p1_accumulators['manual']:+.2f} Wh")
            
            # Log P1 tracking information
            self.logger.log('info', "")
            self.logger.log('info', "P1 Meter Tracking State:")
            last_p1 = self.p1_tracking['last_p1_power']
            last_p1_time = self.p1_tracking['last_update_time']
            last_p1_quarter = self.p1_tracking['last_quarter']
            last_p1_hour = self.p1_tracking['last_hour']
            last_p1_date = self.p1_tracking['last_date']
            
            self.logger.log('info', f"  Last P1 power:     {last_p1} W" if last_p1 is not None else "  Last P1 power:     None")
            
            if last_p1_time is not None:
                time_str = last_p1_time.strftime("%Y-%m-%d %H:%M:%S %Z")
                self.logger.log('info', f"  Last update time: {time_str}")
            else:
                self.logger.log('info', "  Last update time: None")
            
            self.logger.log('info', f"  Last quarter:     {last_p1_quarter}" if last_p1_quarter is not None else "  Last quarter:     None")
            self.logger.log('info', f"  Last hour:        {last_p1_hour:02d}" if last_p1_hour is not None else "  Last hour:        None")
            self.logger.log('info', f"  Last date:        {last_p1_date}" if last_p1_date is not None else "  Last date:        None")
            
            self.logger.log('info', "=" * 60)


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
    MIN_CHARGE_LEVEL = 20          # Minimum battery level (%) - stop discharging below this
    MAX_CHARGE_LEVEL = 90          # Maximum battery level (%) - stop charging above this
    
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
            -1: Battery at or below MIN_CHARGE_LEVEL (discharge not allowed)
             0: Battery within acceptable range (no limits) or if read fails
             1: Battery at or above MAX_CHARGE_LEVEL (charge not allowed)
        """
        # Read Zendure data to get battery level
        reader = DeviceDataReader(config_path=self.config_path)
        zendure_data = reader.read_zendure(update_json=True)
        
        if not zendure_data:
            self.log('warning', "Failed to read Zendure data for battery limit check, assuming OK")
            self.limit_state = 0
            return
        
        # Extract battery level from properties
        props = zendure_data.get("properties", {})
        battery_level = props.get("electricLevel")
        
        if battery_level is None:
            self.log('warning', "Battery level not found in Zendure data, assuming OK")
            self.limit_state = 0
            return
        
        # Check limits
        if battery_level <= MIN_CHARGE_LEVEL:
            self.limit_state = -1
        elif battery_level >= MAX_CHARGE_LEVEL:
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
            self.log('warning', f"Battery at MAX_CHARGE_LEVEL ({MAX_CHARGE_LEVEL}%), preventing charge")
            power_feed = 0
        
        # If discharging (power_feed < 0) and at MIN_CHARGE_LEVEL, prevent discharge
        if power_feed < 0 and self.limit_state == -1:
            self.log('warning', f"Battery at MIN_CHARGE_LEVEL ({MIN_CHARGE_LEVEL}%), preventing discharge")
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
            self.accumulator.accumulate_power_feed(power_feed)
            return (True, None, power_feed)
        
        url = f"http://{self.device_ip}/properties/write"
    
        # Construct properties based on power_feed value
        properties = self._build_device_properties(power_feed)
        payload = {"sn": self.device_sn, "properties": properties}
        
        if TEST_MODE:
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
            
            # Accumulate power feed energy over time
            self.accumulator.accumulate_power_feed(power_feed)
            
            return (True, None, power_feed)
        
        except requests.exceptions.RequestException as e:
            return (False, str(e), original_power)
        except Exception as e:
            return (False, str(e), original_power)
    
    def print_accumulators(self) -> None:
        """Debug method to log accumulator values. Delegates to PowerAccumulator."""
        self.accumulator.print_accumulators()
    
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
            if electric_level > self.MAX_CHARGE_LEVEL and effective_desired < 0:
                effective_desired = 0
                self.log('warning', f"Charge level above {self.MAX_CHARGE_LEVEL}%, preventing charge")
            # Too empty to discharge
            if electric_level < self.MIN_CHARGE_LEVEL and effective_desired > 0:
                effective_desired = 0
                self.log('warning', f"Charge level below {self.MIN_CHARGE_LEVEL}%, preventing discharge")

        # Clamp effective desired feed
        effective_desired = max(self.POWER_FEED_MIN, min(self.POWER_FEED_MAX, effective_desired))

        # Apply minimum absolute threshold on resulting feed:
        # if the resulting discharge/charge is very small, turn it off.
        if abs(effective_desired) < self.POWER_FEED_MIN_THRESHOLD:
            effective_desired = 0  # Set to 0 to stop, will return 1 in calculate_netzero_power() to avoid standby

        # Apply minimum delta threshold on the CHANGE:
        # if the change is too small, keep current settings to avoid unnecessary adjustments
        effective_delta = effective_desired - effective_current
        if abs(effective_delta) < self.POWER_FEED_MIN_DELTA:
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
        reader = DeviceDataReader(config_path=self.config_path)
        
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
            Test mode is controlled by the global TEST_MODE constant.
            When TEST_MODE is True, operations are simulated but not applied.
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
                if TEST_MODE:
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
    