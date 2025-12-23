#!/usr/bin/env python3
"""
Single-shot P1 Meter Zero Feed-In Controller (standalone copy for run_schedule)

This file is adapted to be fully local to the run_schedule directory:
- Config and data are read from ./config/config.json and ./data/zendure_data.json
- No imports outside run_schedule are required.
"""

import argparse
import json
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import Optional, Tuple, Dict, Any, Union

import requests

# ============================================================================
# CONFIGURATION PARAMETERS
# ============================================================================

# Path to config.json (local to run_schedule)
CONFIG_FILE_PATH = Path(__file__).parent / "config" / "config.json"

# Power limits (W)
POWER_FEED_MIN = -800  # Minimum effective power feed (discharge)
POWER_FEED_MAX = 800   # Maximum effective power feed (charge)

# Thresholds and battery limits
POWER_FEED_MIN_THRESHOLD = 20  # Minimum change (W) to actually adjust limits
MIN_CHARGE_LEVEL = 20          # Minimum battery level (%) - stop discharging below this
MAX_CHARGE_LEVEL = 90          # Maximum battery level (%) - stop charging above this

TEST_MODE = True


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
# HELPERS
# ============================================================================

def load_config(config_path: Path) -> Dict[str, str]:
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

    p1_meter_ip = config.get("p1MeterIp")
    device_ip = config.get("deviceIp")
    device_sn = config.get("deviceSn")

    if not p1_meter_ip:
        raise ValueError("p1MeterIp not found in config.json")
    if not device_ip:
        raise ValueError("deviceIp not found in config.json")
    if not device_sn:
        raise ValueError("deviceSn not found in config.json")

    return {
        "p1MeterIp": p1_meter_ip,
        "deviceIp": device_ip,
        "deviceSn": device_sn,
    }


def read_p1_meter(ip_address: str) -> Optional[int]:
    """
    Fetch total_power from Zendure P1 Meter API endpoint.

    Args:
        ip_address: IP address of the P1 meter

    Returns:
        int: Total power in watts, or None if error
    """
    url = f"http://{ip_address}/properties/report"

    try:
        response = requests.get(url, timeout=5)
        response.raise_for_status()
        data = response.json()
        total_power = data.get("total_power")
        return total_power
    except requests.exceptions.RequestException as e:
        print(f"Error connecting to Zendure P1 at {ip_address}: {e}")
        return None
    except (json.JSONDecodeError, KeyError) as e:
        print(f"Error parsing P1 response: {e}")
        return None


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
        print(f"Warning: zendure_data.json not found at {data_path}")
        return None
    except json.JSONDecodeError as e:
        print(f"Warning: Invalid JSON in zendure_data.json: {e}")
        return None


def read_zendure_current_settings() -> Tuple[Optional[int], Optional[int]]:
    """
    Read current inputLimit (charge) and outputLimit (discharge) from zendure_data.json.

    Returns:
        tuple: (inputLimit, outputLimit) in watts, or (None, None) if error.
    """
    data = _read_zendure_data()
    if not data:
        return (None, None)

    props = data.get("properties", {})
    input_limit = props.get("inputLimit")
    output_limit = props.get("outputLimit")
    return (input_limit, output_limit)


def read_electric_level() -> Optional[int]:
    """
    Read electricLevel (battery percentage) from zendure_data.json.

    Returns:
        int: Battery level percentage (0-100), or None if error.
    """
    data = _read_zendure_data()
    if not data:
        return None

    try:
        return data.get("properties", {}).get("electricLevel")
    except Exception as e:
        print(f"Warning: Error reading electricLevel from zendure_data.json: {e}")
        return None


