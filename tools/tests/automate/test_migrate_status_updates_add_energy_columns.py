#!/usr/bin/env python3
"""Tests for the status_updates energy-column migration script."""

from __future__ import annotations

import sqlite3
import sys
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[3]


def _import_migration_module():
    tools_dir = REPO_ROOT / "automate" / "tools"
    if str(tools_dir) not in sys.path:
        sys.path.insert(0, str(tools_dir))
    import migrate_status_updates_add_energy_columns  # type: ignore

    return migrate_status_updates_add_energy_columns


def test_migration_adds_missing_columns_and_is_idempotent(tmp_path):
    migration = _import_migration_module()
    db_path = tmp_path / "status_updates.db"
    with sqlite3.connect(db_path) as conn:
        conn.executescript(
            """
            CREATE TABLE status_updates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                old_value TEXT,
                new_value TEXT,
                p1_total_power INTEGER,
                electric_level INTEGER,
                timestamp INTEGER NOT NULL
            );
            INSERT INTO status_updates (type, timestamp) VALUES ('start', 1000);
            """
        )
        conn.commit()

    first = migration.migrate_status_updates_energy_columns(str(db_path))
    second = migration.migrate_status_updates_energy_columns(str(db_path))

    assert first["added"] == ["total_act_x100", "total_act_ret_x100"]
    assert second["added"] == []
    with sqlite3.connect(db_path) as conn:
        columns = [row[1] for row in conn.execute("PRAGMA table_info(status_updates)").fetchall()]
        row = conn.execute(
            "SELECT type, timestamp, total_act_x100, total_act_ret_x100 FROM status_updates"
        ).fetchone()
    assert "total_act_x100" in columns
    assert "total_act_ret_x100" in columns
    assert row == ("start", 1000, None, None)
