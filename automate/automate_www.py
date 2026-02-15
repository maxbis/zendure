#!/usr/bin/env python3
"""
Automation script for charge schedule monitoring (OOP version with HTTP API)

Runs continuously, checking the charge schedule API and applying power settings
using the OOP device controller classes. Exposes an HTTP API on port 1611 with
/api/test, /api/p1, /api/zendure, /api/status, and /api/all endpoints.
/api/p1 and /api/zendure accept optional query param max_age (or maxAge), default 60: 0 = always refresh;
N = refresh if cached data is older than N seconds.
Supports interactive keyboard commands.
"""

import json
import os
import signal
import sqlite3
import time
import requests
import sys
import select
import platform
import threading
import queue
import http.server
import socketserver
from dataclasses import dataclass
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo
from typing import Optional, Any, Callable
from urllib.parse import urlparse, parse_qs

from device_controller import AutomateController, ScheduleController, BaseDeviceController, get_reader

# ============================================================================
# CONFIGURATION PARAMETERS - default values, can be overridden in config.json
# ============================================================================

# Time to pause between loop iterations (seconds)
LOOP_INTERVAL_SECONDS = 20

# Time between schedule API refreshes (seconds) - 5 minutes
API_REFRESH_INTERVAL_SECONDS = 300

# Number of consecutive 0-power iterations before setting device to standby
ZERO_COUNT_THRESHOLD_STANDBY = 21

# HTTP API port for /api/test endpoint
HTTP_API_PORT = 1611

# Wh-per-hour API: timezone and default days
WH_PER_HOUR_TIMEZONE = "Europe/Amsterdam"
WH_PER_HOUR_DAYS_DEFAULT = 3

# ============================================================================
# API READINGS DATA CLASSES
# ============================================================================

@dataclass
class P1Readings:
    """P1 meter readings with timestamp."""
    readings: Optional[dict]
    timestamp: Optional[int]

    def to_dict(self) -> dict:
        return {"readings": self.readings, "timestamp": self.timestamp}


@dataclass
class ZendureReadings:
    """Zendure device readings with timestamp."""
    readings: Optional[dict]
    timestamp: Optional[int]

    def to_dict(self) -> dict:
        return {"readings": self.readings, "timestamp": self.timestamp}


@dataclass
class StatusChange:
    """Last status change event with values and timestamp."""
    event_type: str
    old_value: Any
    new_value: Any
    timestamp: Optional[int]

    def to_dict(self) -> dict:
        return {
            "eventType": self.event_type,
            "oldValue": self.old_value,
            "newValue": self.new_value,
            "timestamp": self.timestamp,
        }


class ApiState:
    """Shared state for API endpoints: latest P1, Zendure, and status readings."""

    def __init__(self):
        self.last_p1: Optional[P1Readings] = None
        self.last_zendure: Optional[ZendureReadings] = None
        self.last_status: Optional[StatusChange] = None


# ============================================================================
# HTTP API HANDLER
# ============================================================================

