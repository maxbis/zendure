#!/usr/bin/env python3
"""
HTTP API server for automate_www.
"""

from __future__ import annotations

import http.server
import json
import os
import socketserver
import threading
import time
from typing import Any, Callable, Optional
from urllib.parse import parse_qs, urlparse

HTTP_API_PORT = 1611
WH_PER_HOUR_DAYS_DEFAULT = 3
WH_PER_HOUR_DAYS_MAX = 30
WH_PER_HOUR_CACHE_SECONDS = 60

EVENT_TYPE_CHANGE = "change"
EVENT_TYPE_RESCAN = "Rescan"

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
API_PATH_SLOW_CHARGE_MAX_POWER = "/api/slow_charge_max_power"
API_PATH_NETZERO_TARGET_W = "/api/netzero_target_w"
API_PATH_MIN_CHARGE_LEVEL = "/api/min_charge_level"
API_PATH_MAX_CHARGE_LEVEL = "/api/max_charge_level"

TEST_ENDPOINTS = [
    {"path": API_PATH_TEST, "optional_params": []},
    {"path": API_PATH_P1, "optional_params": [{"name": "max_age", "alt": "maxAge", "type": "int", "default": 60, "description": "0 = always refresh; N = refresh if data older than N seconds (default 60)"}]},
    {"path": API_PATH_ZENDURE, "optional_params": [{"name": "max_age", "alt": "maxAge", "type": "int", "default": 60, "description": "0 = always refresh; N = refresh if data older than N seconds (default 60)"}]},
    {"path": API_PATH_STATUS, "optional_params": []},
    {"path": API_PATH_ALL, "optional_params": []},
    {"path": API_PATH_AUTOMATION_STATUS, "optional_params": []},
    {"path": API_PATH_WH_PER_HOUR, "optional_params": [{"name": "days", "type": "int", "default": WH_PER_HOUR_DAYS_DEFAULT, "max": WH_PER_HOUR_DAYS_MAX, "description": "Optional history window in days including today; values above max are clamped"}]},
    {"path": API_PATH_STATUS_UPDATES_DELTA, "optional_params": [{"name": "after_id", "type": "int", "required": True, "description": "Return rows where id > after_id"}, {"name": "limit", "type": "int", "default": 500, "max": 2000, "description": "Maximum rows per page"}, {"name": "token", "type": "string", "required": False, "description": "Optional auth token when server token protection is enabled"}]},
    {"path": API_PATH_REFRESH, "optional_params": []},
    {"path": API_PATH_RESTART, "optional_params": []},
    {"path": API_PATH_PAUSE, "optional_params": [{"name": "state", "type": "string", "allowed": ["on", "off", "true", "false", "1", "0"], "description": "POST only: set pause override state"}]},
    {"path": API_PATH_LOG_LEVEL, "optional_params": [{"name": "level", "alt": "loglevel|log_level", "type": "string", "allowed": ["DEBUG", "INFO", "WARNING", "ERROR"], "description": "POST only: set runtime log level"}]},
    {"path": API_PATH_SLOW_CHARGE_MAX_POWER, "optional_params": [{"name": "value", "type": "int", "min": 0, "description": "POST only: set runtime slow-charge max power; must be between 0 and MAX_CHARGE_POWER"}]},
    {"path": API_PATH_NETZERO_TARGET_W, "optional_params": [{"name": "value", "type": "int", "description": "POST only: set runtime NETZERO_TARGET_W override as a signed integer"}]},
    {"path": API_PATH_MIN_CHARGE_LEVEL, "optional_params": [{"name": "value", "type": "int", "description": "POST only: set runtime MIN_CHARGE_LEVEL override as integer percent"}]},
    {"path": API_PATH_MAX_CHARGE_LEVEL, "optional_params": [{"name": "value", "type": "int", "description": "POST only: set runtime MAX_CHARGE_LEVEL override as integer percent"}]},
]

