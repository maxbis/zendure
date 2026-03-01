#!/usr/bin/env python3
"""
Test script: run the same SQL queries as the /api/wh_per_hour API in automate_www.py.

Usage:
  python tests/test_wh_per_hour_queries.py [path/to/status_updates.db]
  python tests/test_wh_per_hour_queries.py   # uses automate/data/status_updates.db from repo root

Outputs raw rows from:
  1) Change points (type='change', new_value, timestamp) - used for Wh integration
  2) Electric levels (timestamp, electric_level) - used for battery % per hour
"""

from __future__ import annotations

import json
import os
import sqlite3
import sys
from datetime import datetime
from zoneinfo import ZoneInfo

# Same constant as automate_www.py
EVENT_TYPE_CHANGE = "change"

# Same queries as in automate_www.py: _load_change_points and _load_electric_levels_by_hour
QUERY_CHANGE_POINTS = """
    SELECT new_value, timestamp FROM status_updates
    WHERE type = ? AND new_value IS NOT NULL
"""
QUERY_ELECTRIC_LEVELS = """
    SELECT timestamp, electric_level FROM status_updates
    WHERE timestamp IS NOT NULL AND electric_level IS NOT NULL
    ORDER BY timestamp ASC
"""


def main() -> int:
    repo_root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    default_db = os.path.join(repo_root, "automate", "data", "status_updates.db")

    db_path = sys.argv[1] if len(sys.argv) > 1 else default_db
    if not os.path.exists(db_path):
        print(f"Error: DB not found: {db_path}", file=sys.stderr)
        return 2

    try:
        conn = sqlite3.connect(db_path)
        conn.row_factory = sqlite3.Row
    except sqlite3.Error as e:
        print(f"Error: cannot open database: {e}", file=sys.stderr)
        return 2

    try:
        # 1) Change points (same query as _load_change_points)
        print("== Change points (type='change', for Wh integration) ==")
        print(QUERY_CHANGE_POINTS.strip(), "(?", repr(EVENT_TYPE_CHANGE), ")")
        cur = conn.execute(QUERY_CHANGE_POINTS, (EVENT_TYPE_CHANGE,))
        rows = cur.fetchall()
        print(f"Rows: {len(rows)}\n")
        for row in rows:
            nv_raw, ts = row[0], row[1]
            # Parse like API: new_value can be JSON number
            try:
                nv = json.loads(nv_raw) if isinstance(nv_raw, str) else nv_raw
            except (json.JSONDecodeError, TypeError, ValueError):
                nv = nv_raw
            print(f"  timestamp={ts}, new_value={nv_raw!r} -> parsed={nv}")

        # 2) Electric levels (same query as _load_electric_levels_by_hour)
        print("\n== Electric levels (for battery % per hour) ==")
        print(QUERY_ELECTRIC_LEVELS.strip())
        cur = conn.execute(QUERY_ELECTRIC_LEVELS)
        rows = cur.fetchall()
        print(f"Rows: {len(rows)}\n")
        tz = ZoneInfo("Europe/Amsterdam")
        for row in rows:
            ts, level = row[0], row[1]
            dt = datetime.fromtimestamp(ts, tz=tz) if ts is not None else None
            date_hour = dt.strftime("%Y-%m-%d %H:00") if dt else "?"
            print(f"  timestamp={ts}, electric_level={level}, date_hour={date_hour}")

        return 0
    except sqlite3.Error as e:
        print(f"SQLite error: {e}", file=sys.stderr)
        return 2
    finally:
        conn.close()


if __name__ == "__main__":
    sys.exit(main())
