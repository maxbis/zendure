#!/usr/bin/env python3
"""
Automation script for charge schedule monitoring

Runs continuously, checking the charge schedule API every 5 minutes
and finding the current schedule value based on the resolved time entries.
"""

import json
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Optional, Dict, Any, List
from zoneinfo import ZoneInfo
import requests
from zero_feed_in_shot import set_power

# ============================================================================
# TIMEZONE CONFIGURATION
# ============================================================================

# Configurable timezone (default: Europe/Amsterdam)
TIMEZONE = 'Europe/Amsterdam'

# ============================================================================
# CONFIGURATION PARAMETERS
# ============================================================================

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
    root_config = script_dir.parent.parent / "config" / "config.json"
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

# Path to config.json (with fallback to project root config)
CONFIG_FILE_PATH = find_config_file()

# Time to pause between loop iterations (seconds)
LOOP_INTERVAL_SECONDS = 20

# Time between API calls (seconds) - 5 minutes
API_REFRESH_INTERVAL_SECONDS = 300



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
            print(f"⚠️  Status API returned success=false: {data.get('error', 'Unknown error')}")
            return False
        
        # Only warn if method is not POST (indicates a problem)
        detected_method = data.get('method', 'unknown')
        if detected_method != 'POST' and event_type in ['start', 'stop', 'change']:
            print(f"⚠️  WARNING: Server responded with method '{detected_method}' instead of 'POST' - server PHP file may need updating!")
        
        return True
    except requests.exceptions.RequestException as e:
        print(f"⚠️  Error posting status update to API: {e}")
        return False
    except json.JSONDecodeError as e:
        print(f"⚠️  Error parsing status API response: {e}")
        return False
    except Exception as e:
        print(f"⚠️  Unexpected error posting status update: {e}")
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
            print(f"❌ API returned success=false: {data.get('error', 'Unknown error')}")
            return None
            
        return data
    except requests.exceptions.RequestException as e:
        print(f"❌ Error fetching schedule API: {e}")
        return None
    except json.JSONDecodeError as e:
        print(f"❌ Error parsing JSON response: {e}")
        return None
    except Exception as e:
        print(f"❌ Unexpected error calling API: {e}")
        return None


# ============================================================================
# TIME MATCHING LOGIC
# ============================================================================

def find_current_schedule_value(resolved: List[Dict[str, Any]], current_hour: str) -> Optional[Any]:
    """
    Finds the schedule value for the current time.
    
    Finds the resolved entry with the largest time that is still <= current_hour.
    
    Args:
        resolved: List of resolved schedule entries, each with 'time' and 'value' keys
        current_hour: Current hour in "HHMM" format (e.g., "2300")
        
    Returns:
        The value from the matching entry, or None if no match found
    """
    try:
        current_hour_int = int(current_hour)
        
        # Filter entries where time <= current_hour
        valid_entries = [
            entry for entry in resolved
            if 'time' in entry and isinstance(entry['time'], (str, int))
        ]
        
        # Convert time to int for comparison
        valid_entries_with_int_time = []
        for entry in valid_entries:
            try:
                time_int = int(entry['time'])
                if time_int <= current_hour_int:
                    valid_entries_with_int_time.append((time_int, entry))
            except (ValueError, TypeError):
                continue
        
        if not valid_entries_with_int_time:
            print(f"⚠️  No valid entries found for current hour {current_hour}")
            return None
        
        # Find the entry with the maximum time (closest but not exceeding)
        max_time, matching_entry = max(valid_entries_with_int_time, key=lambda x: x[0])
        
        return matching_entry.get('value')
        
    except Exception as e:
        print(f"❌ Error finding current schedule value: {e}")
        return None


