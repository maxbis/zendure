#!/usr/bin/env python3
"""
Print the last 10 rows from the status_updates SQLite database.

Usage:
  python tools/list_last_status_updates.py
  python tools/list_last_status_updates.py /path/to/status_updates.db
"""

from __future__ import annotations

import os
import sqlite3
import sys
from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo


REPO_ROOT = Path(__file__).resolve().parents[1]
AUTOMATE_DIR = REPO_ROOT / "automate"
sys.path.insert(0, str(AUTOMATE_DIR))

from config_loader import load_config  # noqa: E402


ENERGY_TIMEZONE = "Europe/Amsterdam"
DEFAULT_DB = AUTOMATE_DIR / "data" / "status_updates.db"


def load_db_path() -> str:
    config_path = AUTOMATE_DIR / "config" / "config.jsonc"
    if config_path.exists():
        try:
            cfg = load_config(config_path)
            data_dir = str(cfg.get("dataDir", "./data/"))
            if os.path.isabs(data_dir):
                base_dir = data_dir.rstrip("/").rstrip("\\")
            else:
                base_dir = str((AUTOMATE_DIR / data_dir).resolve())
            return os.path.join(base_dir, "status_updates.db")
        except (ValueError, OSError):
            pass
    return str(DEFAULT_DB)


def format_timestamp(ts: int | None) -> str:
    if ts is None:
        return ""
    dt = datetime.fromtimestamp(int(ts), tz=ZoneInfo(ENERGY_TIMEZONE))
    return dt.strftime("%Y-%m-%d %H:%M:%S")


def main() -> int:
    db_path = sys.argv[1] if len(sys.argv) > 1 else load_db_path()
    if not os.path.exists(db_path):
        print(f"DB not found: {db_path}", file=sys.stderr)
        return 2

    with sqlite3.connect(db_path) as conn:
        rows = conn.execute(
            """
            SELECT id, type, old_value, new_value, p1_total_power, electric_level, timestamp
            FROM status_updates
            ORDER BY timestamp DESC
            LIMIT 10
            """
        ).fetchall()

    if not rows:
        print(f"No rows found in {db_path}")
        return 0

    col_width = 10
    columns = ["id", "type", "old_value", "new_value", "p1_total_power", "electric_level", "timestamp"]

    print(f"db_path: {db_path}")
    header_parts = [f"{col:<{col_width}}" for col in columns[:-1]] + [columns[-1]]
    print(" ".join(header_parts))
    for row in rows:
        row_id, event_type, old_value, new_value, p1_total_power, electric_level, timestamp = row
        values = [row_id, event_type, old_value, new_value, p1_total_power, electric_level, format_timestamp(timestamp)]
        row_parts = [f"{str(v):<{col_width}}" for v in values[:-1]] + [str(values[-1])]
        print(" ".join(row_parts))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
