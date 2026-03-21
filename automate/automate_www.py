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
import sys
import select
import platform
import threading
import queue
import http.server
import socketserver
from dataclasses import dataclass
from datetime import datetime, timedelta
from warnings import simplefilter
from zoneinfo import ZoneInfo
from typing import Optional, Any, Callable
from urllib.parse import urlparse, parse_qs

from device_controller import AutomateController, ScheduleController, BaseDeviceController, get_reader
from power_metere_loader import get_power_meter_reader

# ============================================================================
# CONSTANTS & DEFAULT CONFIG (override via config.jsonc)
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
# Cap last segment so we don't extrapolate one power reading to "now" for days (avoids inflated totals)
WH_PER_HOUR_LAST_SEGMENT_MAX_SECONDS = 3600  # 1 hour
WH_PER_HOUR_CACHE_SECONDS = 60

# Shared timezone for status timestamps
STATUS_TIMEZONE = "Europe/Amsterdam"

# Power mode strings and validation defaults
POWER_MODE_NETZERO = "netzero"
POWER_MODE_NETZERO_PLUS = "netzero+"
POWER_MODE_NETZERO_VALIDATION_W = -250
POWER_MODE_NETZERO_PLUS_VALIDATION_W = 250

# Event types
EVENT_TYPE_START = "start"
EVENT_TYPE_STOP = "stop"
EVENT_TYPE_CHANGE = "change"
EVENT_TYPE_RESCAN = "Rescan"

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


class ApiState:
    """Shared state for API endpoints: latest P1, Zendure, and status readings."""

    def __init__(self):
        self.last_p1: Optional[P1Readings] = None
        self.last_zendure: Optional[ZendureReadings] = None
        self.last_status: Optional[StatusChange] = None
        self.last_status_by_type: dict[str, AutomationStatusEntry] = {}


# ============================================================================
# HTTP API HANDLER
# ============================================================================

def _allowed_wh_dates(now: int, days_back: int, tz: ZoneInfo) -> list[str]:
    dt = datetime.fromtimestamp(now, tz=tz)
    start_day = datetime(dt.year, dt.month, dt.day, tzinfo=tz)
    return [
        (start_day - timedelta(days=i)).strftime("%Y-%m-%d")
        for i in range(days_back + 1)
    ]


def _wh_window_start_ts(now: int, days_back: int, tz: ZoneInfo) -> int:
    dt = datetime.fromtimestamp(now, tz=tz)
    start_day = datetime(dt.year, dt.month, dt.day, tzinfo=tz) - timedelta(days=days_back)
    return int(start_day.timestamp())


def _empty_wh_result(allowed_dates: list[str]) -> dict:
    return {
        d: [
            {
                "hour": f"{h:02d}",
                "charged_wh": 0.0,
                "discharged_wh": 0.0,
                "electric_level": None,
            }
            for h in range(24)
        ]
        for d in sorted(allowed_dates)
    }


def _hour_start_ts(ts: int, tz: ZoneInfo) -> int:
    dt = datetime.fromtimestamp(ts, tz=tz)
    return int(datetime(dt.year, dt.month, dt.day, dt.hour, 0, 0, tzinfo=tz).timestamp())


def _mark_hour_anchor_indices(
    raw_points: list[tuple[int, float, Optional[int]]], tz: ZoneInfo
    ) -> set[int]:
    anchors: dict[int, tuple[int, int]] = {}
    for idx, (ts, _power, _level) in enumerate(raw_points):
        hour_start_ts = _hour_start_ts(ts, tz)
        distance = abs(ts - hour_start_ts)
        current = anchors.get(hour_start_ts)
        if current is None or distance < current[0]:
            anchors[hour_start_ts] = (distance, idx)
    return {anchor_idx for _distance, anchor_idx in anchors.values()}


def _load_change_points(db_path: str, window_start_ts: int, tz: ZoneInfo) -> list[tuple[int, float]]:
    raw_points: list[tuple[int, float, Optional[int]]] = []
    with sqlite3.connect(db_path) as conn:
        seed_cur = conn.execute(
            "SELECT CAST(new_value AS REAL), timestamp, electric_level FROM status_updates "
            "WHERE type = ? AND new_value IS NOT NULL AND timestamp < ? "
            "ORDER BY timestamp DESC LIMIT 1",
            (EVENT_TYPE_CHANGE, window_start_ts),
        )
        rows = seed_cur.fetchall()
        cur = conn.execute(
            "SELECT CAST(new_value AS REAL), timestamp, electric_level FROM status_updates "
            "WHERE type = ? AND new_value IS NOT NULL AND timestamp >= ? "
            "ORDER BY timestamp ASC",
            (EVENT_TYPE_CHANGE, window_start_ts),
        )
        rows.extend(cur.fetchall())
        for power_raw, ts, electric_level_raw in rows:
            if ts is None:
                continue
            try:
                electric_level = (
                    int(electric_level_raw) if electric_level_raw is not None else None
                )
                raw_points.append((int(ts), float(power_raw), electric_level))
            except (TypeError, ValueError):
                continue
    if not raw_points:
        return []

    hour_anchor_indices = _mark_hour_anchor_indices(raw_points, tz)
    points: list[tuple[int, float]] = []
    run_start_ts, run_power, run_level = raw_points[0]
    run_last_ts = run_start_ts
    for idx, (ts, power, electric_level) in enumerate(raw_points[1:], start=1):
        if power == run_power and electric_level == run_level and idx not in hour_anchor_indices:
            run_last_ts = ts
            continue
        points.append((run_start_ts, run_power))
        run_start_ts = ts
        run_last_ts = ts
        run_power = power
        run_level = electric_level
    points.append((run_last_ts, run_power))
    return points


