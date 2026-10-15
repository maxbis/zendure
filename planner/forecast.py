from __future__ import annotations

from datetime import datetime, timedelta
import math
from typing import Any, Dict, List, Tuple

from zoneinfo import ZoneInfo

from planner.config import DAY_PART_WINDOWS, PlannerSettings
from planner.models import LoadForecastRecord


def _validate_hour_values(values: Any) -> List[float]:
    if isinstance(values, list):
        if len(values) != 24:
            raise ValueError("baseline_load_w_by_hour must contain exactly 24 values")
        return [float(value) for value in values]
    if isinstance(values, dict):
        result = [0.0] * 24
        for hour in range(24):
            key = f"{hour:02d}"
            alt_key = str(hour)
            raw = values.get(key, values.get(alt_key))
            if raw is None:
                raise ValueError(f"baseline_load_w_by_hour missing hour {key}")
            result[hour] = float(raw)
        return result
    raise ValueError("baseline_load_w_by_hour must be a list or dict")


def normalize_load_forecast_payload(payload: Dict[str, Any], now_iso: str) -> LoadForecastRecord:
    if not isinstance(payload, dict):
        raise ValueError("JSON body must be an object")
    date = payload.get("date")
    if not isinstance(date, str) or not date:
        raise ValueError("Missing required field: date")
    timezone = payload.get("timezone")
    if not isinstance(timezone, str) or not timezone:
        raise ValueError("Missing required field: timezone")
    baseline = _validate_hour_values(payload.get("baseline_load_w_by_hour"))
    incidentals = payload.get("incidentals")
    if not isinstance(incidentals, dict):
        raise ValueError("Missing required field: incidentals")
    normalized_incidentals: Dict[str, float] = {}
    for name in DAY_PART_WINDOWS:
        raw = incidentals.get(name, 0)
        normalized_incidentals[name] = float(raw or 0)
    return LoadForecastRecord(
        date=date,
        timezone=timezone,
        baseline_load_w_by_hour=baseline,
        incidentals=normalized_incidentals,
        updated_at=now_iso,
    )


def _day_part_hours(name: str) -> List[int]:
    start_hour, end_hour = DAY_PART_WINDOWS[name]
    if start_hour < end_hour:
        return list(range(start_hour, end_hour))
    return list(range(start_hour, 24)) + list(range(0, end_hour))


def derive_hourly_load_w(record: LoadForecastRecord) -> List[float]:
    result = list(record.baseline_load_w_by_hour)
    for name, total_wh in record.incidentals.items():
        hours = _day_part_hours(name)
        if not hours:
            continue
        overlay_w = float(total_wh) / float(len(hours))
        for hour in hours:
            result[hour] += overlay_w
    return result


