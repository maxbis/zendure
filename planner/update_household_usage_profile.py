from __future__ import annotations

import argparse
from datetime import date, datetime, timedelta
import json
import math
import os
from pathlib import Path
import subprocess
import sys
import tempfile
from typing import Any, Iterable, Sequence
from zoneinfo import ZoneInfo

from planner.config import load_settings


SCHEMA_VERSION = 1
DEFAULT_LOOKBACK_DAYS = 30
DEFAULT_MIN_SAMPLES = 14
DEFAULT_PERCENTILE = 60.0
DEFAULT_ROUND_STEP_W = 10
DEFAULT_TABLE = "hourly_report_inputs"
DEFAULT_OUTPUT_FILE_NAME = "household_usage_profile.json"
DEFAULT_ENV_FILE = Path(__file__).resolve().parents[1] / "daily_report" / ".env"
PHP_ROW_READER = Path(__file__).with_name("read_household_usage_rows.php")


class ProfileNotReady(RuntimeError):
    """Raised when the source data is not complete enough to publish a profile."""


def load_env_file(path: Path = DEFAULT_ENV_FILE) -> None:
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


def linear_percentile(values: Sequence[float], percentile: float) -> float:
    if not values:
        raise ValueError("Cannot calculate a percentile without values.")
    if not 0.0 <= percentile <= 100.0:
        raise ValueError("Percentile must be between 0 and 100.")
    ordered = sorted(float(value) for value in values)
    position = (len(ordered) - 1) * (percentile / 100.0)
    lower = int(math.floor(position))
    upper = int(math.ceil(position))
    if lower == upper:
        return ordered[lower]
    fraction = position - lower
    return ordered[lower] * (1.0 - fraction) + ordered[upper] * fraction


def round_to_step(value: float, step: int) -> int:
    if step <= 0:
        raise ValueError("Round step must be greater than zero.")
    return max(0, int(math.floor((value / step) + 0.5)) * step)


def _date_text(value: Any) -> str | None:
    if isinstance(value, datetime):
        return value.date().isoformat()
    if isinstance(value, date):
        return value.isoformat()
    if isinstance(value, str):
        try:
            return date.fromisoformat(value).isoformat()
        except ValueError:
            return None
    return None


def _complete_days(
    rows: Iterable[dict[str, Any]],
    today: date,
) -> dict[str, dict[int, float]]:
    by_date: dict[str, dict[int, float]] = {}
    invalid_dates: set[str] = set()
    for row in rows:
        date_text = _date_text(row.get("local_date"))
        if date_text is None or date_text >= today.isoformat():
            continue
        try:
            hour = int(row.get("local_hour"))
            value = float(row.get("estimated_home_load_wh"))
        except (TypeError, ValueError):
            continue
        if hour < 0 or hour > 23 or not math.isfinite(value) or value < 0:
            continue
        day = by_date.setdefault(date_text, {})
        if hour in day:
            invalid_dates.add(date_text)
            continue
        day[hour] = value

    expected_hours = set(range(24))
    return {
        date_text: values
        for date_text, values in by_date.items()
        if date_text not in invalid_dates and set(values) == expected_hours
    }