def _load_electric_levels_by_hour(
    db_path: str, allowed_dates: set[str], tz: ZoneInfo, window_start_ts: int
    ) -> dict[str, dict[str, int]]:
    levels_by_date_hour: dict[str, dict[str, int]] = {}
    with sqlite3.connect(db_path) as conn:
        for hour_offset in range((len(allowed_dates) * 24)):
            hour_start_dt = datetime.fromtimestamp(window_start_ts, tz=tz) + timedelta(hours=hour_offset)
            hour_end_dt = hour_start_dt + timedelta(hours=1)
            cur = conn.execute(
                "SELECT timestamp, electric_level FROM status_updates "
                "WHERE timestamp >= ? AND timestamp < ? "
                "AND electric_level IS NOT NULL "
                "ORDER BY timestamp DESC LIMIT 1",
                (int(hour_start_dt.timestamp()), int(hour_end_dt.timestamp())),
            )
            row = cur.fetchone()
            if row is None:
                continue
            ts_raw, level_raw = row
            try:
                ts = int(ts_raw)
                level = int(level_raw)
            except (TypeError, ValueError):
                continue
            dt = datetime.fromtimestamp(ts, tz=tz)
            date_key = dt.strftime("%Y-%m-%d")
            if date_key not in allowed_dates:
                continue
            hour_key = dt.strftime("%H")
            by_hour = levels_by_date_hour.setdefault(date_key, {})
            # Rows are processed in ascending timestamp order, so this keeps the
            # latest non-null level seen within each hour bucket.
            by_hour[hour_key] = level
    return levels_by_date_hour


def _accumulate_wh_segment(
    wh_by_date_hour: dict[str, dict[str, dict[str, float]]],
    allowed_dates: set[str],
    tz: ZoneInfo,
    t_start: int,
    t_end: int,
    power: float,
    ) -> None:
    def _slice_for_hour(cur_ts: int) -> tuple[int, str, str, int]:
        dt_start = datetime.fromtimestamp(cur_ts, tz=tz)
        hour_start = int(dt_start.strftime("%H"))
        hour_epoch = int(
            datetime(
                dt_start.year,
                dt_start.month,
                dt_start.day,
                hour_start,
                0,
                0,
                tzinfo=tz,
            ).timestamp()
        )
        hour_end = hour_epoch + 3600
        clip_start = max(t_start, hour_epoch)
        clip_end = min(t_end, hour_end)
        seconds = max(0, clip_end - clip_start)
        return hour_end, dt_start.strftime("%Y-%m-%d"), f"{hour_start:02d}", seconds

    cur = t_start
    while cur < t_end:
        hour_end, date_str, hour_key, seconds = _slice_for_hour(cur)
        if seconds > 0 and date_str in allowed_dates:
            by_hour = wh_by_date_hour.setdefault(date_str, {})
            bucket = by_hour.setdefault(
                hour_key, {"charged_wh": 0.0, "discharged_wh": 0.0}
            )
            wh = abs(power) * seconds / 3600
            if power > 0:
                bucket["charged_wh"] += wh
            elif power < 0:
                bucket["discharged_wh"] += wh
        cur = hour_end


def _integrate_wh_points(
    points: list[tuple[int, float]], now: int, allowed_dates: set[str], tz: ZoneInfo
    ) -> dict[str, dict[str, dict[str, float]]]:
    wh_by_date_hour: dict[str, dict[str, dict[str, float]]] = {}
    for idx, (t_start, power) in enumerate(points):
        if idx < len(points) - 1:
            t_end = points[idx + 1][0]
        else:
            # Only cap the open-ended tail segment (last known value -> now).
            # Historical segments between two explicit change points should be
            # integrated over their full duration.
            t_end = min(now, t_start + WH_PER_HOUR_LAST_SEGMENT_MAX_SECONDS)
        _accumulate_wh_segment(
            wh_by_date_hour=wh_by_date_hour,
            allowed_dates=allowed_dates,
            tz=tz,
            t_start=t_start,
            t_end=t_end,
            power=power,
        )
    return wh_by_date_hour


def _build_wh_result(
    wh_by_date_hour: dict[str, dict[str, dict[str, float]]],
    electric_levels_by_date_hour: dict[str, dict[str, int]],
    allowed_dates: list[str],
    ) -> dict:
    result = {}
    for date_key in sorted(allowed_dates):
        hours = wh_by_date_hour.get(date_key, {})
        levels = electric_levels_by_date_hour.get(date_key, {})
        result[date_key] = [
            {
                "hour": f"{hour:02d}",
                "charged_wh": round(hours.get(f"{hour:02d}", {}).get("charged_wh", 0.0), 2),
                "discharged_wh": round(
                    hours.get(f"{hour:02d}", {}).get("discharged_wh", 0.0), 2
                ),
                "electric_level": levels.get(f"{hour:02d}"),
            }
            for hour in range(24)
        ]
    return result


def compute_wh_per_hour(db_path: str, now: int, days_back: int = WH_PER_HOUR_DAYS_DEFAULT) -> dict:
    """
    Compute watt-hours charged and discharged per calendar hour from status_updates SQLite.
    Uses step integration: power constant between consecutive readings.
    Returns { "YYYY-MM-DD": [ {"hour": "HH", "charged_wh": float, "discharged_wh": float,
                               "electric_level": int|null}, ... ], ... }
    for the last days_back full days including today. Hours are ordered 00, 01, ... 23 (array preserves order).
    """
    tz = ZoneInfo(WH_PER_HOUR_TIMEZONE)
    allowed_dates = _allowed_wh_dates(now=now, days_back=days_back, tz=tz)
    window_start_ts = _wh_window_start_ts(now=now, days_back=days_back, tz=tz)
    if not os.path.exists(db_path):
        return _empty_wh_result(allowed_dates)
    allowed_dates_set = set(allowed_dates)

    try:
        electric_levels_by_date_hour = _load_electric_levels_by_hour(
            db_path=db_path, allowed_dates=allowed_dates_set, tz=tz, window_start_ts=window_start_ts
        )
    except Exception:
        electric_levels_by_date_hour = {}

    try:
        points = _load_change_points(db_path, window_start_ts=window_start_ts, tz=tz)
    except Exception:
        points = []

    wh_by_date_hour: dict[str, dict[str, dict[str, float]]] = {}
    if points:
        wh_by_date_hour = _integrate_wh_points(
            points=points,
            now=now,
            allowed_dates=allowed_dates_set,
            tz=tz,
        )
    return _build_wh_result(wh_by_date_hour, electric_levels_by_date_hour, allowed_dates)


