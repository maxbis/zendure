#!/usr/bin/env python3
"""Tests for the status_updates rule-column migration script."""

from __future__ import annotations

import sqlite3
import sys
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[3]


def _import_migration_module():
    tools_dir = REPO_ROOT / "automate" / "tools"
    if str(tools_dir) not in sys.path:
        sys.path.insert(0, str(tools_dir))
    import migrate_status_updates_add_rule_column  # type: ignore

    return migrate_status_updates_add_rule_column


def test_migration_adds_missing_rule_column_and_is_idempotent(tmp_path):
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

    first = migration.migrate_status_updates_rule_column(str(db_path))
    second = migration.migrate_status_updates_rule_column(str(db_path))

    assert first["added"] == ["rule"]
    assert second["added"] == []
    with sqlite3.connect(db_path) as conn:
        columns = [row[1] for row in conn.execute("PRAGMA table_info(status_updates)").fetchall()]
        row = conn.execute(
            "SELECT type, timestamp, rule FROM status_updates"
        ).fetchone()
    assert "rule" in columns
    assert row == ("start", 1000, None)
