#!/usr/bin/env python3
"""
Automation script for charge schedule monitoring

Runs continuously, checking the charge schedule API every 5 minutes
and finding the current schedule value based on the resolved time entries.
"""

import json
import signal
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Optional, Dict, Any, List
from zoneinfo import ZoneInfo
import requests
from zero_feed_in_controller import set_power, CONFIG_FILE_PATH, check_battery_limits
from logger import log_info, log_debug, log_warning, log_error, log_success

# ============================================================================
# TIMEZONE CONFIGURATION
# ============================================================================

# Configurable timezone (default: Europe/Amsterdam)
TIMEZONE = 'Europe/Amsterdam'

# ============================================================================
# CONFIGURATION PARAMETERS
# ============================================================================

# Time to pause between loop iterations (seconds)
LOOP_INTERVAL_SECONDS = 20

# Time between API calls (seconds) - 5 minutes
API_REFRESH_INTERVAL_SECONDS = 300

# ============================================================================
# SIGNAL HANDLING
# ============================================================================

# Shutdown flag (using list for mutable reference in signal handler)
shutdown_flag = [False]

def signal_handler(signum, frame):
    """
    Signal handler for graceful shutdown.
    Sets the shutdown flag when SIGTERM or SIGINT is received.
    """
    signal_name = signal.Signals(signum).name
    log_warning(f"Received {signal_name} signal, initiating graceful shutdown...")
    shutdown_flag[0] = True

# ============================================================================
# TIMESTAMP FUNCTION
# ============================================================================

def get_my_datetime() -> datetime:
    """
    Get the current datetime object using the configured timezone.
    
    Returns:
        datetime object with the configured timezone
    """
    return datetime.now(ZoneInfo(TIMEZONE))

def get_my_timestamp() -> int:
    """
    Get the current Unix timestamp (as integer) using the configured timezone.
    
    Returns:
        Unix timestamp as integer (seconds since epoch)
    """
    return int(get_my_datetime().timestamp())


# ============================================================================
# CONFIG LOADING
# ============================================================================

def load_config(config_path: Path) -> Dict[str, Any]:
    """
    Load configuration from config.json.
    
    Expected keys:
    - apiUrl: API endpoint URL for charge schedule
    - statusApiUrl: API endpoint URL for automation status logging
    """
    try:
        with open(config_path, "r") as f:
            config = json.load(f)
    except FileNotFoundError:
        raise FileNotFoundError(f"Config file not found: {config_path}")
    except json.JSONDecodeError as e:
        raise ValueError(f"Invalid JSON in config file {config_path}: {e}")
    
    api_url = config.get("apiUrl")
    status_api_url = config.get("statusApiUrl")
    
    if not api_url:
        raise ValueError("apiUrl not found in config.json")
    if not status_api_url:
        raise ValueError("statusApiUrl not found in config.json")
    
    return {
        "apiUrl": api_url,
        "statusApiUrl": status_api_url,
    }


# ============================================================================
# STATUS UPDATE FUNCTION
# ============================================================================

def post_status_update(status_api_url: str, event_type: str, old_value: Any = None, new_value: Any = None) -> bool:
    """
    Posts a status update to the automation status API.
    
    Args:
        status_api_url: The status API endpoint URL
        event_type: Type of event ('start', 'stop', 'change', 'heartbeat')
        old_value: Previous power value (for 'change' events)
        new_value: New power value (for 'change' events)
    
    Returns:
        True if successful, False otherwise
    """
    try:
        timestamp = get_my_timestamp()
        
        payload = {
            'type': event_type,
            'timestamp': timestamp,
            'oldValue': old_value,
            'newValue': new_value
        }
        
        response = requests.post(status_api_url, json=payload, timeout=5, allow_redirects=False)
        
        # Check for redirects and follow them silently
        if response.status_code in [301, 302, 303, 307, 308]:
            redirect_url = response.headers.get('Location')
            if redirect_url:
                if not redirect_url.startswith('http'):
                    # Relative URL
                    from urllib.parse import urljoin
                    redirect_url = urljoin(status_api_url, redirect_url)
                response = requests.post(redirect_url, json=payload, timeout=5)
        
        response.raise_for_status()
        data = response.json()
        
        if not data.get('success', False):
            log_warning(f"Status API returned success=false: {data.get('error', 'Unknown error')}")
            return False
        
        # Only warn if method is not POST (indicates a problem)
        detected_method = data.get('method', 'unknown')
        if detected_method != 'POST' and event_type in ['start', 'stop', 'change']:
            log_warning(f"Server responded with method '{detected_method}' instead of 'POST' - server PHP file may need updating!")
        
        return True
    except requests.exceptions.RequestException as e:
        log_warning(f"Error posting status update to API: {e}")
        return False
    except json.JSONDecodeError as e:
        log_warning(f"Error parsing status API response: {e}")
        return False
    except Exception as e:
        log_warning(f"Unexpected error posting status update: {e}")
        return False