def derive_pv_forecast_by_date(
    shortwave_payload: Dict[str, Any],
    settings: PlannerSettings,
) -> Dict[str, List[float]]:
    if settings.pv_panels:
        expected_groups: Dict[tuple[float, float], int] = {}
        for panel in settings.pv_panels:
            key = (panel.facing_degrees, panel.tilt_degrees)
            expected_groups[key] = expected_groups.get(key, 0) + 1
        panel_irradiance = shortwave_payload.get("panel_irradiance")
        if not isinstance(panel_irradiance, list) or len(panel_irradiance) != len(expected_groups):
            raise ValueError("PV forecast is missing configured panel orientations")
        hourly_power: Dict[str, float] = {}
        seen_groups = set()
        reference_times = None
        reference = settings.shortwave_radiation_reference_w_m2
        for group in panel_irradiance:
            if not isinstance(group, dict):
                raise ValueError("Invalid PV panel irradiation group")
            key = (group.get("facing_degrees"), group.get("tilt_degrees"))
            if key not in expected_groups or key in seen_groups or group.get("count") != expected_groups[key]:
                raise ValueError("PV forecast panel groups do not match configuration")
            seen_groups.add(key)
            hourly_group = group.get("hourly")
            if not isinstance(hourly_group, dict):
                raise ValueError("PV forecast panel group has no hourly data")
            times = hourly_group.get("time")
            irradiance = hourly_group.get("global_tilted_irradiance")
            if not isinstance(times, list) or not isinstance(irradiance, list) or not times or len(times) != len(irradiance):
                raise ValueError("PV forecast panel group has mismatched hourly data")
            if len(set(times)) != len(times) or (reference_times is not None and times != reference_times):
                raise ValueError("PV forecast panel groups must have identical, unique hours")
            reference_times = times
            for raw_time, raw_irradiance in zip(times, irradiance):
                try:
                    value = float(raw_irradiance)
                except (TypeError, ValueError) as exc:
                    raise ValueError("PV forecast has nonnumeric tilted irradiation") from exc
                if not math.isfinite(value):
                    raise ValueError("PV forecast has nonfinite tilted irradiation")
                timestamp = str(raw_time)
                hourly_power[timestamp] = hourly_power.get(timestamp, 0.0) + max(0.0, value) / reference * settings.pv_panel_rated_power_w * expected_groups[key]
        result: Dict[str, List[float]] = {}
        tz = ZoneInfo(settings.timezone)
        for raw_time, raw_power in hourly_power.items():
            dt = datetime.fromisoformat(raw_time)
            if dt.tzinfo is None:
                dt = dt.replace(tzinfo=tz)
            dt = dt.astimezone(tz)
            date_key = dt.strftime("%Y-%m-%d")
            if date_key not in result:
                result[date_key] = [0.0] * 24
            correction = settings.pv_monthly_correction_factors[dt.month - 1]
            pv_w = raw_power * settings.pv_derate_factor * correction
            result[date_key][dt.hour] = max(0.0, min(pv_w, settings.pv_output_clip_w))
        return result

    hourly = shortwave_payload.get("hourly") if isinstance(shortwave_payload.get("hourly"), dict) else {}
    times = hourly.get("time") if isinstance(hourly.get("time"), list) else []
    radiation = hourly.get("shortwave_radiation") if isinstance(hourly.get("shortwave_radiation"), list) else []
    if len(times) != len(radiation):
        raise ValueError("shortwave payload hourly time/value length mismatch")

    result: Dict[str, List[float]] = {}
    tz = ZoneInfo(settings.timezone)
    for raw_time, raw_radiation in zip(times, radiation):
        dt = datetime.fromisoformat(str(raw_time))
        if dt.tzinfo is None:
            dt = dt.replace(tzinfo=tz)
        dt = dt.astimezone(tz)
        date_key = dt.strftime("%Y-%m-%d")
        if date_key not in result:
            result[date_key] = [0.0] * 24
        try:
            radiation_value = max(0.0, float(raw_radiation))
        except (TypeError, ValueError):
            radiation_value = 0.0
        normalized = radiation_value / max(settings.shortwave_radiation_reference_w_m2, 0.000001)
        pv_w = normalized * settings.pv_system_capacity_w * settings.pv_derate_factor * settings.pv_monthly_correction_factors[dt.month - 1]
        pv_w = max(0.0, min(pv_w, settings.pv_output_clip_w))
        result[date_key][dt.hour] = pv_w
    return result


def select_horizon_dates(now: datetime, settings: PlannerSettings) -> List[str]:
    today = now.strftime("%Y-%m-%d")
    if now.hour < settings.price_release_hour_local:
        return [today]
    tomorrow = (now + timedelta(days=1)).strftime("%Y-%m-%d")
    return [today, tomorrow]


def build_segment_boundaries(now: datetime, horizon_dates: List[str], timezone: str) -> List[Tuple[datetime, datetime]]:
    tz = ZoneInfo(timezone)
    segments: List[Tuple[datetime, datetime]] = []
    current_start = now.replace(second=0, microsecond=0)
    if current_start.minute > 0:
        next_hour = (current_start.replace(minute=0, second=0, microsecond=0) + timedelta(hours=1))
        segments.append((current_start, next_hour))
        segment_start = next_hour
    else:
        segment_start = current_start

    for date_text in horizon_dates:
        day = datetime.strptime(date_text, "%Y-%m-%d").replace(tzinfo=tz)
        for hour in range(24):
            start = day.replace(hour=hour, minute=0, second=0, microsecond=0)
            end = start + timedelta(hours=1)
            if start < segment_start:
                continue
            segments.append((start, end))
    return segments
