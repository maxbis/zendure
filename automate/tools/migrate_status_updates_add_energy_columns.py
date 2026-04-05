#!/usr/bin/env python3
"""Add energy counter columns to the status_updates SQLite table."""

from __future__ import annotations

import argparse
import sqlite3
from pathlib import Path


REQUIRED_COLUMNS = {
    "total_act_x100": "INTEGER",
    "total_act_ret_x100": "INTEGER",
}


def migrate_status_updates_energy_columns(db_path: str) -> dict[str, object]:
    path = Path(db_path)
    if not path.exists():
        raise FileNotFoundError(f"Database file not found: {path}")

    added: list[str] = []
    with sqlite3.connect(str(path)) as conn:
        tables = conn.execute(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='status_updates'"
        ).fetchall()
        if not tables:
            raise RuntimeError("Table 'status_updates' does not exist")

        existing_columns = {
            str(row[1])
            for row in conn.execute("PRAGMA table_info(status_updates)").fetchall()
        }
        for column_name, column_type in REQUIRED_COLUMNS.items():
            if column_name in existing_columns:
                continue
            conn.execute(
                f"ALTER TABLE status_updates ADD COLUMN {column_name} {column_type}"
            )
            added.append(column_name)
        conn.commit()

        final_columns = [
            str(row[1])
            for row in conn.execute("PRAGMA table_info(status_updates)").fetchall()
        ]

    return {"db_path": str(path), "added": added, "columns": final_columns}


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Add total_act_x100 and total_act_ret_x100 columns to status_updates.db",
    )
    parser.add_argument("db_path", help="Path to the SQLite status_updates database")
    args = parser.parse_args()

    result = migrate_status_updates_energy_columns(args.db_path)
    added = result.get("added", [])
    if added:
        print(f"Added columns to {result['db_path']}: {', '.join(added)}")
    else:
        print(f"No changes needed for {result['db_path']}; columns already present")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
