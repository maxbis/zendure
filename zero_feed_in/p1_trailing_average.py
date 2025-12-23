#!/usr/bin/env python3
"""
P1 Meter Zero Feed-In Controller
Reads total power from Zendure P1 meter periodically and adjusts power feed to maintain zero feed-in.
"""

import requests
import json
import time
from datetime import datetime
from pathlib import Path

# ============================================================================
# CONFIGURATION PARAMETERS
# ============================================================================
READ_INTERVAL_SECONDS = 5  # Time between readings
RUN_TIME_MINUTES = 60  # Total runtime duration in minutes
INITIALIZATION_ITERATIONS = 1  # Number of iterations to initialize (power_feed won't change during this period)
POWER_FEED_ADJUSTMENT_THRESHOLD = 40  # Minimum adjustment (W) to trigger power_feed change
POWER_FEED_MIN = -800  # Minimum power feed value (W)
POWER_FEED_MAX = 800  # Maximum power feed value (W)
POWER_FEED_MIN_THRESHOLD = 60  # Minimum absolute value for power_feed (if between -MIN and +MIN, set to 0 or ±MIN)
ENABLE_POWER_FEED_SEND = True  # Enable sending power_feed to Zendure device (set False for testing)
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


def format_time_remaining(seconds_remaining):
    """
    Format remaining time in minutes and seconds.
    
    Args:
        seconds_remaining: Number of seconds remaining
        
    Returns:
        str: Formatted string like "5m 30s" or "0m 10s"
    """
    minutes = int(seconds_remaining // 60)
    seconds = int(seconds_remaining % 60)
    return f"{minutes}m {seconds}s"


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


def calculate_power_feed(total_power, old_power_feed):
    """
    Calculate new power_feed value based on current power reading.
    
    The power_feed represents the desired battery discharge rate to keep
    the power reading as close to zero as possible.
    
    To bring reading to 0, we set new_power_feed = total_power.
    But we only adjust if the change exceeds the threshold.
    
    Args:
        total_power: Current power reading from P1 meter (W)
        old_power_feed: Previous power_feed value (W)
        
    Returns:
        tuple: (new_power_feed, adjustment_applied) where adjustment_applied is the
               actual adjustment that was applied (0 if threshold not met)
    """
    # Calculate adjustment based on total_power (current reading)
    # This brings power_feed to match total_power (compensating for the reading)
    adjustment = total_power - old_power_feed
    
    # Only apply adjustment if it exceeds threshold
    if abs(adjustment) >= POWER_FEED_ADJUSTMENT_THRESHOLD:
        new_power_feed = total_power
        adjustment_applied = adjustment
    else:
        # Keep power_feed unchanged if adjustment is too small
        new_power_feed = old_power_feed
        adjustment_applied = 0
    
    # Clamp to valid range
    new_power_feed = max(POWER_FEED_MIN, min(POWER_FEED_MAX, new_power_feed))
    
    # Apply minimum threshold: if between -MIN and +MIN (excluding 0), set to 0 or ±MIN
    if abs(new_power_feed) < POWER_FEED_MIN_THRESHOLD and new_power_feed != 0:
        new_power_feed = old_power_feed
        adjustment_applied = 0
    
    # Round to integer
    new_power_feed = int(round(new_power_feed))
    
    return (new_power_feed, adjustment_applied)


def main():
    """Main function to run the P1 meter zero feed-in controller."""
    print("P1 Meter Zero Feed-In Controller")
    print("=" * 50)
    print(f"Read interval: {READ_INTERVAL_SECONDS} seconds")
    print(f"Initialization iterations: {INITIALIZATION_ITERATIONS}")
    print(f"Run time: {RUN_TIME_MINUTES} minutes")
    print("=" * 50)
    print()
    
    # Load configuration
    try:
        config = load_config()
        p1_meter_ip = config['p1MeterIp']
        device_ip = config['deviceIp']
        device_sn = config['deviceSn']
        print(f"P1 Meter IP: {p1_meter_ip}")
        print(f"Zendure Device IP: {device_ip}")
        print(f"Zendure Device SN: {device_sn}")
    except Exception as e:
        print(f"Error loading config: {e}")
        return 1
    
    # Initialize power_feed tracking
    power_feed = 0
    last_sent_power_feed = None  # Track last sent value to avoid unnecessary API calls
    iteration_count = 0  # Track number of successful readings
    
    # Calculate end time
    start_time = time.time()
    end_time = start_time + (RUN_TIME_MINUTES * 60)
    
    print(f"Starting at {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"Will run until {datetime.fromtimestamp(end_time).strftime('%Y-%m-%d %H:%M:%S')}")
    print()
    
    # Set power_feed to 0 at start
    if ENABLE_POWER_FEED_SEND:
        print("Setting power_feed to 0 at startup...")
        success, error_msg = send_power_feed(device_ip, device_sn, 0)
        if success:
            print("✅ Power feed reset to 0")
            last_sent_power_feed = 0
        else:
            print(f"⚠️  Failed to reset power feed to 0: {error_msg}")
        print()
    
    # Main loop with cleanup
    try:
        while time.time() < end_time:
            # Calculate time remaining
            current_time = time.time()
            time_remaining = end_time - current_time
            
            # Read from P1 meter
            total_power = read_p1_meter(p1_meter_ip)
            
            if total_power is not None:
                iteration_count += 1
                
                # Calculate power_feed only after initialization period
                adjustment_applied = 0
                send_status = None  # Track send status for output
                if iteration_count > INITIALIZATION_ITERATIONS:
                    new_power_feed, adjustment_applied = calculate_power_feed(total_power, power_feed)
                    power_feed = new_power_feed
                    
                    # Send power_feed to device if enabled and value changed
                    # Compare integer values to avoid floating-point precision issues
                    power_feed_int = int(round(power_feed))
                    last_sent_int = int(round(last_sent_power_feed)) if last_sent_power_feed is not None else None
                    
                    if ENABLE_POWER_FEED_SEND and power_feed_int != last_sent_int:
                        success, error_msg = send_power_feed(device_ip, device_sn, power_feed)
                        if success:
                            last_sent_power_feed = power_feed
                            send_status = "→ Sent"
                        else:
                            send_status = f"→ Send failed: {error_msg}"
                
                # Format timestamp
                timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
                
                # Format output
                if iteration_count > INITIALIZATION_ITERATIONS:
                    power_feed_str = f"{power_feed} W"
                    if adjustment_applied != 0:
                        adjustment_str = f" (adj: {int(round(adjustment_applied)):+d} W)"
                    else:
                        adjustment_str = ""
                else:
                    power_feed_str = f"{power_feed} W (init)"
                    adjustment_str = ""
                
                # Print reading with power feed and countdown
                time_remaining_str = format_time_remaining(time_remaining)
                send_status_str = f" {send_status}" if send_status else ""
                print(f"[{timestamp}] Reading: {total_power} W | "
                      f"Power Feed: {power_feed_str}{adjustment_str}{send_status_str} | "
                      f"Time remaining: {time_remaining_str}")
            else:
                # Error reading, but continue
                timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
                time_remaining_str = format_time_remaining(time_remaining)
                print(f"[{timestamp}] Failed to read meter | Time remaining: {time_remaining_str}")
            
            # Sleep until next reading (account for processing time)
            sleep_time = READ_INTERVAL_SECONDS - (time.time() - current_time)
            if sleep_time > 0:
                time.sleep(sleep_time)
    finally:
        # Set power_feed to 0 at end (cleanup)
        if ENABLE_POWER_FEED_SEND:
            print()
            print("Setting power_feed to 0 at shutdown...")
            success, error_msg = send_power_feed(device_ip, device_sn, 0)
            if success:
                print("✅ Power feed reset to 0")
            else:
                print(f"⚠️  Failed to reset power feed to 0: {error_msg}")
    
    print()
    print("=" * 50)
    print(f"Finished at {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"Total readings collected: {iteration_count}")
    print("=" * 50)
    
    return 0


if __name__ == "__main__":
    exit(main())

