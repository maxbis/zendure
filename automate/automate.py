#!/usr/bin/env python3
"""
Automation script for charge schedule monitoring (OOP version with keyboard commands)

Runs continuously, checking the charge schedule API and applying power settings
using the OOP device controller classes. Supports interactive keyboard commands.
"""

import signal
import time
import json
import requests
import sys
import select
import platform
import threading
import queue
from datetime import datetime
from zoneinfo import ZoneInfo
from pathlib import Path
from typing import Optional

from device_controller import AutomateController, ScheduleController, BaseDeviceController, DeviceDataReader

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
# KEYBOARD COMMAND HANDLING
# ============================================================================

def print_help():
    """Print available keyboard commands."""
    print("\n" + "="*60)
    print("Available Commands:")
    print("="*60)
    print("  h, help          - Show this help message")
    print("  s, status        - Show current status (power, battery, schedule)")
    print("  a, accumulators  - Print accumulator status")
    print("  r, refresh       - Force refresh schedule from API")
    print("  p <value>        - Set power manually (e.g., 'p 500' or 'p netzero')")
    print("  z, zero          - Set power to 0")
    print("  nz, netzero      - Set power to netzero mode")
    print("  nzp, netzero+    - Set power to netzero+ mode")
    print("  q, quit          - Quit gracefully")
    print("="*60 + "\n")

def handle_command(command: str, controller: AutomateController, 
                   schedule_controller: ScheduleController, 
                   status_api_url: str) -> bool:
    """
    Handle a keyboard command.
    
    Args:
        command: The command string entered by user
        controller: The AutomateController instance
        schedule_controller: The ScheduleController instance
        status_api_url: Status API URL for updates
    
    Returns:
        True if should continue, False if should quit
    """
    command = command.strip().lower()
    
    if not command:
        return True
    
    parts = command.split()
    cmd = parts[0]
    args = parts[1:] if len(parts) > 1 else []
    
    try:
        if cmd in ['h', 'help']:
            print_help()
        
        elif cmd in ['s', 'status']:
            # Show current status
            log_info("=== Current Status ===")
            try:
                desired_power = schedule_controller.get_desired_power(refresh=False)
                log_info(f"Schedule desired power: {desired_power}")
            except Exception as e:
                log_error(f"Error getting desired power: {e}")
            
            controller.check_battery_limits()
            log_info(f"Battery limit state: {controller.limit_state} (1=max, -1=min, 0=ok)")
            
            try:
                reader = DeviceDataReader(config_path=controller.config_path)
                zendure_data = reader.read_zendure(update_json=False)
                if zendure_data:
                    props = zendure_data.get("properties", {})
                    battery_level = props.get("electricLevel", 'N/A')
                    log_info(f"Battery level: {battery_level}%")
            except Exception as e:
                log_warning(f"Could not read Zendure data: {e}")
        
        elif cmd in ['a', 'accumulators']:
            controller.print_accumulators()
        
        elif cmd in ['r', 'refresh']:
            log_info("Forcing schedule refresh...")
            try:
                schedule_controller.fetch_schedule()
                log_info("Schedule refreshed successfully")
            except Exception as e:
                log_error(f"Failed to refresh schedule: {e}")
        
        elif cmd == 'p' and args:
            # Set power manually
            power_arg = args[0]
            try:
                # Try to parse as integer first
                if power_arg.lstrip('-').isdigit():
                    power_value = int(power_arg)
                elif power_arg in ['netzero', 'netzero+']:
                    power_value = power_arg
                else:
                    log_error(f"Invalid power value: {power_arg}")
                    log_info("Use an integer (e.g., 500) or 'netzero' or 'netzero+'")
                    return True
                
                log_info(f"Manually setting power to: {power_value}")
                result = controller.set_power(power_value)
                if result.success:
                    log_info(f"Power set to: {result.power}")
                    post_status_update(status_api_url, 'change', None, result.power)
                else:
                    log_error(f"Failed to set power: {result.error}")
            except ValueError:
                log_error(f"Invalid power value: {power_arg}")
        
        elif cmd in ['z', 'zero']:
            log_info("Setting power to 0")
            result = controller.set_power(0)
            if result.success:
                log_info(f"Power set to 0")
                post_status_update(status_api_url, 'change', None, 0)
            else:
                log_error(f"Failed to set power: {result.error}")
        
        elif cmd in ['nz', 'netzero']:
            log_info("Setting power to netzero")
            result = controller.set_power('netzero')
            if result.success:
                log_info(f"Power set to netzero")
                post_status_update(status_api_url, 'change', None, 'netzero')
            else:
                log_error(f"Failed to set power: {result.error}")
        
        elif cmd in ['nzp', 'netzero+']:
            log_info("Setting power to netzero+")
            result = controller.set_power('netzero+')
            if result.success:
                log_info(f"Power set to netzero+")
                post_status_update(status_api_url, 'change', None, 'netzero+')
            else:
                log_error(f"Failed to set power: {result.error}")
        
        elif cmd in ['q', 'quit']:
            log_info("Quit command received")
            return False
        
        else:
            log_warning(f"Unknown command: {cmd}. Type 'h' or 'help' for available commands.")
    
    except Exception as e:
        log_error(f"Error executing command: {e}")
    
    return True

