#!/usr/bin/env python3
"""
Automation script for charge schedule monitoring (OOP version with HTTP API)

Runs continuously, checking the charge schedule API and applying power settings
using the OOP device controller classes. Exposes an HTTP API on port 1611 with
/api/test, /api/p1, /api/zendure, /api/status, and /api/all endpoints.
/api/p1 and /api/zendure accept optional query param max_age (or maxAge), default 60: 0 = always refresh;
N = refresh if cached data is older than N seconds.
"""

import json
import os
import signal
import time
import sys
import threading
from dataclasses import dataclass
from warnings import simplefilter
from typing import Optional, Any, Callable

from automate_api import ApiState, create_http_server
from device_controller import AutomateController, ScheduleController, BaseDeviceController, get_reader
from status_updates_store import (
    StatusApi,
    EVENT_TYPE_CHANGE,
    EVENT_TYPE_RESCAN,
    EVENT_TYPE_START,
    EVENT_TYPE_STOP,
)
from power_metere_loader import get_power_meter_reader
from power_meter_mqtt_subscriber import MqttPowerMeterSubscriber

# ============================================================================
# CONSTANTS & DEFAULT CONFIG (override via config.jsonc)
# ============================================================================

# Time to pause between loop iterations (seconds)
LOOP_INTERVAL_SECONDS = 20

# Time between schedule API refreshes (seconds) - 5 minutes
API_REFRESH_INTERVAL_SECONDS = 300
MQTT_STALE_AFTER_SECONDS = 55
MQTT_PERIODIC_CONTROL_INTERVAL_SECONDS = 60
FAST_LOOP_INTERVAL_SECONDS = 2

# Time at 0 power before setting device to standby (seconds)
STANDBY_DELAY_SECONDS = 300

# HTTP API port for /api/test endpoint
HTTP_API_PORT = 1611

# Retention cleanup scheduling
RETENTION_CLEANUP_INTERVAL_SECONDS = 24 * 60 * 60
RETENTION_CLEANUP_LOOP_INTERVAL = 500

# Power mode strings and validation defaults
POWER_MODE_NETZERO = "netzero"
POWER_MODE_NETZERO_PLUS = "netzero+"
POWER_MODE_NETZERO_VALIDATION_W = -250
POWER_MODE_NETZERO_PLUS_VALIDATION_W = 250

# HTTP API endpoints
API_PATH_TEST = "/api/test"
API_PATH_P1 = "/api/p1"
API_PATH_ZENDURE = "/api/zendure"
API_PATH_STATUS = "/api/status"
API_PATH_ALL = "/api/all"
API_PATH_AUTOMATION_STATUS = "/api/automation_status"
API_PATH_WH_PER_HOUR = "/api/wh_per_hour"
API_PATH_STATUS_UPDATES_DELTA = "/api/status_updates_delta"
API_PATH_REFRESH = "/api/refresh"
API_PATH_RESTART = "/api/restart"
API_PATH_PAUSE = "/api/pause"
API_PATH_LOG_LEVEL = "/api/loglevel"

# Shutdown behavior
SHUTDOWN_FORCE_EXIT_SECONDS = 5.0
RESTART_EXIT_CODE = 75

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


@dataclass
class AutomationStatusEntry:
    """Last status entry per event type."""
    event_type: str
    old_value: Any
    new_value: Any
    p1_total_power: Optional[int]
    timestamp: Optional[int]

    def to_dict(self) -> dict:
        return {
            "timestamp": self.timestamp,
            "type": self.event_type,
            "oldValue": self.old_value,
            "newValue": self.new_value,
            "p1TotalPower": self.p1_total_power,
        }


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

    def info(self, message: str, include_timestamp: bool = True, message_key: Optional[str] = None):
        """Log info message."""
        if self.controller:
            self.controller.log('info', message, include_timestamp, message_key=message_key)
        else:
            print(message)

    def warning(self, message: str, include_timestamp: bool = True, message_key: Optional[str] = None):
        """Log warning message."""
        if self.controller:
            self.controller.log('warning', message, include_timestamp, message_key=message_key)
        else:
            print(f"WARNING: {message}")

    def debug(self, message: str, include_timestamp: bool = True, message_key: Optional[str] = None):
        """Log debug message."""
        if self.controller:
            self.controller.log('debug', message, include_timestamp, message_key=message_key)
        else:
            print(f"DEBUG: {message}")

    def error(self, message: str, include_timestamp: bool = True, message_key: Optional[str] = None):
        """Log error message."""
        if self.controller:
            self.controller.log('error', message, include_timestamp, message_key=message_key)
        else:
            print(f"ERROR: {message}")


# ============================================================================
# AUTOMATION APP CLASS
# ============================================================================

