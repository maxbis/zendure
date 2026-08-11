#!/usr/bin/env python3
"""
Build compact hourly daily-report inputs from sqlite_replication.status_updates.

This is a sidecar aggregate updater. It does not change daily report generation
and it never deletes raw status_updates rows.
"""

from __future__ import annotations

import argparse
import os
import sys
from datetime import datetime, timedelta
from pathlib import Path
from typing import Any, Iterable
from zoneinfo import ZoneInfo

import pymysql

import hourly_daily_grid_battery_report as report


DEFAULT_AGGREGATE_TABLE = "hourly_report_inputs"
DEFAULT_LOG_TABLE = "hourly_report_inputs_update_log"
DEFAULT_PRODUCTION_DATABASE = "enphase_history"
DEFAULT_PRODUCTION_TABLE = "production_hourly"
DEFAULT_PRODUCTION_SYSTEM_ID = 5053376
DEFAULT_PRODUCTION_SOURCE = "production_micro"
RUN_TYPE_MANUAL = "manual"
RUN_TYPE_DAILY = "daily"
RUN_TYPE_PNL_BACKFILL = "pnl_backfill"
REPO_ROOT = Path(__file__).resolve().parents[2]
ENV_FILE = REPO_ROOT / "daily_report" / ".env"


CREATE_AGGREGATE_TABLE_SQL = f"""
CREATE TABLE IF NOT EXISTS `{DEFAULT_AGGREGATE_TABLE}` (
    local_date DATE NOT NULL,
    local_hour TINYINT UNSIGNED NOT NULL,
    hour_start_ts BIGINT NOT NULL,
    hour_end_ts BIGINT NOT NULL,
    charged_wh DECIMAL(12,3) NOT NULL DEFAULT 0,
    discharged_wh DECIMAL(12,3) NOT NULL DEFAULT 0,
    battery_pct_start DECIMAL(6,2) NULL,
    battery_pct_end DECIMAL(6,2) NULL,
    battery_pct_delta DECIMAL(6,2) NULL,
    grid_from_wh DECIMAL(12,3) NULL,
    grid_to_wh DECIMAL(12,3) NULL,
    estimated_home_load_wh INT UNSIGNED NULL,
    battery_charge_grid_wh INT UNSIGNED NULL,
    battery_charge_surplus_wh INT UNSIGNED NULL,
    battery_discharge_home_wh INT UNSIGNED NULL,
    battery_discharge_export_wh INT UNSIGNED NULL,
    consumer_eur_per_kwh DECIMAL(10,6) NULL,
    spot_eur_per_kwh DECIMAL(10,6) NULL,
    price_source VARCHAR(32) NULL,
    battery_charge_cost_milli_eur INT NULL,
    battery_home_savings_milli_eur INT NULL,
    battery_export_revenue_milli_eur INT NULL,
    battery_flow_pnl_milli_eur INT NULL,
    battery_pnl_status VARCHAR(32) NULL,
    battery_pnl_method_version SMALLINT UNSIGNED NULL,
    source_min_id BIGINT NULL,
    source_max_id BIGINT NULL,
    source_rows INT UNSIGNED NOT NULL DEFAULT 0,
    computed_at DATETIME NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hourly_report_inputs_hour (local_date, local_hour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
"""


CREATE_LOG_TABLE_SQL = f"""
CREATE TABLE IF NOT EXISTS `{DEFAULT_LOG_TABLE}` (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    target_date DATE NOT NULL,
    run_type VARCHAR(16) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    hours_upserted TINYINT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NOT NULL,
    error_text TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_hourly_report_inputs_update_log_target_date (target_date),
    KEY idx_hourly_report_inputs_update_log_run_type (run_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
"""