def compute_wh_per_hour(db_path: str, now: int, days_back: int = WH_PER_HOUR_DAYS_DEFAULT) -> dict:
    """
    Compute watt-hours charged and discharged per calendar hour from status_updates SQLite.
    Uses step integration: power constant between consecutive readings.
    Returns { "YYYY-MM-DD": [ {"hour": "HH", "charged_wh": float, "discharged_wh": float}, ... ], ... }
    for the last days_back full days including today. Hours are ordered 00, 01, ... 23 (array preserves order).
    """
    tz = ZoneInfo(WH_PER_HOUR_TIMEZONE)
    allowed_dates = set()
    for i in range(days_back + 1):
        dt = datetime.fromtimestamp(now, tz=tz)
        from_day = datetime(dt.year, dt.month, dt.day, tzinfo=tz) - timedelta(days=i)
        allowed_dates.add(from_day.strftime("%Y-%m-%d"))

    if not os.path.exists(db_path):
        return {d: [{"hour": f"{h:02d}", "charged_wh": 0.0, "discharged_wh": 0.0} for h in range(24)] for d in sorted(allowed_dates)}

    points = []
    try:
        with sqlite3.connect(db_path) as conn:
            cur = conn.execute(
                "SELECT new_value, timestamp FROM status_updates WHERE type = 'change' AND new_value IS NOT NULL"
            )
            for row in cur.fetchall():
                nv_raw, ts = row[0], row[1]
                if ts is None:
                    continue
                try:
                    nv = json.loads(nv_raw) if isinstance(nv_raw, str) else nv_raw
                    if nv is not None and isinstance(nv, (int, float)):
                        points.append((int(ts), float(nv)))
                except (json.JSONDecodeError, TypeError, ValueError):
                    continue
    except Exception:
        return {d: [{"hour": f"{h:02d}", "charged_wh": 0.0, "discharged_wh": 0.0} for h in range(24)] for d in sorted(allowed_dates)}

    if not points:
        return {d: [{"hour": f"{h:02d}", "charged_wh": 0.0, "discharged_wh": 0.0} for h in range(24)] for d in sorted(allowed_dates)}

    points.sort(key=lambda p: p[0])
    wh_by_date_hour: dict = {}
    n = len(points)
    for i in range(n):
        t_start, power = points[i][0], points[i][1]
        t_end = points[i + 1][0] if i < n - 1 else now
        cur = t_start
        while cur < t_end:
            dt_start = datetime.fromtimestamp(cur, tz=tz)
            hour_start = int(dt_start.strftime("%H"))
            hour_epoch = int(datetime(dt_start.year, dt_start.month, dt_start.day, hour_start, 0, 0, tzinfo=tz).timestamp())
            hour_end = hour_epoch + 3600
            clip_start = max(t_start, hour_epoch)
            clip_end = min(t_end, hour_end)
            if clip_start < clip_end:
                date_str = dt_start.strftime("%Y-%m-%d")
                if date_str not in allowed_dates:
                    cur = hour_end
                    continue
                hh = f"{hour_start:02d}"
                if date_str not in wh_by_date_hour:
                    wh_by_date_hour[date_str] = {}
                if hh not in wh_by_date_hour[date_str]:
                    wh_by_date_hour[date_str][hh] = {"charged_wh": 0.0, "discharged_wh": 0.0}
                wh = abs(power) * (clip_end - clip_start) / 3600
                if power > 0:
                    wh_by_date_hour[date_str][hh]["charged_wh"] += wh
                elif power < 0:
                    wh_by_date_hour[date_str][hh]["discharged_wh"] += wh
            cur = hour_end

    result = {}
    for d in sorted(allowed_dates):
        hours = wh_by_date_hour.get(d, {})
        result[d] = [
            {
                "hour": f"{h:02d}",
                "charged_wh": round(hours.get(f"{h:02d}", {}).get("charged_wh", 0.0), 2),
                "discharged_wh": round(hours.get(f"{h:02d}", {}).get("discharged_wh", 0.0), 2),
            }
            for h in range(24)
        ]
    return result


class AutomationTCPServer(socketserver.ThreadingTCPServer):
    """TCPServer that holds api_state for the request handler."""
    pass  # api_state set on instance after construction


