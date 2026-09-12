from __future__ import annotations

import argparse
from datetime import datetime
import fcntl
import json
import os
from pathlib import Path
from statistics import fmean
import time
from typing import Any, Dict, List, Optional, Tuple

from zoneinfo import ZoneInfo

from planner.clients import PricePayload, fetch_battery_state, fetch_price_payload, fetch_shortwave_payload
from planner.config import PlannerSettings, load_settings
from planner.forecast import derive_pv_forecast_by_date
from planner.rolling_optimizer import (
    RollingInputSlot,
    build_rolling_boundaries,
    optimize_rolling_schedule,
)


DEFAULT_HORIZON_HOURS = 24
DEFAULT_EXTEND_TO_MIDNIGHT = True
DEFAULT_SOC_STEP_WH = 50.0
DEFAULT_TERMINAL_VALUE_FACTOR = 1.0
DEFAULT_LOG_FILE_NAME = "optimizer_schedule.log"
DEFAULT_LATEST_FILE_NAME = "optimizer_schedule_latest.json"
DEFAULT_LOCK_FILE_NAME = "optimizer_schedule.lock"


def _env_int(name: str, default: int, minimum: int = 1) -> int:
    try:
        return max(minimum, int(os.getenv(name, str(default))))
    except (TypeError, ValueError):
        return default


def _env_float(name: str, default: float, minimum: float = 0.0) -> float:
    try:
        return max(minimum, float(os.getenv(name, str(default))))
    except (TypeError, ValueError):
        return default


def _env_bool(name: str, default: bool) -> bool:
    raw = os.getenv(name)
    if raw is None:
        return default
    return raw.strip().lower() not in ("0", "false", "off", "no")


def shadow_log_path(settings: PlannerSettings) -> Path:
    configured = os.getenv("PLANNER_SHADOW_LOG_PATH")
    if configured:
        return Path(configured).expanduser().resolve()
    return settings.data_dir / DEFAULT_LOG_FILE_NAME


def latest_schedule_path(settings: PlannerSettings) -> Path:
    configured = os.getenv("PLANNER_LATEST_SCHEDULE_PATH")
    if configured:
        return Path(configured).expanduser().resolve()
    return settings.data_dir / DEFAULT_LATEST_FILE_NAME


def run_lock_path(settings: PlannerSettings) -> Path:
    configured = os.getenv("PLANNER_RUN_LOCK_PATH")
    if configured:
        return Path(configured).expanduser().resolve()
    return settings.data_dir / DEFAULT_LOCK_FILE_NAME


