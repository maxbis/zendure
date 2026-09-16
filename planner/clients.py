from __future__ import annotations

import json
import math
import urllib.parse
import urllib.request
from dataclasses import dataclass
from typing import Any, Dict, List, Optional

from planner.config import PlannerSettings, consumer_to_spot_price
from planner.models import BatteryState


class UpstreamError(RuntimeError):
    pass


@dataclass(frozen=True)
class RuntimeReadings:
    battery_state: BatteryState
    observed_at_timestamp: Optional[float]
    p1_timestamp: Optional[float]
    zendure_timestamp: Optional[float]
    grid_power_w: Optional[float]
    battery_power_w: Optional[float]
    solar_power_w: Optional[float]
    household_power_w: Optional[float]
    net_household_power_w: Optional[float]


def fetch_json(url: str, timeout: int) -> Dict[str, Any]:
    try:
        with urllib.request.urlopen(url, timeout=timeout) as response:
            payload = json.load(response)
    except Exception as exc:
        raise UpstreamError(f"Failed to fetch {url}: {exc}") from exc
    if not isinstance(payload, dict):
        raise UpstreamError(f"Expected JSON object from {url}")
    return payload


@dataclass
class PricePayload:
    today_date: Optional[str]
    tomorrow_date: Optional[str]
    import_today: List[Optional[float]]
    import_tomorrow: List[Optional[float]]
    export_today: List[Optional[float]]
    export_tomorrow: List[Optional[float]]


def _hour_map_to_list(hour_map: Any) -> List[Optional[float]]:
    values: List[Optional[float]] = [None] * 24
    if not isinstance(hour_map, dict):
        return values
    for hour in range(24):
        raw = hour_map.get(f"{hour:02d}")
        try:
            values[hour] = float(raw) if raw is not None else None
        except (TypeError, ValueError):
            values[hour] = None
    return values


def fetch_price_payload(settings: PlannerSettings) -> PricePayload:
    payload = fetch_json(settings.price_api_url, settings.http_timeout_seconds)
    import_today = _hour_map_to_list(payload.get("today"))
    import_tomorrow = _hour_map_to_list(payload.get("tomorrow"))
    export_today = [
        consumer_to_spot_price(value, settings.price_conversion) for value in import_today
    ]
    export_tomorrow = [
        consumer_to_spot_price(value, settings.price_conversion) for value in import_tomorrow
    ]
    dates = payload.get("dates") if isinstance(payload.get("dates"), dict) else {}
    return PricePayload(
        today_date=str(dates.get("today")) if dates.get("today") else None,
        tomorrow_date=str(dates.get("tomorrow")) if dates.get("tomorrow") else None,
        import_today=import_today,
        import_tomorrow=import_tomorrow,
        export_today=export_today,
        export_tomorrow=export_tomorrow,
    )


def _parse_soc_percent(all_payload: Dict[str, Any]) -> float:
    zendure = all_payload.get("zendure") if isinstance(all_payload.get("zendure"), dict) else {}
    readings = zendure.get("readings") if isinstance(zendure.get("readings"), dict) else {}
    properties = readings.get("properties") if isinstance(readings.get("properties"), dict) else {}
    raw = properties.get("electricLevel")
    if raw is None:
        raise UpstreamError("automation /api/all payload missing zendure.readings.properties.electricLevel")
    try:
        value = float(raw)
    except (TypeError, ValueError) as exc:
        raise UpstreamError("automation /api/all electricLevel is not numeric") from exc
    return max(0.0, min(100.0, value))


def _optional_float(value: Any) -> Optional[float]:
    if isinstance(value, bool) or value is None:
        return None
    try:
        number = float(value)
    except (TypeError, ValueError):
        return None
    return number if math.isfinite(number) else None


def _reading_timestamp(payload: Any) -> Optional[float]:
    if not isinstance(payload, dict):
        return None
    value = _optional_float(payload.get("timestamp"))
    return value if value is not None and value > 0 else None


