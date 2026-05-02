from __future__ import annotations

import json
import urllib.parse
import urllib.request
from dataclasses import dataclass
from typing import Any, Dict, List, Optional

from planner.config import PlannerSettings, consumer_to_spot_price
from planner.models import BatteryState


class UpstreamError(RuntimeError):
    pass


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


def fetch_battery_state(settings: PlannerSettings) -> BatteryState:
    payload = fetch_json(settings.automation_all_api_url, settings.http_timeout_seconds)
    soc_percent = _parse_soc_percent(payload)
    return BatteryState(
        soc_percent=soc_percent,
        usable_capacity_wh=settings.base_wh,
        max_charge_power_w=settings.max_charge_power_w,
        max_discharge_power_w=settings.max_discharge_power_w,
        min_charge_level_percent=settings.min_charge_level,
        max_charge_level_percent=settings.max_charge_level,
    )


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

