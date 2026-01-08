#!/usr/bin/env python3
"""
Automation script for charge schedule monitoring (OOP version)

Runs continuously, checking the charge schedule API and applying power settings
using the OOP device controller classes.
"""

import signal
import time
from pathlib import Path
from typing import Optional

from device_controller import AutomateController, ScheduleController, BaseDeviceController

# ============================================================================
# CONFIGURATION PARAMETERS
# ============================================================================

# Time to pause between loop iterations (seconds)
LOOP_INTERVAL_SECONDS = 20

# Time between schedule API refreshes (seconds) - 5 minutes
API_REFRESH_INTERVAL_SECONDS = 300

# ============================================================================
# LOGGING SETUP
# ============================================================================

# Global logger instance (will be initialized in main())
_logger = None

def _get_logger():
    """Get or create logger instance."""
    global _logger
    if _logger is None:
        try:
            _logger = BaseDeviceController()
        except Exception:
            # Fallback: create a minimal logger that just prints
            class SimpleLogger:
                def log(self, level, message, include_timestamp=True, file_path=None):
                    from datetime import datetime
                    from zoneinfo import ZoneInfo
                    emoji_map = {'info': '', 'debug': '🔍', 'warning': '⚠️', 'error': '❌', 'success': '✅'}
                    emoji = emoji_map.get(level.lower(), '')
                    if include_timestamp:
                        timestamp = datetime.now(ZoneInfo('Europe/Amsterdam')).strftime('%Y-%m-%d %H:%M:%S')
                        prefix = f"[{timestamp}]"
                    else:
                        prefix = ""
                    output = f"{prefix} {emoji} {message}".strip() if emoji else f"{prefix} {message}".strip()
                    print(output)
            _logger = SimpleLogger()
    return _logger

def log_info(message: str, include_timestamp: bool = True):
    """Log an informational message."""
    _get_logger().log('info', message, include_timestamp)

def log_warning(message: str, include_timestamp: bool = True):
    """Log a warning message."""
    _get_logger().log('warning', message, include_timestamp)

def log_error(message: str, include_timestamp: bool = True):
    """Log an error message."""
    _get_logger().log('error', message, include_timestamp)

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
# STATUS UPDATE FUNCTION
# ============================================================================

def post_status_update(status_api_url: str, event_type: str, old_value: any = None, new_value: any = None) -> bool:
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
    import requests
    import json
    from datetime import datetime
    from zoneinfo import ZoneInfo
    
    try:
        timestamp = int(datetime.now(ZoneInfo('Europe/Amsterdam')).timestamp())
        
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
    except Exception as e:
        log_warning(f"Error posting status update to API: {e}")
        return False


# ============================================================================
# MAIN LOOP
# ============================================================================

def main():
    """Main execution loop."""
    # Initialize controllers
    try:
        controller = AutomateController()
        schedule_controller = ScheduleController()
        
        # Set global logger to use controller's logger (which is a BaseDeviceController instance)
        global _logger
        _logger = controller
        
        # Get status API URL from config
        status_api_url = schedule_controller.config.get("statusApiUrl")
        if not status_api_url:
            log_error("statusApiUrl not found in config.json")
            return
    except FileNotFoundError as e:
        log_error(f"Configuration error: {e}")
        log_info("   Please ensure config.json exists in one of the checked locations")
        return
    except ValueError as e:
        log_error(f"Configuration error: {e}")
        return
    except Exception as e:
        log_error(f"Failed to initialize controllers: {e}")
        return
    
    # Log startup
    post_status_update(status_api_url, 'start')
    
    log_info("🚀 Starting charge schedule automation script (OOP version)")
    log_info(f"   Loop interval: {LOOP_INTERVAL_SECONDS} seconds")
    log_info(f"   API refresh interval: {API_REFRESH_INTERVAL_SECONDS} seconds ({API_REFRESH_INTERVAL_SECONDS // 60} minutes)")
    print()  # Empty line for readability
    
    # Register signal handlers for graceful shutdown
    signal.signal(signal.SIGTERM, signal_handler)
    signal.signal(signal.SIGINT, signal_handler)
    
    last_api_refresh_time = 0
    old_value = None
    value = 0
    
    try:
        while True:
            # Check for shutdown request
            if shutdown_flag[0]:
                break
            
            current_time = time.time()
            
            # Check if we need to refresh schedule API data (at startup or every 5 minutes)
            time_since_last_refresh = current_time - last_api_refresh_time
            if time_since_last_refresh >= API_REFRESH_INTERVAL_SECONDS:
                try:
                    schedule_controller.fetch_schedule()
                    last_api_refresh_time = current_time
                    log_info("Schedule data refreshed from API")
                    # Print accumulator status every 5 minutes when schedule is updated
                    controller.print_accumulators()
                except Exception as e:
                    log_error(f"Failed to refresh schedule: {e}")
                    # Continue with cached data if available
            
            # Step 1: Get desired power from schedule
            old_value = value
            try:
                desired_power = schedule_controller.get_desired_power(refresh=False)
                if desired_power is None:
                    desired_power = 0
                    log_info("Schedule value is None, setting desired power to 0")
                else:
                    log_info(f"Current schedule value: {desired_power}")
            except Exception as e:
                log_error(f"Error getting desired power from schedule: {e}")
                desired_power = 0
            
            # Step 2: Check battery limits
            controller.check_battery_limits()
            
            # Step 3: Validate desired_power against battery limits
            # Translate netzero modes to validation values for limit checking
            validation_power = desired_power
            if desired_power == 'netzero':
                # netzero can charge or discharge, use -250 (discharge) as worst case for validation
                validation_power = -250
            elif desired_power == 'netzero+':
                # netzero+ only charges, use +250 for validation
                validation_power = 250
            
            # Check if limit_state would prevent the desired operation
            should_prevent = False
            reason = ""
            if isinstance(validation_power, int):
                if validation_power < 0 and controller.limit_state == 1:
                    # Trying to charge but at MAX_CHARGE_LEVEL
                    should_prevent = True
                    reason = f"Battery at MAX_CHARGE_LEVEL, preventing charge"
                elif validation_power > 0 and controller.limit_state == -1:
                    # Trying to discharge but at MIN_CHARGE_LEVEL
                    should_prevent = True
                    reason = f"Battery at MIN_CHARGE_LEVEL, preventing discharge"
            
            # Step 4: Override desired_power if limits are hit
            if should_prevent:
                log_warning(reason)
                desired_power = 0  # Override desired power to 0 when limits are reached
            
            # Step 5: Apply desired_power only if it differs from old_value
            if old_value != desired_power:
                result = controller.set_power(desired_power)
                if result.success:
                    resulting_power = result.power
                    log_info(f"Power set to: {resulting_power} (desired: {desired_power})")
                    post_status_update(status_api_url, 'change', old_value, resulting_power)
                else:
                    log_error(f"Failed to set power: {result.error}")
            else:
                log_info(f"No change needed (desired: {desired_power}, current: {old_value})")
            
            # Update value for next iteration
            value = desired_power

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
        result = controller.set_power(0)
        if result.success:
            log_info(f"   Power set to 0")
        else:
            log_error(f"   Failed to set power to 0: {result.error}")
        post_status_update(status_api_url, 'stop', value, None)


if __name__ == "__main__":
    main()