def send_power_feed(device_ip: str, device_sn: str, power_feed: int) -> Tuple[bool, Optional[str]]:
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

    # In the original controller, power_feed is inverted before mapping:
    #   positive => charge (inputLimit), negative => discharge (outputLimit)
    # To keep compatibility, we keep the same behavior here.
    power_feed = int(round(power_feed) * -1)

    # Construct properties based on power_feed value
    if power_feed > 0:
        # Charge mode: acMode 1 = Input
        properties = {
            "acMode": 1,
            "inputLimit": int(power_feed),
            "outputLimit": 0,
            "smartMode": 1,
        }
    elif power_feed < 0:
        # Discharge mode: acMode 2 = Output
        properties = {
            "acMode": 2,
            "outputLimit": int(abs(power_feed)),
            "inputLimit": 0,
            "smartMode": 1,
        }
    else:
        # Stop all
        properties = {
            "inputLimit": 0,
            "outputLimit": 0,
            "smartMode": 1,
        }

    payload = {"sn": device_sn, "properties": properties}

    if TEST_MODE:
        print(f"TEST MODE: Would set power feed to {power_feed} W")
        return (True, None)

    try:
        response = requests.post(
            url,
            json=payload,
            timeout=5,
            headers={"Content-Type": "application/json"},
        )
        response.raise_for_status()

        # Try to parse JSON response (some devices may not return JSON)
        try:
            response.json()
        except json.JSONDecodeError:
            pass

        return (True, None)
    except requests.exceptions.RequestException as e:
        return (False, str(e))
    except Exception as e:
        return (False, str(e))


# ============================================================================
# CORE CALCULATION
# ============================================================================

