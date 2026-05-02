from __future__ import annotations

import json
import math
import os
import re
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple


TIMEZONE = "Europe/Amsterdam"
PRICE_RELEASE_HOUR_LOCAL = 14
ARBITRAGE_MIN_SPREAD_EUR_PER_KWH = 0.12
ROUND_TRIP_EFFICIENCY = 0.90
CHEAP_HOUR_TOLERANCE_EUR_PER_KWH = 0.01
EXPENSIVE_HOUR_TOLERANCE_EUR_PER_KWH = 0.01
NETZERO_MARKET_PRICE_THRESHOLD_EUR_PER_KWH = 0.18
NEGATIVE_EXPORT_AVOIDANCE_ENABLED = True
SERVICE_PORT = 8765
SERVICE_HOST = "127.0.0.1"
HTTP_TIMEOUT_SECONDS = 12
SHORTWAVE_RADIATION_REFERENCE_W_M2 = 1000.0
PV_SYSTEM_CAPACITY_W = 5000.0
PV_DERATE_FACTOR = 0.85
PV_OUTPUT_CLIP_W = 5000.0
MIN_ACTION_POWER_W = 50
DEFAULT_MAX_CHARGE_LEVEL = 100
LOAD_FORECAST_FILE_NAME = "load_forecast.json"
DEFAULT_LOAD_FORECAST_TEMPLATE_FILE_NAME = "load_forecast_default.json"

DAY_PART_WINDOWS: Dict[str, Tuple[int, int]] = {
    "morning": (6, 12),
    "afternoon": (12, 18),
    "evening": (18, 23),
    "night": (23, 6),
}


@dataclass(frozen=True)
class PriceConversionConfig:
    supplier_markup_eur_per_kwh: float = 0.0219
    energy_tax_eur_per_kwh: float = 0.0898
    vat_multiplier: float = 1.21
    consumer_precision: int = 4
    spot_precision: int = 6


@dataclass(frozen=True)
class PlannerSettings:
    repo_root: Path
    data_dir: Path
    load_forecast_path: Path
    default_load_forecast_template_path: Path
    main_config_path: Path
    timezone: str
    service_host: str
    service_port: int
    http_timeout_seconds: int
    price_api_url: str
    automation_all_api_url: str
    shortwave_api_url: str
    latitude: float
    longitude: float
    base_wh: float
    min_charge_level: int
    max_charge_level: int
    max_charge_power_w: int
    max_discharge_power_w: int
    arbitrage_min_spread_eur_per_kwh: float
    round_trip_efficiency: float
    cheap_hour_tolerance_eur_per_kwh: float
    expensive_hour_tolerance_eur_per_kwh: float
    netzero_market_price_threshold_eur_per_kwh: float
    negative_export_avoidance_enabled: bool
    price_release_hour_local: int
    shortwave_radiation_reference_w_m2: float
    pv_system_capacity_w: float
    pv_derate_factor: float
    pv_output_clip_w: float
    min_action_power_w: int
    price_conversion: PriceConversionConfig


_PLACEHOLDER_PATTERN = re.compile(r"\$\{([^}]+)\}")


def _repo_root() -> Path:
    return Path(__file__).resolve().parent.parent


def _load_json_file(path: Path) -> Dict[str, Any]:
    if not path.exists():
        return {}
    try:
        with path.open("r", encoding="utf-8") as handle:
            data = json.load(handle)
    except Exception:
        return {}
    return data if isinstance(data, dict) else {}


def _get_first_string(value: Any) -> Optional[str]:
    if isinstance(value, str) and value.strip():
        return value.strip()
    if isinstance(value, list):
        for item in value:
            if isinstance(item, str) and item.strip():
                return item.strip()
    return None


def _get_float(value: Any, default: float) -> float:
    try:
        parsed = float(value)
    except (TypeError, ValueError):
        return default
    return parsed


def _get_int(value: Any, default: int) -> int:
    try:
        parsed = int(value)
    except (TypeError, ValueError):
        return default
    return parsed


