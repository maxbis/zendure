#!/usr/bin/env python3
"""
Automation script for charge schedule monitoring (OOP version with keyboard commands)

Runs continuously, checking the charge schedule API and applying power settings
using the OOP device controller classes. Supports interactive keyboard commands.
"""

import signal
import time
import requests
import sys
import select
import platform
import threading
import queue
from datetime import datetime
from zoneinfo import ZoneInfo
from typing import Optional

from device_controller import AutomateController, ScheduleController, BaseDeviceController, DeviceDataReader

# ============================================================================
# CONFIGURATION PARAMETERS
# ============================================================================

# Time to pause between loop iterations (seconds)
LOOP_INTERVAL_SECONDS = 20

# Time between schedule API refreshes (seconds) - 5 minutes
API_REFRESH_INTERVAL_SECONDS = 300

# Number of consecutive 0-power iterations before setting device to standby
ZERO_COUNT_THRESHOLD = 21

# ============================================================================
# LOGGER CLASS
# ============================================================================

class Logger:
    """
    Wrapper around device controller logging.
    Provides a consistent logging interface for the automation app.
    """
    
    def __init__(self, controller: Optional[BaseDeviceController] = None):
        """
        Initialize logger with optional controller.
        
        Args:
            controller: Device controller instance that provides logging functionality.
                       If None, falls back to print statements.
        """
        self.controller = controller
    
    def info(self, message: str, include_timestamp: bool = True):
        """Log info message."""
        if self.controller:
            self.controller.log('info', message, include_timestamp)
        else:
            print(message)
    
    def warning(self, message: str, include_timestamp: bool = True):
        """Log warning message."""
        if self.controller:
            self.controller.log('warning', message, include_timestamp)
        else:
            print(f"WARNING: {message}")
    
    def error(self, message: str, include_timestamp: bool = True):
        """Log error message."""
        if self.controller:
            self.controller.log('error', message, include_timestamp)
        else:
            print(f"ERROR: {message}")


# ============================================================================
# STATUS API CLASS
# ============================================================================

class StatusApi:
    """
    Handles status updates to the automation status API.
    Posts events like start, stop, and power changes.
    """
    
    def __init__(self, api_url: Optional[str], logger: Logger):
        """
        Initialize status API client.
        
        Args:
            api_url: URL of the status API endpoint. If None, operations will be no-ops.
            logger: Logger instance for error/warning messages.
        """
        self.api_url = api_url
        self.logger = logger
    
    def post_update(self, event_type: str, old_value: any = None, new_value: any = None) -> bool:
        """
        Post a status update to the automation status API.
        
        Args:
            event_type: Type of event ('start', 'stop', 'change')
            old_value: Previous value (for change events)
            new_value: New value (for change events)
        
        Returns:
            True if successful, False otherwise
        """
        if not self.api_url:
            return False
            
        try:
            timestamp = int(datetime.now(ZoneInfo('Europe/Amsterdam')).timestamp())
            
            payload = {
                'type': event_type,
                'timestamp': timestamp,
                'oldValue': old_value,
                'newValue': new_value
            }
            
            response = requests.post(self.api_url, json=payload, timeout=5, allow_redirects=False)
            
            # Check for redirects
            if response.status_code in [301, 302, 303, 307, 308]:
                redirect_url = response.headers.get('Location')
                if redirect_url:
                    if not redirect_url.startswith('http'):
                        from urllib.parse import urljoin
                        redirect_url = urljoin(self.api_url, redirect_url)
                    response = requests.post(redirect_url, json=payload, timeout=5)
            
            response.raise_for_status()
            data = response.json()
            
            if not data.get('success', False):
                self.logger.warning(f"Status API returned success=false: {data.get('error', 'Unknown error')}")
                return False
                
            return True
        except Exception as e:
            self.logger.warning(f"Error posting status update to API: {e}")
            return False


# ============================================================================
# INPUT HANDLER CLASS
# ============================================================================