def _battery_power_w(properties: Dict[str, Any]) -> Optional[float]:
    output_pack = _optional_float(properties.get("outputPackPower"))
    output_home = _optional_float(properties.get("outputHomePower"))
    ac_mode = _optional_float(properties.get("acMode"))
    input_limit = _optional_float(properties.get("inputLimit"))
    output_limit = _optional_float(properties.get("outputLimit"))

    if output_pack is not None and output_pack > 0:
        return output_pack
    if output_home is not None and output_home > 0:
        return -output_home
    if output_pack is not None or output_home is not None:
        if (ac_mode == 1 and input_limit is not None and input_limit > 0) or (
            ac_mode == 2 and output_limit is not None and output_limit > 0
        ):
            # Limits are commands, not measurements. Some devices report zero
            # telemetry while active; do not mislabel the configured limit as actual power.
            return None
        return 0.0
    return None


def parse_runtime_readings(all_payload: Dict[str, Any], settings: PlannerSettings) -> RuntimeReadings:
    zendure = all_payload.get("zendure") if isinstance(all_payload.get("zendure"), dict) else {}
    readings = zendure.get("readings") if isinstance(zendure.get("readings"), dict) else {}
    properties = readings.get("properties") if isinstance(readings.get("properties"), dict) else {}
    p1 = all_payload.get("p1") if isinstance(all_payload.get("p1"), dict) else {}
    p1_readings = p1.get("readings") if isinstance(p1.get("readings"), dict) else {}

    soc_percent = _parse_soc_percent(all_payload)
    battery_state = BatteryState(
        soc_percent=soc_percent,
        usable_capacity_wh=settings.base_wh,
        max_charge_power_w=settings.max_charge_power_w,
        max_discharge_power_w=settings.max_discharge_power_w,
        min_charge_level_percent=settings.min_charge_level,
        max_charge_level_percent=settings.max_charge_level,
    )
    grid_power_w = _optional_float(p1_readings.get("total_power"))
    battery_power_w = _battery_power_w(properties)
    solar_power_w = _optional_float(properties.get("solarInputPower"))
    net_household_power_w = (
        grid_power_w - battery_power_w
        if grid_power_w is not None and battery_power_w is not None
        else None
    )
    household_power_w = (
        net_household_power_w + solar_power_w
        if net_household_power_w is not None and solar_power_w is not None
        else None
    )
    p1_timestamp = _reading_timestamp(p1)
    zendure_timestamp = _reading_timestamp(zendure)
    available_timestamps = [value for value in (p1_timestamp, zendure_timestamp) if value is not None]
    return RuntimeReadings(
        battery_state=battery_state,
        observed_at_timestamp=max(available_timestamps) if available_timestamps else None,
        p1_timestamp=p1_timestamp,
        zendure_timestamp=zendure_timestamp,
        grid_power_w=grid_power_w,
        battery_power_w=battery_power_w,
        solar_power_w=solar_power_w,
        household_power_w=household_power_w,
        net_household_power_w=net_household_power_w,
    )


def fetch_runtime_readings(settings: PlannerSettings) -> RuntimeReadings:
    payload = fetch_json(settings.automation_all_api_url, settings.http_timeout_seconds)
    return parse_runtime_readings(payload, settings)


def fetch_battery_state(settings: PlannerSettings) -> BatteryState:
    return fetch_runtime_readings(settings).battery_state


def fetch_shortwave_payload(settings: PlannerSettings) -> Dict[str, Any]:
    params = urllib.parse.urlencode(
        {
            "latitude": settings.latitude,
            "longitude": settings.longitude,
            "timezone": settings.timezone,
        }
    )
    separator = "&" if "?" in settings.shortwave_api_url else "?"
    url = f"{settings.shortwave_api_url}{separator}{params}"
    return fetch_json(url, settings.http_timeout_seconds)