def write_json_atomic(path: Path, payload: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary_path = path.with_name(f".{path.name}.{os.getpid()}.tmp")
    serialized = json.dumps(payload, separators=(",", ":"), sort_keys=False)
    try:
        with temporary_path.open("w", encoding="utf-8") as handle:
            handle.write(serialized + "\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary_path, path)
    finally:
        if temporary_path.exists():
            temporary_path.unlink()


def _price_for_slot(
    prices: PricePayload,
    date_text: str,
    hour: int,
) -> Tuple[float, float, str]:
    compact_date = date_text.replace("-", "")
    today_date = (prices.today_date or "").replace("-", "")
    tomorrow_date = (prices.tomorrow_date or "").replace("-", "")

    if compact_date == today_date:
        import_price = prices.import_today[hour]
        export_price = prices.export_today[hour]
        source = "official"
    elif compact_date == tomorrow_date:
        import_price = prices.import_tomorrow[hour]
        export_price = prices.export_tomorrow[hour]
        source = "official"
    else:
        import_price = prices.import_today[hour]
        export_price = prices.export_today[hour]
        source = "repeat_today"

    if import_price is None or export_price is None:
        import_price = prices.import_today[hour]
        export_price = prices.export_today[hour]
        source = "repeat_today"
    if import_price is None or export_price is None:
        raise RuntimeError(f"No usable price for {date_text} hour {hour:02d}")
    return float(import_price), float(export_price), source


def build_shadow_slots(
    *,
    now: datetime,
    settings: PlannerSettings,
    prices: PricePayload,
    shortwave_payload: dict,
    horizon_hours: int,
    extend_to_midnight: bool = True,
) -> List[RollingInputSlot]:
    pv_by_date = derive_pv_forecast_by_date(shortwave_payload, settings)
    slots: List[RollingInputSlot] = []
    for start, end in build_rolling_boundaries(
        now,
        horizon_hours,
        extend_to_midnight=extend_to_midnight,
    ):
        date_text = start.strftime("%Y-%m-%d")
        hour = start.hour
        import_price, export_price, price_source = _price_for_slot(prices, date_text, hour)
        pv_values = pv_by_date.get(date_text, [])
        pv_w = float(pv_values[hour]) if hour < len(pv_values) else 0.0
        load_w = float(settings.default_household_usage_w_by_hour[hour])
        slots.append(
            RollingInputSlot(
                start=start,
                end=end,
                import_price_eur_per_kwh=import_price,
                export_price_eur_per_kwh=export_price,
                price_source=price_source,
                load_w=load_w,
                pv_w=pv_w,
            )
        )
    return slots


def append_json_line(path: Path, payload: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    serialized = json.dumps(payload, separators=(",", ":"), sort_keys=False)
    with path.open("a", encoding="utf-8") as handle:
        fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
        handle.write(serialized + "\n")
        handle.flush()
        os.fsync(handle.fileno())
        fcntl.flock(handle.fileno(), fcntl.LOCK_UN)


def solar_forecast_updated_at(payload: dict, timezone: ZoneInfo) -> Optional[str]:
    """Return the solar endpoint cache timestamp as a local ISO timestamp."""
    raw = payload.get("cachedAt")
    if isinstance(raw, bool):
        return None
    try:
        timestamp = float(raw)
    except (TypeError, ValueError):
        return None
    if timestamp <= 0:
        return None
    try:
        return datetime.fromtimestamp(timestamp, timezone).isoformat()
    except (OverflowError, OSError, ValueError):
        return None


def retained_energy_valuation(prices: PricePayload) -> Dict[str, Any]:
    """Build a terminal-energy price from the latest 24 official consumer prices."""
    points: List[Tuple[str, int, float]] = []
    for date_text, values in (
        (prices.today_date, prices.import_today),
        (prices.tomorrow_date, prices.import_tomorrow),
    ):
        if not date_text:
            continue
        for hour, raw in enumerate(values):
            if raw is None:
                continue
            try:
                value = float(raw)
            except (TypeError, ValueError):
                continue
            points.append((str(date_text), hour, value))

    window = points[-24:]
    dates = list(dict.fromkeys(point[0] for point in window))
    price = max(0.0, fmean(point[2] for point in window)) if len(window) == 24 else None
    return {
        "retained_energy_valuation_price_eur_per_kwh": round(price, 6) if price is not None else None,
        "retained_energy_valuation_hour_count": len(window),
        "retained_energy_valuation_dates": dates,
        "retained_energy_valuation_basis": "latest_24_official_consumer_prices",
    }


def run_shadow_once(
    settings: PlannerSettings,
    *,
    now: Optional[datetime] = None,
    output_path: Optional[Path] = None,
    latest_path: Optional[Path] = None,
) -> dict:
    tz = ZoneInfo(settings.timezone)
    generated_at = (now or datetime.now(tz)).astimezone(tz)
    horizon_hours = _env_int("PLANNER_HORIZON_HOURS", DEFAULT_HORIZON_HOURS)
    extend_to_midnight = _env_bool(
        "PLANNER_EXTEND_HORIZON_TO_MIDNIGHT",
        DEFAULT_EXTEND_TO_MIDNIGHT,
    )
    soc_step_wh = _env_float("PLANNER_SOC_STEP_WH", DEFAULT_SOC_STEP_WH, 1.0)
    terminal_value_factor = _env_float(
        "PLANNER_TERMINAL_VALUE_FACTOR",
        DEFAULT_TERMINAL_VALUE_FACTOR,
    )

    prices = fetch_price_payload(settings)
    battery_state = fetch_battery_state(settings)
    shortwave = fetch_shortwave_payload(settings)
    retained_energy_inputs = retained_energy_valuation(prices)
    slots = build_shadow_slots(
        now=generated_at,
        settings=settings,
        prices=prices,
        shortwave_payload=shortwave,
        horizon_hours=horizon_hours,
        extend_to_midnight=extend_to_midnight,
    )
    plan = optimize_rolling_schedule(
        now=generated_at,
        battery_state=battery_state,
        slots=slots,
        round_trip_efficiency=settings.round_trip_efficiency,
        power_step_w=settings.power_step_w,
        soc_step_wh=soc_step_wh,
        terminal_value_factor=terminal_value_factor,
    )
    payload = {
        "type": "optimizer_shadow_plan",
        "version": 1,
        "mode": "log_only",
        "inputs": {
            "price_today_date": prices.today_date,
            "price_tomorrow_date": prices.tomorrow_date,
            "provisional_price_slots": sum(slot.price_source != "official" for slot in slots),
            "minimum_horizon_hours": horizon_hours,
            "horizon_extended_to_midnight": extend_to_midnight,
            "battery_capacity_wh": battery_state.usable_capacity_wh,
            "min_soc_percent": battery_state.min_charge_level_percent,
            "max_soc_percent": battery_state.max_charge_level_percent,
            "max_charge_power_w": battery_state.max_charge_power_w,
            "max_discharge_power_w": battery_state.max_discharge_power_w,
            "power_step_w": settings.power_step_w,
            "household_forecast_source": "common.config.system.forecast.defaultHouseholdUsageWByHour",
            "solar_forecast_source": "shortwave_radiation",
            "solar_forecast_updated_at": solar_forecast_updated_at(shortwave, tz),
            **retained_energy_inputs,
        },
        "plan": plan.to_dict(),
    }
    append_json_line(output_path or shadow_log_path(settings), payload)
    executable_payload = {
        "type": "optimizer_executable_schedule",
        "version": 1,
        "published_at": datetime.now(tz).isoformat(),
        "inputs": payload["inputs"],
        "plan": payload["plan"],
    }
    write_json_atomic(latest_path or latest_schedule_path(settings), executable_payload)
    return payload


def build_arg_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Log rolling optimizer plans without controlling the battery")
    run_mode = parser.add_mutually_exclusive_group()
    run_mode.add_argument("--once", action="store_true", help="Generate one plan and exit (default).")
    run_mode.add_argument(
        "--interval-seconds",
        type=int,
        default=None,
        help="Keep recalculating at this interval; omit to run once.",
    )
    parser.add_argument("--output", type=Path, default=None, help="Override the JSON-lines log path.")
    parser.add_argument(
        "--latest-output",
        type=Path,
        default=None,
        help="Override the atomically published executable schedule path.",
    )
    return parser


def main(argv: Optional[List[str]] = None) -> int:
    args = build_arg_parser().parse_args(argv)
    settings = load_settings()
    output_path = args.output.expanduser().resolve() if args.output else shadow_log_path(settings)
    latest_path = args.latest_output.expanduser().resolve() if args.latest_output else latest_schedule_path(settings)
    lock_path = run_lock_path(settings)
    lock_path.parent.mkdir(parents=True, exist_ok=True)
    interval = args.interval_seconds
    if interval is not None:
        interval = max(1, interval)

    while True:
        try:
            with lock_path.open("a", encoding="utf-8") as lock_handle:
                try:
                    fcntl.flock(lock_handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
                except BlockingIOError:
                    print(f"Optimizer run skipped because another process holds {lock_path}")
                    if interval is None:
                        return 2
                    time.sleep(interval)
                    continue
                payload = run_shadow_once(settings, output_path=output_path, latest_path=latest_path)
                fcntl.flock(lock_handle.fileno(), fcntl.LOCK_UN)
            plan = payload["plan"]
            print(
                f"Logged optimizer plan to {output_path} "
                f"({plan['horizon_start']} -> {plan['horizon_end']}, "
                f"objective EUR {plan['objective_eur']:.4f})"
            )
        except Exception as exc:
            error_payload = {
                "type": "optimizer_shadow_error",
                "version": 1,
                "mode": "log_only",
                "generated_at": datetime.now(ZoneInfo(settings.timezone)).isoformat(),
                "error": str(exc),
            }
            append_json_line(output_path, error_payload)
            print(f"Optimizer shadow run failed; error logged to {output_path}: {exc}")
            if interval is None:
                return 1

        if interval is None:
            return 0
        time.sleep(interval)


if __name__ == "__main__":
    raise SystemExit(main())
