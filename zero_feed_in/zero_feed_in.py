#!/usr/bin/env python3
"""
P1 Meter Zero Feed-In Controller
Reads total power from Zendure P1 meter periodically and adjusts power feed to maintain zero feed-in.
"""

import requests
import json
import time
import argparse
from datetime import datetime
from pathlib import Path

# ============================================================================
# CONFIGURATION PARAMETERS
# ============================================================================
MODE = "both"  # Mode: "both", "charge_only", or "discharge_only"
READ_INTERVAL_SECONDS = 5  # Time between readings
MAX_ITERATIONS = 720  # Maximum number of iterations to run
POWER_FEED_ADJUSTMENT_THRESHOLD = 20  # Minimum adjustment (W) to trigger power_feed change
MAX_ADJUSTMENT_STEP =100  # Maximum adjustment step (W)
POWER_FEED_MIN = -800  # Minimum power feed value (W)
POWER_FEED_MAX = 800  # Maximum power feed value (W)
POWER_FEED_MIN_THRESHOLD = 20  # Minimum absolute value for power_feed (if between -MIN and +MIN, set to charge/discharge 0)
MIN_CHARGE_LEVEL = 20  # Minimum battery level (%) - stop discharging below this
MAX_CHARGE_LEVEL = 90  # Maximum battery level (%) - stop charging above this
# ============================================================================


def load_config():
    """
    Load configuration from zendure/config/config.json
    Returns a dictionary with p1MeterIp, deviceIp, and deviceSn.
    """
    # Get the script's directory and resolve path to config file
    script_dir = Path(__file__).parent.absolute()
    config_path = script_dir.parent / "zendure" / "config" / "config.json"
    
    try:
        with open(config_path, 'r') as f:
            config = json.load(f)
            p1_meter_ip = config.get('p1MeterIp')
            device_ip = config.get('deviceIp')
            device_sn = config.get('deviceSn')
            
            if not p1_meter_ip:
                raise ValueError("p1MeterIp not found in config.json")
            if not device_ip:
                raise ValueError("deviceIp not found in config.json")
            if not device_sn:
                raise ValueError("deviceSn not found in config.json")
            
            return {
                'p1MeterIp': p1_meter_ip,
                'deviceIp': device_ip,
                'deviceSn': device_sn
            }
    except FileNotFoundError:
        raise FileNotFoundError(f"Config file not found: {config_path}")
    except json.JSONDecodeError as e:
        raise ValueError(f"Invalid JSON in config file: {e}")


def read_p1_meter(ip_address):
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
        print(f"Error parsing response: {e}")
        return None


def read_electric_level():
    """
    Read electricLevel from zendure_data.json file.
    
    Returns:
        int: Battery level percentage (0-100), or None if error
    """
    # Get the script's directory and resolve path to data file
    script_dir = Path(__file__).parent.absolute()
    data_path = script_dir.parent / "data" / "zendure_data.json"
    
    try:
        with open(data_path, 'r') as f:
            data = json.load(f)
            electric_level = data.get("properties", {}).get("electricLevel")
            return electric_level
    except FileNotFoundError:
        print(f"Warning: zendure_data.json not found at {data_path}")
        return None
    except (json.JSONDecodeError, KeyError) as e:
        print(f"Warning: Error reading electricLevel from zendure_data.json: {e}")
        return None