class InputHandler:
    """
    Cross-platform input handling for keyboard commands.
    Handles both Unix (select) and Windows (threading) input methods.
    """
    
    def __init__(self):
        """Initialize input handler with platform-specific setup."""
        self.input_queue = queue.Queue()
        self.input_thread = None
        self.input_thread_running = False
    
    def start_input_thread(self):
        """Start input thread for Windows compatibility."""
        if self.input_thread is None or not self.input_thread.is_alive():
            self.input_thread_running = True
            self.input_thread = threading.Thread(target=self._input_thread_worker, daemon=True)
            self.input_thread.start()
    
    def _input_thread_worker(self):
        """Worker to read stdin."""
        try:
            while self.input_thread_running:
                try:
                    line = sys.stdin.readline()
                    if line:
                        self.input_queue.put(line.strip())
                    else:
                        break  # EOF
                except (EOFError, OSError):
                    break
        except Exception:
            pass
    
    def check_for_input(self, timeout: float = 0.1) -> Optional[str]:
        """
        Check for user input.
        
        Args:
            timeout: Timeout in seconds for non-blocking input check
        
        Returns:
            User input string if available, None otherwise
        """
        if platform.system() == 'Windows':
            self.start_input_thread()
            try:
                return self.input_queue.get_nowait()
            except queue.Empty:
                return None
        else:
            if select.select([sys.stdin], [], [], timeout)[0]:
                try:
                    return sys.stdin.readline().strip()
                except (EOFError, OSError):
                    return None
            return None
    
    def stop(self):
        """Stop input thread (for Windows)."""
        if platform.system() == 'Windows' and self.input_thread_running:
            self.input_thread_running = False


# ============================================================================
# COMMAND HANDLER CLASS
# ============================================================================

class CommandHandler:
    """
    Handles keyboard commands for interactive control.
    Processes commands like status, power settings, refresh, etc.
    """
    
    def __init__(self, controller: AutomateController, schedule_controller: ScheduleController, 
                 status_api: StatusApi, logger: Logger):
        """
        Initialize command handler.
        
        Args:
            controller: Device controller for power operations
            schedule_controller: Schedule controller for schedule operations
            status_api: Status API client for posting updates
            logger: Logger instance for messages
        """
        self.controller = controller
        self.schedule_controller = schedule_controller
        self.status_api = status_api
        self.logger = logger
    
    def print_help(self):
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
    
    def handle(self, command: str) -> bool:
        """
        Handle a keyboard command.
        
        Args:
            command: Command string from user input
        
        Returns:
            True to continue, False to quit
        """
        command = command.strip().lower()
        
        if not command:
            return True
        
        parts = command.split()
        cmd = parts[0]
        args = parts[1:] if len(parts) > 1 else []
        
        try:
            if cmd in ['h', 'help']:
                self.print_help()
            
            elif cmd in ['s', 'status']:
                self.logger.info("=== Current Status ===")
                try:
                    desired_power = self.schedule_controller.get_desired_power(refresh=False)
                    self.logger.info(f"Schedule desired power: {desired_power}")
                except Exception as e:
                    self.logger.error(f"Error getting desired power: {e}")
                
                self.controller.check_battery_limits()
                self.logger.info(f"Battery limit state: {self.controller.limit_state} (1=max, -1=min, 0=ok)")
                
                try:
                    reader = DeviceDataReader(config_path=self.controller.config_path)
                    zendure_data = reader.read_zendure(update_json=False)
                    if zendure_data:
                        props = zendure_data.get("properties", {})
                        battery_level = props.get("electricLevel", 'N/A')
                        self.logger.info(f"Battery level: {battery_level}%")
                except Exception as e:
                    self.logger.warning(f"Could not read Zendure data: {e}")
            
            elif cmd in ['a', 'accumulators']:
                self.logger.info("Accumulator debug output has been removed.")
            
            elif cmd in ['r', 'refresh']:
                self.logger.info("Forcing schedule refresh...")
                try:
                    # Get the API URL from config
                    api_url = self.schedule_controller.config.get("apiUrl")
                    if api_url:
                        print("\n" + "="*60)
                        print("API URL:")
                        print("="*60)
                        print(api_url)
                        print("="*60 + "\n")
                    else:
                        self.logger.warning("API URL not found in config")
                    
                    api_response = self.schedule_controller.fetch_schedule()
                    self.logger.info("Schedule refreshed successfully")
                except Exception as e:
                    self.logger.error(f"Failed to refresh schedule: {e}")
            
            elif cmd == 'p' and args:
                power_arg = args[0]
                try:
                    if power_arg.lstrip('-').isdigit():
                        power_value = int(power_arg)
                    elif power_arg in ['netzero', 'netzero+']:
                        power_value = power_arg
                    else:
                        self.logger.error(f"Invalid power value: {power_arg}")
                        self.logger.info("Use an integer (e.g., 500) or 'netzero' or 'netzero+'")
                        return True
                    
                    self.logger.info(f"Manually setting power to: {power_value}")
                    result = self.controller.set_power(power_value)
                    if result.success:
                        self.logger.info(f"Power set to: {result.power}")
                        self.status_api.post_update('change', None, result.power)
                    else:
                        self.logger.error(f"Failed to set power: {result.error}")
                except ValueError:
                    self.logger.error(f"Invalid power value: {power_arg}")
            
            elif cmd in ['z', 'zero']:
                self.logger.info("Setting power to 0")
                result = self.controller.set_power(0)
                if result.success:
                    self.logger.info(f"Power set to 0")
                    self.status_api.post_update('change', None, 0)
                else:
                    self.logger.error(f"Failed to set power: {result.error}")
            
            elif cmd in ['nz', 'netzero']:
                self.logger.info("Setting power to netzero")
                result = self.controller.set_power('netzero')
                if result.success:
                    self.logger.info(f"Power set to netzero")
                    self.status_api.post_update('change', None, 'netzero')
                else:
                    self.logger.error(f"Failed to set power: {result.error}")
            
            elif cmd in ['nzp', 'netzero+']:
                self.logger.info("Setting power to netzero+")
                result = self.controller.set_power('netzero+')
                if result.success:
                    self.logger.info(f"Power set to netzero+")
                    self.status_api.post_update('change', None, 'netzero+')
                else:
                    self.logger.error(f"Failed to set power: {result.error}")
            
            elif cmd in ['q', 'quit']:
                self.logger.info("Quit command received")
                return False
            
            else:
                self.logger.warning(f"Unknown command: {cmd}. Type 'h' or 'help' for available commands.")
        
        except Exception as e:
            self.logger.error(f"Error executing command: {e}")
        
        return True