class AutomationApp:
    """
    Main application class for the charge schedule automation.
    Encapsulates state, configuration, and the main execution loop.
    Orchestrates all components: logger, status API, MQTT subscriber, and HTTP API.
    Includes HTTP API server for /api/test endpoint.
    """

    def __init__(self):
        self.shutdown_requested = False
        self.restart_requested = False
        self._shutdown_signal_count = 0
        self._first_shutdown_signal_at: Optional[float] = None

        # Controllers
        self.controller = None
        self.schedule_controller = None

        # Components
        self.logger = None
        self.status_api = None
        self.input_handler = None

        # HTTP API server
        self.http_server = None
        self.http_server_thread = None

        # Shared state for API endpoints
        self.api_state = ApiState()

        # State variables
        self.last_api_refresh_time = 0
        self.old_value = None
        self.value = 0
        self.zero_power_since: Optional[float] = None
        self.standby_sent = False
        self.pause_override_active = False
        self.last_p1_total_power: Optional[int] = None  # last P1 meter total power (W) for status API
        self.last_total_act: Optional[float] = None
        self.last_total_act_ret: Optional[float] = None
        self.stop_posted = False
        self.loop_interval_seconds = LOOP_INTERVAL_SECONDS
        self.steps = self._generate_steps(self.loop_interval_seconds, 59)
        self.api_refresh_interval_seconds = API_REFRESH_INTERVAL_SECONDS
        self._runtime_condition_warning_cache: set[str] = set()
        self._last_runtime_decision_signature: Optional[str] = None
        self.mqtt_helper: Optional[MqttPowerMeterSubscriber] = None
        self.mqtt_stale_after_seconds = MQTT_STALE_AFTER_SECONDS
        self.periodic_control_interval_seconds = MQTT_PERIODIC_CONTROL_INTERVAL_SECONDS
        self.fast_loop_interval_seconds = FAST_LOOP_INTERVAL_SECONDS
        self.fast_loop_active = False
        self.last_full_control_run_ts = 0.0
        self._last_p1_read_source = "http"
        self._last_mqtt_status_log_ts = 0.0
        self._last_mqtt_debug_message_ts: Optional[float] = None
        self.loop_counter = 0
        self.last_retention_cleanup_ts: Optional[int] = None


    def initialize(self) -> bool:
        """Initialize controllers and components."""
        try:
            # Initialize controllers
            self.controller = AutomateController()
            self.schedule_controller = ScheduleController()

            # Startup: print CWD and config path for debugging
            print(f"[startup] CWD: {os.getcwd()}")
            print(f"[startup] config file: {os.path.abspath(str(self.controller.config_path))}")

            # Initialize shared readers early (fail fast on config issues)
            get_reader(self.controller.config_path)
            get_power_meter_reader(self.controller.config_path)

            # Initialize logger
            self.logger = Logger(self.controller)
            self.mqtt_helper = MqttPowerMeterSubscriber(self.controller.config_path)

            data_dir = self.schedule_controller.config.get("dataDir", "./data/")
            db_path = os.path.join(data_dir.rstrip("/").rstrip("\\"), "status_updates.db")
            retention_days = int(self.schedule_controller.config.get("statusUpdatesRetentionDays", 7))
            self.logger.info(f"Status updates DB path: {db_path}")

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
                self.logger,
                on_update=self._on_status_update,
                db_path=db_path,
                get_electric_level=get_electric_level,
                retention_days=retention_days
            )

            # Set up signal handlers
            signal.signal(signal.SIGTERM, self._signal_handler)
            signal.signal(signal.SIGINT, self._signal_handler)

            self._load_loop_config()
            if self.mqtt_helper.is_enabled():
                self.mqtt_helper.start()
            return True

        except FileNotFoundError as e:
            # Create a temporary simple logger if controller init fails
            print(f"Configuration error: {e}")
            print("   Please ensure automate/config/config.jsonc exists")
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
        power_meter_config = self.controller.config.get("powerMeter")
        selected_meter_interval = None
        if isinstance(power_meter_config, dict):
            selected_meter_type = power_meter_config.get("type")
            selected_meter_config = power_meter_config.get(selected_meter_type) if selected_meter_type else None
            if isinstance(selected_meter_config, dict):
                selected_meter_interval = selected_meter_config.get("loopIntervalSeconds")

        try:
            loop_interval = int(
                selected_meter_interval
                if selected_meter_interval is not None
                else self.controller.config.get("LOOP_INTERVAL_SECONDS", LOOP_INTERVAL_SECONDS)
            )
        except (TypeError, ValueError):
            loop_interval = LOOP_INTERVAL_SECONDS
        self.loop_interval_seconds = max(5, min(loop_interval, 300))  # clamp 5–300 seconds
        self.steps = self._generate_steps(self.loop_interval_seconds, 59)

        try:
            api_refresh = int(self.controller.config.get("API_REFRESH_INTERVAL_SECONDS", API_REFRESH_INTERVAL_SECONDS))
        except (TypeError, ValueError):
            api_refresh = API_REFRESH_INTERVAL_SECONDS
        self.api_refresh_interval_seconds = max(60, min(api_refresh, 3600))  # clamp 1–60 minutes

        try:
            fast_loop_interval = int(
                self.controller.config.get("FAST_LOOP_INTERVAL_SECONDS", FAST_LOOP_INTERVAL_SECONDS)
            )
        except (TypeError, ValueError):
            fast_loop_interval = FAST_LOOP_INTERVAL_SECONDS
        self.fast_loop_interval_seconds = max(1, min(fast_loop_interval, 10))

        mqtt_config = self.controller.config.get("mqttPowerMeter")
        if isinstance(mqtt_config, dict):
            try:
                stale_after = int(mqtt_config.get("staleAfterSeconds", MQTT_STALE_AFTER_SECONDS))
            except (TypeError, ValueError):
                stale_after = MQTT_STALE_AFTER_SECONDS
            try:
                periodic_interval = int(
                    mqtt_config.get(
                        "periodicControlIntervalSeconds",
                        MQTT_PERIODIC_CONTROL_INTERVAL_SECONDS,
                    )
                )
            except (TypeError, ValueError):
                periodic_interval = MQTT_PERIODIC_CONTROL_INTERVAL_SECONDS
            self.mqtt_stale_after_seconds = max(5, stale_after)
            self.periodic_control_interval_seconds = max(5, periodic_interval)

    def _signal_handler(self, signum, frame=None):
        """Handle shutdown signals; force-exit on repeated Ctrl-C."""
        try:
            signal_name = signal.Signals(signum).name
        except (ValueError, AttributeError):
            signal_name = str(signum)
        now = time.time()
        self._shutdown_signal_count += 1
        if self._first_shutdown_signal_at is None:
            self._first_shutdown_signal_at = now

        # First signal: graceful shutdown request.
        if not self.shutdown_requested:
            self.logger.warning(f"Received {signal_name} signal, initiating graceful shutdown...")
            self.shutdown_requested = True
            return

        # If a second signal arrives during shutdown (or many in a short window),
        # exit immediately so a blocked network/device call cannot hang the process.
        if self._shutdown_signal_count >= 2:
            self.logger.error(f"Received {signal_name} again, forcing immediate exit...")
            os._exit(130)

    def _on_status_update(self, event_type: str, old_value: Any, new_value: Any,
                          p1_total_power: Optional[int], timestamp: int):
        """Callback when status is posted; updates api_state.last_status and last_status_by_type."""
        self.api_state.last_status = StatusChange(
            event_type=event_type,
            old_value=old_value,
            new_value=new_value,
            timestamp=timestamp,
        )
        self.api_state.last_status_by_type[event_type] = AutomationStatusEntry(
            event_type=event_type,
            old_value=old_value,
            new_value=new_value,
            p1_total_power=p1_total_power,
            timestamp=timestamp,
        )

    def _start_http_server(self):
        """Start HTTP API server in a daemon thread."""
        try:
            token_cfg = self.schedule_controller.config.get("statusUpdatesDeltaApiToken", "")
            env_token = os.getenv("STATUS_UPDATES_DELTA_API_TOKEN", "")
            token = str(token_cfg).strip() if token_cfg is not None else ""
            if not token and env_token is not None:
                token = str(env_token).strip()
            self.http_server = create_http_server(
                api_state=self.api_state,
                db_path=self.status_api.db_path,
                schedule_controller=self.schedule_controller,
                status_api=self.status_api,
                refresh_p1_callback=self._refresh_p1_for_api,
                refresh_zendure_callback=self._refresh_zendure_for_api,
                restart_callback=self.request_restart,
                pause_getter=lambda: self.pause_override_active,
                pause_setter=self._set_pause_override,
                controller=self.controller,
                status_updates_delta_token=token or None,
                port=HTTP_API_PORT,
                log_level_priorities=BaseDeviceController._LOG_LEVEL_PRIORITY,
            )
            self.status_api.http_server = self.http_server
            self.http_server_thread = threading.Thread(target=self.http_server.serve_forever, daemon=True)
            self.http_server_thread.start()
            self.logger.info(f"HTTP API listening on port {HTTP_API_PORT}")
        except OSError as e:
            self.logger.error(f"Failed to start HTTP API server: {e}")

    def request_restart(self):
        """Request graceful shutdown and in-process restart."""
        if self.restart_requested:
            return
        self.restart_requested = True
        self.shutdown_requested = True
        if self.logger:
            self.logger.info("Restart requested via API; shutting down for restart")

    # ------------------------------------------------------------------------
    # MAIN LOGIC HELPERS
    # ------------------------------------------------------------------------

    def _accumulate_p1_data(self) -> Optional[dict]:
        """Read the configured power meter."""
        try:
            power_meter_reader = get_power_meter_reader(self.controller.config_path)
            self._last_p1_read_source = "http"
            return power_meter_reader.read()
        except Exception as e:
            self.logger.warning(f"Failed to read power meter: {e}")
            return None

    def _get_mqtt_p1_data(self) -> Optional[dict]:
        """Return the latest fresh MQTT reading if available."""
        if self.mqtt_helper is None or not self.mqtt_helper.is_enabled():
            return None
        if self.mqtt_helper.is_stale(self.mqtt_stale_after_seconds):
            return None
        reading = self.mqtt_helper.get_latest_reading()
        if reading is not None:
            self._last_p1_read_source = "mqtt"
        return reading

    def _format_mqtt_status_line(self, snapshot: dict[str, Any]) -> str:
        """Render a compact periodic MQTT health line for operators."""
        connected = bool(snapshot.get("connected"))
        stale = bool(snapshot.get("stale"))
        if not connected:
            state = "down"
        elif stale:
            state = "stale"
        else:
            state = "ok"

        age_seconds = snapshot.get("age_seconds")
        age_text = "never" if age_seconds is None else f"{age_seconds:.1f}s"
        power_text = snapshot.get("total_power")
        power_display = "?" if power_text is None else f"{power_text}W"
        message_count = snapshot.get("message_count")
        message_display = 0 if message_count is None else message_count
        return f"MQTT: {state} age={age_text} p={power_display} n={message_display}"

    def _log_mqtt_diagnostics_if_needed(self) -> None:
        """Log MQTT receive activity and a periodic health summary."""
        if self.mqtt_helper is None or not self.mqtt_helper.is_enabled() or self.logger is None:
            return

        snapshot = self.mqtt_helper.get_status_snapshot(self.mqtt_stale_after_seconds)
        last_message_ts = snapshot.get("last_message_timestamp")
        if (
            last_message_ts is not None
            and self._last_mqtt_debug_message_ts != last_message_ts
        ):
            self._last_mqtt_debug_message_ts = last_message_ts
            age_seconds = snapshot.get("age_seconds")
            age_text = "n/a" if age_seconds is None else f"{age_seconds:.1f}s"
            self.logger.debug(
                "MQTT message received: "
                f"topic={snapshot.get('topic')} "
                f"power={snapshot.get('total_power')}W "
                f"age={age_text} "
                f"count={snapshot.get('message_count')}",
                message_key="mqtt_message_received",
            )

        now = time.time()
        if (now - self._last_mqtt_status_log_ts) < 60:
            return
        self._last_mqtt_status_log_ts = now

        status_level = (
            self.logger.warning
            if (not snapshot.get("connected") or snapshot.get("stale"))
            else self.logger.info
        )
        status_level(self._format_mqtt_status_line(snapshot))

    def _refresh_p1_for_api(self) -> None:
        """Read P1 meter and update api_state.last_p1 (for on-demand refresh from /api/p1)."""
        p1_data = self._get_mqtt_p1_data()
        if p1_data is None:
            p1_data = self._accumulate_p1_data()
        if p1_data is not None:
            self._update_p1_state(p1_data)

    def _apply_dynamic_power_command(self, mode: str) -> tuple[bool, Optional[int], Optional[str]]:
        p1_data = self._get_mqtt_p1_data()
        if p1_data is None:
            p1_data = self._accumulate_p1_data()
        if p1_data is None:
            return False, None, "Failed to read P1 meter data"

        self._update_p1_state(p1_data)
        result = self.controller.set_power(
            mode,
            p1_data=p1_data,
            p1_source=self._last_p1_read_source,
        )
        if not result.success:
            return False, None, result.error
        return True, result.power, None

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
                self._post_status_update(EVENT_TYPE_RESCAN, None, None, p1_total_power=self.last_p1_total_power)
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
                self.logger.debug("Schedule value is None, setting desired power to 0")
                return 0
            return desired_power
        except Exception as e:
            self.logger.error(f"Error getting desired power from schedule: {e}")
            try:
                self.controller.accumulator.last_schedule_entry = None
            except Exception:
                pass
            return 0

    def _set_pause_override(self, active: bool) -> None:
        active = bool(active)
        if active == self.pause_override_active:
            self.logger.info(f"Pause override already {'ON' if active else 'OFF'}.")
            return
        if active:
            result = self.controller.set_power(0)
            if not result.success:
                raise RuntimeError(f"Failed to set power to 0 before enabling pause: {result.error}")

            self.pause_override_active = True
            self.value = 0
            self.old_value = 0
            p1_w = self.last_p1_total_power
            self._post_status_update(EVENT_TYPE_CHANGE, None, 0, p1_total_power=p1_w)
            self.logger.info("Pause override enabled after setting power to 0.")
        else:
            self.pause_override_active = False
            self.logger.info("Pause override disabled: schedule control resumed.")

    def _warn_runtime_condition_once(self, key: str, message: str) -> None:
        if key in self._runtime_condition_warning_cache:
            return
        self._runtime_condition_warning_cache.add(key)
        self.logger.warning(message)

    def _get_current_electricity_level(self) -> Optional[int]:
        try:
            reader = get_reader(self.controller.config_path)
            zendure_data = getattr(reader, "last_zendure_data", None)
            if not isinstance(zendure_data, dict):
                return None
            properties = zendure_data.get("properties") or {}
            if not isinstance(properties, dict):
                return None
            raw_level = properties.get("electricLevel")
            if raw_level is None:
                return None
            return int(raw_level)
        except Exception:
            return None

    def _read_zendure_snapshot(self) -> Optional[dict]:
        """Read Zendure once for the current loop iteration and refresh the shared cache."""
        try:
            reader = get_reader(self.controller.config_path)
            zendure_data = reader.read_zendure(update_json=True)
            if isinstance(zendure_data, dict):
                self.controller.accumulator.last_zendure_data = zendure_data
            return zendure_data
        except Exception as e:
            self.logger.warning(f"Failed to read Zendure device data: {e}")
            return None

    def _compare_runtime_condition(self, left: float, op: str, right: float) -> Optional[bool]:
        if op == '>':
            return left > right
        if op == '>=':
            return left >= right
        if op == '<':
            return left < right
        if op == '<=':
            return left <= right
        if op == '==':
            return left == right
        if op == '!=':
            return left != right
        return None

    def _normalize_fallback_value(self, fallback_value: Any) -> Optional[Any]:
        if fallback_value is None:
            return None
        if isinstance(fallback_value, str):
            normalized = fallback_value.strip().lower()
            if normalized in (POWER_MODE_NETZERO, POWER_MODE_NETZERO_PLUS):
                return normalized
            if normalized.lstrip('-').isdigit():
                return int(normalized)
            return None
        if isinstance(fallback_value, (int, float)) and not isinstance(fallback_value, bool):
            return int(fallback_value)
        return None

    def _format_runtime_fallback_log(
        self,
        slot_time: Any,
        electricity_level: Any,
        desired_power: Any,
        fallback_value: Any,
        min_power: Any = None,
        max_power: Any = None,
    ) -> str:
        parts = [
            f"Runtime: slot={slot_time} fallback",
            f"lvl={electricity_level}",
        ]
        if min_power is not None:
            parts.append(f"min={min_power}")
        if max_power is not None:
            parts.append(f"max={max_power}")
        parts.append(f"base={desired_power} -> fb={fallback_value}")
        return " ".join(parts)

    def _apply_runtime_conditions(self, desired_power: Any) -> Any:
        schedule_entry = getattr(self.schedule_controller, "last_schedule_entry", None)
        if not isinstance(schedule_entry, dict):
            self._last_runtime_decision_signature = None
            return desired_power

        runtime_conditions = schedule_entry.get("runtime_conditions")
        if not isinstance(runtime_conditions, list) or len(runtime_conditions) == 0:
            self._last_runtime_decision_signature = None
            return desired_power

        electricity_level = self._get_current_electricity_level()
        slot_time = schedule_entry.get("time")
        if electricity_level is None:
            self._warn_runtime_condition_once(
                f"runtime-missing-level:{slot_time}",
                f"Runtime conditions present for slot {slot_time}, but electricLevel is unavailable; keeping base value"
            )
            self._last_runtime_decision_signature = None
            return desired_power

        valid_conditions = 0
        all_valid_conditions_match = True

        for idx, condition in enumerate(runtime_conditions):
            condition_key = f"slot={slot_time},idx={idx}"
            if not isinstance(condition, dict):
                self._warn_runtime_condition_once(
                    f"runtime-invalid-shape:{condition_key}",
                    f"Invalid runtime condition ({condition_key}): expected object, got {type(condition).__name__}; skipping"
                )
                continue

            field = str(condition.get("field", "")).strip()
            op = str(condition.get("op", "")).strip()
            value = condition.get("value")

            if field in ("electric_level", "electricLevel"):
                field = "electricity_level"

            if field != "electricity_level":
                self._warn_runtime_condition_once(
                    f"runtime-invalid-field:{condition_key}:{field}",
                    f"Unsupported runtime field '{field}' ({condition_key}); skipping"
                )
                continue

            try:
                right = float(value)
            except (TypeError, ValueError):
                self._warn_runtime_condition_once(
                    f"runtime-invalid-value:{condition_key}",
                    f"Invalid runtime condition value for field '{field}' ({condition_key}); skipping"
                )
                continue

            match = self._compare_runtime_condition(float(electricity_level), op, right)
            if match is None:
                self._warn_runtime_condition_once(
                    f"runtime-invalid-op:{condition_key}:{op}",
                    f"Unsupported runtime operator '{op}' ({condition_key}); skipping"
                )
                continue

            valid_conditions += 1
            if not match:
                all_valid_conditions_match = False

        if valid_conditions == 0:
            signature = f"{slot_time}|{desired_power}|no-valid|{electricity_level}"
            if self._last_runtime_decision_signature != signature:
                self.logger.debug(
                    f"Runtime conditions present for slot {slot_time}, but none are valid; keeping base value {desired_power}"
                )
                self._last_runtime_decision_signature = signature
            return desired_power

        if all_valid_conditions_match:
            signature = f"{slot_time}|{desired_power}|matched|{electricity_level}"
            if self._last_runtime_decision_signature != signature:
                self.logger.debug(
                    f"Runtime conditions matched for slot {slot_time} (electricity_level={electricity_level}); using base value {desired_power}"
                )
                self._last_runtime_decision_signature = signature
            return desired_power

        fallback_value = self._normalize_fallback_value(schedule_entry.get("fallback_value"))
        if fallback_value is None:
            fallback_value = 0
            signature = f"{slot_time}|{desired_power}|fallback:{fallback_value}|{electricity_level}"
            if self._last_runtime_decision_signature != signature:
                self._warn_runtime_condition_once(
                    f"runtime-missing-fallback:{slot_time}",
                    f"Runtime conditions false for slot {slot_time}, but fallback_value is missing/invalid; using default fallback 0"
                )
                self._last_runtime_decision_signature = signature
            return fallback_value

        min_power = schedule_entry.get("min_power")
        max_power = schedule_entry.get("max_power")

        signature = f"{slot_time}|{desired_power}|fallback:{fallback_value}|{electricity_level}"
        if self._last_runtime_decision_signature != signature:
            self.logger.info(
                self._format_runtime_fallback_log(
                    slot_time=slot_time,
                    electricity_level=electricity_level,
                    desired_power=desired_power,
                    fallback_value=fallback_value,
                    min_power=min_power,
                    max_power=max_power,
                )
            )
            self._last_runtime_decision_signature = signature
        return fallback_value

    def _check_battery_limits(self, desired_power: any, prechecked: bool = False) -> any:
        """Check availability and modify desired power if limited."""
        if not prechecked:
            self.logger.warning("Battery level check not pre-checked; invoking battery limit check now.", message_key="battery_limit_not_prechecked")
            self.controller.check_battery_limits()

        validation_power = desired_power
        if desired_power == POWER_MODE_NETZERO:
            validation_power = POWER_MODE_NETZERO_VALIDATION_W
        elif desired_power == POWER_MODE_NETZERO_PLUS:
            validation_power = POWER_MODE_NETZERO_PLUS_VALIDATION_W

        if isinstance(validation_power, int):
            if validation_power > 0 and self.controller.limit_state == 1:
                self.logger.warning(
                    "Battery at MAX_CHARGE_LEVEL, preventing charge",
                    message_key="battery_max_charge_block",
                )
                return 0
            if validation_power < 0 and self.controller.limit_state == -1:
                self.logger.warning(
                    "Battery at MIN_CHARGE_LEVEL, preventing discharge",
                    message_key="battery_min_discharge_block",
                )
                return 0

        return desired_power

    def _apply_power_settings(
        self,
        desired_power: any,
        p1_data: Optional[dict],
        zendure_data: Optional[dict] = None,
    ):
        """Apply the power settings if changed."""
        should_apply = (
            self.old_value != desired_power
            or (desired_power in [POWER_MODE_NETZERO, POWER_MODE_NETZERO_PLUS])
        )
        if not should_apply:
            self.value = desired_power
            return

        schedule_entry = getattr(self.schedule_controller, "last_schedule_entry", None)
        result = self.controller.set_power(
            desired_power,
            p1_data=p1_data,
            schedule_entry=schedule_entry,
            zendure_data=zendure_data,
            p1_source=self._last_p1_read_source,
        )

        previous_fast_loop_active = self.fast_loop_active
        self.fast_loop_active = bool(
            result.max_delta_limited or result.reversal_ramp_active
        )
        if self.fast_loop_active and not previous_fast_loop_active:
            self.logger.debug(
                "Adaptive fast loop enabled: "
                f"max_delta_limited={result.max_delta_limited}, "
                f"reversal_ramp_active={result.reversal_ramp_active}",
                message_key="adaptive_fast_loop_enabled",
            )
        elif not self.fast_loop_active and previous_fast_loop_active:
            self.logger.debug(
                "Adaptive fast loop cleared; restoring normal loop interval",
                message_key="adaptive_fast_loop_cleared",
            )

        if not result.success:
            self.logger.error(f"Failed to set power: {result.error}")
            return

        power_log_message = f"Power: {result.power} (desired: {desired_power})"
        if result.power != self.old_value:
            self.logger.info(power_log_message)
        else:
            self.logger.debug(power_log_message)

        p1_w = None

        if p1_data is not None and p1_data.get('total_power') is not None:
            try:
                p1_w = int(p1_data['total_power'])
            except (TypeError, ValueError):
                pass

        # Avoid refreshing the "latest change" timestamp for dynamic modes when
        # the effective watt value did not actually change. Otherwise the main UI
        # can remain stuck in a perpetual "Pending..." state.
        if result.power != self.old_value:
            self._post_status_update(
                EVENT_TYPE_CHANGE,
                self.old_value,
                result.power,
                p1_total_power=p1_w,
            )

        # Update self.value with the actual power that was set (result.power)
        # This is important for netzero modes where calculated power may differ from 'netzero'
        self.value = result.power


    def _handle_standby_check(self):
        """Check if we need to enter standby mode."""
        if self.value == 0:
            if self.zero_power_since is None:
                self.zero_power_since = time.time()
                return

            if self.standby_sent:
                return

            zero_duration = time.time() - self.zero_power_since
            if zero_duration >= STANDBY_DELAY_SECONDS:
                self.logger.info(
                    f"0 power for {int(zero_duration)} seconds, setting device in standby mode"
                )
                result = self.controller.set_standby_mode()
                if getattr(result, "success", False):
                    self.standby_sent = True
                    self.zero_power_since = None
        else:
            self.zero_power_since = None
            self.standby_sent = False

    def _sleep_interrupted(self):
        """Sleep with interrupt for shutdown/MQTT wake events."""
        active_sleep_seconds = (
            self.fast_loop_interval_seconds if self.fast_loop_active else self.loop_interval_seconds
        )
        if self.fast_loop_active:
            self.logger.debug(
                "Fast loop mode active: "
                f"sleeping {self.fast_loop_interval_seconds} seconds instead of {self.loop_interval_seconds} seconds",
                message_key="fast_loop_mode_active",
            )
        sleep_remaining = active_sleep_seconds
        while sleep_remaining > 0 and not self.shutdown_requested:
            # Skip sleep if it's the first second of the minute
            now = time.localtime().tm_sec
            if now in (self.steps) and sleep_remaining < active_sleep_seconds:
                return

            if not self.shutdown_requested:
                wait_seconds = min(1, sleep_remaining)
                mqtt_enabled = self.mqtt_helper is not None and self.mqtt_helper.is_enabled()
                if mqtt_enabled and self.mqtt_helper.wait_for_wake(wait_seconds):
                    if self.mqtt_helper.consume_wake_event():
                        self.logger.debug(
                            "MQTT wake event received; interrupting sleep early",
                            message_key="mqtt_wake_event",
                        )
                        return
                else:
                    time.sleep(wait_seconds) if not mqtt_enabled else None
            sleep_remaining -= 1

    def _shutdown(self):
        """Perform graceful shutdown."""
        start_time = time.time()

        if self.mqtt_helper is not None:
            self.mqtt_helper.stop()

        # Shutdown HTTP API server
        if self.http_server:
            try:
                self.http_server.shutdown()
                self.http_server.server_close()
            except Exception:
                pass
            if self.http_server_thread:
                try:
                    self.http_server_thread.join(timeout=1.5)
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
                self.logger.info("   Power set to 0")
            else:
                self.logger.error(f"   Failed to set power to 0: {result.error}")

            # Post 'stop' in a thread with short join so shutdown never blocks (e.g. slow/hanging HTTP on Mac)
            if self.status_api and not self.stop_posted:
                self.stop_posted = True
                def _post_stop():
                    try:
                        self._post_status_update(EVENT_TYPE_STOP, self.value, None, p1_total_power=self.last_p1_total_power)
                    except Exception:
                        pass
                t = threading.Thread(target=_post_stop, daemon=True)
                t.start()
                t.join(timeout=1.5)
        force_exit = False
        if self.http_server_thread and self.http_server_thread.is_alive():
            force_exit = True
        if (time.time() - start_time) > SHUTDOWN_FORCE_EXIT_SECONDS:
            force_exit = True
        if force_exit:
            # Force immediate process exit (nothing can block or catch this; avoids hang on Mac)
            os._exit(0)

    def _log_startup(self) -> None:
        self.logger.info("🚀 Starting charge schedule automation script")
        self.logger.info(f"ℹ  LOG LEVEL: {getattr(self.controller, 'log_level', 'INFO')}")
        # Show config path and charge level limits derived from config.jsonc
        self.logger.info(
            f"   Config file: {os.path.abspath(str(self.controller.config_path))}"
        )
        self.logger.info(
            f"   Battery SoC limits: MIN_CHARGE_LEVEL={self.controller.min_charge_level}%, "
            f"MAX_CHARGE_LEVEL={self.controller.max_charge_level}%"
        )
        self.logger.info(
            f"   Power caps: MAX_DISCHARGE_POWER={self.controller.max_discharge_power} W, "
            f"MAX_CHARGE_POWER={self.controller.max_charge_power} W"
        )
        if (
            getattr(self.controller, "slow_charge_start_level", None) is not None
            and getattr(self.controller, "slow_charge_max_power", None) is not None
        ):
            self.logger.info(
                "   Dynamic slow-charge taper: "
                f"SLOW_CHARGE_START_LEVEL={self.controller.slow_charge_start_level}%, "
                f"SLOW_CHARGE_MAX_POWER={self.controller.slow_charge_max_power} W"
            )
        else:
            self.logger.info("   Dynamic slow-charge taper: disabled")
        # Show test mode prominently on startup (controlled via config.jsonc key: TEST_MODE).
        if getattr(self.controller, "test_mode", False):
            self.logger.warning(
                "TEST MODE: ON (no commands wil be send to the Zendure device)"
            )
        else:
            self.logger.info("TEST MODE: OFF")
        self.logger.info(f"   Loop interval: {self.loop_interval_seconds} seconds")
        self.logger.info(
            "   Fast loop interval when power_feed_max_delta or reversal ramp is active: "
            f"{self.fast_loop_interval_seconds} seconds"
        )
        self.logger.info(
            "   API refresh interval: "
            f"{self.api_refresh_interval_seconds} seconds "
            f"({self.api_refresh_interval_seconds // 60} minutes)"
        )
        if self.mqtt_helper is not None and self.mqtt_helper.is_enabled():
            self.logger.info(
                "   MQTT power meter: enabled "
                f"(stale after {self.mqtt_stale_after_seconds}s, "
                f"periodic control every {self.periodic_control_interval_seconds}s)"
            )
        else:
            self.logger.info("   MQTT power meter: disabled; using HTTP-only power meter reads")
        self.logger.info("   Runtime controls are available via the HTTP API/control page")
        print()

    def _update_p1_state(self, p1_data: Optional[dict]) -> None:
        if p1_data is None:
            return
        self.api_state.last_p1 = P1Readings(
            readings=p1_data,
            timestamp=int(time.time()),
        )
        self._update_energy_counter_state(p1_data)
        total_power = p1_data.get("total_power")
        if total_power is None:
            return
        try:
            self.last_p1_total_power = int(total_power)
        except (TypeError, ValueError):
            pass

    @staticmethod
    def _parse_optional_float(raw: Any) -> Optional[float]:
        if raw is None:
            return None
        try:
            return float(raw)
        except (TypeError, ValueError):
            return None

    def _update_energy_counter_state(self, p1_data: Optional[dict]) -> None:
        if isinstance(p1_data, dict):
            total_act = self._parse_optional_float(p1_data.get("total_act"))
            if total_act is not None:
                self.last_total_act = total_act
            total_act_ret = self._parse_optional_float(p1_data.get("total_act_ret"))
            if total_act_ret is not None:
                self.last_total_act_ret = total_act_ret
        if self.mqtt_helper is not None:
            mqtt_total_act = self.mqtt_helper.get_latest_total_act()
            if mqtt_total_act is not None:
                self.last_total_act = mqtt_total_act
            mqtt_total_act_ret = self.mqtt_helper.get_latest_total_act_ret()
            if mqtt_total_act_ret is not None:
                self.last_total_act_ret = mqtt_total_act_ret

    def _post_status_update(
        self,
        event_type: str,
        old_value: Any = None,
        new_value: Any = None,
        p1_total_power: Optional[int] = None,
    ) -> bool:
        self._update_energy_counter_state(None)
        return self.status_api.post_update(
            event_type,
            old_value,
            new_value,
            p1_total_power=p1_total_power,
            total_act=self.last_total_act,
            total_act_ret=self.last_total_act_ret,
        )

    def _update_zendure_state(self) -> None:
        zendure_data = getattr(
            get_reader(self.controller.config_path), "last_zendure_data", None
        )
        if zendure_data is None:
            return
        self.api_state.last_zendure = ZendureReadings(
            readings=zendure_data,
            timestamp=int(time.time()),
        )

    def _run_full_control_pipeline(self, p1_data: Optional[dict]) -> bool:
        """Run the full control flow using the provided P1 reading."""
        self._update_p1_state(p1_data)
        self._refresh_schedule_if_needed() # API call to the schedule API to get the current schedule entry
        self.old_value = self.value
        if self.pause_override_active:
            desired_power = 0
        else:
            desired_power = self._calculate_desired_power()

        # 4. Battery limits + runtime conditions + state updates
        zendure_data = None
        zendure_data = self._read_zendure_snapshot() # reads Zendure API once for this loop iteration

        if not self.pause_override_active:
            self.controller.check_battery_limits(zendure_data=zendure_data) # updates limit_state from the iteration snapshot
            desired_power = self._apply_runtime_conditions(desired_power) # Checks if desired power is withing battery dynamic limits (uses cached Zendure data)
            desired_power = self._check_battery_limits(desired_power, prechecked=True) # uses limit_state property to prevent charging a full battery or discharging an empty one
        self._update_zendure_state() # stores the last Zendure data in self.api_state.last_zendure, this lead to GUI data

        # 5. Apply settings
        self._apply_power_settings(desired_power, p1_data, zendure_data=zendure_data) # translates the desired power into a (dedubbed) command to the Zendure device and sends it to the device

        # 6. Standby check
        self._handle_standby_check()
        self.last_full_control_run_ts = time.time()
        return True

    def _should_run_periodic_control(self) -> bool:
        if self.last_full_control_run_ts <= 0:
            return True
        return (time.time() - self.last_full_control_run_ts) >= self.periodic_control_interval_seconds

    def _run_cycle(self) -> bool:
        # 0. Sleep
        self._sleep_interrupted()
        if self.shutdown_requested:
            return False

        self._log_mqtt_diagnostics_if_needed()

        mqtt_enabled = self.mqtt_helper is not None and self.mqtt_helper.is_enabled()
        mqtt_changed = bool(mqtt_enabled and self.mqtt_helper.consume_power_change_event())
        mqtt_fresh = bool(mqtt_enabled and not self.mqtt_helper.is_stale(self.mqtt_stale_after_seconds))
        periodic_due = self._should_run_periodic_control()

        if mqtt_enabled and mqtt_fresh and not mqtt_changed and not periodic_due:
            snapshot = self.mqtt_helper.get_status_snapshot(self.mqtt_stale_after_seconds)
            delta_watts = snapshot.get("last_delta_watts")
            last_triggered_change = bool(snapshot.get("last_triggered_change"))
            if delta_watts is not None and not last_triggered_change:
                self.logger.debug(
                    "MQTT power delta below threshold; skipping control run: "
                    f"delta={delta_watts}W threshold={snapshot.get('change_threshold_watts')}W "
                    f"power={snapshot.get('total_power')}W",
                    message_key="mqtt_delta_below_threshold",
                )

        if mqtt_changed and mqtt_fresh:
            p1_data = self._get_mqtt_p1_data()
            if p1_data is not None:
                return self._run_full_control_pipeline(p1_data)

        if not mqtt_fresh:
            p1_data = self._accumulate_p1_data()
            return self._run_full_control_pipeline(p1_data)

        if periodic_due:
            p1_data = self._get_mqtt_p1_data()
            if p1_data is None:
                p1_data = self._accumulate_p1_data()
            return self._run_full_control_pipeline(p1_data)

        return True

    def _maybe_run_retention_cleanup(self, now_ts: Optional[int] = None) -> None:
        if self.status_api is None:
            return
        if self.loop_counter <= 0 or (self.loop_counter % RETENTION_CLEANUP_LOOP_INTERVAL) != 0:
            return

        current_ts = int(time.time()) if now_ts is None else int(now_ts)
        if (
            self.last_retention_cleanup_ts is not None
            and (current_ts - self.last_retention_cleanup_ts) < RETENTION_CLEANUP_INTERVAL_SECONDS
        ):
            return

        if self.status_api.cleanup_old_rows(current_ts):
            self.last_retention_cleanup_ts = current_ts

    def run(self) -> int:
        """Main execution method."""
        if not self.initialize():
            return 1

        self._update_energy_counter_state(self._get_mqtt_p1_data())
        self._post_status_update(EVENT_TYPE_START)
        self._log_startup()

        # Start HTTP API server
        self._start_http_server()

        try:
            while not self.shutdown_requested:
                self.loop_counter += 1
                if not self._run_cycle():
                    break
                self._maybe_run_retention_cleanup()
        except KeyboardInterrupt:
            self.shutdown_requested = True
        except Exception as e:
            self.logger.error(f"Fatal error in main loop: {e}")
            if self.status_api:
                self.stop_posted = True
                self._post_status_update(EVENT_TYPE_STOP, self.value, None, p1_total_power=self.last_p1_total_power)
            return 1
        finally:
            self._shutdown()
        return RESTART_EXIT_CODE if self.restart_requested else 0


def main():
    while True:
        app = AutomationApp()
        exit_code = app.run()
        if exit_code == RESTART_EXIT_CODE:
            continue
        return exit_code


if __name__ == "__main__":
    sys.exit(main())
