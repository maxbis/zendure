#!/usr/bin/env python3
"""
Dump all rows from the status_updates SQLite database.
Usage: python dump_status_updates.py [db_path]
  db_path: Optional path to status_updates.db (default: ./data/status_updates.db)
"""

import json
import os
import sqlite3
import sys

from config_loader import load_config
from datetime import datetime
from zoneinfo import ZoneInfo

DEFAULT_DB = os.path.join(os.path.dirname(__file__), "data", "status_updates.db")
ENERGY_TIMEZONE = "Europe/Amsterdam"


def load_db_path() -> str:
    """Load DB path from automate/config/config.jsonc if available."""
    config_paths = [
        os.path.join(os.path.dirname(__file__), "config", "config.jsonc"),
    ]
    for p in config_paths:
        if os.path.exists(p):
            try:
                cfg = load_config(p)
                data_dir = cfg.get("dataDir", "./data/")
                base = data_dir.rstrip("/").rstrip("\\")
                return os.path.join(base, "status_updates.db")
            except (ValueError, OSError):
                pass
    return DEFAULT_DB


def _parse_numeric_json(value):
    """Parse JSON-encoded numeric values from SQLite TEXT columns."""
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
    return None


def _accumulate_segment_by_day(day_totals, tz, t_start, t_end, power_w):
    """Accumulate one [t_start, t_end) segment into per-day charged/discharged totals."""
    if t_end <= t_start or power_w == 0:
        return
    cur = t_start
    while cur < t_end:
        dt_cur = datetime.fromtimestamp(cur, tz=tz)
        day_start = datetime(dt_cur.year, dt_cur.month, dt_cur.day, tzinfo=tz)
        next_day_start = day_start.timestamp() + 24 * 60 * 60
        seg_end = min(t_end, int(next_day_start))
        seconds = seg_end - cur
        if seconds > 0:
            date_key = day_start.strftime("%Y-%m-%d")
            bucket = day_totals.setdefault(
                date_key, {"charged_wh": 0.0, "discharged_wh": 0.0}
            )
            wh = abs(power_w) * seconds / 3600
            if power_w > 0:
                bucket["charged_wh"] += wh
            else:
                bucket["discharged_wh"] += wh
        cur = seg_end


def calculate_energy_totals(rows):
    """
    Calculate charged/discharged energy per day and overall from status_updates rows.
    Mirrors automate_www.py step integration by using type='change' and numeric new_value.
    """
    if not rows:
        return {}, {"charged_wh": 0.0, "discharged_wh": 0.0}

    points = []
    for row in rows:
        if row["type"] != "change" or row["new_value"] is None:
            continue
        ts = row["timestamp"]
        if ts is None:
            continue
        power = _parse_numeric_json(row["new_value"])
        if power is None:
            continue
        points.append((int(ts), power, int(row["id"])))

    if not points:
        return {}, {"charged_wh": 0.0, "discharged_wh": 0.0}

    points.sort(key=lambda p: (p[0], p[2]))
    tz = ZoneInfo(ENERGY_TIMEZONE)
    last_ts = max(int(r["timestamp"]) for r in rows if r["timestamp"] is not None)

    day_totals = {}
    for idx, (t_start, power, _) in enumerate(points):
        if idx < len(points) - 1:
            t_end = points[idx + 1][0]
        else:
            # For dump/reporting, close the final segment at the latest timestamp in the dataset.
            t_end = last_ts
        _accumulate_segment_by_day(day_totals, tz, t_start, t_end, power)

    overall = {"charged_wh": 0.0, "discharged_wh": 0.0}
    for bucket in day_totals.values():
        overall["charged_wh"] += bucket["charged_wh"]
        overall["discharged_wh"] += bucket["discharged_wh"]
    return day_totals, overall


def print_energy_totals(rows) -> None:
    """Print day totals and overall totals in Wh and kWh."""
    day_totals, overall = calculate_energy_totals(rows)
    print("\nEnergy Totals (from change/new_value step integration):")
    if not day_totals:
        print("(no numeric 'change' points found)")
        return

    for day in sorted(day_totals.keys()):
        charged_wh = day_totals[day]["charged_wh"]
        discharged_wh = day_totals[day]["discharged_wh"]
        print(
            f"{day} charged_wh={charged_wh:.2f} discharged_wh={discharged_wh:.2f} "
            f"charged_kWh={charged_wh / 1000:.4f} discharged_kWh={discharged_wh / 1000:.4f}"
        )

    print(
        f"OVERALL charged_wh={overall['charged_wh']:.2f} "
        f"discharged_wh={overall['discharged_wh']:.2f} "
        f"charged_kWh={overall['charged_wh'] / 1000:.4f} "
        f"discharged_kWh={overall['discharged_wh'] / 1000:.4f}"
    )


def dump(db_path: str) -> None:
    if not os.path.exists(db_path):
        print(f"Error: Database not found: {db_path}")
        sys.exit(1)
    conn = sqlite3.connect(db_path)
    conn.row_factory = sqlite3.Row
    cur = conn.execute("SELECT * FROM status_updates ORDER BY id")
    rows = cur.fetchall()
    conn.close()
    print(f"Database: {db_path}")
    print(f"Rows: {len(rows)}\n")
    if not rows:
        print("(no rows)")
        return
    for row in rows:
        d = dict(row)
        ts = d.get("timestamp")
        if ts is not None:
            try:
                dt = datetime.fromtimestamp(int(ts))
                d["timestamp_readable"] = dt.strftime("%Y-%m-%d %H:%M:%S")
            except (ValueError, OSError):
                d["timestamp_readable"] = str(ts)
        print(json.dumps(d, default=str))
    print_energy_totals(rows)


def main() -> None:
    db_path = sys.argv[1] if len(sys.argv) > 1 else load_db_path()
    dump(db_path)


if __name__ == "__main__":
    main()