CONTROL_COMMANDS = [
    {"path": API_PATH_PAUSE, "name": "status", "method": "GET", "description": "Get current pause override state", "example": f"{API_PATH_PAUSE}"},
    {"path": API_PATH_PAUSE, "name": "pause_on", "method": "POST", "description": "Enable pause override (forces desired power to 0)", "example": f"{API_PATH_PAUSE}?state=on"},
    {"path": API_PATH_PAUSE, "name": "pause_off", "method": "POST", "description": "Disable pause override (resume schedule control)", "example": f"{API_PATH_PAUSE}?state=off"},
    {"path": API_PATH_RESTART, "name": "restart", "method": "POST", "description": "Request graceful restart of automation process", "example": f"{API_PATH_RESTART}"},
    {"path": API_PATH_REFRESH, "name": "refresh_schedule", "method": "GET", "description": "Force schedule refresh from API", "example": f"{API_PATH_REFRESH}"},
    {"path": API_PATH_LOG_LEVEL, "name": "log_level_status", "method": "GET", "description": "Get current runtime log level", "example": f"{API_PATH_LOG_LEVEL}"},
    {"path": API_PATH_LOG_LEVEL, "name": "log_level_set", "method": "POST", "description": "Set runtime log level (DEBUG|INFO|WARNING|ERROR)", "example": f"{API_PATH_LOG_LEVEL}?level=info"},
    {"path": API_PATH_SLOW_CHARGE_MAX_POWER, "name": "slow_charge_max_power_status", "method": "GET", "description": "Get current runtime slow-charge max power override", "example": f"{API_PATH_SLOW_CHARGE_MAX_POWER}"},
    {"path": API_PATH_SLOW_CHARGE_MAX_POWER, "name": "slow_charge_max_power_set", "method": "POST", "description": "Set runtime slow-charge max power override", "example": f"{API_PATH_SLOW_CHARGE_MAX_POWER}?value=300"},
    {"path": API_PATH_NETZERO_TARGET_W, "name": "netzero_target_w_status", "method": "GET", "description": "Get current runtime NETZERO_TARGET_W override", "example": f"{API_PATH_NETZERO_TARGET_W}"},
    {"path": API_PATH_NETZERO_TARGET_W, "name": "netzero_target_w_set", "method": "POST", "description": "Set runtime NETZERO_TARGET_W override", "example": f"{API_PATH_NETZERO_TARGET_W}?value=-50"},
    {"path": API_PATH_MIN_CHARGE_LEVEL, "name": "min_charge_level_status", "method": "GET", "description": "Get current runtime MIN_CHARGE_LEVEL override", "example": f"{API_PATH_MIN_CHARGE_LEVEL}"},
    {"path": API_PATH_MIN_CHARGE_LEVEL, "name": "min_charge_level_set", "method": "POST", "description": "Set runtime MIN_CHARGE_LEVEL override", "example": f"{API_PATH_MIN_CHARGE_LEVEL}?value=15"},
    {"path": API_PATH_MAX_CHARGE_LEVEL, "name": "max_charge_level_status", "method": "GET", "description": "Get current runtime MAX_CHARGE_LEVEL override", "example": f"{API_PATH_MAX_CHARGE_LEVEL}"},
    {"path": API_PATH_MAX_CHARGE_LEVEL, "name": "max_charge_level_set", "method": "POST", "description": "Set runtime MAX_CHARGE_LEVEL override", "example": f"{API_PATH_MAX_CHARGE_LEVEL}?value=93"},
]


class ApiState:
    """Shared state for API endpoints: latest P1, Zendure, and status readings."""

    def __init__(self):
        self.last_p1: Optional[Any] = None
        self.last_zendure: Optional[Any] = None
        self.last_status: Optional[Any] = None
        self.last_status_by_type: dict[str, Any] = {}


def compute_automation_status_from_state(api_state: ApiState, type_filter: str, limit: int) -> dict:
    """Build Automation Status response from in-memory state."""
    if type_filter not in (EVENT_TYPE_CHANGE, "all"):
        type_filter = EVENT_TYPE_CHANGE

    limit = max(limit, 1)
    limit = min(limit, 50)

    all_entries = list(api_state.last_status_by_type.values())
    filtered = (
        all_entries
        if type_filter == "all"
        else [entry for entry in all_entries if entry.event_type == EVENT_TYPE_CHANGE]
    )

    filtered.sort(key=lambda e: (e.timestamp or 0), reverse=True)
    last_changes = [entry.to_dict() for entry in filtered[:limit]]

    timestamps = [e.timestamp for e in all_entries if e.timestamp is not None]
    last_alive = max(timestamps) if timestamps else None
    last_update = last_alive
    entry_count = len(all_entries)

    running_time = 0
    start_entry = api_state.last_status_by_type.get("start")
    stop_entry = api_state.last_status_by_type.get("stop")
    if start_entry and start_entry.timestamp is not None:
        if stop_entry and stop_entry.timestamp is not None and stop_entry.timestamp >= start_entry.timestamp:
            running_time = stop_entry.timestamp - start_entry.timestamp
        else:
            running_time = int(time.time()) - start_entry.timestamp

    return {
        "success": True,
        "method": "GET",
        "lastChanges": last_changes,
        "lastAlive": last_alive,
        "runningTime": running_time,
        "entryCount": entry_count,
        "lastUpdate": last_update,
    }


