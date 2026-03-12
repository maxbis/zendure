from __future__ import annotations

import sqlite3
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import quote

from config import Settings, TableConfig


@dataclass(frozen=True)
class ColumnMeta:
    name: str
    sqlite_type: str
    nullable: bool
    default: object | None
    ordinal: int
    primary_key_position: int


@dataclass(frozen=True)
class TableMeta:
    name: str
    columns: list[ColumnMeta]
    primary_key: str | None
    replication_key: str | None
    supports_incremental: bool
    notes: str | None


def _sqlite_uri(path: str) -> str:
    resolved = Path(path).expanduser().resolve()
    return f"file:{quote(str(resolved))}?mode=ro"


def connect_readonly(settings: Settings) -> sqlite3.Connection:
    connection = sqlite3.connect(_sqlite_uri(settings.sqlite_db_path), uri=True)
    connection.row_factory = sqlite3.Row
    connection.execute(f"PRAGMA busy_timeout = {settings.busy_timeout_ms}")
    return connection


def _quote_identifier(identifier: str) -> str:
    return '"' + identifier.replace('"', '""') + '"'


def _is_integer_type(sqlite_type: str) -> bool:
    normalized = (sqlite_type or "").strip().upper()
    return "INT" in normalized


def _fetch_columns(connection: sqlite3.Connection, table_name: str) -> list[ColumnMeta]:
    rows = connection.execute(f"PRAGMA table_info({_quote_identifier(table_name)})").fetchall()
    return [
        ColumnMeta(
            name=row["name"],
            sqlite_type=row["type"] or "",
            nullable=not bool(row["notnull"]),
            default=row["dflt_value"],
            ordinal=int(row["cid"]) + 1,
            primary_key_position=int(row["pk"]),
        )
        for row in rows
    ]


def _single_primary_key(columns: list[ColumnMeta]) -> str | None:
    primary_key_columns = sorted((column for column in columns if column.primary_key_position > 0), key=lambda item: item.primary_key_position)
    if len(primary_key_columns) != 1:
        return None
    return primary_key_columns[0].name


def _replication_key(table_config: TableConfig, columns: list[ColumnMeta], primary_key: str | None) -> str | None:
    if table_config.replication_key:
        return table_config.replication_key
    return primary_key


def _supports_incremental(columns: list[ColumnMeta], replication_key: str | None, primary_key: str | None) -> bool:
    if not primary_key or not replication_key or replication_key != primary_key:
        return False

    column = next((item for item in columns if item.name == replication_key), None)
    if column is None:
        return False
    return _is_integer_type(column.sqlite_type)


def get_table_meta(connection: sqlite3.Connection, table_name: str, table_config: TableConfig) -> TableMeta:
    columns = _fetch_columns(connection, table_name)
    primary_key = _single_primary_key(columns)
    replication_key = _replication_key(table_config, columns, primary_key)
    return TableMeta(
        name=table_name,
        columns=columns,
        primary_key=primary_key,
        replication_key=replication_key,
        supports_incremental=_supports_incremental(columns, replication_key, primary_key),
        notes=table_config.notes,
    )


def list_configured_tables(connection: sqlite3.Connection, settings: Settings) -> list[TableMeta]:
    tables: list[TableMeta] = []
    for table_name in sorted(settings.allowed_tables):
        table_config = settings.allowed_tables[table_name]
        if not table_config.enabled:
            continue
        tables.append(get_table_meta(connection, table_name, table_config))
    return tables
