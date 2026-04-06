#!/usr/bin/env python3
"""
Compute one-day hourly battery/grid metrics from the live MariaDB status_updates replica.

Default source:
  - host: 127.0.0.1
  - port: 3306
  - user: root
  - password: ""
  - database: sqlite_replication

Default report:
  - date: today in Europe/Amsterdam
  - output: JSON to stdout
  - optional save to file

Metrics per hour:
  - charged_wh
  - discharged_wh
  - battery_pct_start / battery_pct_end / battery_pct_delta
  - grid_from_wh
  - grid_to_wh
  - price_eur_per_kwh
  - grid_from_cost / grid_to_cost / net_cost

Interpolation:
  - linear interpolation between nearest surrounding readings
  - exact boundary readings win
  - one-sided carry is allowed only within a bounded fallback window
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
from dataclasses import dataclass
from datetime import datetime, timedelta
from decimal import Decimal
from pathlib import Path
from typing import Any, Iterable
from zoneinfo import ZoneInfo

import pymysql


DEFAULT_HOST = os.getenv("MARIADB_HOST", "127.0.0.1")
DEFAULT_PORT = int(os.getenv("MARIADB_PORT", "3306"))
DEFAULT_USER = os.getenv("MARIADB_USER", "root")
DEFAULT_PASSWORD = os.getenv("MARIADB_PASSWORD", "")
DEFAULT_DATABASE = os.getenv("MARIADB_DATABASE", "sqlite_replication")
DEFAULT_TABLE = "status_updates"
DEFAULT_TIMEZONE = "Europe/Amsterdam"
EVENT_TYPE_CHANGE = "change"
BOUNDARY_FALLBACK_MAX_SECONDS = 3600
REPO_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_PRICE_ROOT = REPO_ROOT / "main" / "data" / "price"


@dataclass(frozen=True)
class StatusRow:
    id: int
    event_type: str
    old_value: Any
    new_value: Any
    p1_total_power: int | None
    electric_level: int | None
    timestamp: int
    total_act_x100: int | None
    total_act_ret_x100: int | None


@dataclass(frozen=True)
class NumericSample:
    ts: int
    value: float


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Build one-day hourly grid/battery metrics from sqlite_replication.status_updates."
    )
    parser.add_argument(
        "--date",
        default=None,
        help="Target date in YYYY-MM-DD. Default: today in Europe/Amsterdam.",
    )
    parser.add_argument("--host", default=DEFAULT_HOST, help=f"MariaDB host (default: {DEFAULT_HOST})")
    parser.add_argument("--port", type=int, default=DEFAULT_PORT, help=f"MariaDB port (default: {DEFAULT_PORT})")
    parser.add_argument("--user", default=DEFAULT_USER, help=f"MariaDB user (default: {DEFAULT_USER})")
    parser.add_argument(
        "--password",
        default=DEFAULT_PASSWORD,
        help="MariaDB password (default: empty string or MARIADB_PASSWORD env value).",
    )
    parser.add_argument(
        "--database",
        default=DEFAULT_DATABASE,
        help=f"MariaDB database (default: {DEFAULT_DATABASE})",
    )
    parser.add_argument(
        "--table",
        default=DEFAULT_TABLE,
        help=f"Source table (default: {DEFAULT_TABLE})",
    )
    parser.add_argument(
        "--timezone",
        default=DEFAULT_TIMEZONE,
        help=f"IANA timezone (default: {DEFAULT_TIMEZONE})",
    )
    parser.add_argument(
        "--fallback-seconds",
        type=int,
        default=BOUNDARY_FALLBACK_MAX_SECONDS,
        help=f"Maximum one-sided boundary carry in seconds (default: {BOUNDARY_FALLBACK_MAX_SECONDS})",
    )
    parser.add_argument(
        "--output",
        default=None,
        help="Optional output JSON file path. Parent directories are created automatically.",
    )
    return parser.parse_args()


def _json_default(value: Any) -> Any:
    if isinstance(value, Decimal):
        return float(value)
    raise TypeError(f"Object of type {type(value).__name__} is not JSON serializable")


def _round_or_none(value: float | None, digits: int = 2) -> float | None:
    if value is None:
        return None
    return round(value, digits)


def build_saved_report_path(
    target_day_start: datetime,
    *,
    data_root: Path | None = None,
) -> Path:
    base_root = data_root or (REPO_ROOT / "daily_report" / "data")
    yyyymm = target_day_start.strftime("%Y%m")
    yyyymmdd = target_day_start.strftime("%Y%m%d")
    return base_root / yyyymm / f"daily_report_{yyyymmdd}.json"


def save_report_json(report: dict[str, Any], output_path: str | Path) -> Path:
    output = Path(output_path)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, indent=2, default=_json_default), encoding="utf-8")
    return output


def _parse_numeric_value(value: Any) -> float | None:
    if value is None or isinstance(value, bool):
        return None
    if isinstance(value, (int, float, Decimal)):
        return float(value)
    if isinstance(value, bytes):
        value = value.decode("utf-8", errors="ignore")
    if isinstance(value, str):
        raw = value.strip()
        if raw == "":
            return None
        try:
            parsed = json.loads(raw)
        except (TypeError, ValueError, json.JSONDecodeError):
            parsed = raw
        if isinstance(parsed, bool):
            return None
        if isinstance(parsed, (int, float)):
            return float(parsed)
        if isinstance(parsed, str):
            try:
                return float(parsed.strip())
            except (TypeError, ValueError):
                return None
    return None


def _dt_to_ts(dt: datetime) -> int:
    return int(dt.timestamp())


def _build_target_day(date_arg: str | None, tz: ZoneInfo) -> datetime:
    if date_arg:
        try:
            return datetime.strptime(date_arg, "%Y-%m-%d").replace(tzinfo=tz)
        except ValueError as exc:
            raise ValueError(f"Invalid --date '{date_arg}', expected YYYY-MM-DD") from exc
    now = datetime.now(tz)
    return datetime(now.year, now.month, now.day, tzinfo=tz)


def _quote_identifier(identifier: str) -> str:
    return "`" + identifier.replace("`", "``") + "`"


def _build_price_file_path(target_day_start: datetime, price_root: Path = DEFAULT_PRICE_ROOT) -> Path:
    yyyymm = target_day_start.strftime("%Y%m")
    yyyymmdd = target_day_start.strftime("%Y%m%d")
    return price_root / yyyymm / f"price{yyyymmdd}.json"


def load_price_map_for_day(
    target_day_start: datetime,
    *,
    price_root: Path = DEFAULT_PRICE_ROOT,
) -> tuple[dict[str, float | None] | None, Path, bool]:
    price_path = _build_price_file_path(target_day_start, price_root=price_root)
    if not price_path.exists():
        return None, price_path, False

    try:
        payload = json.loads(price_path.read_text(encoding="utf-8"))
    except (OSError, ValueError, json.JSONDecodeError):
        return {f"{hour:02d}": None for hour in range(24)}, price_path, True

    if not isinstance(payload, dict):
        return {f"{hour:02d}": None for hour in range(24)}, price_path, True

    prices_by_hour: dict[str, float | None] = {}
    for hour in range(24):
        hour_key = f"{hour:02d}"
        prices_by_hour[hour_key] = _parse_numeric_value(payload.get(hour_key))
    return prices_by_hour, price_path, True


def fetch_status_rows(
    *,
    host: str,
    port: int,
    user: str,
    password: str,
    database: str,
    table: str,
    start_ts: int,
    end_ts: int,
) -> list[StatusRow]:
    quoted_table = _quote_identifier(table)
    main_sql = (
        f"SELECT id, type, old_value, new_value, p1_total_power, electric_level, timestamp, "
        f"total_act_x100, total_act_ret_x100 "
        f"FROM {quoted_table} "
        f"WHERE timestamp >= %s AND timestamp <= %s "
        f"ORDER BY timestamp ASC, id ASC"
    )
    prev_sql = (
        f"SELECT id, type, old_value, new_value, p1_total_power, electric_level, timestamp, "
        f"total_act_x100, total_act_ret_x100 "
        f"FROM {quoted_table} "
        f"WHERE timestamp < %s "
        f"ORDER BY timestamp DESC, id DESC "
        f"LIMIT 1"
    )
    next_sql = (
        f"SELECT id, type, old_value, new_value, p1_total_power, electric_level, timestamp, "
        f"total_act_x100, total_act_ret_x100 "
        f"FROM {quoted_table} "
        f"WHERE timestamp > %s "
        f"ORDER BY timestamp ASC, id ASC "
        f"LIMIT 1"
    )

    connection = pymysql.connect(
        host=host,
        port=port,
        user=user,
        password=password,
        database=database,
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=True,
    )
    try:
        rows: list[StatusRow] = []
        with connection.cursor() as cursor:
            cursor.execute(prev_sql, (start_ts,))
            prev_row = cursor.fetchone()
            if prev_row is not None:
                rows.append(_row_from_db(prev_row))

            cursor.execute(main_sql, (start_ts, end_ts))
            rows.extend(_row_from_db(row) for row in cursor.fetchall())

            cursor.execute(next_sql, (end_ts,))
            next_row = cursor.fetchone()
            if next_row is not None:
                rows.append(_row_from_db(next_row))
    finally:
        connection.close()

    rows.sort(key=lambda row: (row.timestamp, row.id))
    deduped: list[StatusRow] = []
    seen: set[tuple[int, int]] = set()
    for row in rows:
        key = (row.timestamp, row.id)
        if key in seen:
            continue
        seen.add(key)
        deduped.append(row)
    return deduped


def _row_from_db(row: dict[str, Any]) -> StatusRow:
    return StatusRow(
        id=int(row["id"]),
        event_type=str(row["type"]),
        old_value=row.get("old_value"),
        new_value=row.get("new_value"),
        p1_total_power=int(row["p1_total_power"]) if row.get("p1_total_power") is not None else None,
        electric_level=int(row["electric_level"]) if row.get("electric_level") is not None else None,
        timestamp=int(row["timestamp"]),
        total_act_x100=int(row["total_act_x100"]) if row.get("total_act_x100") is not None else None,
        total_act_ret_x100=int(row["total_act_ret_x100"]) if row.get("total_act_ret_x100") is not None else None,
    )


def _build_samples(rows: Iterable[StatusRow], attr: str) -> list[NumericSample]:
    latest_by_ts: dict[int, float] = {}
    for row in rows:
        raw_value = getattr(row, attr)
        value = _parse_numeric_value(raw_value)
        if value is None:
            continue
        latest_by_ts[row.timestamp] = value
    return [NumericSample(ts=ts, value=latest_by_ts[ts]) for ts in sorted(latest_by_ts)]


def _find_before_after(samples: list[NumericSample], target_ts: int) -> tuple[NumericSample | None, NumericSample | None]:
    before: NumericSample | None = None
    after: NumericSample | None = None
    for sample in samples:
        if sample.ts <= target_ts:
            before = sample
            continue
        after = sample
        break
    return before, after


def _samples_in_window(
    samples: list[NumericSample],
    start_ts: int,
    end_ts: int,
) -> list[NumericSample]:
    return [sample for sample in samples if start_ts <= sample.ts <= end_ts]


def interpolate_boundary_value(
    samples: list[NumericSample],
    target_ts: int,
    fallback_seconds: int = BOUNDARY_FALLBACK_MAX_SECONDS,
) -> float | None:
    if not samples:
        return None

    before, after = _find_before_after(samples, target_ts)
    if before is not None and before.ts == target_ts:
        return before.value
    if after is not None and after.ts == target_ts:
        return after.value

    if before is not None and after is not None:
        span = after.ts - before.ts
        if span <= 0:
            return after.value
        ratio = (target_ts - before.ts) / span
        return before.value + ((after.value - before.value) * ratio)

    if before is not None and (target_ts - before.ts) <= fallback_seconds:
        return before.value
    if after is not None and (after.ts - target_ts) <= fallback_seconds:
        return after.value
    return None


def _change_points(rows: Iterable[StatusRow], analysis_end_ts: int) -> list[tuple[int, float]]:
    points: list[tuple[int, float]] = []
    for row in rows:
        if row.event_type != EVENT_TYPE_CHANGE or row.timestamp > analysis_end_ts:
            continue
        power = _parse_numeric_value(row.new_value)
        if power is None:
            continue
        points.append((row.timestamp, power))
    points.sort(key=lambda item: item[0])
    return points


def _integrate_power_window(
    points: list[tuple[int, float]],
    start_ts: int,
    end_ts: int,
) -> tuple[float, float]:
    if end_ts <= start_ts or not points:
        return 0.0, 0.0

    charged_wh = 0.0
    discharged_wh = 0.0
    for idx, (point_ts, power) in enumerate(points):
        segment_start = point_ts
        segment_end = points[idx + 1][0] if idx < len(points) - 1 else end_ts
        if segment_end <= start_ts or segment_start >= end_ts:
            continue
        overlap_start = max(start_ts, segment_start)
        overlap_end = min(end_ts, segment_end)
        if overlap_end <= overlap_start:
            continue
        wh = abs(power) * ((overlap_end - overlap_start) / 3600.0)
        if power > 0:
            charged_wh += wh
        elif power < 0:
            discharged_wh += wh
    return charged_wh, discharged_wh


def build_daily_report(
    rows: list[StatusRow],
    *,
    target_day_start: datetime,
    analysis_end_ts: int,
    tz: ZoneInfo,
    fallback_seconds: int = BOUNDARY_FALLBACK_MAX_SECONDS,
    prices_by_hour: dict[str, float | None] | None = None,
    price_file_path: Path | None = None,
    price_file_found: bool | None = None,
) -> dict[str, Any]:
    day_start_ts = _dt_to_ts(target_day_start)
    day_end_ts = _dt_to_ts(target_day_start + timedelta(days=1))
    effective_end_ts = max(day_start_ts, min(day_end_ts, analysis_end_ts))
    is_partial_day = effective_end_ts < day_end_ts

    battery_samples = _build_samples(rows, "electric_level")
    grid_from_samples = _build_samples(rows, "total_act_x100")
    grid_to_samples = _build_samples(rows, "total_act_ret_x100")
    power_points = _change_points(rows, effective_end_ts)

    hours: list[dict[str, Any]] = []
    total_charged = 0.0
    total_discharged = 0.0
    total_grid_from = 0.0
    total_grid_to = 0.0
    total_grid_from_cost = 0.0
    total_grid_to_cost = 0.0
    total_net_cost = 0.0

    if price_file_found is None:
        price_file_found = prices_by_hour is not None
    price_hours_available = 0
    if prices_by_hour is not None:
        price_hours_available = sum(1 for value in prices_by_hour.values() if value is not None)

    for hour in range(24):
        hour_key = f"{hour:02d}"
        bucket_start_ts = day_start_ts + (hour * 3600)
        nominal_end_ts = bucket_start_ts + 3600
        effective_bucket_end_ts = min(nominal_end_ts, effective_end_ts)
        is_elapsed = bucket_start_ts < effective_end_ts
        is_partial_hour = is_elapsed and effective_bucket_end_ts < nominal_end_ts

        charged_wh = 0.0
        discharged_wh = 0.0
        battery_start = None
        battery_end = None
        battery_delta = None
        grid_from_wh = None
        grid_to_wh = None
        price_eur_per_kwh = prices_by_hour.get(hour_key) if prices_by_hour is not None else None
        grid_from_cost = None
        grid_to_cost = None
        net_cost = None

        if is_elapsed:
            charged_wh, discharged_wh = _integrate_power_window(
                power_points,
                bucket_start_ts,
                effective_bucket_end_ts,
            )
            battery_start = interpolate_boundary_value(
                battery_samples, bucket_start_ts, fallback_seconds=fallback_seconds
            )
            battery_end = interpolate_boundary_value(
                battery_samples, effective_bucket_end_ts, fallback_seconds=fallback_seconds
            )
            hourly_battery_samples = _samples_in_window(
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

            grid_from_start = interpolate_boundary_value(
                grid_from_samples, bucket_start_ts, fallback_seconds=fallback_seconds
            )
            grid_from_end = interpolate_boundary_value(
                grid_from_samples, effective_bucket_end_ts, fallback_seconds=fallback_seconds
            )
            if grid_from_start is not None and grid_from_end is not None:
                grid_from_wh = (grid_from_end - grid_from_start) / 100.0

            grid_to_start = interpolate_boundary_value(
                grid_to_samples, bucket_start_ts, fallback_seconds=fallback_seconds
            )
            grid_to_end = interpolate_boundary_value(
                grid_to_samples, effective_bucket_end_ts, fallback_seconds=fallback_seconds
            )
            if grid_to_start is not None and grid_to_end is not None:
                grid_to_wh = (grid_to_end - grid_to_start) / 100.0

            total_charged += charged_wh
            total_discharged += discharged_wh
            if grid_from_wh is not None:
                total_grid_from += grid_from_wh
            if grid_to_wh is not None:
                total_grid_to += grid_to_wh

            if price_eur_per_kwh is not None:
                if grid_from_wh is not None:
                    grid_from_cost = (grid_from_wh / 1000.0) * price_eur_per_kwh
                    total_grid_from_cost += grid_from_cost
                if grid_to_wh is not None:
                    grid_to_cost = -1.0 * ((grid_to_wh / 1000.0) * price_eur_per_kwh)
                    total_grid_to_cost += grid_to_cost
                if grid_from_cost is not None or grid_to_cost is not None:
                    net_cost = (grid_from_cost or 0.0) + (grid_to_cost or 0.0)
                    total_net_cost += net_cost

        hours.append(
            {
                "hour": hour_key,
                "charged_wh": round(charged_wh, 2),
                "discharged_wh": round(discharged_wh, 2),
                "battery_pct_start": _round_or_none(battery_start),
                "battery_pct_end": _round_or_none(battery_end),
                "battery_pct_delta": _round_or_none(battery_delta),
                "grid_from_wh": _round_or_none(grid_from_wh),
                "grid_to_wh": _round_or_none(grid_to_wh),
                "price_eur_per_kwh": _round_or_none(price_eur_per_kwh, digits=4),
                "grid_from_cost": _round_or_none(grid_from_cost, digits=4),
                "grid_to_cost": _round_or_none(grid_to_cost, digits=4),
                "net_cost": _round_or_none(net_cost, digits=4),
                "is_partial_hour": is_partial_hour,
            }
        )

    day_battery_start = interpolate_boundary_value(
        battery_samples, day_start_ts, fallback_seconds=fallback_seconds
    )
    day_battery_end = interpolate_boundary_value(
        battery_samples, effective_end_ts, fallback_seconds=fallback_seconds
    )
    day_battery_samples = _samples_in_window(
        battery_samples,
        day_start_ts,
        effective_end_ts,
    )
    if day_battery_start is None and day_battery_samples:
        day_battery_start = day_battery_samples[0].value
    if day_battery_end is None and day_battery_samples:
        day_battery_end = day_battery_samples[-1].value
    day_battery_delta = None
    if day_battery_start is not None and day_battery_end is not None:
        day_battery_delta = day_battery_end - day_battery_start

    return {
        "date": target_day_start.strftime("%Y-%m-%d"),
        "timezone": tz.key,
        "day_start_ts": day_start_ts,
        "day_end_ts": day_end_ts,
        "analysis_end_ts": effective_end_ts,
        "is_partial_day": is_partial_day,
        "price_file_found": price_file_found,
        "price_file_path": str(price_file_path) if price_file_path is not None else None,
        "price_hours_available": price_hours_available,
        "hours": hours,
        "totals": {
            "charged_wh": round(total_charged, 2),
            "discharged_wh": round(total_discharged, 2),
            "battery_pct_delta_total": _round_or_none(day_battery_delta),
            "grid_from_wh": round(total_grid_from, 2),
            "grid_to_wh": round(total_grid_to, 2),
            "grid_from_cost": _round_or_none(total_grid_from_cost, digits=4) if price_file_found else None,
            "grid_to_cost": _round_or_none(total_grid_to_cost, digits=4) if price_file_found else None,
            "net_cost": _round_or_none(total_net_cost, digits=4) if price_file_found else None,
        },
    }


def main() -> int:
    args = parse_args()
    tz = ZoneInfo(args.timezone)
    target_day_start = _build_target_day(args.date, tz)
    day_start_ts = _dt_to_ts(target_day_start)
    day_end_ts = _dt_to_ts(target_day_start + timedelta(days=1))
    now_ts = int(time.time())
    analysis_end_ts = min(day_end_ts, now_ts)
    fetch_end_ts = max(analysis_end_ts, day_start_ts)

    try:
        prices_by_hour, price_file_path, price_file_found = load_price_map_for_day(target_day_start)
        rows = fetch_status_rows(
            host=args.host,
            port=args.port,
            user=args.user,
            password=args.password,
            database=args.database,
            table=args.table,
            start_ts=day_start_ts,
            end_ts=fetch_end_ts,
        )
        report = build_daily_report(
            rows,
            target_day_start=target_day_start,
            analysis_end_ts=analysis_end_ts,
            tz=tz,
            fallback_seconds=args.fallback_seconds,
            prices_by_hour=prices_by_hour,
            price_file_path=price_file_path,
            price_file_found=price_file_found,
        )
    except Exception as exc:
        print(f"Error: {exc}", file=sys.stderr)
        return 1

    if args.output:
        save_report_json(report, args.output)

    print(json.dumps(report, indent=2, default=_json_default))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
