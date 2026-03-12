#!/usr/bin/env python3
"""
Create a compressed copy of status_updates.db for profiling experiments.

Compression rule:
- copy all non-"change" rows unchanged
- collapse consecutive "change" rows only when both numeric new_value and electric_level match
- keep the first row of each completed run
- keep the last row of the final run so open-ended tail behavior stays realistic

Usage:
  python tools/compress_status_updates_db.py SOURCE_DB DEST_DB
"""

from __future__ import annotations

import argparse
import os
import sqlite3
from dataclasses import dataclass


EVENT_TYPE_CHANGE = "change"


@dataclass
class StatusRow:
    row_id: int
    event_type: str
    old_value: str | None
    new_value: str | None
    p1_total_power: int | None
    electric_level: int | None
    timestamp: int


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Create a compressed copy of status_updates.db for wh_per_hour profiling."
    )
    parser.add_argument("source_db", help="Path to the source SQLite database")
    parser.add_argument("dest_db", help="Path to the destination SQLite database")
    return parser.parse_args()


def ensure_parent_dir(path: str) -> None:
    parent = os.path.dirname(os.path.abspath(path))
    if parent:
        os.makedirs(parent, exist_ok=True)


def create_destination_schema(conn: sqlite3.Connection) -> None:
    conn.executescript(
        """
        DROP TABLE IF EXISTS status_updates;
        CREATE TABLE status_updates (
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
    )


def load_rows(src: sqlite3.Connection) -> list[StatusRow]:
    rows: list[StatusRow] = []
    cur = src.execute(
        """
        SELECT id, type, old_value, new_value, p1_total_power, electric_level, timestamp
        FROM status_updates
        ORDER BY timestamp ASC, id ASC
        """
    )
    for raw in cur.fetchall():
        rows.append(StatusRow(*raw))
    return rows


def normalize_change_value(raw: str | None) -> float | None:
    if raw is None:
        return None
    try:
        return float(raw)
    except (TypeError, ValueError):
        return None


def compress_rows(rows: list[StatusRow]) -> list[StatusRow]:
    result: list[StatusRow] = []
    change_rows = [row for row in rows if row.event_type == EVENT_TYPE_CHANGE]
    non_change_rows = [row for row in rows if row.event_type != EVENT_TYPE_CHANGE]

    if change_rows:
        run_first = change_rows[0]
        run_last = change_rows[0]
        run_value = normalize_change_value(change_rows[0].new_value)
        run_level = change_rows[0].electric_level

        for row in change_rows[1:]:
            row_value = normalize_change_value(row.new_value)
            if row_value == run_value and row.electric_level == run_level:
                run_last = row
                continue
            result.append(run_first)
            run_first = row
            run_last = row
            run_value = row_value
            run_level = row.electric_level

        # Keep the last row of the final run to preserve the open-ended tail timestamp.
        result.append(run_last)

    result.extend(non_change_rows)
    result.sort(key=lambda row: (row.timestamp, row.row_id))
    return result


def insert_rows(dest: sqlite3.Connection, rows: list[StatusRow]) -> None:
    dest.executemany(
        """
        INSERT INTO status_updates (type, old_value, new_value, p1_total_power, electric_level, timestamp)
        VALUES (?, ?, ?, ?, ?, ?)
        """,
        [
            (
                row.event_type,
                row.old_value,
                row.new_value,
                row.p1_total_power,
                row.electric_level,
                row.timestamp,
            )
            for row in rows
        ],
    )


def main() -> int:
    args = parse_args()
    source_db = os.path.abspath(args.source_db)
    dest_db = os.path.abspath(args.dest_db)

    if not os.path.exists(source_db):
        raise SystemExit(f"Source DB not found: {source_db}")
    if source_db == dest_db:
        raise SystemExit("Destination DB must be different from source DB")

    ensure_parent_dir(dest_db)
    if os.path.exists(dest_db):
        os.remove(dest_db)

    with sqlite3.connect(source_db) as src:
        rows = load_rows(src)

    compressed_rows = compress_rows(rows)

    with sqlite3.connect(dest_db) as dest:
        create_destination_schema(dest)
        insert_rows(dest, compressed_rows)
        dest.commit()

    original_count = len(rows)
    compressed_count = len(compressed_rows)
    print(f"source_db:      {source_db}")
    print(f"dest_db:        {dest_db}")
    print(f"original_rows:  {original_count}")
    print(f"compressed_rows:{compressed_count}")
    print(f"removed_rows:   {original_count - compressed_count}")
    if original_count:
        ratio = (compressed_count / original_count) * 100.0
        print(f"kept_pct:       {ratio:.2f}%")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