def send_power_feed(device_ip, device_sn, power_feed):
    """
    Send power_feed value to Zendure device via /properties/write endpoint.
    
    Args:
        device_ip: IP address of the Zendure device
        device_sn: Serial number of the Zendure device
        power_feed: Power feed value in watts (positive for charge, negative for discharge)
        
    Returns:
        tuple: (success: bool, error_message: str or None)
    """
    url = f"http://{device_ip}/properties/write"

    power_feed = int(round(power_feed)*-1)
    
    # Construct properties based on power_feed value
    if power_feed > 0:
        # Charge mode: acMode 1 = Input
        properties = {
            'acMode': 1,
            'inputLimit': int(power_feed),
            'outputLimit': 0,
            'smartMode': 1
        }
    elif power_feed < 0:
        # Discharge mode: acMode 2 = Output
        properties = {
            'acMode': 2,
            'outputLimit': int(abs(power_feed)),
            'inputLimit': 0,
            'smartMode': 1
        }
    else:
        # Stop all
        properties = {
            'inputLimit': 0,
            'outputLimit': 0,
            'smartMode': 1
        }
    
    payload = {
        'sn': device_sn,
        'properties': properties
    }
    
    try:
        response = requests.post(url, json=payload, timeout=5, headers={'Content-Type': 'application/json'})
        response.raise_for_status()
        
        # Try to parse JSON response
        try:
            response.json()
        except json.JSONDecodeError:
            pass  # Some devices may not return JSON, which is OK
        
        return (True, None)
    except requests.exceptions.RequestException as e:
        return (False, str(e))
    except Exception as e:
        return (False, str(e))