def _lookup_config_value(config: Dict[str, Any], key: str) -> Any:
    value: Any = config
    for part in key.split("."):
        if not isinstance(value, dict) or part not in value:
            return None
        value = value[part]
    return value


def _resolve_config_string(template: str, config: Dict[str, Any], max_depth: int = 5) -> str:
    value = template
    for _ in range(max_depth):
        changed = False

        def _replace(match: re.Match[str]) -> str:
            nonlocal changed
            key = match.group(1).strip()
            resolved = _lookup_config_value(config, key)
            if resolved is None:
                return match.group(0)
            changed = True
            return str(resolved)

        updated = _PLACEHOLDER_PATTERN.sub(_replace, value)
        value = updated
        if not changed:
            break
    return value


def _get_first_resolved_string(value: Any, config: Dict[str, Any]) -> Optional[str]:
    raw = _get_first_string(value)
    if raw is None:
        return None
    return _resolve_config_string(raw, config).strip()


def _derive_automation_all_url(config: Dict[str, Any]) -> str:
    direct = _get_first_resolved_string(config.get("allApi") or config.get("chargeStatusApi"), config)
    if direct:
        return direct
    base = _get_first_resolved_string(config.get("apiBaseUrlPiControl"), config)
    if base:
        return base.rstrip("/") + "/api/all"
    return "http://127.0.0.1:1611/api/all"


def _derive_shortwave_url(config: Dict[str, Any]) -> str:
    configured = _get_first_resolved_string(config.get("shortwaveApiUrl"), config)
    if configured:
        return configured
    return "http://localhost/zendure/main/api/shortwave_radiation_api.php"


def _load_price_conversion(config: Dict[str, Any]) -> PriceConversionConfig:
    conversion = config.get("priceConversion")
    if not isinstance(conversion, dict):
        conversion = {}
    return PriceConversionConfig(
        supplier_markup_eur_per_kwh=_get_float(conversion.get("supplierMarkupEurPerKwh"), 0.0219),
        energy_tax_eur_per_kwh=_get_float(conversion.get("energyTaxEurPerKwh"), 0.0898),
        vat_multiplier=max(_get_float(conversion.get("vatMultiplier"), 1.21), 0.000001),
        consumer_precision=max(_get_int(conversion.get("consumerPrecision"), 4), 0),
        spot_precision=max(_get_int(conversion.get("spotPrecision"), 6), 0),
    )


def consumer_to_spot_price(
    consumer_eur_per_kwh: Optional[float],
    conversion: PriceConversionConfig,
) -> Optional[float]:
    if consumer_eur_per_kwh is None:
        return None
    if not math.isfinite(consumer_eur_per_kwh):
        return None
    return round(
        (consumer_eur_per_kwh / conversion.vat_multiplier)
        - conversion.supplier_markup_eur_per_kwh
        - conversion.energy_tax_eur_per_kwh,
        conversion.spot_precision,
    )


