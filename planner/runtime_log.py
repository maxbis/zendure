from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime
import fcntl
import math
import os
from pathlib import Path
from typing import Dict, Iterable, List, Optional, Sequence
from zoneinfo import ZoneInfo

from planner.clients import RuntimeReadings


DEFAULT_RUNTIME_LOG_FILE_NAME = "optimizer_runtime.log"
MAX_FRESH_AGE_SECONDS = 300
MAX_SAMPLE_HOLD_SECONDS = 20 * 60
MIN_COMPLETE_COVERAGE_PERCENT = 75.0


@dataclass(frozen=True)
class ParsedEvent:
    observed_at: datetime
    fields: Dict[str, str]


def runtime_log_path(data_dir: Path) -> Path:
    configured = os.getenv("PLANNER_RUNTIME_LOG_PATH")
    if configured:
        return Path(configured).expanduser().resolve()
    return data_dir / DEFAULT_RUNTIME_LOG_FILE_NAME


def _hour_start(value: datetime, timezone: ZoneInfo) -> datetime:
    local = value.astimezone(timezone)
    return local.replace(minute=0, second=0, microsecond=0)


def _next_elapsed_hour(value: datetime, timezone: ZoneInfo) -> datetime:
    return datetime.fromtimestamp(value.timestamp() + 3600, timezone)


def _number(value: object) -> Optional[float]:
    if isinstance(value, bool) or value is None:
        return None
    try:
        result = float(value)
    except (TypeError, ValueError):
        return None
    return result if math.isfinite(result) else None


def _format_number(value: Optional[float], digits: int = 1) -> str:
    return "unavailable" if value is None else f"{value:.{digits}f}"


def _format_signed(value: Optional[float], digits: int = 1) -> str:
    return "unavailable" if value is None else f"{value:+.{digits}f}"


def _format_schedule(decision: Optional[dict]) -> str:
    if not decision:
        return "unavailable"
    value = decision.get("schedule_value")
    if isinstance(value, (int, float)) and not isinstance(value, bool):
        return f"{float(value):+g}W"
    label = str(value) if value is not None else "auto"
    minimum = _number(decision.get("min_power"))
    maximum = _number(decision.get("max_power"))
    if minimum is None and maximum is None:
        return label
    return f"{label}({_format_signed(minimum, 0)}..{_format_signed(maximum, 0)}W)"


def _decision_fields(plan: dict) -> Dict[str, str]:
    decisions = plan.get("decisions") if isinstance(plan.get("decisions"), list) else []
    current = decisions[0] if decisions and isinstance(decisions[0], dict) else None
    following = decisions[1] if len(decisions) > 1 and isinstance(decisions[1], dict) else None
    return {
        "current_schedule": _format_schedule(current),
        "current_expected_battery_w": _format_number(_number((current or {}).get("battery_power_w")), 0),
        "current_predicted_end_soc": _format_number(_number((current or {}).get("end_soc_percent"))),
        "current_predicted_load_w": _format_number(_number((current or {}).get("load_w")), 0),
        "current_predicted_solar_w": _format_number(_number((current or {}).get("pv_w")), 0),
        "current_predicted_grid_w": _format_number(_number((current or {}).get("grid_power_w")), 0),
        "next_schedule": _format_schedule(following),
        "next_expected_battery_w": _format_number(_number((following or {}).get("battery_power_w")), 0),
        "next_predicted_end_soc": _format_number(_number((following or {}).get("end_soc_percent"))),
        "next_predicted_load_w": _format_number(_number((following or {}).get("load_w")), 0),
        "next_predicted_solar_w": _format_number(_number((following or {}).get("pv_w")), 0),
        "next_predicted_grid_w": _format_number(_number((following or {}).get("grid_power_w")), 0),
    }


def _ages(observed_at: datetime, readings: RuntimeReadings) -> tuple[Optional[float], Optional[float], Optional[float]]:
    observed_timestamp = observed_at.timestamp()
    p1_age = (
        max(0.0, observed_timestamp - readings.p1_timestamp)
        if readings.p1_timestamp is not None
        else None
    )
    zendure_age = (
        max(0.0, observed_timestamp - readings.zendure_timestamp)
        if readings.zendure_timestamp is not None
        else None
    )
    ages = [age for age in (p1_age, zendure_age) if age is not None]
    return p1_age, zendure_age, max(ages) if ages else None


def _serialize_line(observed_at: datetime, fields: Dict[str, str]) -> str:
    body = " | ".join(f"{key}={value}" for key, value in fields.items())
    return f"[{observed_at.isoformat()}] {body}\n"


def _parse_line(line: str) -> Optional[ParsedEvent]:
    if not line.startswith("[") or "] " not in line:
        return None
    timestamp_text, body = line[1:].split("] ", 1)
    try:
        observed_at = datetime.fromisoformat(timestamp_text)
    except ValueError:
        return None
    fields: Dict[str, str] = {}
    for part in body.strip().split(" | "):
        if "=" not in part:
            continue
        key, value = part.split("=", 1)
        fields[key.strip()] = value.strip()
    if "event" not in fields:
        return None
    return ParsedEvent(observed_at=observed_at, fields=fields)