def load_env_file(path: Path = ENV_FILE) -> None:
    if not path.is_file():
        return
    raw = path.read_text(encoding="utf-8-sig")
    for line in raw.splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        if line.startswith("export "):
            line = line[7:].strip()
        if "=" not in line:
            continue
        name, value = line.split("=", 1)
        name = name.strip()
        if not name or name in os.environ:
            continue
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in {"'", '"'}:
            value = value[1:-1]
        os.environ[name] = value


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Update hourly_report_inputs sidecar aggregates from status_updates."
    )
    group = parser.add_mutually_exclusive_group()
    group.add_argument("--date", help="Single target date in YYYY-MM-DD.")
    group.add_argument("--days-back", type=int, help="Recompute today minus N days through today.")
    parser.add_argument("--start-date", help="Range start date in YYYY-MM-DD.")
    parser.add_argument("--end-date", help="Range end date in YYYY-MM-DD. Defaults to today for ranges.")
    parser.add_argument("--host", default=None, help="MariaDB host. Default: MARIADB_HOST or 127.0.0.1.")
    parser.add_argument("--port", type=int, default=None, help="MariaDB port. Default: MARIADB_PORT or 3306.")
    parser.add_argument("--user", default=None, help="MariaDB user. Default: MARIADB_USER or root.")
    parser.add_argument("--password", default=None, help="MariaDB password. Default: MARIADB_PASSWORD or empty.")
    parser.add_argument("--database", default=None, help="MariaDB database. Default: MARIADB_DATABASE or sqlite_replication.")
    parser.add_argument("--table", default=report.DEFAULT_TABLE, help="Raw status table.")
    parser.add_argument("--price-table", default=report.DEFAULT_PRICE_TABLE, help="Price tick table.")
    parser.add_argument("--production-database", default=None, help="Default: ENPHASE_HISTORY_DATABASE or enphase_history.")
    parser.add_argument("--production-table", default=None, help="Default: ENPHASE_PRODUCTION_TABLE or production_hourly.")
    parser.add_argument("--production-system-id", type=int, default=None, help="Default: ENPHASE_SYSTEM_ID or 5053376.")
    parser.add_argument("--production-source", default=None, help="Default: ENPHASE_PRODUCTION_SOURCE or production_micro.")
    parser.add_argument("--aggregate-table", default=DEFAULT_AGGREGATE_TABLE, help="Aggregate table.")
    parser.add_argument("--log-table", default=DEFAULT_LOG_TABLE, help="Update log table.")
    parser.add_argument(
        "--pnl-only",
        action="store_true",
        help="Update only the battery-flow attribution and PnL columns on existing hourly rows.",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Calculate and report coverage without changing schema or data.",
    )
    parser.add_argument("--timezone", default=report.DEFAULT_TIMEZONE, help="IANA timezone.")
    parser.add_argument(
        "--fallback-seconds",
        type=int,
        default=report.BOUNDARY_FALLBACK_MAX_SECONDS,
        help="Maximum one-sided boundary carry in seconds.",
    )
    return parser.parse_args()


def _parse_date(value: str, tz: ZoneInfo) -> datetime:
    try:
        return datetime.strptime(value, "%Y-%m-%d").replace(tzinfo=tz)
    except ValueError as exc:
        raise ValueError(f"Invalid date '{value}', expected YYYY-MM-DD") from exc


def resolve_target_days(args: argparse.Namespace, tz: ZoneInfo) -> list[datetime]:
    today = datetime.now(tz).replace(hour=0, minute=0, second=0, microsecond=0)
    if args.start_date and (args.date or args.days_back is not None):
        raise ValueError("--start-date cannot be combined with --date or --days-back")
    if args.date:
        if args.end_date:
            raise ValueError("--end-date cannot be combined with --date")
        return [_parse_date(args.date, tz)]

    if args.start_date:
        start = _parse_date(args.start_date, tz)
        end = _parse_date(args.end_date, tz) if args.end_date else today
        if end < start:
            raise ValueError("--end-date must be on or after --start-date")
    elif args.end_date:
        raise ValueError("--end-date requires --start-date")
    elif args.days_back is not None:
        if args.days_back < 0:
            raise ValueError("--days-back must be >= 0")
        start = today - timedelta(days=args.days_back)
        end = today
    else:
        start = today - timedelta(days=1)
        end = today

    days: list[datetime] = []
    cursor = start
    while cursor <= end:
        days.append(cursor)
        cursor += timedelta(days=1)
    return days