class ZeroFeedInController:
    """
    Controller for zero feed-in management using P1 meter and Zendure device.
    """
    
    def __init__(self, p1_meter_ip=None, device_ip=None, device_sn=None):
        """
        Initialize the controller.
        
        Args:
            p1_meter_ip: IP address of the P1 meter (optional, loads from config.json if not provided)
            device_ip: IP address of the Zendure device (optional, loads from config.json if not provided)
            device_sn: Serial number of the Zendure device (optional, loads from config.json if not provided)
        """
        if p1_meter_ip and device_ip and device_sn:
            # Use provided parameters
            self.p1_meter_ip = p1_meter_ip
            self.device_ip = device_ip
            self.device_sn = device_sn
        else:
            # Load from config.json
            config = load_config()
            self.p1_meter_ip = config['p1MeterIp']
            self.device_ip = config['deviceIp']
            self.device_sn = config['deviceSn']
        
        # Initialize state
        self.power_feed = 0
        self.last_sent_power_feed = None
    
    def calculate_power_feed(self, total_power, mode=None, electric_level=None):
        """
        Calculate new power_feed value based on current power reading.
        
        The power_feed represents the desired battery discharge rate to keep
        the power reading as close to zero as possible.
        
        Uses an additive formula: new_power_feed = old_power_feed + adjustment
        where adjustment is based on the reading. This incrementally adjusts
        toward zero feed-in.
        
        Mode behavior:
        - "both": Add reading to current feed (power_feed = old_power_feed + total_power)
        - "charge_only": Only charge when total_power is negative (add abs(total_power) if negative, else 0)
        - "discharge_only": Only discharge when total_power is positive (subtract total_power if positive, else 0)
        
        Battery level limits:
        - If electric_level > MAX_CHARGE_LEVEL: prevent charging (force power_feed <= 0)
        - If electric_level < MIN_CHARGE_LEVEL: prevent discharging (force power_feed >= 0)
        
        Args:
            total_power: Current power reading from P1 meter (W)
            mode: Operation mode ("both", "charge_only", "discharge_only"). Defaults to global MODE constant.
            electric_level: Battery level percentage (0-100). If None, battery limits are not enforced.
        
        Returns:
            tuple: (new_power_feed, adjustment_applied) where adjustment_applied is the
                   actual adjustment that was applied (0 if threshold not met)
        """
        # Use provided mode or fall back to global constant
        if mode is None:
            mode = MODE
        
        old_power_feed = self.power_feed
        
        # Apply mode logic with additive formula
        if mode == "charge_only":
            # Only charge (positive power_feed) when total_power is negative (excess power)
            if total_power < 0:
                adjustment = abs(total_power)  # Add charge to consume excess power
                desired_power_feed = old_power_feed + adjustment
            else:
                desired_power_feed = old_power_feed  # Don't charge when consuming from grid
        elif mode == "discharge_only":
            # Only discharge (negative power_feed) when total_power is positive (consuming from grid)
            if total_power > 0:
                adjustment = -total_power  # Subtract discharge to offset consumption
                desired_power_feed = old_power_feed + adjustment
            else:
                desired_power_feed = old_power_feed  # Don't discharge when feeding into grid
        else:  # mode == "both"
            # Additive behavior: add reading to current feed to incrementally adjust toward zero
            desired_power_feed = old_power_feed + total_power
        
        # Calculate adjustment based on desired_power_feed
        adjustment = desired_power_feed - old_power_feed
        
        # Only apply adjustment if it exceeds threshold
        if abs(adjustment) >= POWER_FEED_ADJUSTMENT_THRESHOLD:
            print("adjustment exceeds threshold")
            if abs(adjustment) < MAX_ADJUSTMENT_STEP:
                print("adjustment is less than MAX_ADJUSTMENT_STEP")
                new_power_feed = desired_power_feed
                adjustment_applied = adjustment
            else:
                print("adjustment is greater than MAX_ADJUSTMENT_STEP")
                # Limit adjustment to MAX_ADJUSTMENT_STEP, preserving sign
                sign = 1 if adjustment >= 0 else -1
                new_power_feed = old_power_feed + MAX_ADJUSTMENT_STEP * sign
                adjustment_applied = MAX_ADJUSTMENT_STEP * sign
                print(f"new_power_feed: {new_power_feed}, adjustment_applied: {adjustment_applied}")
        else:
            # Keep power_feed unchanged if adjustment is too small
            new_power_feed = old_power_feed
            adjustment_applied = 0
        
        # Apply battery level limits
        if electric_level is not None:
            if electric_level > MAX_CHARGE_LEVEL and new_power_feed > 0:
                # Battery is above max level, stop charging
                print(f"Battery level ({electric_level}%) above MAX_CHARGE_LEVEL ({MAX_CHARGE_LEVEL}%), preventing charge")
                new_power_feed = 0  # Stop charging
                adjustment_applied = new_power_feed - old_power_feed
            elif electric_level < MIN_CHARGE_LEVEL and new_power_feed < 0:
                # Battery is below min level, stop discharging
                print(f"Battery level ({electric_level}%) below MIN_CHARGE_LEVEL ({MIN_CHARGE_LEVEL}%), preventing discharge")
                new_power_feed = 0  # Stop discharging
                adjustment_applied = new_power_feed - old_power_feed
        
        # Clamp to valid range
        new_power_feed = max(POWER_FEED_MIN, min(POWER_FEED_MAX, new_power_feed))
        print(f"new_power_feed: {new_power_feed}")
        
        # Apply minimum threshold: if between -MIN and +MIN (excluding 0), set to 0 or ±MIN
        if abs(new_power_feed) < POWER_FEED_MIN_THRESHOLD and new_power_feed != 0:
            print("new_power_feed is less than POWER_FEED_MIN_THRESHOLD")
            new_power_feed = old_power_feed
            adjustment_applied = 0
        
        # Round to integer
        new_power_feed = int(round(new_power_feed))
        
        return (new_power_feed, adjustment_applied)
    
    def run_iterations(self, max_iterations=None, read_interval_seconds=None, mode=None):
        """
        Execute iterations with specified interval between each iteration.
        
        Args:
            max_iterations: Maximum number of iterations to run (defaults to global MAX_ITERATIONS)
            read_interval_seconds: Time between readings in seconds (defaults to global READ_INTERVAL_SECONDS)
            mode: Operation mode ("both", "charge_only", "discharge_only") (defaults to global MODE)
        
        Returns:
            int: Number of successful readings collected
        """
        # Use provided parameters or fall back to global constants
        if max_iterations is None:
            max_iterations = MAX_ITERATIONS
        if read_interval_seconds is None:
            read_interval_seconds = READ_INTERVAL_SECONDS
        if mode is None:
            mode = MODE
        
        # Reset state for new run
        self.power_feed = 0
        self.last_sent_power_feed = None
        
        iteration_count = 0  # Track number of successful readings
        loop_iteration = 0  # Track total loop iterations
        
        # Main loop
        while loop_iteration < max_iterations:
            current_time = time.time()
            loop_iteration += 1
            
            # Read from P1 meter
            total_power = read_p1_meter(self.p1_meter_ip)
            
            if total_power is not None:
                iteration_count += 1
                
                # Read battery level from zendure_data.json
                electric_level = read_electric_level()
                
                # Calculate power_feed
                adjustment_applied = 0
                send_status = None  # Track send status for output
                new_power_feed, adjustment_applied = self.calculate_power_feed(total_power, mode, electric_level)
                self.power_feed = new_power_feed
                
                # Send power_feed to device if value changed
                # Compare integer values to avoid floating-point precision issues
                power_feed_int = int(round(self.power_feed))
                last_sent_int = int(round(self.last_sent_power_feed)) if self.last_sent_power_feed is not None else None
                
                if power_feed_int != last_sent_int:
                    success, error_msg = send_power_feed(self.device_ip, self.device_sn, self.power_feed)
                    if success:
                        self.last_sent_power_feed = self.power_feed
                        send_status = "→ Sent"
                    else:
                        send_status = f"→ Send failed: {error_msg}"
                
                # Format timestamp
                timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
                
                # Format output
                power_feed_str = f"{self.power_feed} W"
                if adjustment_applied != 0:
                    adjustment_str = f" (adj: {int(round(adjustment_applied)):+d} W)"
                else:
                    adjustment_str = ""
                
                # Format battery level if available
                battery_str = f" | Battery: {electric_level}%" if electric_level is not None else ""
                
                # Print reading with power feed
                send_status_str = f" {send_status}" if send_status else ""
                print(f"[{timestamp}] Grid in: {total_power} W | "
                      f"Power Feed: {power_feed_str}{adjustment_str}{battery_str}{send_status_str} | "
                      f"Iteration: {loop_iteration}/{max_iterations}")
            else:
                # Error reading, but continue
                timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
                print(f"[{timestamp}] Failed to read meter | Iteration: {loop_iteration}/{max_iterations}")
            
            # Sleep until next reading (account for processing time)
            sleep_time = read_interval_seconds - (time.time() - current_time)
            if sleep_time > 0:
                time.sleep(sleep_time)
        
        return iteration_count


