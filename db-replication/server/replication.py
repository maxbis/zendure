from __future__ import annotations

import sqlite3

from fastapi import HTTPException, status

from sqlite_introspection import TableMeta


def quote_identifier(identifier: str) -> str:
    return '"' + identifier.replace('"', '""') + '"'


def fetch_rows(
    connection: sqlite3.Connection,
    table_meta: TableMeta,
    after: int,
    limit: int,
) -> tuple[list[dict], int, bool]:
    if not table_meta.supports_incremental or not table_meta.replication_key:
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail={
                "error": "unsupported_table",
                "message": f"Table '{table_meta.name}' does not support incremental replication",
                "details": {
                    "primary_key": table_meta.primary_key,
                    "replication_key": table_meta.replication_key,
                },
            },
        )

    quoted_columns = ", ".join(quote_identifier(column.name) for column in table_meta.columns)
    quoted_table = quote_identifier(table_meta.name)
    quoted_key = quote_identifier(table_meta.replication_key)
    query_limit = limit + 1
    query = (
        f"SELECT {quoted_columns} "
        f"FROM {quoted_table} "
        f"WHERE {quoted_key} > ? "
        f"ORDER BY {quoted_key} ASC "
        f"LIMIT ?"
    )
    rows = [dict(row) for row in connection.execute(query, (after, query_limit)).fetchall()]
    has_more = len(rows) > limit
    if has_more:
        rows = rows[:limit]

    next_cursor = after
    if rows:
        next_cursor = int(rows[-1][table_meta.replication_key])

    return rows, next_cursor, has_more
