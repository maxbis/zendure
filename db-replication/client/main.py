from __future__ import annotations

import argparse
import logging
import sys

from config import Settings, load_settings
from mariadb_sink import MariaDbSink
from server_api import ServerApi, ServerTable


logger = logging.getLogger("replication_client")
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Replicate batches from the SQLite source API into MariaDB")
    parser.add_argument("--batch-size", type=int, default=None, help="Override the configured batch size")
    parser.add_argument("--table", default=None, help="Replicate only one table")
    return parser.parse_args()


def resolve_batch_size(settings: Settings, cli_batch_size: int | None) -> int:
    if cli_batch_size is None:
        return settings.batch_size
    if cli_batch_size < 1:
        raise ValueError("--batch-size must be >= 1")
    return cli_batch_size


def select_tables(tables: list[ServerTable], requested_table: str | None) -> list[ServerTable]:
    incremental_tables = [table for table in tables if table.supports_incremental]
    if requested_table is None:
        return incremental_tables

    for table in incremental_tables:
        if table.name == requested_table:
            return [table]

    raise ValueError(f"Requested table '{requested_table}' is not available for incremental replication")


def replicate_once(
    settings: Settings,
    batch_size: int,
    requested_table: str | None = None,
    api: ServerApi | None = None,
    sink: MariaDbSink | None = None,
) -> int:
    server_api = api or ServerApi(settings.server_base_url, settings.access_token)
    mariadb_sink = sink or MariaDbSink(settings)
    tables = select_tables(server_api.get_tables(), requested_table)

    total_inserted = 0
    if not tables:
        logger.info("No incremental tables available")
        return total_inserted

    with mariadb_sink.connection() as connection:
        for table in tables:
            logger.info("Syncing table=%s", table.name)
            schema = server_api.get_schema(table.name)
            if not schema.primary_key or not schema.replication_key:
                raise ValueError(f"Table '{table.name}' is missing primary_key or replication_key")

            mariadb_sink.ensure_table(connection, schema)
            after = mariadb_sink.get_max_key(connection, schema.table, schema.primary_key)
            logger.info("Requesting table=%s after=%s limit=%s", table.name, after, batch_size)
            batch = server_api.get_rows(table.name, after=after, limit=batch_size)
            logger.info(
                "Received table=%s count=%s next_cursor=%s has_more=%s",
                table.name,
                batch.count,
                batch.next_cursor,
                batch.has_more,
            )

            if not batch.rows:
                connection.rollback()
                continue

            column_names = [column.name for column in schema.columns]
            try:
                mariadb_sink.insert_rows(connection, schema.table, column_names, batch.rows)
                connection.commit()
            except Exception:
                connection.rollback()
                raise

            total_inserted += batch.count

    return total_inserted


def main() -> int:
    args = parse_args()
    try:
        settings = load_settings()
        batch_size = resolve_batch_size(settings, args.batch_size)
        inserted = replicate_once(settings, batch_size, requested_table=args.table)
    except Exception as exc:
        logger.error("Replication failed: %s", exc)
        return 1

    logger.info("Replication completed inserted=%s", inserted)
    return 0


if __name__ == "__main__":
    sys.exit(main())
