"""
SQLite persistence for automation status_updates: schema, retention, Wh/h queries, delta reads.
"""

from __future__ import annotations

import json
import os
import sqlite3
from datetime import datetime, timedelta
from typing import Any, Callable, Optional
from zoneinfo import ZoneInfo

from config_loader import load_system_config


_SYSTEM_TIMEZONE = load_system_config()["installation"]["timezone"]

# Wh-per-hour API: timezone and default days
WH_PER_HOUR_TIMEZONE = _SYSTEM_TIMEZONE
WH_PER_HOUR_DAYS_DEFAULT = 3
WH_PER_HOUR_DAYS_MAX = 30
WH_PER_HOUR_LAST_SEGMENT_MAX_SECONDS = 3600  # 1 hour

# Shared timezone for status timestamps
STATUS_TIMEZONE = _SYSTEM_TIMEZONE

EVENT_TYPE_START = "start"
EVENT_TYPE_STOP = "stop"
EVENT_TYPE_CHANGE = "change"
EVENT_TYPE_RESCAN = "Rescan"

_SCHEMA = """
    CREATE TABLE IF NOT EXISTS status_updates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT NOT NULL,
        old_value TEXT,
        new_value TEXT,
        p1_total_power INTEGER,
        electric_level INTEGER,
        total_act_x100 INTEGER,
        total_act_ret_x100 INTEGER,
        timestamp INTEGER NOT NULL
    );
    CREATE INDEX IF NOT EXISTS idx_status_updates_timestamp ON status_updates(timestamp);
    CREATE INDEX IF NOT EXISTS idx_status_updates_type_timestamp ON status_updates(type, timestamp);
"""

_OPTIONAL_COLUMNS = {
    "total_act_x100": "INTEGER",
    "total_act_ret_x100": "INTEGER",
}


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
    store = StatusUpdatesStore(
        db_path=db_path,
        retention_days=0,
        log_warning=lambda _msg: None,
    )
    return store.compute_wh_per_hour(now=now, days_back=days_back)


class StatusUpdatesStore:
    """SQLite access for status_updates: schema, inserts with change dedup, retention, delta reads."""

    def __init__(
        self,
        db_path: Optional[str],
        retention_days: int,
        log_warning: Callable[[str], None],
    ) -> None:
        self.db_path = db_path
        self.retention_days = retention_days
        self._log_warning = log_warning
        self._db_initialized = False
        self._last_stored_change_power: Optional[float] = None
        self._last_stored_change_level: Optional[int] = None
        self._last_stored_change_timestamp: Optional[int] = None
        self._last_stored_change_hour_start_ts: Optional[int] = None

    def compute_wh_per_hour(self, now: int, days_back: int = WH_PER_HOUR_DAYS_DEFAULT) -> dict:
        """
        Compute watt-hours charged and discharged per calendar hour from status_updates SQLite.
        Uses step integration: power constant between consecutive readings.
        Returns { "YYYY-MM-DD": [ {"hour": "HH", "charged_wh": float, "discharged_wh": float,
                                   "electric_level": int|null}, ... ], ... }
        for the last days_back full days including today. Hours are ordered 00, 01, ... 23.
        """
        tz = ZoneInfo(WH_PER_HOUR_TIMEZONE)
        allowed_dates = _allowed_wh_dates(now=now, days_back=days_back, tz=tz)
        window_start_ts = _wh_window_start_ts(now=now, days_back=days_back, tz=tz)
        if not self.db_path:
            return _empty_wh_result(allowed_dates)
        if not os.path.exists(self.db_path):
            self._log_warning(f"Status updates database not found for wh_per_hour: {self.db_path}")
            return _empty_wh_result(allowed_dates)
        allowed_dates_set = set(allowed_dates)

        try:
            electric_levels_by_date_hour = _load_electric_levels_by_hour(
                db_path=self.db_path,
                allowed_dates=allowed_dates_set,
                tz=tz,
                window_start_ts=window_start_ts,
            )
        except Exception as e:
            self._log_warning(f"Failed to load electric levels for wh_per_hour from SQLite: {e}")
            electric_levels_by_date_hour = {}

        try:
            points = _load_change_points(self.db_path, window_start_ts=window_start_ts, tz=tz)
        except Exception as e:
            self._log_warning(f"Failed to load change points for wh_per_hour from SQLite: {e}")
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

    def ensure_db(self) -> None:
        if not self.db_path or self._db_initialized:
            return
        try:
            db_dir = os.path.dirname(self.db_path)
            if db_dir:
                os.makedirs(db_dir, exist_ok=True)
            with sqlite3.connect(self.db_path) as conn:
                conn.executescript(_SCHEMA)
                self._ensure_optional_columns(conn)
                self._load_change_insert_state(conn)
            self._db_initialized = True
        except Exception as e:
            self._log_warning(f"Failed to initialize SQLite DB: {e}")

    def _ensure_optional_columns(self, conn: sqlite3.Connection) -> None:
        columns = {
            str(row[1])
            for row in conn.execute("PRAGMA table_info(status_updates)").fetchall()
        }
        for column_name, column_type in _OPTIONAL_COLUMNS.items():
            if column_name in columns:
                continue
            conn.execute(
                f"ALTER TABLE status_updates ADD COLUMN {column_name} {column_type}"
            )

    @staticmethod
    def _normalize_counter_x100(value: Any) -> Optional[int]:
        if value is None:
            return None
        try:
            return int(round(float(value) * 100))
        except (TypeError, ValueError):
            return None

    @staticmethod
    def _counter_x100_to_decimal(value: Any) -> Optional[float]:
        if value is None:
            return None
        try:
            return int(value) / 100.0
        except (TypeError, ValueError):
            return None

    @staticmethod
    def _parse_change_power(value: Any) -> Optional[float]:
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

    def insert_status(
        self,
        event_type: str,
        old_value: Any,
        new_value: Any,
        p1_total_power: Optional[int],
        electric_level: Optional[int],
        total_act_x100: Optional[int],
        total_act_ret_x100: Optional[int],
        timestamp: int,
    ) -> bool:
        """Persist one row. Returns True if a row was inserted."""
        if not self.db_path:
            return False
        self.ensure_db()
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
                        "INSERT INTO status_updates (type, old_value, new_value, p1_total_power, electric_level, total_act_x100, total_act_ret_x100, timestamp) "
                        "VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                        (
                            event_type,
                            old_str,
                            new_str,
                            p1_total_power,
                            electric_level,
                            total_act_x100,
                            total_act_ret_x100,
                            timestamp,
                        ),
                    )
                    if event_type == EVENT_TYPE_CHANGE:
                        self._remember_stored_change_row(new_value, electric_level, timestamp)
                conn.commit()
        except Exception as e:
            self._log_warning(f"Failed to write status update to SQLite: {e}")
            return False
        return should_insert

    def cleanup_old_rows(self, now_ts: int) -> bool:
        if not self.db_path:
            return False
        self.ensure_db()
        try:
            cutoff_ts = int(now_ts) - (self.retention_days * 24 * 60 * 60)
            with sqlite3.connect(self.db_path) as conn:
                conn.execute(
                    "DELETE FROM status_updates WHERE timestamp < ?",
                    (cutoff_ts,),
                )
                conn.commit()
            return True
        except Exception as e:
            self._log_warning(f"Failed to clean up old SQLite status rows: {e}")
            return False

    def fetch_status_updates_delta(self, after_id: int, limit: int) -> dict[str, Any]:
        """Match /api/status_updates_delta JSON shape: rows, max_id_returned, has_more."""
        if not self.db_path:
            raise ValueError("no db_path")
        self.ensure_db()
        with sqlite3.connect(self.db_path) as conn:
            conn.row_factory = sqlite3.Row
            cur = conn.execute(
                "SELECT id, type, old_value, new_value, p1_total_power, electric_level, total_act_x100, total_act_ret_x100, timestamp "
                "FROM status_updates WHERE id > ? ORDER BY id ASC LIMIT ?",
                (after_id, limit + 1),
            )
            fetched = cur.fetchall()
        has_more = len(fetched) > limit
        selected = fetched[:limit]
        rows = []
        for row in selected:
            payload = dict(row)
            payload["total_act"] = self._counter_x100_to_decimal(payload.pop("total_act_x100", None))
            payload["total_act_ret"] = self._counter_x100_to_decimal(payload.pop("total_act_ret_x100", None))
            rows.append(payload)
        max_id_returned = after_id if not rows else int(rows[-1]["id"])
        return {"rows": rows, "max_id_returned": max_id_returned, "has_more": has_more}