# Global input queue for cross-platform input handling
_input_queue = queue.Queue()
_input_thread = None
_input_thread_running = False

def _input_thread_worker():
    """Worker thread that reads from stdin and puts lines into the queue."""
    global _input_thread_running
    try:
        while _input_thread_running:
            try:
                line = sys.stdin.readline()
                if line:
                    _input_queue.put(line.strip())
                else:
                    # EOF reached
                    break
            except (EOFError, OSError):
                break
    except Exception:
        pass

def _start_input_thread():
    """Start the input reading thread (for Windows compatibility)."""
    global _input_thread, _input_thread_running
    if _input_thread is None or not _input_thread.is_alive():
        _input_thread_running = True
        _input_thread = threading.Thread(target=_input_thread_worker, daemon=True)
        _input_thread.start()

def check_for_input(timeout: float = 0.1) -> Optional[str]:
    """
    Check if input is available on stdin (non-blocking).
    Cross-platform implementation that works on both Windows and Unix.
    
    Args:
        timeout: Timeout in seconds (default 0.1)
    
    Returns:
        Input string if available, None otherwise
    """
    # On Windows, use threading approach since select doesn't work with stdin
    if platform.system() == 'Windows':
        # Start input thread if not already running
        if _input_thread is None or not _input_thread.is_alive():
            _start_input_thread()
        
        # Check if there's input in the queue (non-blocking)
        try:
            return _input_queue.get_nowait()
        except queue.Empty:
            return None
    else:
        # Unix/Linux: use select (original approach)
        if select.select([sys.stdin], [], [], timeout)[0]:
            try:
                return sys.stdin.readline().strip()
            except (EOFError, OSError):
                return None
        return None


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
    
    log_info("🚀 Starting charge schedule automation script (OOP version with keyboard commands)")
    log_info(f"   Loop interval: {LOOP_INTERVAL_SECONDS} seconds")
    log_info(f"   API refresh interval: {API_REFRESH_INTERVAL_SECONDS} seconds ({API_REFRESH_INTERVAL_SECONDS // 60} minutes)")
    log_info("   Type 'h' or 'help' for available keyboard commands")
    print()  # Empty line for readability
    
    # Register signal handlers for graceful shutdown
    signal.signal(signal.SIGTERM, signal_handler)
    signal.signal(signal.SIGINT, signal_handler)
    
    last_api_refresh_time = 0
    old_value = None
    value = 0
    zero_count = 0
    zero_count_threshold = 10  # If the power has been set to 0 for 10 consecutive iterations, set device in standby mode
    
    try:
        while True:
            # Check for shutdown request
            if shutdown_flag[0]:
                break
            
            # Read P1 meter and accumulate (every iteration)
            # Read with update_json=True since we may use it for netzero calculation too
            p1_data = None
            try:
                reader = DeviceDataReader(config_path=controller.config_path)
                p1_data = reader.read_p1_meter(update_json=True)
                if p1_data and p1_data.get("total_power") is not None:
                    controller.accumulator.accumulate_p1_reading(p1_data["total_power"])
            except Exception as e:
                log_warning(f"Failed to read P1 for accumulation: {e}")
            
            # Check for keyboard input (non-blocking)
            user_input = check_for_input(timeout=0.1)
            if user_input:
                should_continue = handle_command(user_input, controller, schedule_controller, status_api_url)
                if not should_continue:
                    shutdown_flag[0] = True
                    break
            
            current_time = time.time()
            
            # Check if we need to refresh schedule API data (at startup or every 5 minutes)
            time_since_last_refresh = current_time - last_api_refresh_time
            if time_since_last_refresh >= API_REFRESH_INTERVAL_SECONDS:
                try:
                    schedule_controller.fetch_schedule()
                    last_api_refresh_time = current_time
                    log_info("Schedule data refreshed from API")
                    # Accumulate power feed before printing accumulators to ensure accurate values
                    try:
                        current_power = controller.previous_power if controller.previous_power is not None else 0
                        controller.accumulator.accumulate_power_feed(current_power)
                    except Exception as e:
                        log_warning(f"Failed to accumulate power feed before printing: {e}")
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
                # else:
                #     log_info(f"Current schedule value: {desired_power}")
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
                if validation_power > 0 and controller.limit_state == 1:
                    # Trying to charge but at MAX_CHARGE_LEVEL
                    should_prevent = True
                    reason = f"Battery at MAX_CHARGE_LEVEL, preventing charge"
                elif validation_power < 0 and controller.limit_state == -1:
                    # Trying to discharge but at MIN_CHARGE_LEVEL
                    should_prevent = True
                    reason = f"Battery at MIN_CHARGE_LEVEL, preventing discharge"
            
            # Step 4: Override desired_power if limits are hit
            if should_prevent:
                log_warning(reason)
                desired_power = 0  # Override desired power to 0 when limits are reached
            
            # Step 5: Apply desired_power only if it differs from old_value
            should_apply = (old_value != desired_power) or (desired_power in ['netzero', 'netzero+'])
            
            if should_apply:
                # Pass p1_data to set_power to avoid reading P1 meter again for netzero calculations
                result = controller.set_power(desired_power, p1_data=p1_data)
                if result.success:
                    log_info(f"Power: {result.power} (desired: {desired_power})")
                    post_status_update(status_api_url, 'change', old_value, result.power)
                else:
                    log_error(f"Failed to set power: {result.error}")
            # else:
            #     log_info(f"No change needed (desired: {desired_power}, current: {old_value})")
            
            # Update value for next iteration
            value = desired_power

            # Count the number of times the power has been set to 0
            if value == 0:
                zero_count += 1
            else:
                zero_count = 0
        
            # If the power has been set to 0 for zero_count_threshold consecutive iterations, set device in standby mode
            if zero_count == zero_count_threshold:
                log_info(f"0 power for {zero_count_threshold} consecutive iterations, setting device in standby mode")
                controller.set_power(1)
                sleep(2)
                controller.set_power(0)
            

            # Interruptible sleep: sleep in 1-second chunks and check shutdown flag and input
            sleep_remaining = LOOP_INTERVAL_SECONDS
            while sleep_remaining > 0 and not shutdown_flag[0]:
                # Check for input during sleep
                user_input = check_for_input(timeout=min(1.0, sleep_remaining))
                if user_input:
                    should_continue = handle_command(user_input, controller, schedule_controller, status_api_url)
                    if not should_continue:
                        shutdown_flag[0] = True
                        break
                
                if not shutdown_flag[0]:
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
        # Stop input thread on Windows
        global _input_thread_running
        if platform.system() == 'Windows' and _input_thread_running:
            _input_thread_running = False
        
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