# ============================================================================
# API CALL FUNCTION
# ============================================================================

def fetch_schedule_api(api_url: str) -> Optional[Dict[str, Any]]:
    """
    Fetches the charge schedule data from the API.
    
    Args:
        api_url: The API endpoint URL to fetch from
    
    Returns:
        Dictionary with API response data, or None on error
    """
    try:
        response = requests.get(api_url, timeout=10)
        response.raise_for_status()
        data = response.json()
        
        if not data.get("success"):
            log_error(f"API returned success=false: {data.get('error', 'Unknown error')}")
            return None
            
        return data
    except requests.exceptions.RequestException as e:
        log_error(f"Error fetching schedule API: {e}")
        return None
    except json.JSONDecodeError as e:
        log_error(f"Error parsing JSON response: {e}")
        return None
    except Exception as e:
        log_error(f"Unexpected error calling API: {e}")
        return None


# ============================================================================
# TIME MATCHING LOGIC
# ============================================================================

def find_current_schedule_value(resolved: List[Dict[str, Any]], current_time: str) -> Optional[Any]:
    """
    Finds the schedule value for the current time.
    
    Finds the resolved entry with the largest time that is still <= current_time.
    
    Args:
        resolved: List of resolved schedule entries, each with 'time' and 'value' keys
        current_time: Current time in "HHMM" format (e.g., "1811" or "2300")
        
    Returns:
        The value from the matching entry, or None if no match found
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
            log_warning(f"No valid entries found for current time {current_time}")
            return None
        
        # Find the entry with the maximum time (closest but not exceeding)
        max_time, matching_entry = max(valid_entries_with_int_time, key=lambda x: x[0])
        
        return matching_entry.get('value')
        
    except Exception as e:
        log_error(f"Error finding current schedule value: {e}")
        return None


# ============================================================================
# REFRESH SCHEDULE FUNCTION, read from AP
# ============================================================================
def refresh_schedule(api_url, last_api_call_time, current_time):
    """
    Fetch and process the schedule API.
    Returns: (resolved_data, current_time_str, last_api_call_time)
    """
    log_info("Fetching schedule from API...")
    
    api_data = fetch_schedule_api(api_url)
    
    if api_data:
        resolved_data = api_data.get('resolved')
        # Prefer currentTime (includes minutes) over currentHour (hour only)
        # This allows entries like "1801" to be matched correctly at 18:11
        current_time_str = api_data.get('currentTime') or api_data.get('currentHour')

        if resolved_data is None:
            log_warning("API response missing 'resolved' field")
        elif current_time_str is None:
            log_warning("API response missing 'currentTime' and 'currentHour' fields")
        else:
            log_success(f"API data refreshed. Current time: {current_time_str}, Resolved entries: {len(resolved_data)}")
        
        last_api_call_time = current_time
        return resolved_data, current_time_str, last_api_call_time
    else:
        log_warning("Failed to fetch API data, set power to 0")
        resulting_power = set_power(0)
        log_info(f"Value set at: {resulting_power}")
        return None, None, last_api_call_time


# ============================================================================
# MAIN LOOP
# ============================================================================

def main():
    """Main execution loop."""
    # Load configuration
    try:
        config = load_config(CONFIG_FILE_PATH)
        api_url = config["apiUrl"]
        status_api_url = config["statusApiUrl"]
    except FileNotFoundError as e:
        log_error(f"Configuration error: {e}")
        log_info("   Please ensure config.json exists in one of the checked locations")
        return
    except ValueError as e:
        log_error(f"Configuration error: {e}")
        log_info("   Please ensure config.json contains 'apiUrl' and 'statusApiUrl' fields")
        return
    
    # Log startup
    post_status_update(status_api_url, 'start')
    
    log_info("🚀 Starting charge schedule automation script")
    log_info(f"   Loop interval: {LOOP_INTERVAL_SECONDS} seconds")
    log_info(f"   API refresh interval: {API_REFRESH_INTERVAL_SECONDS} seconds ({API_REFRESH_INTERVAL_SECONDS // 60} minutes)")
    log_info(f"   API URL: {api_url}")
    print()  # Empty line for readability
    
    # Register signal handlers for graceful shutdown
    signal.signal(signal.SIGTERM, signal_handler)
    signal.signal(signal.SIGINT, signal_handler)
    
    last_api_call_time = 0
    resolved_data = None
    current_time_str = None
    old_value = None
    value = 0
    
    try:
        while True:
            # Check for shutdown request
            if shutdown_flag[0]:
                break
            
            current_time = get_my_timestamp()
            now_amsterdam = get_my_datetime()
            
            # Check if we need to refresh API data (at startup or every 5 minutes)
            time_since_last_api = current_time - last_api_call_time
            if time_since_last_api >= API_REFRESH_INTERVAL_SECONDS:
                resolved_data, current_time_str, last_api_call_time = refresh_schedule(api_url, last_api_call_time, current_time)
            
            # Check for shutdown request again after potentially long-running operations
            if shutdown_flag[0]:
                break
            
            # Find current schedule value if we have valid data
            if resolved_data and current_time_str:
                old_value = value
                value = find_current_schedule_value(resolved_data, current_time_str)
                log_info(f"Current schedule value: {value}")
                if value is None:
                    resulting_power = set_power(0)
                    log_info(f"No value found, set power to 0")
                    post_status_update(status_api_url, 'change', old_value, resulting_power)
                elif old_value != value or value == 'netzero' or value == 'netzero+':
                    resulting_power = set_power(value)
                    log_info(f"Value at: {resulting_power}")
                    post_status_update(status_api_url, 'change', old_value, resulting_power)
                else:
                    log_info(f"No new value to set")
            else:
                resulting_power = set_power(0)
                log_info(f"No data found, set power to {resulting_power}")
                post_status_update(status_api_url, 'change', old_value, resulting_power)

            # Check battery limits continuously (every loop iteration)
            # This ensures we stop charging/discharging when limits are reached
            # even if the schedule value hasn't changed
            should_stop, reason = check_battery_limits(config_path=CONFIG_FILE_PATH, update=False)
            if should_stop:
                log_warning(reason)
                battery_check_old_value = value  # Store current value for status update
                resulting_power = set_power(0)
                post_status_update(status_api_url, 'change', battery_check_old_value, resulting_power)

            # Interruptible sleep: sleep in 1-second chunks and check shutdown flag
            sleep_remaining = LOOP_INTERVAL_SECONDS
            while sleep_remaining > 0 and not shutdown_flag[0]:
                time.sleep(min(1, sleep_remaining))
                sleep_remaining -= 1
            
            # If shutdown was requested during sleep, break immediately
            if shutdown_flag[0]:
                break
            
    except KeyboardInterrupt:
        log_info("👋 Shutting down gracefully...")
        shutdown_flag[0] = True
    except Exception as e:
        log_error(f"Fatal error in main loop: {e}")
        post_status_update(status_api_url, 'stop', value, None)
        raise
    
    # Handle graceful shutdown from signal
    if shutdown_flag[0]:
        log_info("👋 Shutting down gracefully...")
        log_info("   Setting power to 0 before shutdown...")
        set_power(0)
        post_status_update(status_api_url, 'stop', value, None)


if __name__ == "__main__":
    main()

