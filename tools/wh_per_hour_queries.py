#!/usr/bin/env python3
"""
Compute per-hour charged_wh, discharged_wh, and electric_level the same way as
the /api/wh_per_hour API (Energy per Hour partial).

Uses the same SQL queries and integration logic as automate_www.py.
Each segment is capped at 1 hour so we don't extrapolate one reading for days.

Usage:
  python tools/wh_per_hour_queries.py [path/to/status_updates.db]
  python tools/wh_per_hour_queries.py --days 5
  python tools/wh_per_hour_queries.py --profile
  python tools/wh_per_hour_queries.py   # uses automate/data/status_updates.db, 3 days

Output: JSON in API shape, then grand totals (charged positive, discharged negative).
"""

from __future__ import annotations

import json
import os
import sqlite3
import sys
import time
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo

# Same as automate_www.py
EVENT_TYPE_CHANGE = "change"
WH_PER_HOUR_TIMEZONE = "Europe/Amsterdam"
WH_PER_HOUR_DAYS_DEFAULT = 3
# Cap each segment so we don't extrapolate one power reading for days
LAST_SEGMENT_MAX_SECONDS = 3600  # 1 hour

QUERY_CHANGE_POINTS = """
    SELECT new_value, timestamp FROM status_updates
    WHERE type = ? AND new_value IS NOT NULL
"""
QUERY_ELECTRIC_LEVELS = """
    SELECT timestamp, electric_level FROM status_updates
    WHERE timestamp IS NOT NULL AND electric_level IS NOT NULL
    ORDER BY timestamp ASC
"""


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


def _load_change_points(db_path: str, window_start_ts: int) -> list[tuple[int, float]]:
    points: list[tuple[int, float]] = []
    with sqlite3.connect(db_path) as conn:
        seed_cur = conn.execute(
            """
            SELECT new_value, timestamp FROM status_updates
            WHERE type = ? AND new_value IS NOT NULL AND timestamp < ?
            ORDER BY timestamp DESC
            LIMIT 1
            """,
            (EVENT_TYPE_CHANGE, window_start_ts),
        )
        rows = seed_cur.fetchall()
        cur = conn.execute(
            """
            SELECT new_value, timestamp FROM status_updates
            WHERE type = ? AND new_value IS NOT NULL AND timestamp >= ?
            ORDER BY timestamp ASC
            """,
            (EVENT_TYPE_CHANGE, window_start_ts),
        )
        rows.extend(cur.fetchall())
        for nv_raw, ts in rows:
            if ts is None:
                continue
            try:
                nv = json.loads(nv_raw) if isinstance(nv_raw, str) else nv_raw
            except (json.JSONDecodeError, TypeError, ValueError):
                continue
            if isinstance(nv, (int, float)):
                points.append((int(ts), float(nv)))
    return points


def _load_electric_levels_by_hour(
    db_path: str, allowed_dates: set[str], tz: ZoneInfo, window_start_ts: int
) -> dict[str, dict[str, int]]:
    levels_by_date_hour: dict[str, dict[str, int]] = {}
    with sqlite3.connect(db_path) as conn:
        cur = conn.execute(
            """
            SELECT timestamp, electric_level FROM status_updates
            WHERE timestamp IS NOT NULL AND electric_level IS NOT NULL
              AND timestamp >= ?
            ORDER BY timestamp ASC
            """,
            (window_start_ts,),
        )
        for ts_raw, level_raw in cur.fetchall():
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
    points: list[tuple[int, float]],
    now: int,
    allowed_dates: set[str],
    tz: ZoneInfo,
    segment_max_seconds: int | None = LAST_SEGMENT_MAX_SECONDS,
) -> dict[str, dict[str, dict[str, float]]]:
    wh_by_date_hour: dict[str, dict[str, dict[str, float]]] = {}
    for idx, (t_start, power) in enumerate(points):
        if idx < len(points) - 1:
            t_end = points[idx + 1][0]
        else:
            t_end = now
        if segment_max_seconds is not None:
            t_end = min(t_end, t_start + segment_max_seconds)
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


def compute_wh_per_hour(
    db_path: str, now: int, days_back: int = WH_PER_HOUR_DAYS_DEFAULT
) -> dict:
    """Per-hour charged_wh, discharged_wh, electric_level (each segment capped at 1h)."""
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
        points = _load_change_points(db_path, window_start_ts=window_start_ts)
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
    return _build_wh_result(
        wh_by_date_hour, electric_levels_by_date_hour, allowed_dates
    )