class ApiTestHandler(http.server.BaseHTTPRequestHandler):
    """Handles GET /api/test, /api/p1, /api/zendure, /api/status, /api/all, /api/wh_per_hour, /api/refresh with JSON responses."""

    def _send_json(self, data, status=200, sort_keys=True):
        """Send JSON response."""
        body = json.dumps(data, sort_keys=sort_keys).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _parse_max_age(self, parsed) -> int:
        """Parse max_age (or maxAge) from query string; default 60. Returns non-negative int."""
        max_age = 60
        query = parse_qs(parsed.query)
        for key in ("max_age", "maxAge"):
            if key in query and query[key]:
                try:
                    val = int(query[key][0])
                    if val >= 0:
                        max_age = val
                    break
                except (ValueError, TypeError):
                    pass
        return max_age

    def _maybe_refresh_reading(self, reading, max_age: int, refresh_cb) -> None:
        """If reading is missing or older than max_age seconds, call refresh_cb (0 = always refresh)."""
        now = int(time.time())
        need_refresh = (
            max_age == 0
            or reading is None
            or (now - (reading.timestamp or 0)) > max_age
        )
        if need_refresh and refresh_cb is not None:
            refresh_cb()

    def do_GET(self):
        if self.path == "/api/test":
            self._send_json({
                "status": "ok",
                "message": "API is up and running",
                "endpoints": [
                    {"path": "/api/test", "optional_params": []},
                    {
                        "path": "/api/p1",
                        "optional_params": [
                            {"name": "max_age", "alt": "maxAge", "type": "int", "default": 60, "description": "0 = always refresh; N = refresh if data older than N seconds (default 60)"},
                        ],
                    },
                    {
                        "path": "/api/zendure",
                        "optional_params": [
                            {"name": "max_age", "alt": "maxAge", "type": "int", "default": 60, "description": "0 = always refresh; N = refresh if data older than N seconds (default 60)"},
                        ],
                    },
                    {"path": "/api/status", "optional_params": []},
                    {"path": "/api/all", "optional_params": []},
                    {"path": "/api/wh_per_hour", "optional_params": []},
                    {"path": "/api/refresh", "optional_params": []},
                ],
            })
            return

        if self.path == "/api/wh_per_hour":
            db_path = getattr(self.server, "db_path", None)
            if not db_path or not os.path.exists(db_path):
                self._send_json({"error": "Status updates database not available"})
                return
            data = compute_wh_per_hour(db_path, int(time.time()), WH_PER_HOUR_DAYS_DEFAULT)
            self._send_json(data, sort_keys=True)
            return

        if self.path == "/api/refresh":
            schedule_controller = getattr(self.server, "schedule_controller", None)
            status_api = getattr(self.server, "status_api", None)
            if not schedule_controller or not status_api:
                self._send_json({"error": "Refresh not available"}, 503)
                return
            try:
                schedule_controller.fetch_schedule()
                status_api.post_update("Rescan", None, None)
                self._send_json({"ok": True})
            except Exception as e:
                self._send_json({"ok": False, "error": str(e)}, 500)
            return

        api_state = getattr(self.server, "api_state", None)
        if api_state is None:
            self._send_json({"error": "API state not initialized"}, 503)
            return

        parsed = urlparse(self.path)
        if parsed.path == "/api/p1":
            max_age = self._parse_max_age(parsed)
            self._maybe_refresh_reading(
                api_state.last_p1, max_age,
                getattr(self.server, "refresh_p1_callback", None),
            )
            data = api_state.last_p1.to_dict() if api_state.last_p1 else None
            self._send_json(data)
        elif parsed.path == "/api/zendure":
            max_age = self._parse_max_age(parsed)
            self._maybe_refresh_reading(
                api_state.last_zendure, max_age,
                getattr(self.server, "refresh_zendure_callback", None),
            )
            data = api_state.last_zendure.to_dict() if api_state.last_zendure else None
            self._send_json(data)
        elif parsed.path == "/api/status":
            data = api_state.last_status.to_dict() if api_state.last_status else None
            self._send_json(data)
        elif parsed.path == "/api/all":
            response = {
                "p1": api_state.last_p1.to_dict() if api_state.last_p1 else None,
                "zendure": api_state.last_zendure.to_dict() if api_state.last_zendure else None,
                "status": api_state.last_status.to_dict() if api_state.last_status else None,
            }
            self._send_json(response)
        else:
            self.send_error(404, "Not Found")

    def log_message(self, format, *args):
        """Suppress default request logging to avoid cluttering automation output."""
        pass


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
    Optionally stores updates in SQLite for Wh/h calculation.
    """
    
    _SCHEMA = """
        CREATE TABLE IF NOT EXISTS status_updates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL,
            old_value TEXT,
            new_value TEXT,
            p1_total_power INTEGER,
            electric_level INTEGER,
            timestamp INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_status_updates_timestamp ON status_updates(timestamp);
    """
    
    def __init__(self, api_url: Optional[str], logger: Logger,
                 on_update: Optional[Callable[[str, Any, Any, int], None]] = None,
                 db_path: Optional[str] = None,
                 get_electric_level: Optional[Callable[[], Optional[int]]] = None,
                 retention_days: int = 7):
        """
        Initialize status API client.
        
        Args:
            api_url: URL of the status API endpoint. If None, operations will be no-ops.
            logger: Logger instance for error/warning messages.
            on_update: Optional callback(event_type, old_value, new_value, timestamp) when posting.
            db_path: Optional path to SQLite DB for storing status updates.
            get_electric_level: Optional callable returning current battery % (0-100) for DB storage.
            retention_days: Days to retain rows in SQLite (default 7).
        """
        self.api_url = api_url
        self.logger = logger
        self.on_update = on_update
        self.db_path = db_path
        self.get_electric_level = get_electric_level
        self.retention_days = retention_days
        self._db_initialized = False
    
    def _ensure_db(self) -> None:
        """Create DB file, directory, and table if needed."""
        if not self.db_path or self._db_initialized:
            return
        try:
            db_dir = os.path.dirname(self.db_path)
            if db_dir:
                os.makedirs(db_dir, exist_ok=True)
            with sqlite3.connect(self.db_path) as conn:
                conn.executescript(self._SCHEMA)
            self._db_initialized = True
        except Exception as e:
            self.logger.warning(f"Failed to initialize SQLite DB: {e}")
    
    def _insert_status(self, event_type: str, old_value: Any, new_value: Any,
                       p1_total_power: Optional[int], timestamp: int) -> None:
        """Insert status update into SQLite and optionally run retention cleanup."""
        if not self.db_path:
            return
        self._ensure_db()
        electric_level = None
        if self.get_electric_level:
            try:
                electric_level = self.get_electric_level()
            except Exception:
                pass
        old_str = json.dumps(old_value) if old_value is not None else None
        new_str = json.dumps(new_value) if new_value is not None else None
        try:
            with sqlite3.connect(self.db_path) as conn:
                conn.execute(
                    "INSERT INTO status_updates (type, old_value, new_value, p1_total_power, electric_level, timestamp) "
                    "VALUES (?, ?, ?, ?, ?, ?)",
                    (event_type, old_str, new_str, p1_total_power, electric_level, timestamp)
                )
                cutoff = int(timestamp) - (self.retention_days * 24 * 60 * 60)
                conn.execute("DELETE FROM status_updates WHERE timestamp < ?", (cutoff,))
                conn.commit()
        except Exception as e:
            self.logger.warning(f"Failed to write status update to SQLite: {e}")
    
    def post_update(self, event_type: str, old_value: Any = None, new_value: Any = None,
                    p1_total_power: Optional[int] = None) -> bool:
        """
        Post a status update to the automation status API.
        
        Args:
            event_type: Type of event ('start', 'stop', 'change')
            old_value: Previous value (for change events)
            new_value: New value (for change events)
            p1_total_power: Optional last P1 meter total power (W) to attach to the entry.
        
        Returns:
            True if successful, False otherwise
        """
        timestamp = int(datetime.now(ZoneInfo('Europe/Amsterdam')).timestamp())
        if self.on_update:
            self.on_update(event_type, old_value, new_value, timestamp)

        self._insert_status(event_type, old_value, new_value, p1_total_power, timestamp)

        if not self.api_url:
            return False
            
        try:
            
            payload = {
                'type': event_type,
                'timestamp': timestamp,
                'oldValue': old_value,
                'newValue': new_value
            }
            if p1_total_power is not None:
                payload['p1TotalPower'] = p1_total_power
            
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
        self._command_handlers = {
            "h": self._cmd_help,
            "help": self._cmd_help,
            "s": self._cmd_status,
            "status": self._cmd_status,
            "a": self._cmd_accumulators,
            "accumulators": self._cmd_accumulators,
            "r": self._cmd_refresh,
            "refresh": self._cmd_refresh,
            "p": self._cmd_power,
            "z": self._cmd_zero,
            "zero": self._cmd_zero,
            "nz": self._cmd_netzero,
            "netzero": self._cmd_netzero,
            "nzp": self._cmd_netzero_plus,
            "netzero+": self._cmd_netzero_plus,
            "q": self._cmd_quit,
            "quit": self._cmd_quit,
        }

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
            handler = self._command_handlers.get(cmd)
            if handler is not None:
                return handler(args)
            self.logger.warning(f"Unknown command: {cmd}. Type 'h' or 'help' for available commands.")
            return True
        except Exception as e:
            self.logger.error(f"Error executing command: {e}")
            return True

    def _cmd_help(self, args: list) -> bool:
        self.print_help()
        return True

    def _cmd_status(self, args: list) -> bool:
        self.logger.info("=== Current Status ===")
        try:
            desired_power = self.schedule_controller.get_desired_power(refresh=False)
            self.logger.info(f"Schedule desired power: {desired_power}")
        except Exception as e:
            self.logger.error(f"Error getting desired power: {e}")
        self.controller.check_battery_limits()
        self.logger.info(f"Battery limit state: {self.controller.limit_state} (1=max, -1=min, 0=ok)")
        try:
            reader = get_reader(self.controller.config_path)
            zendure_data = reader.read_zendure(update_json=False)
            if zendure_data:
                props = zendure_data.get("properties", {})
                battery_level = props.get("electricLevel", "N/A")
                self.logger.info(f"Battery level: {battery_level}%")
        except Exception as e:
            self.logger.warning(f"Could not read Zendure data: {e}")
        return True

    def _cmd_accumulators(self, args: list) -> bool:
        self.logger.info("Accumulator debug output has been removed.")
        return True

    def _cmd_refresh(self, args: list) -> bool:
        self.logger.info("Forcing schedule refresh...")
        try:
            api_url = self.schedule_controller.config.get("apiUrl")
            if api_url:
                print("\n" + "="*60)
                print("API URL:")
                print("="*60)
                print(api_url)
                print("="*60 + "\n")
            else:
                self.logger.warning("API URL not found in config")
            self.schedule_controller.fetch_schedule()
            self.logger.info("Schedule refreshed successfully")
        except Exception as e:
            self.logger.error(f"Failed to refresh schedule: {e}")
        return True

    def _cmd_power(self, args: list) -> bool:
        if not args:
            self.logger.error("Power command requires a value (e.g., 'p 500' or 'p netzero')")
            return True
        power_arg = args[0]
        try:
            if power_arg.lstrip("-").isdigit():
                power_value = int(power_arg)
            elif power_arg in ["netzero", "netzero+"]:
                power_value = power_arg
            else:
                self.logger.error(f"Invalid power value: {power_arg}")
                self.logger.info("Use an integer (e.g., 500) or 'netzero' or 'netzero+'")
                return True
            self.logger.info(f"Manually setting power to: {power_value}")
            result = self.controller.set_power(power_value)
            if result.success:
                self.logger.info(f"Power set to: {result.power}")
                self.status_api.post_update("change", None, result.power)
            else:
                self.logger.error(f"Failed to set power: {result.error}")
        except ValueError:
            self.logger.error(f"Invalid power value: {power_arg}")
        return True

    def _cmd_zero(self, args: list) -> bool:
        self.logger.info("Setting power to 0")
        result = self.controller.set_power(0)
        if result.success:
            self.logger.info("Power set to 0")
            self.status_api.post_update("change", None, 0)
        else:
            self.logger.error(f"Failed to set power: {result.error}")
        return True

    def _cmd_netzero(self, args: list) -> bool:
        self.logger.info("Setting power to netzero")
        result = self.controller.set_power("netzero")
        if result.success:
            self.logger.info("Power set to netzero")
            self.status_api.post_update("change", None, "netzero")
        else:
            self.logger.error(f"Failed to set power: {result.error}")
        return True

    def _cmd_netzero_plus(self, args: list) -> bool:
        self.logger.info("Setting power to netzero+")
        result = self.controller.set_power("netzero+")
        if result.success:
            self.logger.info("Power set to netzero+")
            self.status_api.post_update("change", None, "netzero+")
        else:
            self.logger.error(f"Failed to set power: {result.error}")
        return True

    def _cmd_quit(self, args: list) -> bool:
        self.logger.info("Quit command received")
        return False


# ============================================================================
# AUTOMATION APP CLASS
# ============================================================================

class AutomationApp:
    """
    Main application class for the charge schedule automation.
    Encapsulates state, configuration, and the main execution loop.
    Orchestrates all components: logger, status API, input handler, command handler.
    Includes HTTP API server for /api/test endpoint.
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
        
        # HTTP API server
        self.http_server = None
        self.http_server_thread = None
        
        # Shared state for API endpoints
        self.api_state = ApiState()
        
        # State variables
        self.last_api_refresh_time = 0
        self.old_value = None
        self.value = 0
        self.zero_count = 0
        self.last_p1_total_power: Optional[int] = None  # last P1 meter total power (W) for status API


    def initialize(self) -> bool:
        """Initialize controllers and components."""
        try:
            # Initialize controllers
            self.controller = AutomateController()
            self.schedule_controller = ScheduleController()

            # Initialize shared DeviceDataReader early (fail fast on config issues)
            get_reader(self.controller.config_path)
            
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
            
            data_dir = self.schedule_controller.config.get("dataDir", "./data/")
            db_path = os.path.join(data_dir.rstrip("/").rstrip("\\"), "status_updates.db")
            retention_days = int(self.schedule_controller.config.get("statusUpdatesRetentionDays", 7))
            
            def get_electric_level() -> Optional[int]:
                if not self.api_state or not self.api_state.last_zendure:
                    return None
                readings = self.api_state.last_zendure.readings
                if not readings:
                    return None
                props = readings.get("properties") or {}
                return props.get("electricLevel")
            
            # Initialize components
            self.status_api = StatusApi(
                status_api_url, self.logger,
                on_update=self._on_status_update,
                db_path=db_path,
                get_electric_level=get_electric_level,
                retention_days=retention_days
            )
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

            self._load_loop_config()
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

    def _load_loop_config(self) -> None:
        """Load loop-related config from controller config and set attributes."""
        try:
            loop_interval = int(self.controller.config.get("LOOP_INTERVAL_SECONDS", LOOP_INTERVAL_SECONDS))
        except (TypeError, ValueError):
            loop_interval = LOOP_INTERVAL_SECONDS
        self.loop_interval_seconds = max(5, min(loop_interval, 300))  # clamp 5–300 seconds
        self.steps = self._generate_steps(self.loop_interval_seconds, 59)

        try:
            power_feed_max_delta = int(self.controller.config.get("POWER_FEED_MAX_DELTA", 2400))
        except (TypeError, ValueError):
            power_feed_max_delta = 2400
        self.power_feed_max_delta = max(0, power_feed_max_delta)

        try:
            api_refresh = int(self.controller.config.get("API_REFRESH_INTERVAL_SECONDS", API_REFRESH_INTERVAL_SECONDS))
        except (TypeError, ValueError):
            api_refresh = API_REFRESH_INTERVAL_SECONDS
        self.api_refresh_interval_seconds = max(60, min(api_refresh, 3600))  # clamp 1–60 minutes

        try:
            zero_threshold = int(self.controller.config.get("ZERO_COUNT_THRESHOLD_STANDBY", ZERO_COUNT_THRESHOLD_STANDBY))
        except (TypeError, ValueError):
            zero_threshold = ZERO_COUNT_THRESHOLD_STANDBY
        self.zero_count_threshold_standby = max(1, min(zero_threshold, 100))

    def _signal_handler(self, signum, frame):
        """Handle shutdown signals."""
        signal_name = signal.Signals(signum).name
        self.logger.warning(f"Received {signal_name} signal, initiating graceful shutdown...")
        self.shutdown_requested = True

    def _on_status_update(self, event_type: str, old_value: Any, new_value: Any, timestamp: int):
        """Callback when status is posted; updates api_state.last_status."""
        self.api_state.last_status = StatusChange(
            event_type=event_type,
            old_value=old_value,
            new_value=new_value,
            timestamp=timestamp,
        )

    def _start_http_server(self):
        """Start HTTP API server in a daemon thread."""
        try:
            self.http_server = AutomationTCPServer(("", HTTP_API_PORT), ApiTestHandler)
            self.http_server.api_state = self.api_state
            self.http_server.db_path = self.status_api.db_path
            self.http_server.schedule_controller = self.schedule_controller
            self.http_server.status_api = self.status_api
            self.http_server.refresh_p1_callback = self._refresh_p1_for_api
            self.http_server.refresh_zendure_callback = self._refresh_zendure_for_api
            self.http_server_thread = threading.Thread(target=self.http_server.serve_forever, daemon=True)
            self.http_server_thread.start()
            self.logger.info(f"HTTP API listening on port {HTTP_API_PORT}")
        except OSError as e:
            self.logger.warning(f"Failed to start HTTP API server: {e}")

    # ------------------------------------------------------------------------
    # MAIN LOGIC HELPERS
    # ------------------------------------------------------------------------

    def _accumulate_p1_data(self) -> Optional[dict]:
        """Read P1 meter and accumulate data."""
        p1_data = None
        try:
            reader = get_reader(self.controller.config_path)
            p1_data = reader.read_p1_meter(update_json=True)
            if p1_data:
                if p1_data["total_power_import_kwh"] is not None and p1_data["total_power_export_kwh"] is not None:
                    import_delta, export_delta = self.controller.accumulator.accumulate_p1_reading_hourly(p1_data["total_power_import_kwh"], p1_data["total_power_export_kwh"])
                    self.logger.info(f"P1 deltas: import_delta={int(import_delta*1000)} Wh, export_delta={int(export_delta*1000)} Wh, actual power={p1_data['total_power']} W")
        except Exception as e:
            self.logger.warning(f"Failed to read P1 for accumulation: {e}")
        return p1_data

    def _refresh_p1_for_api(self) -> None:
        """Read P1 meter and update api_state.last_p1 (for on-demand refresh from /api/p1)."""
        p1_data = self._accumulate_p1_data()
        if p1_data is not None:
            self.api_state.last_p1 = P1Readings(
                readings=p1_data,
                timestamp=int(time.time()),
            )
            if p1_data.get("total_power") is not None:
                try:
                    self.last_p1_total_power = int(p1_data["total_power"])
                except (TypeError, ValueError):
                    pass

    def _refresh_zendure_for_api(self) -> None:
        """Read Zendure device and update api_state.last_zendure (for on-demand refresh from /api/zendure)."""
        try:
            reader = get_reader(self.controller.config_path)
            zendure_data = reader.read_zendure(update_json=True)
            if zendure_data is not None:
                self.api_state.last_zendure = ZendureReadings(
                    readings=zendure_data,
                    timestamp=int(time.time()),
                )
        except Exception as e:
            self.logger.warning(f"Failed to read Zendure for API: {e}")

    def _refresh_schedule_if_needed(self):
        """Refresh schedule API if interval passed."""
        current_time = time.time()
        time_since_last_refresh = current_time - self.last_api_refresh_time
        
        if time_since_last_refresh >= self.api_refresh_interval_seconds:
            try:
                self.schedule_controller.fetch_schedule()
                self.last_api_refresh_time = current_time
                self.logger.info("Schedule data refreshed from API")
                
                # (print_accumulators removed)
                self.status_api.post_update('Rescan', None, None, p1_total_power=self.last_p1_total_power)
            except Exception as e:
                self.logger.error(f"Failed to refresh schedule: {e}")

    def _calculate_desired_power(self) -> any:
        """Get desired power from schedule."""
        try:
            desired_power = self.schedule_controller.get_desired_power(refresh=False)
            # Share the active schedule entry with the accumulator for hourly persistence/logging.
            try:
                self.controller.accumulator.last_schedule_entry = getattr(self.schedule_controller, "last_schedule_entry", None)
            except Exception:
                # Non-fatal: schedule entry persistence should not break automation.
                pass

            if desired_power is None:
                self.logger.info("Schedule value is None, setting desired power to 0")
                return 0
            return desired_power
        except Exception as e:
            self.logger.error(f"Error getting desired power from schedule: {e}")
            try:
                self.controller.accumulator.last_schedule_entry = None
            except Exception:
                pass
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
                p1_w = None
                if p1_data is not None and p1_data.get('total_power') is not None:
                    try:
                        p1_w = int(p1_data['total_power'])
                    except (TypeError, ValueError):
                        pass
                self.status_api.post_update('change', self.old_value, result.power, p1_total_power=p1_w)
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
            
        if self.zero_count == self.zero_count_threshold_standby:
            self.logger.info(f"0 power for {self.zero_count_threshold_standby} consecutive iterations, setting device in standby mode")
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
        sleep_remaining = self.loop_interval_seconds
        while sleep_remaining > 0 and not self.shutdown_requested:
            # Skip sleep if it's the first second of the minute
            now = time.localtime().tm_sec
            if now in (self.steps) and sleep_remaining < self.loop_interval_seconds:
                return
            
            # Check input
            if not self._handle_user_input():
                break
                
            if not self.shutdown_requested:
                time.sleep(min(1, sleep_remaining))
            sleep_remaining -= 1

    def _shutdown(self):
        """Perform graceful shutdown."""
        # Shutdown HTTP API server
        if self.http_server:
            try:
                self.http_server.shutdown()
            except Exception:
                pass

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
                self.status_api.post_update('stop', self.value, None, p1_total_power=self.last_p1_total_power)

    def run(self):
        """Main execution method."""
        if not self.initialize():
            return
            
        self.status_api.post_update('start')
        
        self.logger.info("🚀 Starting charge schedule automation script")
        # Show test mode prominently on startup (controlled via config.json key: TEST_MODE).
        if getattr(self.controller, "test_mode", False):
            self.logger.warning("TEST MODE: ON (no commands wil be send to the Zendure device)")
        else:
            self.logger.info("TEST MODE: OFF")

        self.logger.info(f"   Loop interval: {self.loop_interval_seconds} seconds")
        self.logger.info(f"   API refresh interval: {self.api_refresh_interval_seconds} seconds ({self.api_refresh_interval_seconds // 60} minutes)")
        self.logger.info("   Type 'h' or 'help' for available keyboard commands")
        print()
        
        # Start HTTP API server
        self._start_http_server()
        
        try:
            while not self.shutdown_requested:
                 # 0. Sleep
                self._sleep_interrupted()

                # 1. Accumulate Data
                p1_data = self._accumulate_p1_data()
                if p1_data is not None:
                    self.api_state.last_p1 = P1Readings(
                        readings=p1_data,
                        timestamp=int(time.time()),
                    )
                    if p1_data.get('total_power') is not None:
                        try:
                            self.last_p1_total_power = int(p1_data['total_power'])
                        except (TypeError, ValueError):
                            pass
                
                # 2. Check input
                if not self._handle_user_input():
                    break
                    
                # 3. Schedule Logic
                self._refresh_schedule_if_needed()
                
                self.old_value = self.value
                desired_power = self._calculate_desired_power()
                
                # 4. Battery Limits
                desired_power = self._check_battery_limits(desired_power)
                zendure_data = getattr(
                    get_reader(self.controller.config_path), "last_zendure_data", None
                )
                if zendure_data is not None:
                    self.api_state.last_zendure = ZendureReadings(
                        readings=zendure_data,
                        timestamp=int(time.time()),
                    )

                # 4b. Max delta step limiting
                if isinstance(desired_power, int) and isinstance(self.old_value, int):
                    delta = desired_power - self.old_value
                    if abs(delta) > self.power_feed_max_delta:
                        limited_power = self.old_value + (
                            self.power_feed_max_delta if delta > 0 else -self.power_feed_max_delta
                        )
                        self.logger.warning(
                            f"Max delta limit hit: old={self.old_value}, desired={desired_power}, "
                            f"power_feed_max_delta={self.power_feed_max_delta}, limited={limited_power}"
                        )
                        desired_power = limited_power
                
                # 5. Apply Settings
                self._apply_power_settings(desired_power, p1_data)
                
                # 6. Standby Check
                self._handle_standby_check()
                
        except KeyboardInterrupt:
            self.shutdown_requested = True
        except Exception as e:
            self.logger.error(f"Fatal error in main loop: {e}")
            if self.status_api:
                self.status_api.post_update('stop', self.value, None, p1_total_power=self.last_p1_total_power)
            raise
        finally:
            self._shutdown()


def main():
    app = AutomationApp()
    app.run()


if __name__ == "__main__":
    main()