def db_config(args: argparse.Namespace) -> dict[str, Any]:
    return {
        "host": args.host or os.getenv("MARIADB_HOST", report.DEFAULT_HOST),
        "port": args.port or int(os.getenv("MARIADB_PORT", str(report.DEFAULT_PORT))),
        "user": args.user or os.getenv("MARIADB_USER", report.DEFAULT_USER),
        "password": args.password if args.password is not None else os.getenv("MARIADB_PASSWORD", report.DEFAULT_PASSWORD),
        "database": args.database or os.getenv("MARIADB_DATABASE", report.DEFAULT_DATABASE),
    }


def connect(config: dict[str, Any]) -> pymysql.connections.Connection:
    return pymysql.connect(
        host=config["host"],
        port=int(config["port"]),
        user=config["user"],
        password=config["password"],
        database=config["database"],
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=True,
    )


def ensure_tables(connection: pymysql.connections.Connection, aggregate_table: str, log_table: str) -> None:
    aggregate_sql = CREATE_AGGREGATE_TABLE_SQL.replace(
        f"`{DEFAULT_AGGREGATE_TABLE}`", report._quote_identifier(aggregate_table), 1
    )
    log_sql = CREATE_LOG_TABLE_SQL.replace(f"`{DEFAULT_LOG_TABLE}`", report._quote_identifier(log_table), 1)
    with connection.cursor() as cursor:
        cursor.execute(aggregate_sql)
        cursor.execute(log_sql)
        quoted_aggregate_table = report._quote_identifier(aggregate_table)
        cursor.execute(
            f"ALTER TABLE {quoted_aggregate_table} "
            "ADD COLUMN IF NOT EXISTS consumer_eur_per_kwh DECIMAL(10,6) NULL AFTER grid_to_wh"
        )
        cursor.execute(
            f"ALTER TABLE {quoted_aggregate_table} "
            "ADD COLUMN IF NOT EXISTS spot_eur_per_kwh DECIMAL(10,6) NULL AFTER consumer_eur_per_kwh"
        )
        cursor.execute(
            f"ALTER TABLE {quoted_aggregate_table} "
            "ADD COLUMN IF NOT EXISTS price_source VARCHAR(32) NULL AFTER spot_eur_per_kwh"
        )
        pnl_columns = [
            "estimated_home_load_wh INT UNSIGNED NULL",
            "battery_charge_grid_wh INT UNSIGNED NULL",
            "battery_charge_surplus_wh INT UNSIGNED NULL",
            "battery_discharge_home_wh INT UNSIGNED NULL",
            "battery_discharge_export_wh INT UNSIGNED NULL",
            "battery_charge_cost_milli_eur INT NULL",
            "battery_home_savings_milli_eur INT NULL",
            "battery_export_revenue_milli_eur INT NULL",
            "battery_flow_pnl_milli_eur INT NULL",
            "battery_pnl_status VARCHAR(32) NULL",
            "battery_pnl_method_version SMALLINT UNSIGNED NULL",
        ]
        for definition in pnl_columns:
            cursor.execute(
                f"ALTER TABLE {quoted_aggregate_table} ADD COLUMN IF NOT EXISTS {definition}"
            )


def _round(value: float | None, digits: int) -> float | None:
    return None if value is None else round(value, digits)


def load_price_ticks_for_day(
    connection: pymysql.connections.Connection,
    table: str,
    target_day_start: datetime,
) -> dict[str, dict[str, Any]]:
    quoted_table = report._quote_identifier(table)
    sql = (
        f"SELECT local_hour, consumer_eur_per_kwh, spot_eur_per_kwh, source "
        f"FROM {quoted_table} "
        f"WHERE local_date = %s "
        f"ORDER BY local_hour ASC"
    )
    prices: dict[str, dict[str, Any]] = {}
    with connection.cursor() as cursor:
        cursor.execute(sql, (target_day_start.strftime("%Y-%m-%d"),))
        for row in cursor.fetchall():
            try:
                hour = int(row["local_hour"])
            except (TypeError, ValueError):
                continue
            if hour < 0 or hour > 23:
                continue
            prices[f"{hour:02d}"] = {
                "consumer_eur_per_kwh": report._parse_numeric_value(row.get("consumer_eur_per_kwh")),
                "spot_eur_per_kwh": report._parse_numeric_value(row.get("spot_eur_per_kwh")),
                "price_source": row.get("source"),
            }
    return prices