def compute_automation_status_from_state(api_state: ApiState, type_filter: str, limit: int) -> dict:
    """Build Automation Status response from in-memory state."""
    if type_filter not in (EVENT_TYPE_CHANGE, "all"):
        type_filter = EVENT_TYPE_CHANGE

    limit = max(limit, 1) # minimum 1
    limit = min(limit, 50) # maximum 50

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
    allow_reuse_address = True  # avoid "Address already in use" when restarting quickly
    daemon_threads = True  # don't wait for request threads during shutdown/restart
    block_on_close = False  # avoid hanging on persistent client connections

    def __init__(self, server_address, request_handler_class):
        super().__init__(server_address, request_handler_class)
        self.api_state: Optional[ApiState] = None
        self.db_path: Optional[str] = None
        self.schedule_controller: Optional[ScheduleController] = None
        self.status_api: Optional["StatusApi"] = None
        self.refresh_p1_callback: Optional[Callable[[], None]] = None
        self.refresh_zendure_callback: Optional[Callable[[], None]] = None
        self.restart_callback: Optional[Callable[[], None]] = None
        self.pause_getter: Optional[Callable[[], bool]] = None
        self.pause_setter: Optional[Callable[[bool], None]] = None
        self.wh_per_hour_cache: Optional[dict[str, Any]] = None
        self.wh_per_hour_cache_lock = threading.Lock()


