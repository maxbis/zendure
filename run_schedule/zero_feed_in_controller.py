#!/usr/bin/env python3
"""
P1 Meter Zero Feed-In Controller (standalone copy for run_schedule)

This file is adapted to be fully local to the run_schedule directory:
- Config and data are read from ./config/config.json and ./data/zendure_data.json
- No imports outside run_schedule are required.
"""

import argparse
import json
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import Optional, Tuple, Dict, Any, Union, Literal

import requests
from logger import log_info, log_debug, log_warning, log_error, log_success


def find_config_file() -> Path:
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


# ============================================================================
# CONFIGURATION PARAMETERS
# ============================================================================

# Path to config.json (with fallback to project root config)
CONFIG_FILE_PATH = find_config_file()

# Power limits (W)
POWER_FEED_MIN = -800  # Minimum effective power feed (discharge)
POWER_FEED_MAX = 1200   # Maximum effective power feed (charge)

# Thresholds and battery limits
POWER_FEED_MIN_THRESHOLD = 30  # Minimum absolute power (W) - if |F_desired| < threshold, set to 0
POWER_FEED_MIN_DELTA = 50      # Minimum change (W) to actually adjust limits - if |delta| < threshold, keep current
MIN_CHARGE_LEVEL = 20          # Minimum battery level (%) - stop discharging below this
MAX_CHARGE_LEVEL = 90          # Maximum battery level (%) - stop charging above this

# Network settings
REQUEST_TIMEOUT = 5  # Timeout in seconds for HTTP requests

TEST_MODE = False


# ============================================================================
# DATA STRUCTURES
# ============================================================================

@dataclass
class ZeroFeedInResult:
    # Inputs
    p1_power: Optional[int]
    current_input: Optional[int]
    current_output: Optional[int]
    electric_level: Optional[int]

    # Calculated
    new_input: Optional[int]
    new_output: Optional[int]

    # Meta
    applied: bool
    error: Optional[str] = None

    def to_dict(self) -> Dict[str, Any]:
        return {
            "p1_power": self.p1_power,
            "current_input": self.current_input,
            "current_output": self.current_output,
            "electric_level": self.electric_level,
            "new_input": self.new_input,
            "new_output": self.new_output,
            "applied": self.applied,
            "error": self.error,
        }


# ============================================================================
# PRIVATE HELPERS
# ============================================================================

def _create_error_result(
    error_msg: str,
    p1_power: Optional[int] = None,
    current_input: Optional[int] = None,
    current_output: Optional[int] = None,
    electric_level: Optional[int] = None,
) -> ZeroFeedInResult:
    """
    Helper to create error result with consistent structure.
    
    Args:
        error_msg: Error message
        p1_power: Optional P1 power value if available
        current_input: Optional current input limit if available
        current_output: Optional current output limit if available
        electric_level: Optional battery level if available
    
    Returns:
        ZeroFeedInResult with error set and applied=False
    """
    return ZeroFeedInResult(
        p1_power=p1_power,
        current_input=current_input,
        current_output=current_output,
        electric_level=electric_level,
        new_input=None,
        new_output=None,
        applied=False,
        error=error_msg,
    )


def _build_device_properties(power_feed: int) -> Dict[str, Any]:
    """
    Build device properties dict based on power_feed value.
    
    Args:
        power_feed: Power feed value in watts (positive for charge, negative for discharge, 0 to stop)
    
    Returns:
        dict: Device properties with acMode, inputLimit, outputLimit, and smartMode
    """
    if power_feed > 0:
        # Charge mode: acMode 1 = Input
        return {
            "acMode": 1,
            "inputLimit": int(abs(power_feed)),
            "outputLimit": 0,
            "smartMode": 1,
        }
    elif power_feed < 0:
        # Discharge mode: acMode 2 = Output
        return {
            "acMode": 2,
            "outputLimit": int(abs(power_feed)),
            "inputLimit": 0,
            "smartMode": 1,
        }
    else:
        # Stop all
        return {
            "inputLimit": 0,
            "outputLimit": 0,
            "smartMode": 1,
        }