# ============================================================================
# REFRESH SCHEDULE FUNCTION, read from AP
# ============================================================================
def refresh_schedule(api_url, last_api_call_time, current_time):
    """
    Fetch and process the schedule API.
    Returns: (resolved_data, current_hour, last_api_call_time)
    """
    now_amsterdam = get_my_datetime()
    print(f"[{now_amsterdam.strftime('%Y-%m-%d %H:%M:%S')}] Fetching schedule from API...")
    
    api_data = fetch_schedule_api(api_url)
    
    if api_data:
        resolved_data = api_data.get('resolved')
        current_hour = api_data.get('currentHour')

        if resolved_data is None:
            print("⚠️  API response missing 'resolved' field")
        elif current_hour is None:
            print("⚠️  API response missing 'currentHour' field")
        else:
            print(f"✅ API data refreshed. Current hour: {current_hour}, Resolved entries: {len(resolved_data)}")
        
        last_api_call_time = current_time
        return resolved_data, current_hour, last_api_call_time
    else:
        print("⚠️  Failed to fetch API data, set power to 0")
        print(f"[{now_amsterdam.strftime('%Y-%m-%d %H:%M:%S')}] Value set at: {set_power(0)}")
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
        print(f"❌ Configuration error: {e}")
        print("   Please ensure config.json exists in one of the checked locations")
        return
    except ValueError as e:
        print(f"❌ Configuration error: {e}")
        print("   Please ensure config.json contains 'apiUrl' and 'statusApiUrl' fields")
        return
    
    # Log startup
    post_status_update(status_api_url, 'start')
    
    print("🚀 Starting charge schedule automation script")
    print(f"   Loop interval: {LOOP_INTERVAL_SECONDS} seconds")
    print(f"   API refresh interval: {API_REFRESH_INTERVAL_SECONDS} seconds ({API_REFRESH_INTERVAL_SECONDS // 60} minutes)")
    print(f"   API URL: {api_url}")
    print()
    
    last_api_call_time = 0
    resolved_data = None
    current_hour = None
    old_value = None
    value = 0
    
    try:
        while True:
            current_time = get_my_timestamp()
            now_amsterdam = get_my_datetime()
            
            # Check if we need to refresh API data (at startup or every 5 minutes)
            time_since_last_api = current_time - last_api_call_time
            if time_since_last_api >= API_REFRESH_INTERVAL_SECONDS:
                resolved_data, current_hour, last_api_call_time = refresh_schedule(api_url, last_api_call_time, current_time)
            
            # Find current schedule value if we have valid data
            if resolved_data and current_hour:
                old_value = value
                value = find_current_schedule_value(resolved_data, current_hour)
                if value is None:
                    resulting_power = set_power(0)
                    print(f"[{now_amsterdam.strftime('%Y-%m-%d %H:%M:%S')}] No value found, set power to 0")
                    post_status_update(status_api_url, 'No Value', old_value, resulting_power)
                elif old_value != value or value == 'netzero' or value == 'netzero+':
                    resulting_power = set_power(value)
                    print(f"[{now_amsterdam.strftime('%Y-%m-%d %H:%M:%S')}] Value set at: {resulting_power}")
                    post_status_update(status_api_url, 'Change', old_value, resulting_power)
                else:
                    print(f"[{now_amsterdam.strftime('%Y-%m-%d %H:%M:%S')}] No new value to set")
            else:
                resulting_power = set_power(0)
                print(f"[{now_amsterdam.strftime('%Y-%m-%d %H:%M:%S')}] No data found, set power to {set_power(0)}")
                post_status_update(status_api_url, 'No Data', old_value, resulting_power)

            # Sleep for the loop interval
            time.sleep(LOOP_INTERVAL_SECONDS)
            
    except KeyboardInterrupt:
        print("\n👋 Shutting down gracefully...")
        post_status_update(status_api_url, 'stop', value, None)
    except Exception as e:
        print(f"\n❌ Fatal error in main loop: {e}")
        post_status_update(status_api_url, 'stop', value, None)
        raise


if __name__ == "__main__":
    main()