def load_production_for_day(
    connection: pymysql.connections.Connection,
    *,
    database: str,
    table: str,
    system_id: int,
    source: str,
    target_day_start: datetime,
) -> dict[str, float]:
    qualified_table = f"{report._quote_identifier(database)}.{report._quote_identifier(table)}"
    sql = (
        f"SELECT local_hour, energy_wh FROM {qualified_table} "
        "WHERE local_date = %s AND system_id = %s AND source = %s "
        "ORDER BY local_hour ASC"
    )
    production: dict[str, float] = {}
    with connection.cursor() as cursor:
        cursor.execute(sql, (target_day_start.strftime("%Y-%m-%d"), system_id, source))
        for row in cursor.fetchall():
            try:
                hour = int(row["local_hour"])
            except (TypeError, ValueError):
                continue
            energy_wh = report._parse_numeric_value(row.get("energy_wh"))
            if 0 <= hour <= 23 and energy_wh is not None:
                production[f"{hour:02d}"] = energy_wh
    return production


def load_existing_energy_targets(
    connection: pymysql.connections.Connection,
    table: str,
    target_day_start: datetime,
) -> dict[str, dict[str, float | None]]:
    quoted_table = report._quote_identifier(table)
    sql = (
        f"SELECT local_hour, charged_wh, discharged_wh, grid_from_wh, grid_to_wh FROM {quoted_table} "
        f"WHERE local_date = %s ORDER BY local_hour ASC"
    )
    targets: dict[str, dict[str, float | None]] = {}
    with connection.cursor() as cursor:
        cursor.execute(sql, (target_day_start.strftime("%Y-%m-%d"),))
        for row in cursor.fetchall():
            try:
                hour = int(row["local_hour"])
            except (TypeError, ValueError):
                continue
            charged = report._parse_numeric_value(row.get("charged_wh"))
            discharged = report._parse_numeric_value(row.get("discharged_wh"))
            if 0 <= hour <= 23 and charged is not None and discharged is not None:
                targets[f"{hour:02d}"] = {
                    "charged_wh": charged,
                    "discharged_wh": discharged,
                    "grid_from_wh": report._parse_numeric_value(row.get("grid_from_wh")),
                    "grid_to_wh": report._parse_numeric_value(row.get("grid_to_wh")),
                }
    return targets


def _source_stats(rows: Iterable[report.StatusRow], start_ts: int, end_ts: int) -> tuple[int | None, int | None, int]:
    ids = [row.id for row in rows if start_ts <= row.timestamp <= end_ts]
    if not ids:
        return None, None, 0
    return min(ids), max(ids), len(ids)