def build_profile(
    rows: Iterable[dict[str, Any]],
    default_values_w: Sequence[int],
    now: datetime,
    *,
    lookback_days: int = DEFAULT_LOOKBACK_DAYS,
    min_samples: int = DEFAULT_MIN_SAMPLES,
    percentile: float = DEFAULT_PERCENTILE,
    round_step_w: int = DEFAULT_ROUND_STEP_W,
    require_yesterday: bool = True,
) -> dict[str, Any]:
    if len(default_values_w) != 24:
        raise ValueError("The configured household profile must contain exactly 24 values.")
    if lookback_days <= 0:
        raise ValueError("Lookback days must be greater than zero.")
    if min_samples <= 0 or min_samples > lookback_days:
        raise ValueError("Minimum samples must be between 1 and the lookback days.")

    today = now.date()
    yesterday = (today - timedelta(days=1)).isoformat()
    complete_days = _complete_days(rows, today)
    if require_yesterday and yesterday not in complete_days:
        latest_complete_date = max(complete_days, default=None)
        availability = (
            f" Latest complete date is {latest_complete_date}."
            if latest_complete_date is not None
            else " No complete historical date is available in the search window."
        )
        raise ProfileNotReady(
            f"Yesterday ({yesterday}) does not have 24 valid household-usage observations."
            f"{availability}"
        )

    selected_dates = sorted(complete_days)[-lookback_days:]
    if not selected_dates:
        raise ProfileNotReady("No complete historical household-usage days are available.")

    hours: dict[str, dict[str, Any]] = {}
    fallback_hours: list[str] = []
    for hour in range(24):
        values = [complete_days[date_text][hour] for date_text in selected_dates]
        hour_key = f"{hour:02d}"
        if len(values) >= min_samples:
            value_w = round_to_step(linear_percentile(values, percentile), round_step_w)
            source = "historical_p60" if percentile == 60.0 else "historical_percentile"
        else:
            value_w = max(0, int(default_values_w[hour]))
            source = "config_fallback"
            fallback_hours.append(hour_key)
        hours[hour_key] = {
            "value_w": value_w,
            "sample_count": len(values),
            "source": source,
            "configured_fallback_w": max(0, int(default_values_w[hour])),
        }

    return {
        "schema_version": SCHEMA_VERSION,
        "timezone": str(now.tzinfo),
        "generated_at": now.isoformat(),
        "source_start_date": selected_dates[0],
        "source_end_date": selected_dates[-1],
        "completed_days_used": len(selected_dates),
        "method": f"p{percentile:g}",
        "lookback_days": lookback_days,
        "minimum_samples_per_hour": min_samples,
        "round_step_w": round_step_w,
        "fallback_hours": fallback_hours,
        "hours": hours,
    }


def atomic_write_json(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temp_path: Path | None = None
    try:
        with tempfile.NamedTemporaryFile(
            mode="w",
            encoding="utf-8",
            dir=path.parent,
            prefix=f".{path.name}.",
            suffix=".tmp",
            delete=False,
        ) as handle:
            temp_path = Path(handle.name)
            json.dump(payload, handle, indent=2, sort_keys=False)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temp_path, 0o644)
        os.replace(temp_path, path)
        temp_path = None
    finally:
        if temp_path is not None:
            temp_path.unlink(missing_ok=True)


def _database_config(args: argparse.Namespace) -> dict[str, Any]:
    return {
        "host": args.host or os.getenv("MARIADB_HOST") or "127.0.0.1",
        "port": args.port or int(os.getenv("MARIADB_PORT") or "3306"),
        "user": args.user or os.getenv("MARIADB_USER") or "root",
        "password": args.password if args.password is not None else os.getenv("MARIADB_PASSWORD", ""),
        "database": args.database or os.getenv("MARIADB_DATABASE") or "sqlite_replication",
    }


def fetch_rows(
    database_config: dict[str, Any],
    table: str,
    today: date,
    search_days: int,
) -> list[dict[str, Any]]:
    if not table.replace("_", "").isalnum():
        raise ValueError("Table name may contain only letters, numbers, and underscores.")
    start_date = today - timedelta(days=search_days)
    try:
        import pymysql
    except ModuleNotFoundError:
        return _fetch_rows_with_php(database_config, table, start_date, today)

    connection = pymysql.connect(
        **database_config,
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=True,
    )
    try:
        with connection.cursor() as cursor:
            cursor.execute(
                f"SELECT local_date, local_hour, estimated_home_load_wh "
                f"FROM `{table}` "
                "WHERE local_date >= %s AND local_date < %s "
                "AND estimated_home_load_wh IS NOT NULL "
                "ORDER BY local_date ASC, local_hour ASC",
                (start_date.isoformat(), today.isoformat()),
            )
            return list(cursor.fetchall())
    finally:
        connection.close()


