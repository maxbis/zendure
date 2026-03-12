from __future__ import annotations

import sys
from dataclasses import dataclass
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from config import Settings
from main import replicate_once, resolve_batch_size, select_tables
from server_api import RowsBatch, ServerColumn, ServerSchema, ServerTable
from type_mapping import map_sqlite_type


@dataclass
class FakeConnection:
    committed: int = 0
    rolled_back: int = 0

    def commit(self) -> None:
        self.committed += 1

    def rollback(self) -> None:
        self.rolled_back += 1


class FakeConnectionManager:
    def __init__(self, connection: FakeConnection) -> None:
        self._connection = connection

    def __enter__(self) -> FakeConnection:
        return self._connection

    def __exit__(self, exc_type, exc, tb) -> bool:
        return False


class FakeApi:
    def __init__(self, tables, schemas, batches) -> None:
        self.tables = tables
        self.schemas = schemas
        self.batches = batches
        self.row_requests: list[tuple[str, int, int]] = []

    def get_tables(self):
        return self.tables

    def get_schema(self, table: str):
        return self.schemas[table]

    def get_rows(self, table: str, after: int, limit: int):
        self.row_requests.append((table, after, limit))
        return self.batches[table]


class FakeSink:
    def __init__(self, max_keys=None, fail_on_insert: bool = False) -> None:
        self.connection_obj = FakeConnection()
        self.created_tables: list[str] = []
        self.max_keys = max_keys or {}
        self.inserted_batches: list[tuple[str, list[str], list[dict]]] = []
        self.fail_on_insert = fail_on_insert

    def connection(self):
        return FakeConnectionManager(self.connection_obj)

    def ensure_table(self, connection, schema):
        self.created_tables.append(schema.table)

    def get_max_key(self, connection, table: str, primary_key: str) -> int:
        return self.max_keys.get(table, 0)

    def insert_rows(self, connection, table: str, columns: list[str], rows: list[dict]) -> None:
        if self.fail_on_insert:
            raise RuntimeError("insert failed")
        self.inserted_batches.append((table, columns, rows))


def build_settings() -> Settings:
    return Settings(
        server_base_url="http://127.0.0.1:1612",
        access_token="secret-token",
        mariadb_host="127.0.0.1",
        mariadb_port=3306,
        mariadb_database="replication_target",
        mariadb_user="replicator",
        mariadb_password="secret",
        batch_size=50,
    )


def build_status_schema() -> ServerSchema:
    return ServerSchema(
        table="status_updates",
        columns=[
            ServerColumn(name="id", sqlite_type="INTEGER", nullable=False, default=None, ordinal=1),
            ServerColumn(name="type", sqlite_type="TEXT", nullable=False, default=None, ordinal=2),
            ServerColumn(name="timestamp", sqlite_type="INTEGER", nullable=False, default=None, ordinal=3),
        ],
        primary_key="id",
        replication_key="id",
    )


def test_select_tables_returns_incremental_only():
    tables = [
        ServerTable("status_updates", "pk_cursor", "id", "id", True),
        ServerTable("audit_log", "pk_cursor", "event_id", "event_id", False),
    ]
    selected = select_tables(tables, requested_table=None)
    assert [table.name for table in selected] == ["status_updates"]


def test_select_tables_rejects_unknown_requested_table():
    tables = [ServerTable("status_updates", "pk_cursor", "id", "id", True)]
    try:
        select_tables(tables, requested_table="missing")
    except ValueError as exc:
        assert "missing" in str(exc)
    else:
        raise AssertionError("Expected ValueError")


def test_resolve_batch_size_prefers_cli_override():
    settings = build_settings()
    assert resolve_batch_size(settings, 10) == 10
    assert resolve_batch_size(settings, None) == 50


def test_map_sqlite_type_defaults_to_longtext():
    assert map_sqlite_type("INTEGER") == "BIGINT"
    assert map_sqlite_type("TEXT") == "LONGTEXT"
    assert map_sqlite_type("") == "LONGTEXT"


def test_replicate_once_uses_destination_max_key_and_inserts_rows():
    settings = build_settings()
    api = FakeApi(
        tables=[ServerTable("status_updates", "pk_cursor", "id", "id", True)],
        schemas={"status_updates": build_status_schema()},
        batches={
            "status_updates": RowsBatch(
                table="status_updates",
                replication_key="id",
                rows=[
                    {"id": 6, "type": "power", "timestamp": 1001},
                    {"id": 7, "type": "power", "timestamp": 1002},
                ],
                count=2,
                next_cursor=7,
                has_more=False,
            )
        },
    )
    sink = FakeSink(max_keys={"status_updates": 5})

    inserted = replicate_once(settings, batch_size=2, api=api, sink=sink)

    assert inserted == 2
    assert api.row_requests == [("status_updates", 5, 2)]
    assert sink.created_tables == ["status_updates"]
    assert sink.connection_obj.committed == 1
    assert sink.inserted_batches[0][0] == "status_updates"


def test_replicate_once_skips_empty_batches():
    settings = build_settings()
    api = FakeApi(
        tables=[ServerTable("status_updates", "pk_cursor", "id", "id", True)],
        schemas={"status_updates": build_status_schema()},
        batches={
            "status_updates": RowsBatch(
                table="status_updates",
                replication_key="id",
                rows=[],
                count=0,
                next_cursor=5,
                has_more=False,
            )
        },
    )
    sink = FakeSink(max_keys={"status_updates": 5})

    inserted = replicate_once(settings, batch_size=2, api=api, sink=sink)

    assert inserted == 0
    assert sink.connection_obj.committed == 0
    assert sink.connection_obj.rolled_back == 1
    assert sink.inserted_batches == []


def test_replicate_once_rolls_back_failed_insert():
    settings = build_settings()
    api = FakeApi(
        tables=[ServerTable("status_updates", "pk_cursor", "id", "id", True)],
        schemas={"status_updates": build_status_schema()},
        batches={
            "status_updates": RowsBatch(
                table="status_updates",
                replication_key="id",
                rows=[{"id": 1, "type": "power", "timestamp": 1001}],
                count=1,
                next_cursor=1,
                has_more=False,
            )
        },
    )
    sink = FakeSink(fail_on_insert=True)

    try:
        replicate_once(settings, batch_size=1, api=api, sink=sink)
    except RuntimeError as exc:
        assert "insert failed" in str(exc)
    else:
        raise AssertionError("Expected RuntimeError")

    assert sink.connection_obj.rolled_back == 1
    assert sink.connection_obj.committed == 0