def build_hourly_input_rows(
    rows: list[report.StatusRow],
    *,
    target_day_start: datetime,
    analysis_end_ts: int,
    tz: ZoneInfo,
    fallback_seconds: int = report.BOUNDARY_FALLBACK_MAX_SECONDS,
    prices_by_hour: dict[str, dict[str, Any]] | None = None,
    production_by_hour: dict[str, float] | None = None,
    energy_targets_by_hour: dict[str, dict[str, float | None]] | None = None,
    computed_at: datetime | None = None,
) -> list[dict[str, Any]]:
    day_start_ts = report._dt_to_ts(target_day_start)
    day_end_ts = report._dt_to_ts(target_day_start + timedelta(days=1))
    effective_end_ts = max(day_start_ts, min(day_end_ts, analysis_end_ts))
    computed_at = computed_at or datetime.now(ZoneInfo("UTC"))
    computed_at_str = computed_at.strftime("%Y-%m-%d %H:%M:%S")

    battery_samples = report._build_samples(rows, "electric_level")
    grid_from_samples = report._normalize_monotonic_counter_samples(report._build_samples(rows, "total_act_x100"))
    grid_to_samples = report._normalize_monotonic_counter_samples(report._build_samples(rows, "total_act_ret_x100"))
    power_points = report._change_points(rows, effective_end_ts)

    hourly_rows: list[dict[str, Any]] = []
    for hour in range(24):
        hour_key = f"{hour:02d}"
        bucket_start_ts = day_start_ts + (hour * 3600)
        nominal_end_ts = bucket_start_ts + 3600
        effective_bucket_end_ts = min(nominal_end_ts, effective_end_ts)
        is_elapsed = bucket_start_ts < effective_end_ts

        charged_wh = 0.0
        discharged_wh = 0.0
        battery_start = None
        battery_end = None
        battery_delta = None
        grid_from_wh = None
        grid_to_wh = None

        if is_elapsed:
            charged_wh, discharged_wh = report._integrate_power_window(
                power_points,
                bucket_start_ts,
                effective_bucket_end_ts,
            )
            battery_start = report.interpolate_boundary_value(
                battery_samples, bucket_start_ts, fallback_seconds=fallback_seconds
            )
            battery_end = report.interpolate_boundary_value(
                battery_samples, effective_bucket_end_ts, fallback_seconds=fallback_seconds
            )
            hourly_battery_samples = report._samples_in_window(
                battery_samples,
                bucket_start_ts,
                effective_bucket_end_ts,
            )
            if battery_start is None and hourly_battery_samples:
                battery_start = hourly_battery_samples[0].value
            if battery_end is None and hourly_battery_samples:
                battery_end = hourly_battery_samples[-1].value
            if battery_start is not None and battery_end is not None:
                battery_delta = battery_end - battery_start

            grid_from_start = report.interpolate_boundary_value(
                grid_from_samples, bucket_start_ts, fallback_seconds=fallback_seconds
            )
            grid_from_end = report.interpolate_boundary_value(
                grid_from_samples, effective_bucket_end_ts, fallback_seconds=fallback_seconds
            )
            grid_from_wh = report._compute_counter_delta_wh(grid_from_start, grid_from_end)

            grid_to_start = report.interpolate_boundary_value(
                grid_to_samples, bucket_start_ts, fallback_seconds=fallback_seconds
            )
            grid_to_end = report.interpolate_boundary_value(
                grid_to_samples, effective_bucket_end_ts, fallback_seconds=fallback_seconds
            )
            grid_to_wh = report._compute_counter_delta_wh(grid_to_start, grid_to_end)

        source_min_id, source_max_id, source_rows = _source_stats(
            rows,
            bucket_start_ts,
            effective_bucket_end_ts,
        )
        price_row = prices_by_hour.get(hour_key, {}) if prices_by_hour is not None else {}
        energy_target = energy_targets_by_hour.get(hour_key, {}) if energy_targets_by_hour is not None else {}
        charged_for_pnl = energy_target.get("charged_wh", round(charged_wh, 3))
        discharged_for_pnl = energy_target.get("discharged_wh", round(discharged_wh, 3))
        grid_from_for_home = energy_target.get("grid_from_wh", grid_from_wh)
        grid_to_for_home = energy_target.get("grid_to_wh", grid_to_wh)
        production_wh = production_by_hour.get(hour_key) if production_by_hour is not None else None
        estimated_home_load_wh = None
        if production_wh is not None and grid_from_for_home is not None and grid_to_for_home is not None:
            estimated_home_load_wh = report._round_wh(
                production_wh + grid_from_for_home - grid_to_for_home + discharged_for_pnl - charged_for_pnl
            )
        pnl_values = report.build_battery_flow_pnl_window(
            power_points=power_points,
            grid_from_samples=grid_from_samples,
            grid_to_samples=grid_to_samples,
            start_ts=bucket_start_ts,
            end_ts=effective_bucket_end_ts,
            consumer_eur_per_kwh=price_row.get("consumer_eur_per_kwh"),
            spot_eur_per_kwh=price_row.get("spot_eur_per_kwh"),
            estimated_home_load_wh=estimated_home_load_wh,
            expected_charged_wh=charged_for_pnl,
            expected_discharged_wh=discharged_for_pnl,
            fallback_seconds=fallback_seconds,
        ) if is_elapsed else {
            "battery_charge_grid_wh": None,
            "battery_charge_surplus_wh": None,
            "battery_discharge_home_wh": None,
            "battery_discharge_export_wh": None,
            "battery_charge_cost_milli_eur": None,
            "battery_home_savings_milli_eur": None,
            "battery_export_revenue_milli_eur": None,
            "battery_flow_pnl_milli_eur": None,
            "battery_pnl_status": "not_calculated",
            "battery_pnl_method_version": None,
        }

        hourly_rows.append(
            {
                "local_date": target_day_start.strftime("%Y-%m-%d"),
                "local_hour": hour,
                "hour_start_ts": bucket_start_ts,
                "hour_end_ts": effective_bucket_end_ts if is_elapsed else nominal_end_ts,
                "charged_wh": round(charged_wh, 3),
                "discharged_wh": round(discharged_wh, 3),
                "battery_pct_start": _round(battery_start, 2),
                "battery_pct_end": _round(battery_end, 2),
                "battery_pct_delta": _round(battery_delta, 2),
                "grid_from_wh": _round(grid_from_wh, 3),
                "grid_to_wh": _round(grid_to_wh, 3),
                "estimated_home_load_wh": estimated_home_load_wh,
                "consumer_eur_per_kwh": _round(price_row.get("consumer_eur_per_kwh"), 6),
                "spot_eur_per_kwh": _round(price_row.get("spot_eur_per_kwh"), 6),
                "price_source": price_row.get("price_source"),
                **pnl_values,
                "source_min_id": source_min_id,
                "source_max_id": source_max_id,
                "source_rows": source_rows,
                "computed_at": computed_at_str,
            }
        )
    return hourly_rows