def _load_config(config_path: Path) -> Dict[str, Any]:
    """
    Load configuration from config.json.

    Expected keys:
    - p1MeterIp
    - deviceIp
    - deviceSn
    """
    try:
        with open(config_path, "r") as f:
            config = json.load(f)
    except FileNotFoundError:
        raise FileNotFoundError(f"Config file not found: {config_path}")
    except json.JSONDecodeError as e:
        raise ValueError(f"Invalid JSON in config file {config_path}: {e}")

    # Validate required keys, but return full config so optional keys
    # like zendureFetchApiUrl are preserved.
    p1_meter_ip = config.get("p1MeterIp")
    device_ip = config.get("deviceIp")
    device_sn = config.get("deviceSn")

    if not p1_meter_ip:
        raise ValueError("p1MeterIp not found in config.json")
    if not device_ip:
        raise ValueError("deviceIp not found in config.json")
    if not device_sn:
        raise ValueError("deviceSn not found in config.json")

    return config


def _update_p1_data(
    config_path: Optional[Path] = None,
    p1_meter_ip: Optional[str] = None,
) -> Optional[dict]:
    """
    Fetch P1 meter data directly and save it via data_api.php endpoint.

    Args:
        config_path: Optional path to config.json; defaults to CONFIG_FILE_PATH
        p1_meter_ip: IP address of the P1 meter (or from config if None)

    Returns:
        dict: Raw P1 meter data from device, or None if error
    """
    # Resolve configuration
    if config_path is None:
        config_path = CONFIG_FILE_PATH

    try:
        config = _load_config(Path(config_path))
    except Exception as e:
        log_error(f"Error loading config: {e}")
        return None

    # Get P1 meter IP from parameter or config
    p1_ip = p1_meter_ip or config.get("p1MeterIp")
    if not p1_ip:
        log_error("p1MeterIp not found in config.json")
        return None

    # Read from P1 meter device
    url = f"http://{p1_ip}/properties/report"

    try:
        response = requests.get(url, timeout=REQUEST_TIMEOUT)
        response.raise_for_status()
        data = response.json()

        # Extract P1 meter data fields
        device_id = data.get("deviceId")
        total_power = data.get("total_power")
        phase_a = data.get("a_aprt_power")
        phase_b = data.get("b_aprt_power")
        phase_c = data.get("c_aprt_power")
        meter_timestamp = data.get("timestamp")

        # Prepare reading data with timestamp
        reading_data = {
            "timestamp": datetime.now().isoformat(),
            "deviceId": device_id,
            "total_power": total_power,
            "a_aprt_power": phase_a,
            "b_aprt_power": phase_b,
            "c_aprt_power": phase_c,
            "meter_timestamp": meter_timestamp,
        }

        # Store via data_api.php endpoint (non-fatal)
        api_url = config.get("p1StoreApiUrl")
        _store_data_via_api(api_url, reading_data, "P1 meter data")

        # Return the raw device data (not the stored format)
        return data

    except requests.exceptions.RequestException as e:
        log_error(f"Error reading from P1 meter at {p1_ip}: {e}")
        return None
    except (json.JSONDecodeError, KeyError) as e:
        log_error(f"Error parsing P1 response: {e}")
        return None