def _fetch_rows_with_php(
    database_config: dict[str, Any],
    table: str,
    start_date: date,
    end_date: date,
) -> list[dict[str, Any]]:
    if not PHP_ROW_READER.is_file():
        raise RuntimeError(
            "PyMySQL is not installed and the PHP database fallback is unavailable."
        )

    child_environment = os.environ.copy()
    child_environment.update({
        "MARIADB_HOST": str(database_config["host"]),
        "MARIADB_PORT": str(database_config["port"]),
        "MARIADB_USER": str(database_config["user"]),
        "MARIADB_PASSWORD": str(database_config["password"]),
        "MARIADB_DATABASE": str(database_config["database"]),
    })
    try:
        result = subprocess.run(
            [
                "php",
                str(PHP_ROW_READER),
                start_date.isoformat(),
                end_date.isoformat(),
                table,
            ],
            check=False,
            capture_output=True,
            text=True,
            env=child_environment,
        )
    except FileNotFoundError as error:
        raise RuntimeError(
            "PyMySQL is not installed and PHP is not available for the database fallback."
        ) from error

    if result.returncode != 0:
        detail = result.stderr.strip() or "unknown PHP database error"
        raise RuntimeError(f"PHP database fallback failed: {detail}")
    try:
        rows = json.loads(result.stdout)
    except json.JSONDecodeError as error:
        raise RuntimeError("PHP database fallback returned invalid JSON.") from error
    if not isinstance(rows, list) or not all(isinstance(row, dict) for row in rows):
        raise RuntimeError("PHP database fallback returned an unexpected response.")
    return rows


def parse_args(argv: Sequence[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Generate a cached hourly household-usage profile from historical Qool data."
    )
    parser.add_argument("--lookback-days", type=int, default=DEFAULT_LOOKBACK_DAYS)
    parser.add_argument("--min-samples", type=int, default=DEFAULT_MIN_SAMPLES)
    parser.add_argument("--percentile", type=float, default=DEFAULT_PERCENTILE)
    parser.add_argument("--round-step-w", type=int, default=DEFAULT_ROUND_STEP_W)
    parser.add_argument("--output", type=Path, default=None)
    parser.add_argument("--host", default=None)
    parser.add_argument("--port", type=int, default=None)
    parser.add_argument("--user", default=None)
    parser.add_argument("--password", default=None)
    parser.add_argument("--database", default=None)
    parser.add_argument("--table", default=DEFAULT_TABLE)
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Print the generated profile without replacing the cached profile file.",
    )
    return parser.parse_args(argv)


def main(argv: Sequence[str] | None = None) -> int:
    args = parse_args(argv)
    try:
        load_env_file()
        settings = load_settings()
        timezone = ZoneInfo(settings.timezone)
        now = datetime.now(timezone)
        search_days = max(90, args.lookback_days * 3)
        rows = fetch_rows(_database_config(args), args.table, now.date(), search_days)
        payload = build_profile(
            rows,
            settings.default_household_usage_w_by_hour,
            now,
            lookback_days=args.lookback_days,
            min_samples=args.min_samples,
            percentile=args.percentile,
            round_step_w=args.round_step_w,
        )
        output_path = args.output or settings.data_dir / DEFAULT_OUTPUT_FILE_NAME
        if args.dry_run:
            print(json.dumps(payload, indent=2, sort_keys=False))
        else:
            atomic_write_json(output_path, payload)
            print(json.dumps({
                "ok": True,
                "output": str(output_path),
                "source_start_date": payload["source_start_date"],
                "source_end_date": payload["source_end_date"],
                "completed_days_used": payload["completed_days_used"],
                "fallback_hours": payload["fallback_hours"],
            }))
        return 0
    except ProfileNotReady as error:
        print(json.dumps({"ok": False, "status": "not_ready", "error": str(error)}), file=sys.stderr)
        return 2
    except Exception as error:
        print(json.dumps({"ok": False, "status": "error", "error": str(error)}), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