def upsert_hourly_rows(connection: pymysql.connections.Connection, table: str, rows: list[dict[str, Any]]) -> int:
    if not rows:
        return 0
    quoted_table = report._quote_identifier(table)
    sql = (
        f"INSERT INTO {quoted_table} ("
        "local_date, local_hour, hour_start_ts, hour_end_ts, charged_wh, discharged_wh, "
        "battery_pct_start, battery_pct_end, battery_pct_delta, grid_from_wh, grid_to_wh, "
        "estimated_home_load_wh, "
        "consumer_eur_per_kwh, spot_eur_per_kwh, price_source, "
        "battery_charge_grid_wh, battery_charge_surplus_wh, battery_discharge_home_wh, battery_discharge_export_wh, "
        "battery_charge_cost_milli_eur, battery_home_savings_milli_eur, battery_export_revenue_milli_eur, "
        "battery_flow_pnl_milli_eur, battery_pnl_status, battery_pnl_method_version, "
        "source_min_id, source_max_id, source_rows, computed_at"
        ") VALUES ("
        "%(local_date)s, %(local_hour)s, %(hour_start_ts)s, %(hour_end_ts)s, %(charged_wh)s, %(discharged_wh)s, "
        "%(battery_pct_start)s, %(battery_pct_end)s, %(battery_pct_delta)s, %(grid_from_wh)s, %(grid_to_wh)s, "
        "%(estimated_home_load_wh)s, "
        "%(consumer_eur_per_kwh)s, %(spot_eur_per_kwh)s, %(price_source)s, "
        "%(battery_charge_grid_wh)s, %(battery_charge_surplus_wh)s, %(battery_discharge_home_wh)s, %(battery_discharge_export_wh)s, "
        "%(battery_charge_cost_milli_eur)s, %(battery_home_savings_milli_eur)s, %(battery_export_revenue_milli_eur)s, "
        "%(battery_flow_pnl_milli_eur)s, %(battery_pnl_status)s, %(battery_pnl_method_version)s, "
        "%(source_min_id)s, %(source_max_id)s, %(source_rows)s, %(computed_at)s"
        ") ON DUPLICATE KEY UPDATE "
        "hour_start_ts = VALUES(hour_start_ts), "
        "hour_end_ts = VALUES(hour_end_ts), "
        "charged_wh = VALUES(charged_wh), "
        "discharged_wh = VALUES(discharged_wh), "
        "battery_pct_start = VALUES(battery_pct_start), "
        "battery_pct_end = VALUES(battery_pct_end), "
        "battery_pct_delta = VALUES(battery_pct_delta), "
        "grid_from_wh = VALUES(grid_from_wh), "
        "grid_to_wh = VALUES(grid_to_wh), "
        "estimated_home_load_wh = VALUES(estimated_home_load_wh), "
        "consumer_eur_per_kwh = VALUES(consumer_eur_per_kwh), "
        "spot_eur_per_kwh = VALUES(spot_eur_per_kwh), "
        "price_source = VALUES(price_source), "
        "battery_charge_grid_wh = VALUES(battery_charge_grid_wh), "
        "battery_charge_surplus_wh = VALUES(battery_charge_surplus_wh), "
        "battery_discharge_home_wh = VALUES(battery_discharge_home_wh), "
        "battery_discharge_export_wh = VALUES(battery_discharge_export_wh), "
        "battery_charge_cost_milli_eur = VALUES(battery_charge_cost_milli_eur), "
        "battery_home_savings_milli_eur = VALUES(battery_home_savings_milli_eur), "
        "battery_export_revenue_milli_eur = VALUES(battery_export_revenue_milli_eur), "
        "battery_flow_pnl_milli_eur = VALUES(battery_flow_pnl_milli_eur), "
        "battery_pnl_status = VALUES(battery_pnl_status), "
        "battery_pnl_method_version = VALUES(battery_pnl_method_version), "
        "source_min_id = VALUES(source_min_id), "
        "source_max_id = VALUES(source_max_id), "
        "source_rows = VALUES(source_rows), "
        "computed_at = VALUES(computed_at)"
    )
    with connection.cursor() as cursor:
        cursor.executemany(sql, rows)
    return len(rows)