def load_settings() -> PlannerSettings:
    repo_root = _repo_root()
    main_config_path = repo_root / "main" / "config" / "config.json"
    config = _load_json_file(main_config_path)
    data_dir = repo_root / "planner" / "data"
    load_forecast_path = data_dir / LOAD_FORECAST_FILE_NAME
    default_load_forecast_template_path = data_dir / DEFAULT_LOAD_FORECAST_TEMPLATE_FILE_NAME
    price_conversion = _load_price_conversion(config)

    price_api_url = (
        os.getenv("PLANNER_PRICE_API_URL")
        or _get_first_resolved_string(config.get("priceApiUrl"), config)
        or "http://localhost/zendure/main/prices/get_prices_v6.php"
    )
    automation_all_api_url = (
        os.getenv("PLANNER_AUTOMATION_ALL_API_URL")
        or _derive_automation_all_url(config)
    )
    shortwave_api_url = (
        os.getenv("PLANNER_SHORTWAVE_API_URL")
        or _derive_shortwave_url(config)
    )

    raw_min_grid = _get_int(config.get("minGridPower"), -1600)
    raw_max_grid = _get_int(config.get("maxGridPower"), 1200)

    return PlannerSettings(
        repo_root=repo_root,
        data_dir=data_dir,
        load_forecast_path=load_forecast_path,
        default_load_forecast_template_path=default_load_forecast_template_path,
        main_config_path=main_config_path,
        timezone=os.getenv("PLANNER_TIMEZONE", TIMEZONE),
        service_host=os.getenv("PLANNER_SERVICE_HOST", SERVICE_HOST),
        service_port=_get_int(os.getenv("PLANNER_SERVICE_PORT"), SERVICE_PORT),
        http_timeout_seconds=_get_int(
            os.getenv("PLANNER_HTTP_TIMEOUT_SECONDS"),
            HTTP_TIMEOUT_SECONDS,
        ),
        price_api_url=price_api_url,
        automation_all_api_url=automation_all_api_url,
        shortwave_api_url=shortwave_api_url,
        latitude=_get_float(config.get("latitude"), 52.3676),
        longitude=_get_float(config.get("longitude"), 4.9041),
        base_wh=_get_float(config.get("baseWh"), 5760.0),
        min_charge_level=max(0, min(100, _get_int(config.get("MIN_CHARGE_LEVEL"), 15))),
        max_charge_level=max(
            0,
            min(100, _get_int(config.get("MAX_CHARGE_LEVEL"), DEFAULT_MAX_CHARGE_LEVEL)),
        ),
        max_charge_power_w=max(0, raw_max_grid),
        max_discharge_power_w=max(0, abs(raw_min_grid)),
        arbitrage_min_spread_eur_per_kwh=_get_float(
            os.getenv("PLANNER_ARBITRAGE_MIN_SPREAD"),
            ARBITRAGE_MIN_SPREAD_EUR_PER_KWH,
        ),
        round_trip_efficiency=max(
            0.01,
            min(
                1.0,
                _get_float(os.getenv("PLANNER_ROUND_TRIP_EFFICIENCY"), ROUND_TRIP_EFFICIENCY),
            ),
        ),
        cheap_hour_tolerance_eur_per_kwh=_get_float(
            os.getenv("PLANNER_CHEAP_HOUR_TOLERANCE"),
            CHEAP_HOUR_TOLERANCE_EUR_PER_KWH,
        ),
        expensive_hour_tolerance_eur_per_kwh=_get_float(
            os.getenv("PLANNER_EXPENSIVE_HOUR_TOLERANCE"),
            EXPENSIVE_HOUR_TOLERANCE_EUR_PER_KWH,
        ),
        netzero_market_price_threshold_eur_per_kwh=_get_float(
            os.getenv("PLANNER_NETZERO_MARKET_PRICE_THRESHOLD"),
            NETZERO_MARKET_PRICE_THRESHOLD_EUR_PER_KWH,
        ),
        negative_export_avoidance_enabled=str(
            os.getenv("PLANNER_NEGATIVE_EXPORT_AVOIDANCE", str(NEGATIVE_EXPORT_AVOIDANCE_ENABLED))
        ).lower() not in ("0", "false", "off", "no"),
        price_release_hour_local=_get_int(
            os.getenv("PLANNER_PRICE_RELEASE_HOUR"),
            PRICE_RELEASE_HOUR_LOCAL,
        ),
        shortwave_radiation_reference_w_m2=_get_float(
            os.getenv("PLANNER_SHORTWAVE_REFERENCE"),
            SHORTWAVE_RADIATION_REFERENCE_W_M2,
        ),
        pv_system_capacity_w=_get_float(
            os.getenv("PLANNER_PV_SYSTEM_CAPACITY_W"),
            PV_SYSTEM_CAPACITY_W,
        ),
        pv_derate_factor=_get_float(
            os.getenv("PLANNER_PV_DERATE_FACTOR"),
            PV_DERATE_FACTOR,
        ),
        pv_output_clip_w=_get_float(
            os.getenv("PLANNER_PV_OUTPUT_CLIP_W"),
            PV_OUTPUT_CLIP_W,
        ),
        min_action_power_w=max(
            0,
            _get_int(os.getenv("PLANNER_MIN_ACTION_POWER_W"), MIN_ACTION_POWER_W),
        ),
        price_conversion=price_conversion,
    )