class ApiTestHandler(http.server.BaseHTTPRequestHandler):
    """Handles GET /api/test, /api/p1, /api/zendure, /api/status, /api/all, /api/wh_per_hour, /api/refresh with JSON responses."""
    protocol_version = "HTTP/1.0"  # close per-request to avoid keep-alive restart hangs

    def _send_json(self, data, status=200, sort_keys=True):
        """Send JSON response."""
        body = json.dumps(data, sort_keys=sort_keys).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(body)
        self.close_connection = True

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

    def _parse_non_negative_int_query(self, parsed, key: str, default: Optional[int] = None) -> Optional[int]:
        """Parse a non-negative integer query param; returns default when absent/invalid."""
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

    def _is_status_updates_delta_authorized(self, parsed) -> bool:
        """Optional token auth for status_updates_delta endpoint."""
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
        """If reading is missing or older than max_age seconds, call refresh_cb (0 = always refresh)."""
        now = int(time.time())
        need_refresh = (
            max_age == 0
            or reading is None
            or (now - (reading.timestamp or 0)) > max_age
        )
        if need_refresh and refresh_cb is not None:
            refresh_cb()

    def _test_payload(self) -> dict:
        return {
            "path": API_PATH_TEST,
            "status": "ok",
            "message": "API is up and running",
            "endpoints": [
                {"path": API_PATH_TEST, "optional_params": []},
                {
                    "path": API_PATH_P1,
                    "optional_params": [
                        {
                            "name": "max_age",
                            "alt": "maxAge",
                            "type": "int",
                            "default": 60,
                            "description": "0 = always refresh; N = refresh if data older than N seconds (default 60)",
                        },
                    ],
                },
                {
                    "path": API_PATH_ZENDURE,
                    "optional_params": [
                        {
                            "name": "max_age",
                            "alt": "maxAge",
                            "type": "int",
                            "default": 60,
                            "description": "0 = always refresh; N = refresh if data older than N seconds (default 60)",
                        },
                    ],
                },
                {"path": API_PATH_STATUS, "optional_params": []},
                {"path": API_PATH_ALL, "optional_params": []},
                {"path": API_PATH_AUTOMATION_STATUS, "optional_params": []},
                {"path": API_PATH_WH_PER_HOUR, "optional_params": []},
                {
                    "path": API_PATH_STATUS_UPDATES_DELTA,
                    "optional_params": [
                        {
                            "name": "after_id",
                            "type": "int",
                            "required": True,
                            "description": "Return rows where id > after_id",
                        },
                        {
                            "name": "limit",
                            "type": "int",
                            "default": 500,
                            "max": 2000,
                            "description": "Maximum rows per page",
                        },
                        {
                            "name": "token",
                            "type": "string",
                            "required": False,
                            "description": "Optional auth token when server token protection is enabled",
                        },
                    ],
                },
                {"path": API_PATH_REFRESH, "optional_params": []},
                {"path": API_PATH_RESTART, "optional_params": []},
                {
                    "path": API_PATH_PAUSE,
                    "optional_params": [
                        {
                            "name": "state",
                            "type": "string",
                            "allowed": ["on", "off", "true", "false", "1", "0"],
                            "description": "POST only: set pause override state",
                        },
                    ],
                },
                {
                    "path": API_PATH_LOG_LEVEL,
                    "optional_params": [
                        {
                            "name": "level",
                            "alt": "loglevel|log_level",
                            "type": "string",
                            "allowed": ["DEBUG", "INFO", "WARNING", "ERROR"],
                            "description": "POST only: set runtime log level",
                        },
                    ],
                },
            ],
        }

    def _control_help_payload(self) -> dict:
        return {
            "ok": True,
            "message": "Automation control API help",
            "commands": [
                {
                    "path": API_PATH_PAUSE,
                    "name": "status",
                    "method": "GET",
                    "description": "Get current pause override state",
                    "example": f"{API_PATH_PAUSE}",
                },
                {
                    "path": API_PATH_PAUSE,
                    "name": "pause_on",
                    "method": "POST",
                    "description": "Enable pause override (forces desired power to 0)",
                    "example": f"{API_PATH_PAUSE}?state=on",
                },
                {
                    "path": API_PATH_PAUSE,
                    "name": "pause_off",
                    "method": "POST",
                    "description": "Disable pause override (resume schedule control)",
                    "example": f"{API_PATH_PAUSE}?state=off",
                },
                {
                    "path": API_PATH_RESTART,
                    "name": "restart",
                    "method": "POST",
                    "description": "Request graceful restart of automation process",
                    "example": f"{API_PATH_RESTART}",
                },
                {
                    "path": API_PATH_REFRESH,
                    "name": "refresh_schedule",
                    "method": "GET",
                    "description": "Force schedule refresh from API",
                    "example": f"{API_PATH_REFRESH}",
                },
                {
                    "path": API_PATH_LOG_LEVEL,
                    "name": "log_level_status",
                    "method": "GET",
                    "description": "Get current runtime log level",
                    "example": f"{API_PATH_LOG_LEVEL}",
                },
                {
                    "path": API_PATH_LOG_LEVEL,
                    "name": "log_level_set",
                    "method": "POST",
                    "description": "Set runtime log level (DEBUG|INFO|WARNING|ERROR)",
                    "example": f"{API_PATH_LOG_LEVEL}?level=info",
                },
            ],
            "note": "Use /api/test for full endpoint inventory.",
        }

    def _handle_api_help(self, parsed) -> bool:
        if parsed.path not in ("/", "/api"):
            return False
        self._send_json({
            "path": parsed.path,
            "ok": True,
            "message": "Automation API help",
            "endpoints": self._test_payload().get("endpoints", []),
            "control": self._control_help_payload().get("commands", []),
        }, sort_keys=False)
        return True

    def _handle_test(self, path: str) -> bool:
        if path != API_PATH_TEST:
            return False
        self._send_json(self._test_payload(), sort_keys=False)
        return True

    def _handle_wh_per_hour(self, path: str) -> bool:
        if path != API_PATH_WH_PER_HOUR:
            return False
        db_path = getattr(self.server, "db_path", None)
        if not db_path or not os.path.exists(db_path):
            self._send_json({"error": "Status updates database not available"})
            return True
        now = int(time.time())
        cached = None
        with self.server.wh_per_hour_cache_lock:
            cache_entry = self.server.wh_per_hour_cache
            if (
                cache_entry
                and cache_entry.get("db_path") == db_path
                and (now - int(cache_entry.get("computed_at", 0))) < WH_PER_HOUR_CACHE_SECONDS
            ):
                cached = cache_entry.get("data")
        if cached is not None:
            self._send_json(cached, sort_keys=True)
            return True

        data = compute_wh_per_hour(db_path, now, WH_PER_HOUR_DAYS_DEFAULT)
        with self.server.wh_per_hour_cache_lock:
            self.server.wh_per_hour_cache = {
                "db_path": db_path,
                "computed_at": now,
                "data": data,
            }
        self._send_json(data, sort_keys=True)
        return True

    def _handle_status_updates_delta(self, parsed) -> bool:
        if parsed.path != API_PATH_STATUS_UPDATES_DELTA:
            return False

        if not self._is_status_updates_delta_authorized(parsed):
            self._send_json({"error": "Unauthorized"}, 401)
            return True

        query = parse_qs(parsed.query)
        if "after_id" not in query or not query["after_id"]:
            self._send_json({"error": "Missing required query parameter: after_id"}, 400)
            return True

        try:
            after_id = int(query["after_id"][0])
            if after_id < 0:
                raise ValueError("after_id must be non-negative")
        except (ValueError, TypeError):
            self._send_json({"error": "Invalid after_id; expected non-negative integer"}, 400)
            return True

        limit = self._parse_non_negative_int_query(parsed, "limit", 500)
        if limit is None or limit <= 0:
            self._send_json({"error": "Invalid limit; expected positive integer"}, 400)
            return True
        limit = min(limit, 2000)

        db_path = getattr(self.server, "db_path", None)
        if not db_path or not os.path.exists(db_path):
            self._send_json({"error": "Status updates database not available"}, 503)
            return True

        rows = []
        try:
            with sqlite3.connect(db_path) as conn:
                conn.row_factory = sqlite3.Row
                cur = conn.execute(
                    "SELECT id, type, old_value, new_value, p1_total_power, electric_level, timestamp "
                    "FROM status_updates WHERE id > ? ORDER BY id ASC LIMIT ?",
                    (after_id, limit + 1),
                )
                fetched = cur.fetchall()

            has_more = len(fetched) > limit
            selected = fetched[:limit]
            rows = [dict(r) for r in selected]
        except Exception as e:
            self._send_json({"error": f"Failed to query status updates: {e}"}, 500)
            return True

        max_id_returned = after_id
        if rows:
            max_id_returned = int(rows[-1]["id"])

        self._send_json(
            {
                "rows": rows,
                "max_id_returned": max_id_returned,
                "has_more": has_more,
            },
            sort_keys=False,
        )
        return True

    def _handle_refresh(self, path: str) -> bool:
        if path != API_PATH_REFRESH:
            return False
        schedule_controller = getattr(self.server, "schedule_controller", None)
        status_api = getattr(self.server, "status_api", None)
        if not schedule_controller or not status_api:
            self._send_json({"error": "Refresh not available"}, 503)
            return True
        try:
            schedule_controller.fetch_schedule()
            status_api.post_update(EVENT_TYPE_RESCAN, None, None)
            self._send_json({"ok": True, "message": "Schedule refreshed"})
        except Exception as e:
            self._send_json({"ok": False, "error": str(e)}, 500)
        return True

    def _handle_restart(self, path: str) -> bool:
        if path != API_PATH_RESTART:
            return False
        restart_cb = getattr(self.server, "restart_callback", None)
        if restart_cb is None:
            self._send_json({"ok": False, "error": "Restart not available"}, 503)
            return True
        try:
            restart_cb()
            self._send_json({"ok": True, "message": "Restart requested"})
        except Exception as e:
            self._send_json({"ok": False, "error": str(e)}, 500)
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
            active = bool(pause_getter())
            self._send_json({"ok": True, "pauseActive": active})
        except Exception as e:
            self._send_json({"ok": False, "error": str(e)}, 500)
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
        except Exception as e:
            active = bool(pause_getter())
            self._send_json({"ok": False, "error": str(e), "pauseActive": active}, 500)
        return True

    def _allowed_runtime_log_levels(self) -> list[str]:
        allowed = [
            level
            for level in BaseDeviceController._LOG_LEVEL_PRIORITY.keys()
            if level in ("DEBUG", "INFO", "WARNING", "ERROR")
        ]
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
        self._send_json({
            "ok": True,
            "level": current_level,
            "allowedLevels": allowed,
        })
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
            self._send_json({
                "ok": False,
                "error": "Invalid log level. Use DEBUG|INFO|WARNING|ERROR.",
            }, 400)
            return True
        controller.log_level = desired
        # Always print log level changes (independent of current log level)
        print(f"[loglevel] Log level changed via API to: {desired}")
        self._send_json({
            "ok": True,
            "level": desired,
            "message": f"Log level set to {desired}",
        })
        return True

    def _handle_automation_status(self, path: str, api_state: ApiState) -> bool:
        if path != API_PATH_AUTOMATION_STATUS:
            return False
        data = compute_automation_status_from_state(api_state, "all", 50)
        self._send_json(data)
        return True

    def _handle_p1(self, parsed, api_state: ApiState) -> bool:
        if parsed.path != API_PATH_P1:
            return False
        max_age = self._parse_max_age(parsed)
        self._maybe_refresh_reading(
            api_state.last_p1, max_age, getattr(self.server, "refresh_p1_callback", None)
        )
        data = api_state.last_p1.to_dict() if api_state.last_p1 else None
        self._send_json(data)
        return True

    def _handle_zendure(self, parsed, api_state: ApiState) -> bool:
        if parsed.path != API_PATH_ZENDURE:
            return False
        max_age = self._parse_max_age(parsed)
        self._maybe_refresh_reading(
            api_state.last_zendure,
            max_age,
            getattr(self.server, "refresh_zendure_callback", None),
        )
        data = api_state.last_zendure.to_dict() if api_state.last_zendure else None
        self._send_json(data)
        return True

    def _handle_status(self, path: str, api_state: ApiState) -> bool:
        if path != API_PATH_STATUS:
            return False
        data = api_state.last_status.to_dict() if api_state.last_status else None
        self._send_json(data)
        return True

    def _handle_all(self, path: str, api_state: ApiState) -> bool:
        if path != API_PATH_ALL:
            return False
        response = {
            "p1": api_state.last_p1.to_dict() if api_state.last_p1 else None,
            "zendure": api_state.last_zendure.to_dict() if api_state.last_zendure else None,
            "status": api_state.last_status.to_dict() if api_state.last_status else None,
        }
        self._send_json(response)
        return True

    def do_GET(self):
        """
        Handle HTTP GET requests for the automation API server.

        Routes recognized API paths (such as /api/test, /api/wh_per_hour, /api/refresh, /api/automation_status, 
        /api/p1, /api/zendure, /api/status, and /api/all) to their respective handlers. 
        Returns JSON responses for successful API calls. If the API state is not initialized, 
        returns a 503 error with an explanation. Unrecognized paths result in a 404 error response.
        """
        parsed = urlparse(self.path)
        if self._handle_api_help(parsed):
            return
        if self._handle_test(parsed.path):
            return
        if self._handle_wh_per_hour(parsed.path):
            return
        if self._handle_status_updates_delta(parsed):
            return
        if self._handle_refresh(parsed.path):
            return
        if self._handle_restart(parsed.path):
            return
        if self._handle_pause_get(parsed):
            return
        if self._handle_loglevel_get(parsed):
            return
        api_state = getattr(self.server, "api_state", None)
        if api_state is None:
            self._send_json({"error": "API state not initialized"}, 503)
            return
        if self._handle_automation_status(parsed.path, api_state):
            return
        if self._handle_p1(parsed, api_state):
            return
        if self._handle_zendure(parsed, api_state):
            return
        if self._handle_status(parsed.path, api_state):
            return
        if self._handle_all(parsed.path, api_state):
            return
        self.send_error(404, "Not Found")

    def do_POST(self):
        """Handle HTTP POST requests for the automation API server."""
        parsed = urlparse(self.path)
        if self._handle_api_help(parsed):
            return
        if self._handle_restart(parsed.path):
            return
        if self._handle_pause_post(parsed):
            return
        if self._handle_loglevel_post(parsed):
            return
        self.send_error(404, "Not Found")

    def log_message(self, msg_format, *args):
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
        CREATE INDEX IF NOT EXISTS idx_status_updates_type_timestamp ON status_updates(type, timestamp);
    """

    def __init__(self, logger: Logger,
                 on_update: Optional[Callable[[str, Any, Any, Optional[int], int], None]] = None,
                 db_path: Optional[str] = None,
                 get_electric_level: Optional[Callable[[], Optional[int]]] = None,
                 retention_days: int = 7):
        """
        Initialize status API client (callback + SQLite only; no HTTP POST).

        Args:
            logger: Logger instance for error/warning messages.
            on_update: Optional callback(event_type, old_value, new_value, p1_total_power, timestamp) when posting.
            db_path: Optional path to SQLite DB for storing status updates.
            get_electric_level: Optional callable returning current battery % (0-100) for DB storage.
            retention_days: Days to retain rows in SQLite (default 7).
        """
        self.logger = logger
        self.on_update = on_update
        self.db_path = db_path
        self.get_electric_level = get_electric_level
        self.retention_days = retention_days
        self._db_initialized = False
        self._last_stored_change_power: Optional[float] = None
        self._last_stored_change_level: Optional[int] = None
        self._last_stored_change_timestamp: Optional[int] = None
        self._last_stored_change_hour_start_ts: Optional[int] = None

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
                self._load_change_insert_state(conn)
            self._db_initialized = True
        except Exception as e:
            self.logger.warning(f"Failed to initialize SQLite DB: {e}")

    def _parse_change_power(self, value: Any) -> Optional[float]:
        if value is None:
            return None
        parsed = value
        if isinstance(value, str):
            try:
                parsed = json.loads(value)
            except (json.JSONDecodeError, TypeError, ValueError):
                return None
        if isinstance(parsed, (int, float)):
            return float(parsed)
        if isinstance(parsed, str):
            try:
                return float(parsed.strip())
            except (TypeError, ValueError):
                return None
        return None

    def _hour_window_for_timestamp(self, timestamp: int) -> tuple[int, int]:
        tz = ZoneInfo(STATUS_TIMEZONE)
        dt = datetime.fromtimestamp(int(timestamp), tz=tz)
        hour_start = datetime(dt.year, dt.month, dt.day, dt.hour, 0, 0, tzinfo=tz)
        hour_start_ts = int(hour_start.timestamp())
        return hour_start_ts, hour_start_ts + 3600

    def _load_change_insert_state(self, conn: sqlite3.Connection) -> None:
        prev_row = conn.execute(
            "SELECT new_value, electric_level, timestamp FROM status_updates "
            "WHERE type = ? ORDER BY timestamp DESC, id DESC LIMIT 1",
            (EVENT_TYPE_CHANGE,),
        ).fetchone()
        if prev_row is None:
            self._last_stored_change_power = None
            self._last_stored_change_level = None
            self._last_stored_change_timestamp = None
            self._last_stored_change_hour_start_ts = None
            return

        self._last_stored_change_power = self._parse_change_power(prev_row[0])
        self._last_stored_change_level = int(prev_row[1]) if prev_row[1] is not None else None
        self._last_stored_change_timestamp = int(prev_row[2]) if prev_row[2] is not None else None
        if self._last_stored_change_timestamp is not None:
            self._last_stored_change_hour_start_ts, _ = self._hour_window_for_timestamp(
                self._last_stored_change_timestamp
            )
        else:
            self._last_stored_change_hour_start_ts = None

    def _should_store_change_row(
        self, new_value: Any, electric_level: Optional[int], timestamp: int
    ) -> bool:
        new_power = self._parse_change_power(new_value)
        if new_power is None:
            return True

        if self._last_stored_change_power is None and self._last_stored_change_timestamp is None:
            return True

        prev_power = self._last_stored_change_power
        prev_level = self._last_stored_change_level
        if prev_power is None:
            return True
        if new_power != prev_power:
            return True
        if electric_level != prev_level:
            return True

        hour_start_ts, _hour_end_ts = self._hour_window_for_timestamp(timestamp)
        return self._last_stored_change_hour_start_ts != hour_start_ts

    def _remember_stored_change_row(
        self, new_value: Any, electric_level: Optional[int], timestamp: int
    ) -> None:
        self._last_stored_change_power = self._parse_change_power(new_value)
        self._last_stored_change_level = electric_level
        self._last_stored_change_timestamp = int(timestamp)
        self._last_stored_change_hour_start_ts, _ = self._hour_window_for_timestamp(timestamp)

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
        should_insert = False
        try:
            with sqlite3.connect(self.db_path) as conn:
                should_insert = (
                    event_type != EVENT_TYPE_CHANGE
                    or self._should_store_change_row(new_value, electric_level, timestamp)
                )
                if should_insert:
                    conn.execute(
                        "INSERT INTO status_updates (type, old_value, new_value, p1_total_power, electric_level, timestamp) "
                        "VALUES (?, ?, ?, ?, ?, ?)",
                        (event_type, old_str, new_str, p1_total_power, electric_level, timestamp)
                    )
                    if event_type == EVENT_TYPE_CHANGE:
                        self._remember_stored_change_row(new_value, electric_level, timestamp)
                conn.execute(
                    "DELETE FROM status_updates WHERE timestamp < ?",
                    (int(timestamp) - (self.retention_days * 24 * 60 * 60),)
                )
                conn.commit()
            http_server = getattr(self, "http_server", None)
            if should_insert and http_server is not None and hasattr(http_server, "wh_per_hour_cache_lock"):
                with http_server.wh_per_hour_cache_lock:
                    http_server.wh_per_hour_cache = None
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
        timestamp = int(datetime.now(ZoneInfo(STATUS_TIMEZONE)).timestamp())
        if self.on_update:
            self.on_update(event_type, old_value, new_value, p1_total_power, timestamp)

        self._insert_status(event_type, old_value, new_value, p1_total_power, timestamp)
        return True


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
                 status_api: StatusApi, logger: Logger,
                 on_pause_change: Optional[Callable[[bool], None]] = None,
                 is_pause_active: Optional[Callable[[], bool]] = None,
                 dynamic_power_setter: Optional[Callable[[str], tuple[bool, Optional[int], Optional[str]]]] = None):
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
        self.on_pause_change = on_pause_change
        self.is_pause_active = is_pause_active
        self.dynamic_power_setter = dynamic_power_setter
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
            "pause": self._cmd_pause,
            "pauze": self._cmd_pause,
            "resume": self._cmd_resume,
            "unpause": self._cmd_resume,
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
        print("  pause on|off     - Pause automation (force 0) or resume schedule")
        print("  pause status     - Show pause override status")
        print("  resume, unpause  - Resume schedule control")
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
            self.logger.info(f"Unknown command: {cmd}. Type 'h' or 'help' for available commands.")
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
        self.logger.debug("Accumulator debug output has been removed.")
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
            self.logger.warning("Power command requires a value (e.g., 'p 500' or 'p netzero')")
            return True
        power_arg = args[0]
        try:
            if power_arg.lstrip("-").isdigit():
                power_value = int(power_arg)
            elif power_arg in [POWER_MODE_NETZERO, POWER_MODE_NETZERO_PLUS]:
                power_value = power_arg
            else:
                self.logger.warning(f"Invalid power value: {power_arg}")
                self.logger.info("Use an integer (e.g., 500) or 'netzero' or 'netzero+'")
                return True
            self.logger.info(f"Manually setting power to: {power_value}")
            if power_value in [POWER_MODE_NETZERO, POWER_MODE_NETZERO_PLUS]:
                success, actual_power, error = self._apply_dynamic_power(power_value)
                if success:
                    self.logger.info(f"Power set to: {actual_power}")
                    self.status_api.post_update(EVENT_TYPE_CHANGE, None, actual_power)
                else:
                    self.logger.error(f"Failed to set power: {error}")
            else:
                result = self.controller.set_power(power_value)
                if result.success:
                    self.logger.info(f"Power set to: {result.power}")
                    self.status_api.post_update(EVENT_TYPE_CHANGE, None, result.power)
                else:
                    self.logger.error(f"Failed to set power: {result.error}")
        except ValueError:
            self.logger.warning(f"Invalid power value: {power_arg}")
        return True

    def _cmd_zero(self, args: list) -> bool:
        self.logger.info("Setting power to 0")
        result = self.controller.set_power(0)
        if result.success:
            self.logger.info("Power set to 0")
            self.status_api.post_update(EVENT_TYPE_CHANGE, None, 0)
        else:
            self.logger.error(f"Failed to set power: {result.error}")
        return True

    def _cmd_netzero(self, args: list) -> bool:
        self.logger.info("Setting power to netzero")
        success, actual_power, error = self._apply_dynamic_power(POWER_MODE_NETZERO)
        if success:
            self.logger.info("Power set to netzero")
            self.status_api.post_update(EVENT_TYPE_CHANGE, None, actual_power)
        else:
            self.logger.error(f"Failed to set power: {error}")
        return True

    def _cmd_netzero_plus(self, args: list) -> bool:
        self.logger.info("Setting power to netzero+")
        success, actual_power, error = self._apply_dynamic_power(POWER_MODE_NETZERO_PLUS)
        if success:
            self.logger.info("Power set to netzero+")
            self.status_api.post_update(EVENT_TYPE_CHANGE, None, actual_power)
        else:
            self.logger.error(f"Failed to set power: {error}")
        return True

    def _cmd_quit(self, args: list) -> bool:
        self.logger.info("Quit command received")
        return False

    def _set_pause(self, active: bool) -> bool:
        if self.on_pause_change is None:
            self.logger.error("Pause override is not available in this runtime.")
            return False
        try:
            self.on_pause_change(active)
            return True
        except Exception as e:
            self.logger.error(f"Failed to change pause override: {e}")
            return False

    def _pause_status(self) -> Optional[bool]:
        if self.is_pause_active is None:
            return None
        try:
            return bool(self.is_pause_active())
        except Exception:
            return None

    def _cmd_pause(self, args: list) -> bool:
        if not args:
            status = self._pause_status()
            if status is None:
                self.logger.warning("Pause override status unavailable.")
            else:
                self.logger.info(f"Pause override is {'ON' if status else 'OFF'}.")
            self.logger.info("Usage: pause on|off|status")
            return True

        action = str(args[0]).strip().lower()
        if action in ("on", "1", "true", "start"):
            self._set_pause(True)
            return True
        if action in ("off", "0", "false", "stop"):
            self._set_pause(False)
            return True
        if action == "status":
            status = self._pause_status()
            if status is None:
                self.logger.warning("Pause override status unavailable.")
            else:
                self.logger.info(f"Pause override is {'ON' if status else 'OFF'}.")
            return True

        self.logger.warning("Invalid pause command. Use: pause on|off|status")
        return True

    def _cmd_resume(self, args: list) -> bool:
        self._set_pause(False)
        return True

    def _apply_dynamic_power(self, mode: str) -> tuple[bool, Optional[int], Optional[str]]:
        if self.dynamic_power_setter is None:
            return False, None, "Dynamic power setter is not configured"
        return self.dynamic_power_setter(mode)


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
        self.pause_override_active = False
        self.last_p1_total_power: Optional[int] = None  # last P1 meter total power (W) for status API
        self.stop_posted = False
        self.loop_interval_seconds = LOOP_INTERVAL_SECONDS
        self.steps = self._generate_steps(self.loop_interval_seconds, 59)
        self.api_refresh_interval_seconds = API_REFRESH_INTERVAL_SECONDS
        self.zero_count_threshold_standby = ZERO_COUNT_THRESHOLD_STANDBY
        self._runtime_condition_warning_cache: set[str] = set()
        self._last_runtime_decision_signature: Optional[str] = None


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
            self.input_handler = InputHandler()
            self.command_handler = CommandHandler(
                self.controller,
                self.schedule_controller,
                self.status_api,
                self.logger,
                on_pause_change=self._set_pause_override,
                is_pause_active=lambda: self.pause_override_active,
                dynamic_power_setter=self._apply_dynamic_power_command,
            )

            # Set up signal handlers
            signal.signal(signal.SIGTERM, self._signal_handler)
            signal.signal(signal.SIGINT, self._signal_handler)

            self._load_loop_config()
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
            zero_threshold = int(self.controller.config.get("ZERO_COUNT_THRESHOLD_STANDBY", ZERO_COUNT_THRESHOLD_STANDBY))
        except (TypeError, ValueError):
            zero_threshold = ZERO_COUNT_THRESHOLD_STANDBY
        self.zero_count_threshold_standby = max(1, min(zero_threshold, 100))

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
            self.http_server = AutomationTCPServer(("", HTTP_API_PORT), ApiTestHandler)
            self.http_server.api_state = self.api_state
            self.http_server.db_path = self.status_api.db_path
            self.http_server.schedule_controller = self.schedule_controller
            self.http_server.status_api = self.status_api
            self.status_api.http_server = self.http_server
            self.http_server.refresh_p1_callback = self._refresh_p1_for_api
            self.http_server.refresh_zendure_callback = self._refresh_zendure_for_api
            self.http_server.restart_callback = self.request_restart
            self.http_server.pause_getter = lambda: self.pause_override_active
            self.http_server.pause_setter = self._set_pause_override
            self.http_server.controller = self.controller
            token_cfg = self.schedule_controller.config.get("statusUpdatesDeltaApiToken", "")
            env_token = os.getenv("STATUS_UPDATES_DELTA_API_TOKEN", "")
            token = str(token_cfg).strip() if token_cfg is not None else ""
            if not token and env_token is not None:
                token = str(env_token).strip()
            self.http_server.status_updates_delta_token = token or None
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
            return power_meter_reader.read()
        except Exception as e:
            self.logger.warning(f"Failed to read power meter: {e}")
            return None

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

    def _apply_dynamic_power_command(self, mode: str) -> tuple[bool, Optional[int], Optional[str]]:
        p1_data = self._accumulate_p1_data()
        if p1_data is None:
            return False, None, "Failed to read P1 meter data"

        self._update_p1_state(p1_data)
        result = self.controller.set_power(mode, p1_data=p1_data)
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
                self.status_api.post_update(EVENT_TYPE_RESCAN, None, None, p1_total_power=self.last_p1_total_power)
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
            self.status_api.post_update(EVENT_TYPE_CHANGE, None, 0, p1_total_power=p1_w)
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
                    f"Runtime conditions failed for slot {slot_time}, but fallback_value is missing/invalid; using default fallback 0"
                )
                self._last_runtime_decision_signature = signature
            return fallback_value

        signature = f"{slot_time}|{desired_power}|fallback:{fallback_value}|{electricity_level}"
        if self._last_runtime_decision_signature != signature:
            self.logger.info(
                f"Runtime conditions failed for slot {slot_time} (electricity_level={electricity_level}); "
                f"using fallback_value {fallback_value} instead of base value {desired_power}"
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
            self.status_api.post_update(
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
        start_time = time.time()

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
                        self.status_api.post_update(EVENT_TYPE_STOP, self.value, None, p1_total_power=self.last_p1_total_power)
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
        # Show test mode prominently on startup (controlled via config.jsonc key: TEST_MODE).
        if getattr(self.controller, "test_mode", False):
            self.logger.warning(
                "TEST MODE: ON (no commands wil be send to the Zendure device)"
            )
        else:
            self.logger.info("TEST MODE: OFF")
        self.logger.info(f"   Loop interval: {self.loop_interval_seconds} seconds")
        self.logger.info(
            "   API refresh interval: "
            f"{self.api_refresh_interval_seconds} seconds "
            f"({self.api_refresh_interval_seconds // 60} minutes)"
        )
        self.logger.info("   Type 'h' or 'help' for available keyboard commands")
        print()

    def _update_p1_state(self, p1_data: Optional[dict]) -> None:
        if p1_data is None:
            return
        self.api_state.last_p1 = P1Readings(
            readings=p1_data,
            timestamp=int(time.time()),
        )
        total_power = p1_data.get("total_power")
        if total_power is None:
            return
        try:
            self.last_p1_total_power = int(total_power)
        except (TypeError, ValueError):
            pass

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

    def _run_cycle(self) -> bool:
        # 0. Sleep
        self._sleep_interrupted()
        if self.shutdown_requested:
            return False

        # 1. Accumulate Power Meter Data
        p1_data = self._accumulate_p1_data() # reads the configured power meter via the API
        self._update_p1_state(p1_data)       # updates self.last_p1_total_power

        # 2. Check input
        if not self._handle_user_input() or self.shutdown_requested:
            return False

        # 3. Schedule Logic
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
        return True

    def run(self) -> int:
        """Main execution method."""
        if not self.initialize():
            return 1

        self.status_api.post_update(EVENT_TYPE_START)
        self._log_startup()

        # Start HTTP API server
        self._start_http_server()

        try:
            while not self.shutdown_requested:
                if not self._run_cycle():
                    break
        except KeyboardInterrupt:
            self.shutdown_requested = True
        except Exception as e:
            self.logger.error(f"Fatal error in main loop: {e}")
            if self.status_api:
                self.stop_posted = True
                self.status_api.post_update(EVENT_TYPE_STOP, self.value, None, p1_total_power=self.last_p1_total_power)
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