def update_pnl_rows(connection: pymysql.connections.Connection, table: str, rows: list[dict[str, Any]]) -> int:
    if not rows:
        return 0
    quoted_table = report._quote_identifier(table)
    sql = (
        f"UPDATE {quoted_table} SET "
        "estimated_home_load_wh = %(estimated_home_load_wh)s, "
        "battery_charge_grid_wh = %(battery_charge_grid_wh)s, "
        "battery_charge_surplus_wh = %(battery_charge_surplus_wh)s, "
        "battery_discharge_home_wh = %(battery_discharge_home_wh)s, "
        "battery_discharge_export_wh = %(battery_discharge_export_wh)s, "
        "battery_charge_cost_milli_eur = %(battery_charge_cost_milli_eur)s, "
        "battery_home_savings_milli_eur = %(battery_home_savings_milli_eur)s, "
        "battery_export_revenue_milli_eur = %(battery_export_revenue_milli_eur)s, "
        "battery_flow_pnl_milli_eur = %(battery_flow_pnl_milli_eur)s, "
        "battery_pnl_status = %(battery_pnl_status)s, "
        "battery_pnl_method_version = %(battery_pnl_method_version)s "
        "WHERE local_date = %(local_date)s AND local_hour = %(local_hour)s"
    )
    with connection.cursor() as cursor:
        cursor.executemany(sql, rows)
    return len(rows)


def log_update(
    connection: pymysql.connections.Connection,
    table: str,
    *,
    target_date: str,
    run_type: str,
    success: bool,
    hours_upserted: int,
    started_at: datetime,
    error_text: str | None,
) -> None:
    quoted_table = report._quote_identifier(table)
    finished_at = datetime.now(ZoneInfo("UTC"))
    sql = (
        f"INSERT INTO {quoted_table} "
        "(target_date, run_type, success, hours_upserted, started_at, finished_at, error_text) "
        "VALUES (%s, %s, %s, %s, %s, %s, %s)"
    )
    with connection.cursor() as cursor:
        cursor.execute(
            sql,
            (
                target_date,
                run_type,
                1 if success else 0,
                hours_upserted,
                started_at.strftime("%Y-%m-%d %H:%M:%S"),
                finished_at.strftime("%Y-%m-%d %H:%M:%S"),
                error_text,
            ),
        )


