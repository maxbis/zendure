#!/usr/bin/env python3
"""
Automation script for charge schedule monitoring

Runs continuously, checking the charge schedule API every 5 minutes
and finding the current schedule value based on the resolved time entries.
"""

import json
import time
import os
from datetime import datetime
from typing import Optional, Dict, Any, List

import requests

from zero_feed_in_shot import set_power

# ============================================================================
# CONFIGURATION PARAMETERS
# ============================================================================

# Time to pause between loop iterations (seconds)
LOOP_INTERVAL_SECONDS = 20

# Time between API calls (seconds) - 5 minutes
API_REFRESH_INTERVAL_SECONDS = 300

# API endpoint URL
API_URL = "http://localhost/Energy/schedule/api/charge_schedule_api.php"

# Valid integer range for schedule values
MIN_VALUE = -800
MAX_VALUE = 800


# ============================================================================
# API CALL FUNCTION
# ============================================================================

def fetch_schedule_api() -> Optional[Dict[str, Any]]:
    """
    Fetches the charge schedule data from the API.n automate.py, we have two possible vl
    
    Returns:
        Dictionary with API response data, or None on error
    """
    try:
        response = requests.get(API_URL, timeout=10)
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
# MAIN LOOP
# ============================================================================

def main():
    """Main execution loop."""
    print("🚀 Starting charge schedule automation script")
    print(f"   Loop interval: {LOOP_INTERVAL_SECONDS} seconds")
    print(f"   API refresh interval: {API_REFRESH_INTERVAL_SECONDS} seconds ({API_REFRESH_INTERVAL_SECONDS // 60} minutes)")
    print(f"   API URL: {API_URL}")
    print()
    
    last_api_call_time = 0
    resolved_data = None
    current_hour = None
    value = 0
    old_value = 0
    
    try:
        while True:
            current_time = time.time()
            
            # Check if we need to refresh API data (at startup or every 5 minutes)
            time_since_last_api = current_time - last_api_call_time
            if time_since_last_api >= API_REFRESH_INTERVAL_SECONDS:
                print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] Fetching schedule from API...")
                
                api_data = fetch_schedule_api()
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
                else:
                    print("⚠️  Failed to fetch API data, set power to 0")
                    print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] Value set at: {set_power(0)}")
            
            # Find and print current schedule value if we have valid data
            if resolved_data and current_hour:
                old_value = value
                value = find_current_schedule_value(resolved_data, current_hour)
                if value is None:
                    print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] No value found, set power to {set_power(0)}")
                elif old_value != value or value == 'netzero':
                    print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] Value set at: {set_power(value)}")
                else:
                    print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] No new value to set")
            else:
                print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] No data found, set power to {set_power(0)}")
            
            # Sleep for the loop interval
            time.sleep(LOOP_INTERVAL_SECONDS)
            
    except KeyboardInterrupt:
        print("\n👋 Shutting down gracefully...")
    except Exception as e:
        print(f"\n❌ Fatal error in main loop: {e}")
        raise


if __name__ == "__main__":
    main()

