from __future__ import annotations

from contextlib import contextmanager
from typing import TYPE_CHECKING, Any, Iterator

from config import Settings
from server_api import ServerSchema
from type_mapping import map_sqlite_type

if TYPE_CHECKING:
    import pymysql


def quote_identifier(identifier: str) -> str:
    return "`" + identifier.replace("`", "``") + "`"


class MariaDbSink:
    def __init__(self, settings: Settings) -> None:
        self.settings = settings

    @contextmanager
    def connection(self) -> Iterator["pymysql.connections.Connection"]:
        import pymysql

        connection = pymysql.connect(
            host=self.settings.mariadb_host,
            port=self.settings.mariadb_port,
            user=self.settings.mariadb_user,
            password=self.settings.mariadb_password,
            database=self.settings.mariadb_database,
            charset="utf8mb4",
            autocommit=False,
            cursorclass=pymysql.cursors.Cursor,
        )
        try:
            yield connection
        finally:
            connection.close()

    def ensure_table(self, connection: Any, schema: ServerSchema) -> None:
        if not schema.primary_key:
            raise ValueError(f"Table '{schema.table}' has no primary key")

        column_definitions: list[str] = []
        for column in schema.columns:
            mariadb_type = map_sqlite_type(column.sqlite_type)
            nullable = "NULL" if column.nullable else "NOT NULL"
            column_definitions.append(f"{quote_identifier(column.name)} {mariadb_type} {nullable}")

        primary_key = quote_identifier(schema.primary_key)
        create_sql = (
            f"CREATE TABLE IF NOT EXISTS {quote_identifier(schema.table)} ("
            + ", ".join(column_definitions)
            + f", PRIMARY KEY ({primary_key})"
            + ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        )

        with connection.cursor() as cursor:
            cursor.execute(create_sql)

    def get_max_key(self, connection: Any, table: str, primary_key: str) -> int:
        query = (
            f"SELECT COALESCE(MAX({quote_identifier(primary_key)}), 0) "
            f"FROM {quote_identifier(table)}"
        )
        with connection.cursor() as cursor:
            cursor.execute(query)
            row = cursor.fetchone()
        return int(row[0] or 0)

    def insert_rows(
        self,
        connection: Any,
        table: str,
        columns: list[str],
        rows: list[dict[str, Any]],
    ) -> None:
        if not rows:
            return

        quoted_columns = ", ".join(quote_identifier(column) for column in columns)
        placeholders = ", ".join(["%s"] * len(columns))
        query = (
            f"INSERT INTO {quote_identifier(table)} ({quoted_columns}) "
            f"VALUES ({placeholders})"
        )
        values = [tuple(row.get(column) for column in columns) for row in rows]
        with connection.cursor() as cursor:
            cursor.executemany(query, values)