def parse_runtime_events(lines: Iterable[str]) -> List[ParsedEvent]:
    events: List[ParsedEvent] = []
    for line in lines:
        event = _parse_line(line)
        if event is not None:
            events.append(event)
    return events


def _event_datetime(event: ParsedEvent, key: str) -> Optional[datetime]:
    value = event.fields.get(key)
    if not value:
        return None
    try:
        return datetime.fromisoformat(value)
    except ValueError:
        return None


def _same_instant(left: datetime, right: datetime) -> bool:
    return abs(left.timestamp() - right.timestamp()) < 0.5


def _events_for_hour(events: Sequence[ParsedEvent], event_name: str, start: datetime) -> List[ParsedEvent]:
    result: List[ParsedEvent] = []
    for event in events:
        if event.fields.get("event") != event_name:
            continue
        event_start = _event_datetime(event, "hour_start")
        if event_start is not None and _same_instant(event_start, start):
            result.append(event)
    return result


def _integrate_samples(
    samples: Sequence[ParsedEvent],
    field: str,
    start: datetime,
    end: datetime,
) -> tuple[Optional[float], float]:
    usable: List[tuple[float, float]] = []
    for sample in samples:
        if sample.fields.get("measurement_status") != "fresh":
            continue
        value = _number(sample.fields.get(field))
        if value is None:
            continue
        timestamp = sample.observed_at.timestamp()
        if start.timestamp() <= timestamp < end.timestamp():
            usable.append((timestamp, value))
    usable.sort()
    if not usable:
        return None, 0.0

    watt_seconds = 0.0
    covered_seconds = 0.0
    for index, (timestamp, value) in enumerate(usable):
        next_timestamp = usable[index + 1][0] if index + 1 < len(usable) else end.timestamp()
        interval_end = min(end.timestamp(), next_timestamp, timestamp + MAX_SAMPLE_HOLD_SECONDS)
        seconds = max(0.0, interval_end - timestamp)
        watt_seconds += value * seconds
        covered_seconds += seconds
    return watt_seconds / 3600.0, covered_seconds


def _baseline_for_hour(events: Sequence[ParsedEvent], start: datetime) -> Optional[ParsedEvent]:
    opened = _events_for_hour(events, "HOUR_OPENED", start)
    if opened:
        return min(opened, key=lambda event: event.observed_at.timestamp())
    samples = _events_for_hour(events, "SAMPLE", start)
    return min(samples, key=lambda event: event.observed_at.timestamp()) if samples else None


def _closed_hour_ends(events: Sequence[ParsedEvent]) -> List[datetime]:
    values: List[datetime] = []
    for event in events:
        if event.fields.get("event") != "HOUR_CLOSED":
            continue
        value = _event_datetime(event, "hour_end")
        if value is not None:
            values.append(value)
    return values


def _closure_fields(
    events: Sequence[ParsedEvent],
    start: datetime,
    end: datetime,
    observed_at: datetime,
    readings: RuntimeReadings,
    is_latest_crossed_hour: bool,
) -> Dict[str, str]:
    samples = _events_for_hour(events, "SAMPLE", start)
    baseline = _baseline_for_hour(events, start)
    household_wh, household_coverage = _integrate_samples(samples, "actual_household_w", start, end)
    net_demand_wh, net_coverage = _integrate_samples(samples, "actual_net_demand_w", start, end)
    grid_wh, grid_coverage = _integrate_samples(samples, "actual_grid_w", start, end)
    solar_wh, solar_coverage = _integrate_samples(samples, "actual_solar_w", start, end)
    duration_seconds = max(1.0, end.timestamp() - start.timestamp())
    coverage_seconds = max(household_coverage, net_coverage, grid_coverage, solar_coverage)
    coverage_percent = min(100.0, coverage_seconds / duration_seconds * 100.0)
    status = "complete" if coverage_percent >= MIN_COMPLETE_COVERAGE_PERCENT else "incomplete"

    predicted_load_w = _number((baseline.fields if baseline else {}).get("current_predicted_load_w"))
    predicted_solar_w = _number((baseline.fields if baseline else {}).get("current_predicted_solar_w"))
    predicted_grid_w = _number((baseline.fields if baseline else {}).get("current_predicted_grid_w"))
    duration_hours = duration_seconds / 3600.0
    predicted_usage_wh = predicted_load_w * duration_hours if predicted_load_w is not None else None
    predicted_solar_wh = predicted_solar_w * duration_hours if predicted_solar_w is not None else None
    predicted_grid_wh = predicted_grid_w * duration_hours if predicted_grid_w is not None else None
    predicted_soc = _number((baseline.fields if baseline else {}).get("current_predicted_end_soc"))
    actual_soc = readings.battery_state.soc_percent if is_latest_crossed_hour else None
    soc_error = actual_soc - predicted_soc if actual_soc is not None and predicted_soc is not None else None
    usage_error = household_wh - predicted_usage_wh if household_wh is not None and predicted_usage_wh is not None else None

    return {
        "event": "HOUR_CLOSED",
        "hour_start": start.isoformat(),
        "hour_end": end.isoformat(),
        "status": status,
        "samples": str(len(samples)),
        "coverage_pct": _format_number(coverage_percent),
        "predicted_usage_wh": _format_number(predicted_usage_wh, 0),
        "actual_usage_wh": _format_number(household_wh, 0),
        "usage_error_wh": _format_signed(usage_error, 0),
        "predicted_solar_wh": _format_number(predicted_solar_wh, 0),
        "actual_solar_wh": _format_number(solar_wh, 0),
        "predicted_grid_wh": _format_signed(predicted_grid_wh, 0),
        "actual_grid_wh": _format_signed(grid_wh, 0),
        "actual_net_demand_wh": _format_signed(net_demand_wh, 0),
        "predicted_end_soc": _format_number(predicted_soc),
        "actual_soc": _format_number(actual_soc),
        "soc_error_ppt": _format_signed(soc_error),
        "closed_late_s": _format_number(max(0.0, observed_at.timestamp() - end.timestamp()), 0),
    }