def _store_data_via_api(
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
        log_warning(f"{data_type} API URL not found in config.json, skipping storage")
        return False

    try:
        store_response = requests.post(
            api_url,
            json=data,
            timeout=REQUEST_TIMEOUT,
            headers={"Content-Type": "application/json"},
        )
        store_response.raise_for_status()
        store_result = store_response.json()

        if store_result.get("success", False):
            log_info(f"{data_type} stored via API: {store_result.get('file', 'data.json')}")
            return True
        else:
            error_msg = store_result.get("error", "Unknown API error")
            log_warning(f"API returned error when storing {data_type}: {error_msg}")
            return False
    except Exception as e:
        # Log warning but don't fail - reading was successful
        log_warning(f"Failed to store {data_type} via API: {e}")
        return False


def _read_zendure_data() -> Optional[dict]:
    """
    Internal helper to read the zendure_data.json file from the local data directory.
    """
    script_dir = Path(__file__).parent.absolute()
    data_path = script_dir / "data" / "zendure_data.json"

    try:
        with open(data_path, "r") as f:
            return json.load(f)
    except FileNotFoundError:
        log_warning(f"zendure_data.json not found at {data_path}")
        return None
    except json.JSONDecodeError as e:
        log_warning(f"Invalid JSON in zendure_data.json: {e}")
        return None


def _read_zendure_state(
    config_path: Optional[Path] = None,
    device_ip: Optional[str] = None,
    update: bool = True,
) -> Tuple[Optional[int], Optional[int], Optional[int]]:
    """
    Read current Zendure device state: inputLimit, outputLimit, and electricLevel.
    
    Args:
        config_path: Optional path to config.json; defaults to CONFIG_FILE_PATH
        device_ip: Override Zendure device IP (else from config)
        update: If True, fetch fresh data from device; if False, read from file only
    
    Returns:
        tuple: (inputLimit, outputLimit, electricLevel) or (None, None, None) on error
    """
    if update:
        data = _update_zendure_data(config_path, device_ip)
    else:
        data = _read_zendure_data()
    
    if not data:
        return (None, None, None)
    
    try:
        props = data.get("properties", {})
        input_limit = props.get("inputLimit")
        output_limit = props.get("outputLimit")
        electric_level = props.get("electricLevel")
        return (input_limit, output_limit, electric_level)
    except Exception as e:
        log_warning(f"Error reading Zendure state: {e}")
        return (None, None, None)

def _update_zendure_data(
    config_path: Optional[Path] = None,
    device_ip: Optional[str] = None,
) -> Optional[dict]:
    """
    Fetch Zendure device data directly and save it via data_api.php endpoint.

    Args:
        config_path: Optional path to config.json; defaults to CONFIG_FILE_PATH
        device_ip: Override Zendure device IP (else from config)

    Returns:
        dict: Raw device data from device, or None on error.
    """
    # Resolve configuration
    if config_path is None:
        config_path = CONFIG_FILE_PATH

    try:
        config = _load_config(Path(config_path))
    except Exception as e:
        log_error(f"Error loading config: {e}")
        return None

    # Get device IP from parameter or config
    dev_ip = device_ip or config["deviceIp"]

    # Read from Zendure device directly
    url = f"http://{dev_ip}/properties/report"

    try:
        response = requests.get(url, timeout=REQUEST_TIMEOUT)
        response.raise_for_status()
        data = response.json()

        # Extract properties and pack data
        props = data.get("properties", {})
        packs = data.get("packData", [])

        # Prepare reading data with timestamp
        reading_data = {
            "timestamp": datetime.now().isoformat(),
            "properties": props,
            "packData": packs,
        }

        # Store via data_api.php endpoint (non-fatal)
        api_url = config.get("zendureStoreApiUrl")
        _store_data_via_api(api_url, reading_data, "Zendure data")

        # Return the raw device data (not the stored format)
        return data

    except requests.exceptions.RequestException as e:
        log_error(f"Error reading from Zendure device at {dev_ip}: {e}")
        return None
    except (json.JSONDecodeError, KeyError) as e:
        log_error(f"Error parsing Zendure response: {e}")
        return None
    except Exception as e:
        log_error(f"Unexpected error updating Zendure data: {e}")
        return None


def _update_zendure_data_file(
    config_path: Optional[Path] = None,
    device_ip: Optional[str] = None,
) -> Optional[dict]:
    """
    Fetch Zendure device data and save it to zendure_data.json.

    Args:
        config_path: Optional path to config.json; defaults to CONFIG_FILE_PATH
        device_ip: Override Zendure device IP (else from config)

    Returns:
        dict: Device data with timestamp, properties, and packData, or None on error.
    """
    # Resolve configuration
    if config_path is None:
        config_path = CONFIG_FILE_PATH

    try:
        config = _load_config(Path(config_path))
    except Exception as e:
        log_error(f"Error loading config: {e}")
        return None

    # Get device IP from parameter or config
    dev_ip = device_ip or config["deviceIp"]

    # Fetch data from Zendure device
    url = f"http://{dev_ip}/properties/report"

    try:
        response = requests.get(url, timeout=REQUEST_TIMEOUT)
        response.raise_for_status()
        data = response.json()

        # Extract properties and pack data
        props = data.get("properties", {})
        packs = data.get("packData", [])

        # Prepare reading data with timestamp
        reading_data = {
            "timestamp": datetime.now().isoformat(),
            "properties": props,
            "packData": packs,
        }

        # Save to JSON file atomically
        script_dir = Path(__file__).parent.absolute()
        data_dir = script_dir / "data"
        data_path = data_dir / "zendure_data.json"
        temp_path = data_path.with_suffix(".json.tmp")

        # Ensure data directory exists
        data_dir.mkdir(parents=True, exist_ok=True)

        # Write to temporary file first
        try:
            with open(temp_path, "w") as f:
                json.dump(reading_data, f, indent=2, ensure_ascii=False)
        except Exception as e:
            log_error(f"Error writing temporary file: {e}")
            return None

        # Atomically replace the target file with the temp file
        try:
            temp_path.replace(data_path)
        except Exception as e:
            log_error(f"Error renaming temporary file: {e}")
            # Clean up temp file if rename failed
            try:
                temp_path.unlink()
            except Exception:
                pass
            return None

        return reading_data

    except requests.exceptions.RequestException as e:
        log_error(f"Error connecting to Zendure device at {dev_ip}: {e}")
        return None 
    except (json.JSONDecodeError, KeyError) as e:
        log_error(f"Error parsing Zendure response: {e}")
        return None
    except Exception as e:
        log_error(f"Unexpected error updating Zendure data: {e}")
        return None


def _send_power_feed(device_ip: str, device_sn: str, power_feed: int) -> Tuple[bool, Optional[str]]:
    """
    Send power_feed value to Zendure device via /properties/write endpoint.

    Args:
        device_ip: IP address of the Zendure device
        device_sn: Serial number of the Zendure device
        power_feed: Power feed value in watts (positive for discharge, negative for charge)

    Returns:
        tuple: (success: bool, error_message: str or None)
    """
    url = f"http://{device_ip}/properties/write"

    # Store original value for logging
    original_power_feed = power_feed

    # In the original controller, power_feed is inverted before mapping:
    #   positive => charge (inputLimit), negative => discharge (outputLimit)
    # To keep compatibility, we keep the same behavior here.
    power_feed = int(round(power_feed) * -1)

    # Construct properties based on power_feed value
    properties = _build_device_properties(power_feed)
    payload = {"sn": device_sn, "properties": properties}

    if TEST_MODE:
        log_info(f"TEST MODE: Would set power feed to {original_power_feed} W")
        return (True, None)

    try:
        log_info(f"Setting power feed to {original_power_feed} W...")
        response = requests.post(
            url,
            json=payload,
            timeout=REQUEST_TIMEOUT,
            headers={"Content-Type": "application/json"},
        )
        response.raise_for_status()

        # Try to parse JSON response (some devices may not return JSON)
        try:
            response.json()
        except json.JSONDecodeError:
            pass

        log_info(f"✓ Successfully set power feed to {original_power_feed} W")

        return (True, None)

    except requests.exceptions.RequestException as e:
        return (False, str(e))
    except Exception as e:
        return (False, str(e))


# ============================================================================
# PRIVATE CORE CALCULATION
# ============================================================================

def _calculate_new_settings(
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

    This matches the examples:
      - P1 = 200, discharge = 100:
          F_current = 100, F_desired = 100 + 200 = 300  => new discharge 300W
      - P1 = -150, charge = 200:
          F_current = -200, F_desired = -200 - 150 = -350 => new charge 350W
      - P1 = -204, discharge = 200:
          F_current = 200, F_desired = 200 - 204 = -4    => small 4W charge which
          is below threshold and becomes 0.

    Additional constraints:
    - Apply battery level limits:
      - electric_level > MAX_CHARGE_LEVEL and F_desired < 0 -> prevent charging (F_desired = 0)
      - electric_level < MIN_CHARGE_LEVEL and F_desired > 0 -> prevent discharging (F_desired = 0)
    - Clamp to power limits (POWER_FEED_MIN / POWER_FEED_MAX)
    - Apply minimum absolute threshold on |F_desired| (POWER_FEED_MIN_THRESHOLD):
      if |F_desired| < threshold, then F_desired = 0.
    - Apply minimum delta threshold on |F_desired - F_current| (POWER_FEED_MIN_DELTA):
      if |delta| < threshold, keep current settings (F_desired = F_current).
      This prevents small adjustments that are below the threshold.
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
        if electric_level > MAX_CHARGE_LEVEL and effective_desired < 0:
            effective_desired = 0
            log_warning(f"Charge level above {MAX_CHARGE_LEVEL}, preventing charge")
        # Too empty to discharge
        if electric_level < MIN_CHARGE_LEVEL and effective_desired > 0:
            effective_desired = 0
            log_warning(f"Charge level below {MIN_CHARGE_LEVEL}, preventing discharge")

    # Clamp effective desired feed
    effective_desired = max(POWER_FEED_MIN, min(POWER_FEED_MAX, effective_desired))

    # Apply minimum absolute threshold on resulting feed:
    # if the resulting discharge/charge is very small, turn it off.
    if abs(effective_desired) < POWER_FEED_MIN_THRESHOLD:
        effective_desired = 0

    # Apply minimum delta threshold on the CHANGE:
    # if the change is too small, keep current settings to avoid unnecessary adjustments
    effective_delta = effective_desired - effective_current
    if abs(effective_delta) < POWER_FEED_MIN_DELTA:
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


# ============================================================================
# PUBLIC LIBRARY FUNCTION
# ============================================================================

def execute_zero_feed_in(
    config_path: Optional[Path] = None,
    p1_meter_ip: Optional[str] = None,
    device_ip: Optional[str] = None,
    device_sn: Optional[str] = None,
    apply: bool = True,
    ) -> ZeroFeedInResult:
    """
    Execute a single-shot zero feed-in operation (read, calculate, and optionally apply).

    Args:
        config_path: Optional path to config.json; defaults to CONFIG_FILE_PATH
        p1_meter_ip: Override P1 meter IP (else from config)
        device_ip: Override Zendure device IP (else from config)
        device_sn: Override Zendure device SN (else from config)
        apply: If True, send new settings to device; if False, simulate only

    Returns:
        ZeroFeedInResult with all relevant fields and status.
    """
    # Resolve configuration
    if config_path is None:
        config_path = CONFIG_FILE_PATH

    try:
        config = _load_config(Path(config_path))
    except Exception as e:
        return _create_error_result(str(e))

    p1_ip = p1_meter_ip or config["p1MeterIp"]
    dev_ip = device_ip or config["deviceIp"]
    dev_sn = device_sn or config["deviceSn"]

    # Read inputs - reads and stores P1 meter data automatically
    p1_data = _update_p1_data(config_path=config_path, p1_meter_ip=p1_ip)
    if p1_data is None:
        return _create_error_result("Failed to read P1 meter")
    
    p1_power = p1_data.get("total_power")
    log_debug(f"P1 power (grid-status): {p1_power}", include_timestamp=True)
    if p1_power is None:
        return _create_error_result("Failed to read P1 meter")

    current_input, current_output, electric_level = _read_zendure_state(
        config_path=config_path,
        device_ip=dev_ip,
        update=True,
    )

    # If we cannot read current settings, still report but don't apply
    if current_input is None or current_output is None:
        return _create_error_result(
            "Failed to read current Zendure limits from zendure_data.json",
            p1_power=p1_power,
            current_input=current_input,
            current_output=current_output,
            electric_level=electric_level,
        )

    # Calculate new settings
    new_input, new_output = _calculate_new_settings(
        p1_power=p1_power,
        current_input=current_input,
        current_output=current_output,
        electric_level=electric_level,
    )

    # If nothing changes, no need to apply
    if new_input == current_input and new_output == current_output:
        return ZeroFeedInResult(
            p1_power=p1_power,
            current_input=current_input,
            current_output=current_output,
            electric_level=electric_level,
            new_input=new_input,
            new_output=new_output,
            applied=False,
            error=None,
        )

    # Apply (unless in test/dry-run mode)
    if not apply:
        return ZeroFeedInResult(
            p1_power=p1_power,
            current_input=current_input,
            current_output=current_output,
            electric_level=electric_level,
            new_input=new_input,
            new_output=new_output,
            applied=False,
            error=None,
        )

    # Map new_input/new_output to power_feed for send_power_feed
    if new_output > 0 and new_input == 0:
        # Discharge: positive effective power
        power_feed = new_output
    elif new_input > 0 and new_output == 0:
        # Charge: negative effective power
        power_feed = -new_input
    else:
        # Both zero or mixed (fallback: stop all)
        power_feed = 0

    success, error_msg = _send_power_feed(dev_ip, dev_sn, power_feed)

    return ZeroFeedInResult(
        p1_power=p1_power,
        current_input=current_input,
        current_output=current_output,
        electric_level=electric_level,
        new_input=new_input,
        new_output=new_output,
        applied=success,
        error=error_msg,
    )


def set_power(
    power: Union[int, Literal['netzero', 'netzero+'], None] = 'netzero',
    config_path: Optional[Path] = None,
    test: bool = False,
    ) -> int:
    """
    Unified function to set power feed or use dynamic zero feed-in calculation.

    Args:
        power: Power setting:
            - int: Specific power feed in watts (positive=charge, negative=discharge, 0=stop)
            - 'netzero' or None: Use dynamic zero feed-in calculation (default)
            - 'netzero+': Use dynamic zero feed-in calculation, but only charge (no discharge)
        config_path: Optional path to config.json; defaults to CONFIG_FILE_PATH
        test: If True, calculates but doesn't apply (dry-run mode)

    Returns:
        int: The actual power that was set (or would be set in test mode).
             Positive = charge, negative = discharge, 0 = stop.

    Raises:
        FileNotFoundError: If config file not found
        ValueError: If config is invalid
        Exception: On device communication errors or P1 meter read failure
    """
    # Resolve config path
    if config_path is None:
        config_path = CONFIG_FILE_PATH

    # Handle specific power feed (int)
    if isinstance(power, int): 
        # Load config
        config = _load_config(Path(config_path))
        device_ip = config["deviceIp"]
        device_sn = config["deviceSn"]

        # Convert to internal convention (CLI convention: positive=charge, negative=discharge)
        # Internal convention: positive=discharge, negative=charge
        internal_power_feed = -power

        # Test mode: don't apply, but return what would be set
        if test:
            log_info(f"TEST MODE: Would set power feed to {power} W")
            return power

        # Send power feed
        success, error_msg = _send_power_feed(device_ip, device_sn, internal_power_feed)
        if not success:
            raise Exception(f"Failed to set power feed: {error_msg}")

        return power

    # Handle dynamic zero feed-in ('netzero' or None)
    elif power == 'netzero' or power == 'netzero+' or power is None:
        result = execute_zero_feed_in(
            config_path=config_path,
            apply=not test,
        )

        # Raise exception if there's an error
        if result.error:
            raise Exception(f"Zero feed-in calculation failed: {result.error}")

        # Calculate effective power in CLI convention (positive=charge, negative=discharge)
        if result.new_output and result.new_output > 0:
            # Discharging: return negative value
            if power == 'netzero+': # netzero+ means don't discharge, only charge
                return 0
            else:
                return -result.new_output
        elif result.new_input and result.new_input > 0:
            # Charging: return positive value
            return result.new_input
        else:
            # Stopped
            return 0

    else:
        raise ValueError(f"Invalid power value: {power}. Must be int, 'netzero', 'netzero+', or None")


# ============================================================================
# COMMAND-LINE INTERFACE
# ============================================================================

def main(argv: Optional[list] = None) -> int:
    """
    CLI entry point.
    """
    parser = argparse.ArgumentParser(
        description="P1 Meter Zero Feed-In Controller",
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument(
        "-c",
        "--config",
        type=str,
        default=None,
        help=f"Path to config.json (default: {CONFIG_FILE_PATH})",
    )
    parser.add_argument(
        "-t",
        "--test",
        action="store_true",
        help="Test/dry-run mode: calculate and display, but do not apply settings",
    )
    parser.add_argument(
        "-p",
        "--power-feed",
        type=int,
        default=None,
        help="Manual power feed override in watts (positive=charge, negative=discharge, 0=stop). Bypasses zero feed-in calculation.",
    )

    args = parser.parse_args(argv)

    # Resolve config path
    config_path = Path(args.config) if args.config else CONFIG_FILE_PATH
    apply_changes = not args.test

    # If power_feed is set, bypass calculation and set directly
    if args.power_feed is not None:
        # Load config to get device_ip and device_sn
        try:
            config = _load_config(config_path)
            device_ip = config["deviceIp"]
            device_sn = config["deviceSn"]
        except Exception as e:
            log_error(f"Error loading config: {e}")
            return 1

        # Convert user convention to internal convention
        # User: positive=charge, negative=discharge
        # Internal: positive=discharge, negative=charge
        internal_power_feed = -args.power_feed

        # Output
        if args.test:
            log_info("[TEST MODE - No changes will be applied]")

        # Determine mode description
        if args.power_feed > 0:
            mode_desc = f"Charge: {args.power_feed} W"
        elif args.power_feed < 0:
            mode_desc = f"Discharge: {abs(args.power_feed)} W"
        else:
            mode_desc = "Stop (0 W)"

        log_info(f"Power Feed: {mode_desc}")

        # Apply or simulate
        if args.test:
            log_info("Status: Simulated (test mode)")
            return 0
        else:
            success, error_msg = _send_power_feed(device_ip, device_sn, internal_power_feed)
            if success:
                log_success("Status: Applied")
                return 0
            else:
                log_error(f"Status: Failed to apply - {error_msg}")
                return 1

    # Normal zero feed-in calculation flow
    result = execute_zero_feed_in(
        config_path=config_path,
        apply=apply_changes,
    )

    # Output
    if args.test:
        log_info("[TEST MODE - No changes will be applied]")

    if result.error:
        log_error(f"Status: Error - {result.error}")
        # Still print whatever data we have

    log_info(f"P1 Meter Power: {result.p1_power if result.p1_power is not None else 'N/A'} W")
    log_info("\nCurrent Settings:")
    log_info(
        f"  Input (Charge): "
        f"{result.current_input if result.current_input is not None else 'N/A'} W"
    )
    log_info(
        f"  Output (Discharge): "
        f"{result.current_output if result.current_output is not None else 'N/A'} W"
    )
    if result.electric_level is not None:
        log_info(f"\nBattery Level: {result.electric_level}%")
    else:
        log_info("\nBattery Level: N/A")

    log_info("\nCalculated New Settings:")
    log_info(
        f"  Input (Charge): "
        f"{result.new_input if result.new_input is not None else 'N/A'} W"
    )
    log_info(
        f"  Output (Discharge): "
        f"{result.new_output if result.new_output is not None else 'N/A'} W"
    )

    # Status line
    if result.error:
        status = f"Error: {result.error}"
        log_error(f"\nStatus: {status}")
    elif (
        result.new_input == result.current_input
        and result.new_output == result.current_output
    ):
        status = "No change needed"
        log_info(f"\nStatus: {status}")
    elif args.test:
        status = "Simulated (test mode)"
        log_info(f"\nStatus: {status}")
    else:
        status = "Applied" if result.applied else "Failed to apply"
        if result.applied:
            log_success(f"\nStatus: {status}")
        else:
            log_error(f"\nStatus: {status}")

    # Exit code: non-zero on error
    return 0 if not result.error else 1


if __name__ == "__main__":
    raise SystemExit(main())