class AutomationTCPServer(socketserver.ThreadingTCPServer):
    """TCPServer that holds api_state for the request handler."""

    allow_reuse_address = True
    daemon_threads = True
    block_on_close = False

    def __init__(self, server_address, request_handler_class):
        super().__init__(server_address, request_handler_class)
        self.api_state: Optional[ApiState] = None
        self.db_path: Optional[str] = None
        self.schedule_controller: Optional[Any] = None
        self.status_api: Optional[Any] = None
        self.refresh_p1_callback: Optional[Callable[[], None]] = None
        self.refresh_zendure_callback: Optional[Callable[[], None]] = None
        self.restart_callback: Optional[Callable[[], None]] = None
        self.pause_getter: Optional[Callable[[], bool]] = None
        self.pause_setter: Optional[Callable[[bool], None]] = None
        self.wh_per_hour_cache: Optional[dict[str, Any]] = None
        self.wh_per_hour_cache_lock = threading.Lock()
        self.log_level_priorities: Optional[dict[str, Any]] = None
        self.controller: Optional[Any] = None
        self.status_updates_delta_token: Optional[str] = None


class ApiTestHandler(http.server.BaseHTTPRequestHandler):
    """Handles GET/POST automation API requests."""

    protocol_version = "HTTP/1.0"
    GET_ROUTES = (
        "_handle_api_help",
        "_handle_test",
        "_handle_wh_per_hour",
        "_handle_status_updates_delta",
        "_handle_refresh",
        "_handle_restart",
        "_handle_pause_get",
        "_handle_loglevel_get",
        "_handle_slow_charge_max_power_get",
        "_handle_netzero_target_w_get",
        "_handle_min_charge_level_get",
        "_handle_max_charge_level_get",
    )
    STATEFUL_GET_ROUTES = (
        "_handle_automation_status",
        "_handle_p1",
        "_handle_zendure",
        "_handle_status",
        "_handle_all",
    )
    POST_ROUTES = (
        "_handle_api_help",
        "_handle_restart",
        "_handle_pause_post",
        "_handle_loglevel_post",
        "_handle_slow_charge_max_power_post",
        "_handle_netzero_target_w_post",
        "_handle_min_charge_level_post",
        "_handle_max_charge_level_post",
    )

    def _send_json(self, data, status=200, sort_keys=True):
        body = json.dumps(data, sort_keys=sort_keys).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(body)
        self.close_connection = True

    def _send_error_json(self, message: str, status: int, **extra) -> None:
        payload = {"error": message}
        payload.update(extra)
        self._send_json(payload, status)

    def _dispatch(self, parsed, routes: tuple[str, ...]) -> bool:
        for route_name in routes:
            if getattr(self, route_name)(parsed):
                return True
        return False

    def _require_api_state(self) -> Optional[ApiState]:
        api_state = getattr(self.server, "api_state", None)
        if api_state is None:
            self._send_error_json("API state not initialized", 503)
            return None
        return api_state

    def _parse_max_age(self, parsed) -> int:
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

    def _parse_non_negative_int_query(self, parsed, key: str, default: Optional[int] = None) -> Optional[int]:
        query = parse_qs(parsed.query)
        if key not in query or not query[key]:
            return default
        try:
            value = int(query[key][0])
        except (ValueError, TypeError):
            return default
        if value < 0:
            return default
        return value

    def _parse_int_query(self, parsed, key: str) -> Optional[int]:
        query = parse_qs(parsed.query)
        if key not in query or not query[key]:
            return None
        try:
            return int(query[key][0])
        except (ValueError, TypeError):
            return None

    def _resolve_wh_per_hour_days(self, parsed) -> int:
        requested = self._parse_non_negative_int_query(parsed, "days", WH_PER_HOUR_DAYS_DEFAULT)
        if requested is None:
            return WH_PER_HOUR_DAYS_DEFAULT
        return min(requested, WH_PER_HOUR_DAYS_MAX)

    def _is_status_updates_delta_authorized(self, parsed) -> bool:
        required_token = getattr(self.server, "status_updates_delta_token", None)
        if not required_token:
            return True

        header_token = self.headers.get("X-API-Token")
        if header_token and header_token == required_token:
            return True

        query = parse_qs(parsed.query)
        query_token = query.get("token", [None])[0]
        if query_token and query_token == required_token:
            return True
        return False

    def _maybe_refresh_reading(self, reading, max_age: int, refresh_cb) -> None:
        now = int(time.time())
        need_refresh = max_age == 0 or reading is None or (now - (reading.timestamp or 0)) > max_age
        if need_refresh and refresh_cb is not None:
            refresh_cb()

    def _test_payload(self) -> dict:
        return {
            "path": API_PATH_TEST,
            "status": "ok",
            "message": "API is up and running",
            "endpoints": TEST_ENDPOINTS,
        }

    def _control_help_payload(self) -> dict:
        return {
            "ok": True,
            "message": "Automation control API help",
            "commands": CONTROL_COMMANDS,
            "note": "Use /api/test for full endpoint inventory.",
        }

    def _handle_api_help(self, parsed) -> bool:
        if parsed.path not in ("/", "/api"):
            return False
        self._send_json({"path": parsed.path, "ok": True, "message": "Automation API help", "endpoints": self._test_payload().get("endpoints", []), "control": self._control_help_payload().get("commands", [])}, sort_keys=False)
        return True

    def _handle_test(self, parsed) -> bool:
        if parsed.path != API_PATH_TEST:
            return False
        self._send_json(self._test_payload(), sort_keys=False)
        return True

    def _handle_wh_per_hour(self, parsed) -> bool:
        if parsed.path != API_PATH_WH_PER_HOUR:
            return False
        status_api = getattr(self.server, "status_api", None)
        store = getattr(status_api, "store", None) if status_api is not None else None
        db_identity = getattr(status_api, "db_path", None)
        if db_identity is None and store is not None:
            db_identity = getattr(store, "db_path", None)
        if status_api is None or store is None or not hasattr(status_api, "compute_wh_per_hour"):
            self._send_error_json("Status updates database not available", 200)
            return True
        now = int(time.time())
        resolved_days = self._resolve_wh_per_hour_days(parsed)
        cached = None
        with self.server.wh_per_hour_cache_lock:
            cache_entry = self.server.wh_per_hour_cache
            if cache_entry and cache_entry.get("db_path") == db_identity and cache_entry.get("days") == resolved_days and (now - int(cache_entry.get("computed_at", 0))) < WH_PER_HOUR_CACHE_SECONDS:
                cached = cache_entry.get("data")
        if cached is not None:
            self._send_json(cached, sort_keys=True)
            return True
        data = status_api.compute_wh_per_hour(now, resolved_days)
        with self.server.wh_per_hour_cache_lock:
            self.server.wh_per_hour_cache = {"db_path": db_identity, "days": resolved_days, "computed_at": now, "data": data}
        self._send_json(data, sort_keys=True)
        return True

    def _handle_status_updates_delta(self, parsed) -> bool:
        if parsed.path != API_PATH_STATUS_UPDATES_DELTA:
            return False
        if not self._is_status_updates_delta_authorized(parsed):
            self._send_error_json("Unauthorized", 401)
            return True
        query = parse_qs(parsed.query)
        if "after_id" not in query or not query["after_id"]:
            self._send_error_json("Missing required query parameter: after_id", 400)
            return True
        try:
            after_id = int(query["after_id"][0])
            if after_id < 0:
                raise ValueError("after_id must be non-negative")
        except (ValueError, TypeError):
            self._send_error_json("Invalid after_id; expected non-negative integer", 400)
            return True
        limit = self._parse_non_negative_int_query(parsed, "limit", 500)
        if limit is None or limit <= 0:
            self._send_error_json("Invalid limit; expected positive integer", 400)
            return True
        limit = min(limit, 2000)
        db_path = getattr(self.server, "db_path", None)
        status_api = getattr(self.server, "status_api", None)
        if (
            not db_path
            or not os.path.exists(db_path)
            or status_api is None
            or getattr(status_api, "store", None) is None
        ):
            self._send_error_json("Status updates database not available", 503)
            return True
        try:
            payload = status_api.store.fetch_status_updates_delta(after_id, limit)
        except Exception as exc:
            self._send_error_json(f"Failed to query status updates: {exc}", 500)
            return True
        self._send_json(payload, sort_keys=False)
        return True

    def _handle_refresh(self, parsed) -> bool:
        if parsed.path != API_PATH_REFRESH:
            return False
        schedule_controller = getattr(self.server, "schedule_controller", None)
        status_api = getattr(self.server, "status_api", None)
        if not schedule_controller or not status_api:
            self._send_error_json("Refresh not available", 503)
            return True
        try:
            schedule_controller.fetch_schedule()
            status_api.post_update(EVENT_TYPE_RESCAN, None, None)
            self._send_json({"ok": True, "message": "Schedule refreshed"})
        except Exception as exc:
            self._send_json({"ok": False, "error": str(exc)}, 500)
        return True

    def _handle_restart(self, parsed) -> bool:
        if parsed.path != API_PATH_RESTART:
            return False
        restart_cb = getattr(self.server, "restart_callback", None)
        if restart_cb is None:
            self._send_json({"ok": False, "error": "Restart not available"}, 503)
            return True
        try:
            restart_cb()
            self._send_json({"ok": True, "message": "Restart requested"})
        except Exception as exc:
            self._send_json({"ok": False, "error": str(exc)}, 500)
        return True

    def _parse_pause_state(self, parsed) -> Optional[bool]:
        query = parse_qs(parsed.query)
        raw = None
        for key in ("state", "pause", "mode", "action"):
            if key in query and query[key]:
                raw = str(query[key][0]).strip().lower()
                break
        if raw is None:
            return None
        if raw in ("on", "pause", "true", "1", "start"):
            return True
        if raw in ("off", "resume", "false", "0", "stop"):
            return False
        return None

    def _handle_pause_get(self, parsed) -> bool:
        if parsed.path != API_PATH_PAUSE:
            return False
        pause_getter = getattr(self.server, "pause_getter", None)
        if pause_getter is None:
            self._send_json({"ok": False, "error": "Pause override not available"}, 503)
            return True
        try:
            self._send_json({"ok": True, "pauseActive": bool(pause_getter())})
        except Exception as exc:
            self._send_json({"ok": False, "error": str(exc)}, 500)
        return True

    def _handle_pause_post(self, parsed) -> bool:
        if parsed.path != API_PATH_PAUSE:
            return False
        pause_setter = getattr(self.server, "pause_setter", None)
        pause_getter = getattr(self.server, "pause_getter", None)
        if pause_setter is None or pause_getter is None:
            self._send_json({"ok": False, "error": "Pause override not available"}, 503)
            return True
        desired = self._parse_pause_state(parsed)
        if desired is None:
            self._send_json(self._control_help_payload())
            return True
        try:
            pause_setter(desired)
            active = bool(pause_getter())
            self._send_json({"ok": True, "pauseActive": active})
        except Exception as exc:
            active = bool(pause_getter())
            self._send_json({"ok": False, "error": str(exc), "pauseActive": active}, 500)
        return True

    def _allowed_runtime_log_levels(self) -> list[str]:
        priorities = getattr(self.server, "log_level_priorities", None) or {}
        allowed = [level for level in priorities.keys() if level in ("DEBUG", "INFO", "WARNING", "ERROR")]
        return allowed if allowed else ["DEBUG", "INFO", "WARNING", "ERROR"]

    def _parse_log_level(self, parsed) -> Optional[str]:
        query = parse_qs(parsed.query)
        raw = None
        for key in ("level", "loglevel", "log_level"):
            if key in query and query[key]:
                raw = str(query[key][0]).strip().upper()
                break
        if not raw:
            return None
        return raw if raw in self._allowed_runtime_log_levels() else None

    def _parse_slow_charge_max_power(self, parsed) -> Optional[int]:
        return self._parse_non_negative_int_query(parsed, "value")

    def _parse_netzero_target_w(self, parsed) -> Optional[int]:
        return self._parse_int_query(parsed, "value")

    def _parse_charge_level(self, parsed) -> Optional[int]:
        return self._parse_int_query(parsed, "value")

    def _get_normalized_charge_levels(self, controller: Any) -> tuple[int, int]:
        try:
            min_level = int(getattr(controller, "min_charge_level", 0))
        except (TypeError, ValueError):
            min_level = 0
        try:
            max_level = int(getattr(controller, "max_charge_level", 100))
        except (TypeError, ValueError):
            max_level = 100
        min_level = max(0, min(100, min_level))
        max_level = max(0, min(100, max_level))
        if min_level > max_level:
            max_level = min_level
        return min_level, max_level

    def _set_normalized_charge_levels(self, controller: Any, min_level: int, max_level: int) -> tuple[int, int]:
        min_level = max(0, min(100, int(min_level)))
        max_level = max(0, min(100, int(max_level)))
        if min_level > max_level:
            max_level = min_level
        controller.min_charge_level = min_level
        controller.max_charge_level = max_level
        return min_level, max_level

    def _charge_level_payload(self, controller: Any, message: Optional[str] = None) -> dict[str, Any]:
        min_level, max_level = self._get_normalized_charge_levels(controller)
        payload: dict[str, Any] = {
            "ok": True,
            "minChargeLevel": min_level,
            "maxChargeLevel": max_level,
        }
        if message:
            payload["message"] = message
        return payload

    def _handle_loglevel_get(self, parsed) -> bool:
        if parsed.path != API_PATH_LOG_LEVEL:
            return False
        controller = getattr(self.server, "controller", None)
        if controller is None:
            self._send_json({"ok": False, "error": "Log level control not available"}, 503)
            return True
        current_level = str(getattr(controller, "log_level", "INFO")).upper()
        allowed = self._allowed_runtime_log_levels()
        if current_level not in allowed:
            current_level = "INFO"
        self._send_json({"ok": True, "level": current_level, "allowedLevels": allowed})
        return True

    def _handle_loglevel_post(self, parsed) -> bool:
        if parsed.path != API_PATH_LOG_LEVEL:
            return False
        controller = getattr(self.server, "controller", None)
        if controller is None:
            self._send_json({"ok": False, "error": "Log level control not available"}, 503)
            return True
        desired = self._parse_log_level(parsed)
        if desired is None:
            self._send_json({"ok": False, "error": "Invalid log level. Use DEBUG|INFO|WARNING|ERROR."}, 400)
            return True
        controller.log_level = desired
        print(f"[loglevel] Log level changed via API to: {desired}")
        self._send_json({"ok": True, "level": desired, "message": f"Log level set to {desired}"})
        return True

    def _handle_slow_charge_max_power_get(self, parsed) -> bool:
        if parsed.path != API_PATH_SLOW_CHARGE_MAX_POWER:
            return False
        controller = getattr(self.server, "controller", None)
        if controller is None:
            self._send_json({"ok": False, "error": "Slow-charge max power control not available"}, 503)
            return True
        self._send_json({
            "ok": True,
            "slowChargeMaxPower": getattr(controller, "slow_charge_max_power", None),
            "maxChargePower": getattr(controller, "max_charge_power", None),
        })
        return True

    def _handle_slow_charge_max_power_post(self, parsed) -> bool:
        if parsed.path != API_PATH_SLOW_CHARGE_MAX_POWER:
            return False
        controller = getattr(self.server, "controller", None)
        if controller is None:
            self._send_json({"ok": False, "error": "Slow-charge max power control not available"}, 503)
            return True
        desired = self._parse_slow_charge_max_power(parsed)
        if desired is None:
            self._send_json({"ok": False, "error": "Invalid slow_charge_max_power. Use integer value query parameter."}, 400)
            return True
        max_charge_power = getattr(controller, "max_charge_power", None)
        if not isinstance(max_charge_power, int):
            self._send_json({"ok": False, "error": "Slow-charge max power control not available"}, 503)
            return True
        if desired < 0 or desired > max_charge_power:
            self._send_json(
                {"ok": False, "error": f"Invalid slow_charge_max_power. Must be between 0 and {max_charge_power}."},
                400,
            )
            return True
        old_value = getattr(controller, "slow_charge_max_power", None)
        controller.slow_charge_max_power = desired
        controller.log(
            "info",
            f"Runtime API override changed SLOW_CHARGE_MAX_POWER: {old_value} -> {desired} W",
        )
        self._send_json({
            "ok": True,
            "message": f"SLOW_CHARGE_MAX_POWER set to {controller.slow_charge_max_power} W",
            "slowChargeMaxPower": controller.slow_charge_max_power,
            "maxChargePower": max_charge_power,
        })
        return True

    def _handle_netzero_target_w_get(self, parsed) -> bool:
        if parsed.path != API_PATH_NETZERO_TARGET_W:
            return False
        controller = getattr(self.server, "controller", None)
        if controller is None:
            self._send_json({"ok": False, "error": "NETZERO_TARGET_W control not available"}, 503)
            return True
        current_value = getattr(controller, "netzero_target_w", 0)
        if not isinstance(current_value, int):
            try:
                current_value = int(current_value)
            except (TypeError, ValueError):
                current_value = 0
        self._send_json({"ok": True, "netzeroTargetW": current_value})
        return True

    def _handle_netzero_target_w_post(self, parsed) -> bool:
        if parsed.path != API_PATH_NETZERO_TARGET_W:
            return False
        controller = getattr(self.server, "controller", None)
        if controller is None:
            self._send_json({"ok": False, "error": "NETZERO_TARGET_W control not available"}, 503)
            return True
        desired = self._parse_netzero_target_w(parsed)
        if desired is None:
            self._send_json({"ok": False, "error": "Invalid NETZERO_TARGET_W. Use signed integer value query parameter."}, 400)
            return True
        old_value = getattr(controller, "netzero_target_w", 0)
        controller.netzero_target_w = desired
        controller.log(
            "info",
            f"Runtime API override changed NETZERO_TARGET_W: {old_value} -> {desired} W",
        )
        self._send_json({
            "ok": True,
            "message": f"NETZERO_TARGET_W set to {controller.netzero_target_w} W",
            "netzeroTargetW": controller.netzero_target_w,
        })
        return True

    def _handle_min_charge_level_get(self, parsed) -> bool:
        if parsed.path != API_PATH_MIN_CHARGE_LEVEL:
            return False
        controller = getattr(self.server, "controller", None)
        if controller is None:
            self._send_json({"ok": False, "error": "MIN_CHARGE_LEVEL control not available"}, 503)
            return True
        self._send_json(self._charge_level_payload(controller))
        return True

    def _handle_max_charge_level_get(self, parsed) -> bool:
        if parsed.path != API_PATH_MAX_CHARGE_LEVEL:
            return False
        controller = getattr(self.server, "controller", None)
        if controller is None:
            self._send_json({"ok": False, "error": "MAX_CHARGE_LEVEL control not available"}, 503)
            return True
        self._send_json(self._charge_level_payload(controller))
        return True

    def _handle_min_charge_level_post(self, parsed) -> bool:
        if parsed.path != API_PATH_MIN_CHARGE_LEVEL:
            return False
        controller = getattr(self.server, "controller", None)
        if controller is None:
            self._send_json({"ok": False, "error": "MIN_CHARGE_LEVEL control not available"}, 503)
            return True
        desired = self._parse_charge_level(parsed)
        if desired is None:
            self._send_json({"ok": False, "error": "Invalid MIN_CHARGE_LEVEL. Use integer value query parameter."}, 400)
            return True
        old_min, old_max = self._get_normalized_charge_levels(controller)
        new_min = max(0, min(100, desired))
        new_max = old_max if new_min <= old_max else new_min
        min_level, max_level = self._set_normalized_charge_levels(controller, new_min, new_max)
        controller.log(
            "info",
            f"Runtime API override changed charge limits: MIN_CHARGE_LEVEL {old_min} -> {min_level}%, MAX_CHARGE_LEVEL {old_max} -> {max_level}%",
        )
        self._send_json(self._charge_level_payload(controller, f"Charge limits set to MIN={min_level}% MAX={max_level}%"))
        return True

    def _handle_max_charge_level_post(self, parsed) -> bool:
        if parsed.path != API_PATH_MAX_CHARGE_LEVEL:
            return False
        controller = getattr(self.server, "controller", None)
        if controller is None:
            self._send_json({"ok": False, "error": "MAX_CHARGE_LEVEL control not available"}, 503)
            return True
        desired = self._parse_charge_level(parsed)
        if desired is None:
            self._send_json({"ok": False, "error": "Invalid MAX_CHARGE_LEVEL. Use integer value query parameter."}, 400)
            return True
        old_min, old_max = self._get_normalized_charge_levels(controller)
        new_max = max(0, min(100, desired))
        new_min = old_min if new_max >= old_min else new_max
        min_level, max_level = self._set_normalized_charge_levels(controller, new_min, new_max)
        controller.log(
            "info",
            f"Runtime API override changed charge limits: MIN_CHARGE_LEVEL {old_min} -> {min_level}%, MAX_CHARGE_LEVEL {old_max} -> {max_level}%",
        )
        self._send_json(self._charge_level_payload(controller, f"Charge limits set to MIN={min_level}% MAX={max_level}%"))
        return True

    def _handle_automation_status(self, parsed) -> bool:
        if parsed.path != API_PATH_AUTOMATION_STATUS:
            return False
        api_state = self._require_api_state()
        if api_state is None:
            return True
        self._send_json(compute_automation_status_from_state(api_state, "all", 50))
        return True

    def _handle_p1(self, parsed) -> bool:
        if parsed.path != API_PATH_P1:
            return False
        api_state = self._require_api_state()
        if api_state is None:
            return True
        max_age = self._parse_max_age(parsed)
        self._maybe_refresh_reading(api_state.last_p1, max_age, getattr(self.server, "refresh_p1_callback", None))
        self._send_json(api_state.last_p1.to_dict() if api_state.last_p1 else None)
        return True

    def _handle_zendure(self, parsed) -> bool:
        if parsed.path != API_PATH_ZENDURE:
            return False
        api_state = self._require_api_state()
        if api_state is None:
            return True
        max_age = self._parse_max_age(parsed)
        self._maybe_refresh_reading(api_state.last_zendure, max_age, getattr(self.server, "refresh_zendure_callback", None))
        self._send_json(api_state.last_zendure.to_dict() if api_state.last_zendure else None)
        return True

    def _handle_status(self, parsed) -> bool:
        if parsed.path != API_PATH_STATUS:
            return False
        api_state = self._require_api_state()
        if api_state is None:
            return True
        self._send_json(api_state.last_status.to_dict() if api_state.last_status else None)
        return True

    def _handle_all(self, parsed) -> bool:
        if parsed.path != API_PATH_ALL:
            return False
        api_state = self._require_api_state()
        if api_state is None:
            return True
        self._send_json({
            "p1": api_state.last_p1.to_dict() if api_state.last_p1 else None,
            "zendure": api_state.last_zendure.to_dict() if api_state.last_zendure else None,
            "status": api_state.last_status.to_dict() if api_state.last_status else None,
        })
        return True

    def do_GET(self):
        parsed = urlparse(self.path)
        if self._dispatch(parsed, self.GET_ROUTES):
            return
        if self._dispatch(parsed, self.STATEFUL_GET_ROUTES):
            return
        self.send_error(404, "Not Found")

    def do_POST(self):
        parsed = urlparse(self.path)
        if self._dispatch(parsed, self.POST_ROUTES):
            return
        self.send_error(404, "Not Found")

    def log_message(self, msg_format, *args):
        pass


def create_http_server(
    *,
    api_state: ApiState,
    db_path: str,
    schedule_controller: Any,
    status_api: Any,
    refresh_p1_callback: Callable[[], None],
    refresh_zendure_callback: Callable[[], None],
    restart_callback: Callable[[], None],
    pause_getter: Callable[[], bool],
    pause_setter: Callable[[bool], None],
    controller: Any,
    status_updates_delta_token: Optional[str],
    port: int = HTTP_API_PORT,
    log_level_priorities: Optional[dict[str, Any]] = None,
    ) -> AutomationTCPServer:
    server = AutomationTCPServer(("", port), ApiTestHandler)
    server.api_state = api_state
    server.db_path = db_path
    server.schedule_controller = schedule_controller
    server.status_api = status_api
    server.refresh_p1_callback = refresh_p1_callback
    server.refresh_zendure_callback = refresh_zendure_callback
    server.restart_callback = restart_callback
    server.pause_getter = pause_getter
    server.pause_setter = pause_setter
    server.controller = controller
    server.status_updates_delta_token = status_updates_delta_token
    server.log_level_priorities = log_level_priorities
    return server