def profile_wh_per_hour(
    db_path: str, now: int, days_back: int = WH_PER_HOUR_DAYS_DEFAULT
) -> tuple[dict, dict[str, object]]:
    tz = ZoneInfo(WH_PER_HOUR_TIMEZONE)
    timings: dict[str, float] = {}
    started = time.perf_counter()

    t0 = time.perf_counter()
    allowed_dates = _allowed_wh_dates(now=now, days_back=days_back, tz=tz)
    allowed_dates_set = set(allowed_dates)
    window_start_ts = _wh_window_start_ts(now=now, days_back=days_back, tz=tz)
    timings["allowed_dates"] = time.perf_counter() - t0

    if not os.path.exists(db_path):
        timings["total"] = time.perf_counter() - started
        return _empty_wh_result(allowed_dates), {
            "db_path": db_path,
            "allowed_dates": allowed_dates,
            "timings": timings,
            "electric_hours": 0,
            "change_points": 0,
            "integrated_hours": 0,
        }

    t0 = time.perf_counter()
    try:
        electric_levels_by_date_hour = _load_electric_levels_by_hour(
            db_path=db_path, allowed_dates=allowed_dates_set, tz=tz, window_start_ts=window_start_ts
        )
    except Exception:
        electric_levels_by_date_hour = {}
    timings["load_electric_levels"] = time.perf_counter() - t0

    t0 = time.perf_counter()
    try:
        points = _load_change_points(db_path, window_start_ts=window_start_ts)
    except Exception:
        points = []
    timings["load_change_points"] = time.perf_counter() - t0

    t0 = time.perf_counter()
    wh_by_date_hour: dict[str, dict[str, dict[str, float]]] = {}
    if points:
        wh_by_date_hour = _integrate_wh_points(
            points=points,
            now=now,
            allowed_dates=allowed_dates_set,
            tz=tz,
        )
    timings["integrate_wh_points"] = time.perf_counter() - t0

    t0 = time.perf_counter()
    result = _build_wh_result(
        wh_by_date_hour, electric_levels_by_date_hour, allowed_dates
    )
    timings["build_result"] = time.perf_counter() - t0
    timings["total"] = time.perf_counter() - started

    electric_hours = sum(len(hours) for hours in electric_levels_by_date_hour.values())
    integrated_hours = sum(len(hours) for hours in wh_by_date_hour.values())
    return result, {
        "db_path": db_path,
        "allowed_dates": allowed_dates,
        "timings": timings,
        "electric_hours": electric_hours,
        "change_points": len(points),
        "integrated_hours": integrated_hours,
    }


def main() -> int:
    repo_root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    default_db = os.path.join(repo_root, "automate", "data", "status_updates.db")

    argv = sys.argv[1:]
    opt_days = WH_PER_HOUR_DAYS_DEFAULT
    opt_profile = False
    positional = []
    i = 0
    while i < len(argv):
        if argv[i] == "--days" and i + 1 < len(argv):
            try:
                opt_days = int(argv[i + 1])
            except ValueError:
                pass
            i += 2
            continue
        if argv[i] == "--profile":
            opt_profile = True
            i += 1
            continue
        positional.append(argv[i])
        i += 1

    db_path = positional[0] if positional else default_db
    if not os.path.exists(db_path):
        print(f"Error: DB not found: {db_path}", file=sys.stderr)
        return 2

    now = int(time.time())
    profile = None
    if opt_profile:
        result, profile = profile_wh_per_hour(db_path, now, days_back=opt_days)
    else:
        result = compute_wh_per_hour(db_path, now, days_back=opt_days)
    print(json.dumps(result, indent=2))

    total_charged = 0.0
    total_discharged_magnitude = 0.0
    for date_hours in result.values():
        for row in date_hours:
            total_charged += float(row.get("charged_wh", 0) or 0)
            total_discharged_magnitude += float(row.get("discharged_wh", 0) or 0)
    print("\nGrand totals (charged positive, discharged negative):")
    print(f"  charged_wh:   +{total_charged:.2f}")
    print(f"  discharged_wh: -{total_discharged_magnitude:.2f}")
    print(f"  net_wh:       {total_charged - total_discharged_magnitude:+.2f}")
    if profile is not None:
        print("\nProfile:")
        print(f"  db_path:         {profile['db_path']}")
        print(f"  allowed_dates:   {', '.join(profile['allowed_dates'])}")
        print(f"  change_points:   {profile['change_points']}")
        print(f"  electric_hours:  {profile['electric_hours']}")
        print(f"  integrated_hours:{profile['integrated_hours']}")
        for key, value in profile["timings"].items():
            print(f"  {key:18} {value * 1000:9.2f} ms")
    return 0


if __name__ == "__main__":
    sys.exit(main())
