#!/usr/bin/env python3
"""Verify db-replication server introspection picks up status_updates.rule without code changes."""

from __future__ import annotations

import sqlite3
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]


def _import_sqlite_introspection():
    server_dir = REPO_ROOT / "db-replication" / "server"
    if str(server_dir) not in sys.path:
        sys.path.insert(0, str(server_dir))
    import sqlite_introspection  # type: ignore
    from config import TableConfig  # type: ignore

    return sqlite_introspection, TableConfig


def test_status_updates_rule_column_is_included_in_schema_introspection(tmp_path):
    si, TableConfig = _import_sqlite_introspection()
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
                rule TEXT,
                total_act_x100 INTEGER,
                total_act_ret_x100 INTEGER,
                timestamp INTEGER NOT NULL
            );
            INSERT INTO status_updates (type, rule, timestamp) VALUES ('change', 'NZ0', 1000);
            """
        )
        conn.commit()

    table_config = TableConfig(enabled=True, replication_key="id", notes=None)
    with sqlite3.connect(db_path) as conn:
        conn.row_factory = sqlite3.Row
        meta = si.get_table_meta(conn, "status_updates", table_config)

    column_names = [column.name for column in meta.columns]
    assert "rule" in column_names
    assert meta.primary_key == "id"
    assert meta.replication_key == "id"
    assert meta.supports_incremental is True

    rule_column = next(column for column in meta.columns if column.name == "rule")
    assert "TEXT" in (rule_column.sqlite_type or "").upper()


def test_mariadb_type_mapping_maps_sqlite_text_to_longtext():
    """Document current client mapping; production VARCHAR(5) comes from the SQL ALTER script."""
    client_dir = REPO_ROOT / "db-replication" / "client"
    if str(client_dir) not in sys.path:
        sys.path.insert(0, str(client_dir))
    from type_mapping import map_sqlite_type  # type: ignore

    assert map_sqlite_type("TEXT") == "LONGTEXT"