def _forecast_fields(plan: dict, hour_start: datetime, hour_end: datetime) -> Dict[str, str]:
    return {
        "hour_start": hour_start.isoformat(),
        "hour_end": hour_end.isoformat(),
        "plan_generated_at": str(plan.get("generated_at", "unavailable")),
        **_decision_fields(plan),
    }


def update_runtime_log(
    path: Path,
    *,
    observed_at: datetime,
    timezone: str,
    plan: dict,
    readings: RuntimeReadings,
) -> None:
    tz = ZoneInfo(timezone)
    observed_at = observed_at.astimezone(tz)
    current_hour_start = _hour_start(observed_at, tz)
    current_hour_end = _next_elapsed_hour(current_hour_start, tz)
    path.parent.mkdir(parents=True, exist_ok=True)

    with path.open("a+", encoding="utf-8") as handle:
        fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
        handle.seek(0)
        existing_text = handle.read()
        events = parse_runtime_events(existing_text.splitlines())
        closed_ends = _closed_hour_ends(events)
        latest_closed_end = max(closed_ends, key=lambda value: value.timestamp()) if closed_ends else None
        sample_starts = [
            value
            for event in events
            if event.fields.get("event") in ("HOUR_OPENED", "SAMPLE")
            for value in [_event_datetime(event, "hour_start")]
            if value is not None and value.timestamp() < current_hour_start.timestamp()
        ]
        cursor = latest_closed_end
        if cursor is None and sample_starts:
            cursor = min(sample_starts, key=lambda value: value.timestamp())

        new_lines: List[str] = []
        while cursor is not None and cursor.timestamp() < current_hour_start.timestamp():
            hour_end = _next_elapsed_hour(cursor, tz)
            is_latest = _same_instant(hour_end, current_hour_start)
            closure = _closure_fields(events, cursor, hour_end, observed_at, readings, is_latest)
            new_lines.append(_serialize_line(observed_at, closure))
            events.append(ParsedEvent(observed_at=observed_at, fields=closure))
            cursor = hour_end

        already_opened = bool(_events_for_hour(events, "HOUR_OPENED", current_hour_start))
        forecast = _forecast_fields(plan, current_hour_start, current_hour_end)
        if not already_opened:
            new_lines.append(_serialize_line(observed_at, {"event": "HOUR_OPENED", **forecast}))

        p1_age, zendure_age, measurement_age = _ages(observed_at, readings)
        required_ages = [age for age in (p1_age, zendure_age) if age is not None]
        measurement_status = (
            "fresh"
            if len(required_ages) == 2 and max(required_ages) <= MAX_FRESH_AGE_SECONDS
            else "stale" if required_ages else "unavailable"
        )
        sample = {
            "event": "SAMPLE",
            **forecast,
            "actual_soc": _format_number(readings.battery_state.soc_percent),
            "actual_grid_w": _format_number(readings.grid_power_w, 0),
            "actual_battery_w": _format_number(readings.battery_power_w, 0),
            "actual_solar_w": _format_number(readings.solar_power_w, 0),
            "actual_household_w": _format_number(readings.household_power_w, 0),
            "actual_net_demand_w": _format_number(readings.net_household_power_w, 0),
            "measurement_status": measurement_status,
            "measurement_age_s": _format_number(measurement_age, 0),
        }
        new_lines.append(_serialize_line(observed_at, sample))
        handle.seek(0, os.SEEK_END)
        if existing_text and not existing_text.endswith("\n"):
            handle.write("\n")
        handle.writelines(new_lines)
        handle.flush()
        os.fsync(handle.fileno())
        fcntl.flock(handle.fileno(), fcntl.LOCK_UN)