def update_day(
    connection: pymysql.connections.Connection,
    *,
    target_day_start: datetime,
    args: argparse.Namespace,
    config: dict[str, Any],
    run_type: str,
) -> dict[str, Any]:
    started_at = datetime.now(ZoneInfo("UTC"))
    date_str = target_day_start.strftime("%Y-%m-%d")
    try:
        day_start_ts = report._dt_to_ts(target_day_start)
        day_end_ts = report._dt_to_ts(target_day_start + timedelta(days=1))
        analysis_end_ts = min(day_end_ts, int(datetime.now(ZoneInfo("UTC")).timestamp()))
        fetch_end_ts = max(analysis_end_ts, day_start_ts)

        rows = report.fetch_status_rows(
            host=config["host"],
            port=int(config["port"]),
            user=config["user"],
            password=config["password"],
            database=config["database"],
            table=args.table,
            start_ts=day_start_ts,
            end_ts=fetch_end_ts,
        )
        prices_by_hour = load_price_ticks_for_day(connection, args.price_table, target_day_start)
        production_by_hour = load_production_for_day(
            connection,
            database=args.production_database or os.getenv("ENPHASE_HISTORY_DATABASE", DEFAULT_PRODUCTION_DATABASE),
            table=args.production_table or os.getenv("ENPHASE_PRODUCTION_TABLE", DEFAULT_PRODUCTION_TABLE),
            system_id=args.production_system_id
            or int(os.getenv("ENPHASE_SYSTEM_ID", str(DEFAULT_PRODUCTION_SYSTEM_ID))),
            source=args.production_source or os.getenv("ENPHASE_PRODUCTION_SOURCE", DEFAULT_PRODUCTION_SOURCE),
            target_day_start=target_day_start,
        )
        energy_targets = (
            load_existing_energy_targets(connection, args.aggregate_table, target_day_start)
            if args.pnl_only
            else None
        )
        if args.pnl_only and len(energy_targets or {}) != 24:
            raise RuntimeError(
                f"PnL-only update requires 24 existing hourly rows; found {len(energy_targets or {})}."
            )
        hourly_rows = build_hourly_input_rows(
            rows,
            target_day_start=target_day_start,
            analysis_end_ts=analysis_end_ts,
            tz=ZoneInfo(args.timezone),
            fallback_seconds=args.fallback_seconds,
            prices_by_hour=prices_by_hour,
            production_by_hour=production_by_hour,
            energy_targets_by_hour=energy_targets,
            computed_at=started_at,
        )
        status_counts: dict[str, int] = {}
        for hourly_row in hourly_rows:
            status = str(hourly_row.get("battery_pnl_status") or "unknown")
            status_counts[status] = status_counts.get(status, 0) + 1

        if args.dry_run:
            upserted = 0
        elif args.pnl_only:
            upserted = update_pnl_rows(connection, args.aggregate_table, hourly_rows)
        else:
            upserted = upsert_hourly_rows(connection, args.aggregate_table, hourly_rows)

        if not args.dry_run:
            log_update(
                connection,
                args.log_table,
                target_date=date_str,
                run_type=run_type,
                success=True,
                hours_upserted=upserted,
                started_at=started_at,
                error_text=None,
            )
        return {
            "date": date_str,
            "success": True,
            "hours_upserted": upserted,
            "source_rows": len(rows),
            "status_counts": status_counts,
        }
    except Exception as exc:
        if not args.dry_run:
            log_update(
                connection,
                args.log_table,
                target_date=date_str,
                run_type=run_type,
                success=False,
                hours_upserted=0,
                started_at=started_at,
                error_text=str(exc),
            )
        return {"date": date_str, "success": False, "hours_upserted": 0, "source_rows": 0, "error": str(exc)}


def main() -> int:
    load_env_file()
    args = parse_args()
    tz = ZoneInfo(args.timezone)
    try:
        target_days = resolve_target_days(args, tz)
        config = db_config(args)
        run_type = (
            RUN_TYPE_PNL_BACKFILL
            if args.pnl_only
            else (RUN_TYPE_MANUAL if args.date or args.start_date or args.days_back is not None else RUN_TYPE_DAILY)
        )
        connection = connect(config)
        try:
            if not args.dry_run:
                ensure_tables(connection, args.aggregate_table, args.log_table)
            results = [
                update_day(connection, target_day_start=day, args=args, config=config, run_type=run_type)
                for day in target_days
            ]
        finally:
            connection.close()
    except Exception as exc:
        print(f"Error: {exc}", file=sys.stderr)
        return 1

    exit_code = 0
    for result in results:
        if result["success"]:
            print(
                f"{result['date']} ok hours_upserted={result['hours_upserted']} "
                f"source_rows={result['source_rows']} statuses={result['status_counts']}"
            )
        else:
            exit_code = 1
            print(f"{result['date']} failed error={result['error']}", file=sys.stderr)
    return exit_code


if __name__ == "__main__":
    raise SystemExit(main())