class StatusApi:
    """
    Runtime-facing status updates: in-memory callback + SQLite via StatusUpdatesStore.
    """

    def __init__(
        self,
        logger: Any,
        on_update: Optional[Callable[[str, Any, Any, Optional[int], int], None]] = None,
        db_path: Optional[str] = None,
        get_electric_level: Optional[Callable[[], Optional[int]]] = None,
        retention_days: int = 7,
    ) -> None:
        self.logger = logger
        self.on_update = on_update
        self.get_electric_level = get_electric_level
        self.db_path = db_path
        self.retention_days = retention_days  # mirrored for callers inspecting config
        self.store = StatusUpdatesStore(
            db_path=db_path,
            retention_days=retention_days,
            log_warning=lambda msg: logger.warning(msg),
        )
        self.http_server = None

    def cleanup_old_rows(self, now_ts: int) -> bool:
        return self.store.cleanup_old_rows(now_ts)

    def compute_wh_per_hour(self, now: int, days_back: int = WH_PER_HOUR_DAYS_DEFAULT) -> dict:
        return self.store.compute_wh_per_hour(now=now, days_back=days_back)

    def post_update(
        self,
        event_type: str,
        old_value: Any = None,
        new_value: Any = None,
        p1_total_power: Optional[int] = None,
        total_act: Any = None,
        total_act_ret: Any = None,
    ) -> bool:
        timestamp = int(datetime.now(ZoneInfo(STATUS_TIMEZONE)).timestamp())
        if self.on_update:
            self.on_update(event_type, old_value, new_value, p1_total_power, timestamp)

        electric_level = None
        if self.get_electric_level:
            try:
                electric_level = self.get_electric_level()
            except Exception:
                pass

        should_insert = self.store.insert_status(
            event_type,
            old_value,
            new_value,
            p1_total_power,
            electric_level,
            self.store._normalize_counter_x100(total_act),
            self.store._normalize_counter_x100(total_act_ret),
            timestamp,
        )
        http_server = getattr(self, "http_server", None)
        if should_insert and http_server is not None and hasattr(http_server, "wh_per_hour_cache_lock"):
            with http_server.wh_per_hour_cache_lock:
                http_server.wh_per_hour_cache = None
        return True
