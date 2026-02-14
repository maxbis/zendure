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
from datetime import datetime

DEFAULT_DB = os.path.join(os.path.dirname(__file__), "data", "status_updates.db")


def load_db_path() -> str:
    """Load DB path from config.json if available."""
    config_paths = [
        os.path.join(os.path.dirname(__file__), "..", "config", "config.json"),
        os.path.join(os.path.dirname(__file__), "config.json"),
    ]
    for p in config_paths:
        if os.path.exists(p):
            try:
                with open(p, encoding="utf-8") as f:
                    cfg = json.load(f)
                data_dir = cfg.get("dataDir", "./data/")
                base = data_dir.rstrip("/").rstrip("\\")
                return os.path.join(base, "status_updates.db")
            except (json.JSONDecodeError, OSError):
                pass
    return DEFAULT_DB


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


def main() -> None:
    db_path = sys.argv[1] if len(sys.argv) > 1 else load_db_path()
    dump(db_path)


if __name__ == "__main__":
    main()