def calculate_new_settings(
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
        # Too empty to discharge
        if electric_level < MIN_CHARGE_LEVEL and effective_desired > 0:
            effective_desired = 0

    # Clamp effective desired feed
    effective_desired = max(POWER_FEED_MIN, min(POWER_FEED_MAX, effective_desired))

    # Apply minimum absolute threshold on resulting feed:
    # if the resulting discharge/charge is very small, turn it off.
    if abs(effective_desired) < POWER_FEED_MIN_THRESHOLD:
        effective_desired = 0

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

def calculate_zero_feed_in(
    config_path: Optional[Path] = None,
    p1_meter_ip: Optional[str] = None,
    device_ip: Optional[str] = None,
    device_sn: Optional[str] = None,
    apply: bool = True,
    ) -> ZeroFeedInResult:
    """
    Perform a single-shot zero feed-in calculation.

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
        config = load_config(Path(config_path))
    except Exception as e:
        return ZeroFeedInResult(
            p1_power=None,
            current_input=None,
            current_output=None,
            electric_level=None,
            new_input=None,
            new_output=None,
            applied=False,
            error=str(e),
        )

    p1_ip = p1_meter_ip or config["p1MeterIp"]
    dev_ip = device_ip or config["deviceIp"]
    dev_sn = device_sn or config["deviceSn"]

    # Read inputs
    p1_power = read_p1_meter(p1_ip)
    if p1_power is None:
        return ZeroFeedInResult(
            p1_power=None,
            current_input=None,
            current_output=None,
            electric_level=None,
            new_input=None,
            new_output=None,
            applied=False,
            error="Failed to read P1 meter",
        )

    current_input, current_output = read_zendure_current_settings()
    electric_level = read_electric_level()

    # If we cannot read current settings, still report but don't apply
    if current_input is None or current_output is None:
        return ZeroFeedInResult(
            p1_power=p1_power,
            current_input=current_input,
            current_output=current_output,
            electric_level=electric_level,
            new_input=None,
            new_output=None,
            applied=False,
            error="Failed to read current Zendure limits from zendure_data.json",
        )

    # Calculate new settings
    new_input, new_output = calculate_new_settings(
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

    success, error_msg = send_power_feed(dev_ip, dev_sn, power_feed)

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
    power: Union[int, str, None] = 'netzero',
    config_path: Optional[Path] = None,
    test: bool = False,
    ) -> int:
    """
    Unified function to set power feed or use dynamic zero feed-in calculation.

    Args:
        power: Power setting:
            - int: Specific power feed in watts (positive=charge, negative=discharge, 0=stop)
            - 'netzero' or None: Use dynamic zero feed-in calculation (default)
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
        config = load_config(Path(config_path))
        device_ip = config["deviceIp"]
        device_sn = config["deviceSn"]

        # Convert to internal convention (CLI convention: positive=charge, negative=discharge)
        # Internal convention: positive=discharge, negative=charge
        internal_power_feed = -power

        # Test mode: don't apply, but return what would be set
        if test:
            return power

        # Send power feed
        success, error_msg = send_power_feed(device_ip, device_sn, internal_power_feed)
        if not success:
            raise Exception(f"Failed to set power feed: {error_msg}")

        return power

    # Handle dynamic zero feed-in ('netzero' or None)
    elif power == 'netzero' or power == 'netzero+' or power is None:
        result = calculate_zero_feed_in(
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
        raise ValueError(f"Invalid power value: {power}. Must be int, 'netzero', or None")


# ============================================================================
# COMMAND-LINE INTERFACE
# ============================================================================

def main(argv: Optional[list] = None) -> int:
    """
    CLI entry point.
    """
    parser = argparse.ArgumentParser(
        description="Single-shot P1 Meter Zero Feed-In Controller",
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
            config = load_config(config_path)
            device_ip = config["deviceIp"]
            device_sn = config["deviceSn"]
        except Exception as e:
            print(f"Error loading config: {e}")
            return 1

        # Convert user convention to internal convention
        # User: positive=charge, negative=discharge
        # Internal: positive=discharge, negative=charge
        internal_power_feed = -args.power_feed

        # Output
        timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        if args.test:
            print("[TEST MODE - No changes will be applied]")
        print(f"\nTime: {timestamp}")

        # Determine mode description
        if args.power_feed > 0:
            mode_desc = f"Charge: {args.power_feed} W"
        elif args.power_feed < 0:
            mode_desc = f"Discharge: {abs(args.power_feed)} W"
        else:
            mode_desc = "Stop (0 W)"

        print(f"Power Feed: {mode_desc}")

        # Apply or simulate
        if args.test:
            print("Status: Simulated (test mode)")
            return 0
        else:
            success, error_msg = send_power_feed(device_ip, device_sn, internal_power_feed)
            if success:
                print("Status: Applied")
                return 0
            else:
                print(f"Status: Failed to apply - {error_msg}")
                return 1

    # Normal zero feed-in calculation flow
    result = calculate_zero_feed_in(
        config_path=config_path,
        apply=apply_changes,
    )

    # Output
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    if args.test:
        print("[TEST MODE - No changes will be applied]")
    print(f"\nTime: {timestamp}")

    if result.error:
        print(f"Status: Error - {result.error}")
        # Still print whatever data we have

    print(f"P1 Meter Power: {result.p1_power if result.p1_power is not None else 'N/A'} W")
    print("\nCurrent Settings:")
    print(
        f"  Input (Charge): "
        f"{result.current_input if result.current_input is not None else 'N/A'} W"
    )
    print(
        f"  Output (Discharge): "
        f"{result.current_output if result.current_output is not None else 'N/A'} W"
    )
    if result.electric_level is not None:
        print(f"\nBattery Level: {result.electric_level}%")
    else:
        print("\nBattery Level: N/A")

    print("\nCalculated New Settings:")
    print(
        f"  Input (Charge): "
        f"{result.new_input if result.new_input is not None else 'N/A'} W"
    )
    print(
        f"  Output (Discharge): "
        f"{result.new_output if result.new_output is not None else 'N/A'} W"
    )

    # Status line
    if result.error:
        status = f"Error: {result.error}"
    elif (
        result.new_input == result.current_input
        and result.new_output == result.current_output
    ):
        status = "No change needed"
    elif args.test:
        status = "Simulated (test mode)"
    else:
        status = "Applied" if result.applied else "Failed to apply"

    print(f"\nStatus: {status}")

    # Exit code: non-zero on error
    return 0 if not result.error else 1


if __name__ == "__main__":
    raise SystemExit(main())