# ============================================================================
# AUTOMATION APP CLASS
# ============================================================================

class AutomationApp:
    """
    Main application class for the charge schedule automation.
    Encapsulates state, configuration, and the main execution loop.
    Orchestrates all components: logger, status API, input handler, command handler.
    """
    
    def __init__(self):
        self.shutdown_requested = False
        
        # Controllers
        self.controller = None
        self.schedule_controller = None
        
        # Components
        self.logger = None
        self.status_api = None
        self.input_handler = None
        self.command_handler = None
        
        # State variables
        self.last_api_refresh_time = 0
        self.old_value = None
        self.value = 0
        self.zero_count = 0


    def initialize(self) -> bool:
        """Initialize controllers and components."""
        try:
            # Initialize controllers
            self.controller = AutomateController()
            self.schedule_controller = ScheduleController()
            
            # Initialize logger
            self.logger = Logger(self.controller)
            
            # Get status API URL - select based on location (matching schedule directory pattern)
            location = self.schedule_controller.config.get("location", "remote")
            if location == "local":
                status_api_url = self.schedule_controller.config.get("statusApiUrl-local")
            else:
                status_api_url = self.schedule_controller.config.get("statusApiUrl")
            
            if not status_api_url:
                self.logger.error("statusApiUrl not found in config.json")
                return False
            
            # Initialize components
            self.status_api = StatusApi(status_api_url, self.logger)
            self.input_handler = InputHandler()
            self.command_handler = CommandHandler(
                self.controller,
                self.schedule_controller,
                self.status_api,
                self.logger
            )
                
            # Set up signal handlers
            signal.signal(signal.SIGTERM, self._signal_handler)
            signal.signal(signal.SIGINT, self._signal_handler)

            # Generate steps, f.e. [0, 20, 40] for LOOP_INTERVAL_SECONDS = 20
            self.steps = self._generate_steps(LOOP_INTERVAL_SECONDS, 59)

            return True
            
        except FileNotFoundError as e:
            # Create a temporary simple logger if controller init fails
            print(f"Configuration error: {e}")
            print("   Please ensure config.json exists in one of the checked locations")
            return False
        except ValueError as e:
            print(f"Configuration error: {e}")
            return False
        except Exception as e:
            print(f"Failed to initialize controllers: {e}")
            return False

    def _generate_steps(self, step, max_value):
        return sorted(set(range(0, max_value + 1, step)) | {0})

    def _signal_handler(self, signum, frame):
        """Handle shutdown signals."""
        signal_name = signal.Signals(signum).name
        self.logger.warning(f"Received {signal_name} signal, initiating graceful shutdown...")
        self.shutdown_requested = True

    # ------------------------------------------------------------------------
    # MAIN LOGIC HELPERS
    # ------------------------------------------------------------------------

    def _accumulate_p1_data(self) -> Optional[dict]:
        """Read P1 meter and accumulate data."""
        p1_data = None
        try:
            reader = DeviceDataReader(config_path=self.controller.config_path)
            p1_data = reader.read_p1_meter(update_json=True)
            if p1_data and p1_data.get("total_power") is not None:
                # self.logger.info(f"P1 power: {p1_data['total_power']}, p1_data: {p1_data['total_power_import_kwh']}, p1_data: {p1_data['total_power_export_kwh']}")
                if p1_data["total_power_import_kwh"] is not None and p1_data["total_power_export_kwh"] is not None:
                    self.controller.accumulator.accumulate_p1_reading_hourly(p1_data["total_power_import_kwh"], p1_data["total_power_export_kwh"], p1_data["total_power"])
        except Exception as e:
            self.logger.warning(f"Failed to read P1 for accumulation: {e}")
        return p1_data

    def _refresh_schedule_if_needed(self):
        """Refresh schedule API if interval passed."""
        current_time = time.time()
        time_since_last_refresh = current_time - self.last_api_refresh_time
        
        if time_since_last_refresh >= API_REFRESH_INTERVAL_SECONDS:
            try:
                self.schedule_controller.fetch_schedule()
                self.last_api_refresh_time = current_time
                self.logger.info("Schedule data refreshed from API")
                
                # (print_accumulators removed)
                self.status_api.post_update('Rescan', None, None)
            except Exception as e:
                self.logger.error(f"Failed to refresh schedule: {e}")

    def _calculate_desired_power(self) -> any:
        """Get desired power from schedule."""
        try:
            desired_power = self.schedule_controller.get_desired_power(refresh=False)
            if desired_power is None:
                self.logger.info("Schedule value is None, setting desired power to 0")
                return 0
            return desired_power
        except Exception as e:
            self.logger.error(f"Error getting desired power from schedule: {e}")
            return 0

    def _check_battery_limits(self, desired_power: any) -> any:
        """Check availability and modify desired power if limited."""
        self.controller.check_battery_limits()
        
        validation_power = desired_power
        if desired_power == 'netzero':
            validation_power = -250
        elif desired_power == 'netzero+':
            validation_power = 250
            
        if isinstance(validation_power, int):
            if validation_power > 0 and self.controller.limit_state == 1:
                 self.logger.warning(f"Battery at MAX_CHARGE_LEVEL, preventing charge")
                 return 0
            elif validation_power < 0 and self.controller.limit_state == -1:
                 self.logger.warning(f"Battery at MIN_CHARGE_LEVEL, preventing discharge")
                 return 0
                 
        return desired_power

    def _apply_power_settings(self, desired_power: any, p1_data: Optional[dict]):
        """Apply the power settings if changed."""
        should_apply = (self.old_value != desired_power) or (desired_power in ['netzero', 'netzero+'])
        
        if should_apply:
            result = self.controller.set_power(desired_power, p1_data=p1_data)
            if result.success:
                self.logger.info(f"Power: {result.power} (desired: {desired_power})")
                self.status_api.post_update('change', self.old_value, result.power)
                # Update self.value with the actual power that was set (result.power)
                # This is important for netzero modes where calculated power may differ from 'netzero'
                self.value = result.power
            else:
                self.logger.error(f"Failed to set power: {result.error}")
                # Don't update self.value if setting failed - keep previous value
        else:
            # Power didn't change, but still update self.value to desired_power for consistency
            self.value = desired_power

    def _handle_standby_check(self):
        """Check if we need to enter standby mode."""
        if self.value == 0:
            self.zero_count += 1
        else:
            self.zero_count = 0
            
        if self.zero_count == ZERO_COUNT_THRESHOLD:
            self.logger.info(f"0 power for {ZERO_COUNT_THRESHOLD} consecutive iterations, setting device in standby mode")
            self.controller.set_standby_mode()

    def _handle_user_input(self) -> bool:
        """Process any pending user input. Returns False if quit requested."""
        user_input = self.input_handler.check_for_input(timeout=0.1)
        if user_input:
            should_continue = self.command_handler.handle(user_input)
            if not should_continue:
                self.shutdown_requested = True
                return False
        return True

    def _sleep_interrupted(self):
        """Sleep with interrupt for input/shutdown."""
        sleep_remaining = LOOP_INTERVAL_SECONDS
        while sleep_remaining > 0 and not self.shutdown_requested:
            # Skip sleep if it's the first second of the minute
            now = time.localtime().tm_sec
            if now in (self.steps) and sleep_remaining < LOOP_INTERVAL_SECONDS:
                return
            
            # Check input
            if not self._handle_user_input():
                break
                
            if not self.shutdown_requested:
                time.sleep(min(1, sleep_remaining))
            sleep_remaining -= 1

    def _shutdown(self):
        """Perform graceful shutdown."""
        # Stop input thread
        if self.input_handler:
            self.input_handler.stop()

        self.logger.info("👋 Shutting down gracefully...")
        if self.controller:
            self.logger.info("   Setting power to 0 before shutdown...")
            result = self.controller.set_power(0)
            if result.success:
                self.logger.info(f"   Power set to 0")
            else:
                self.logger.error(f"   Failed to set power to 0: {result.error}")
            
            if self.status_api:
                self.status_api.post_update('stop', self.value, None)

    def run(self):
        """Main execution method."""
        if not self.initialize():
            return
            
        self.status_api.post_update('start')
        
        self.logger.info("🚀 Starting charge schedule automation script (OOP version with keyboard commands)")
        self.logger.info(f"   Loop interval: {LOOP_INTERVAL_SECONDS} seconds")
        self.logger.info(f"   API refresh interval: {API_REFRESH_INTERVAL_SECONDS} seconds ({API_REFRESH_INTERVAL_SECONDS // 60} minutes)")
        self.logger.info("   Type 'h' or 'help' for available keyboard commands")
        print()
        
        try:
            while not self.shutdown_requested:
                # 1. Accumulate Data
                p1_data = self._accumulate_p1_data()
                
                # 2. Check input
                if not self._handle_user_input():
                    break
                    
                # 3. Schedule Logic
                self._refresh_schedule_if_needed()
                
                self.old_value = self.value
                desired_power = self._calculate_desired_power()
                
                # 4. Battery Limits
                desired_power = self._check_battery_limits(desired_power)
                
                # 5. Apply Settings
                self._apply_power_settings(desired_power, p1_data)
                
                # 6. Standby Check
                self._handle_standby_check()
                
                # 7. Sleep
                self._sleep_interrupted()
                
        except KeyboardInterrupt:
            self.shutdown_requested = True
        except Exception as e:
            self.logger.error(f"Fatal error in main loop: {e}")
            if self.status_api:
                self.status_api.post_update('stop', self.value, None)
            raise
        finally:
            self._shutdown()


def main():
    app = AutomationApp()
    app.run()


if __name__ == "__main__":
    main()