def main():
    """Main function to run the P1 meter zero feed-in controller."""
    # Parse command-line arguments
    parser = argparse.ArgumentParser(
        description="P1 Meter Zero Feed-In Controller",
        formatter_class=argparse.RawDescriptionHelpFormatter
    )
    parser.add_argument(
        '-i', '--iterations',
        type=int,
        default=None,
        help=f'Number of iterations to run (default: {MAX_ITERATIONS})'
    )
    parser.add_argument(
        '-r', '--interval',
        type=float,
        default=None,
        help=f'Read interval in seconds (default: {READ_INTERVAL_SECONDS})'
    )
    parser.add_argument(
        '-m', '--mode',
        type=str,
        choices=['both', 'charge_only', 'discharge_only'],
        default=None,
        help=f'Operation mode: both, charge_only, or discharge_only (default: {MODE})'
    )
    
    args = parser.parse_args()
    
    # Determine actual values to use (from args or defaults)
    actual_iterations = args.iterations if args.iterations is not None else MAX_ITERATIONS
    actual_interval = args.interval if args.interval is not None else READ_INTERVAL_SECONDS
    actual_mode = args.mode if args.mode is not None else MODE
    
    # Print startup information
    print("P1 Meter Zero Feed-In Controller")
    print("=" * 50)
    print(f"Mode: {actual_mode}")
    print(f"Read interval: {actual_interval} seconds")
    print(f"Max iterations: {actual_iterations}")
    print("=" * 50)
    print()
    
    # Initialize controller (will load from config.json)
    try:
        controller = ZeroFeedInController()
        print(f"P1 Meter IP: {controller.p1_meter_ip}")
        print(f"Zendure Device IP: {controller.device_ip}")
        print(f"Zendure Device SN: {controller.device_sn}")
    except Exception as e:
        print(f"Error loading config: {e}")
        return 1
    
    print(f"Starting at {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print()
    
    # Execute iterations
    iteration_count = controller.run_iterations(
        max_iterations=actual_iterations,
        read_interval_seconds=actual_interval,
        mode=actual_mode
    )
    
    print()
    print("=" * 50)
    print(f"Finished at {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"Total readings collected: {iteration_count}")
    print("=" * 50)
    
    return 0


if __name__ == "__main__":
    exit(main())


