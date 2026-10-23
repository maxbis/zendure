from __future__ import annotations

import json
import math
import os
import re
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

_COMMON_PYTHON_DIR = Path(__file__).resolve().parent.parent / "common" / "python"
if str(_COMMON_PYTHON_DIR) not in sys.path:
    sys.path.insert(0, str(_COMMON_PYTHON_DIR))

from system_config import load_system_config  # noqa: E402


TIMEZONE = "Europe/Amsterdam"
PRICE_RELEASE_HOUR_LOCAL = 14
ARBITRAGE_MIN_SPREAD_EUR_PER_KWH = 0.12
ROUND_TRIP_EFFICIENCY = 0.85
CHEAP_HOUR_TOLERANCE_EUR_PER_KWH = 0.01
EXPENSIVE_HOUR_TOLERANCE_EUR_PER_KWH = 0.01
NETZERO_MARKET_PRICE_THRESHOLD_EUR_PER_KWH = 0.18
NEGATIVE_EXPORT_AVOIDANCE_ENABLED = True
SERVICE_PORT = 8765
SERVICE_HOST = "127.0.0.1"
HTTP_TIMEOUT_SECONDS = 12
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
class PvPanel:
    facing_degrees: float  # Compass bearing: north 0, east 90, south 180, west 270.
    tilt_degrees: float


@dataclass(frozen=True)
class PlannerSettings:
    repo_root: Path
    data_dir: Path
    load_forecast_path: Path
    default_load_forecast_template_path: Path
    household_usage_profile_path: Path
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
    power_step_w: int
    active_hour_deadband_w: int
    default_household_usage_w_by_hour: List[int]
    arbitrage_min_spread_eur_per_kwh: float
    round_trip_efficiency: float
    battery_wear_cost_eur_per_kwh_discharged: float
    low_soc_discharge_threshold_percent: int
    low_soc_discharge_cost_cents_per_kwh: int
    cheap_hour_tolerance_eur_per_kwh: float
    expensive_hour_tolerance_eur_per_kwh: float
    netzero_market_price_threshold_eur_per_kwh: float
    negative_export_avoidance_enabled: bool
    price_release_hour_local: int
    shortwave_radiation_reference_w_m2: float
    pv_system_capacity_w: float
    pv_panel_rated_power_w: float
    pv_derate_factor: float
    pv_output_clip_w: float
    pv_panels: tuple[PvPanel, ...]
    pv_monthly_correction_factors: tuple[float, ...]
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


def _load_pv_config(path: Path) -> Dict[str, Any]:
    with path.open("r", encoding="utf-8") as handle:
        config = json.load(handle)
    required = {
        "panelCount", "ratedPowerWPerPanel", "panels", "irradianceReferenceWPerM2",
        "derateFactor", "monthlyCorrectionFactors", "outputClipW",
    }
    if not isinstance(config, dict) or set(config) != required:
        raise ValueError("PV config must contain exactly the documented fields")

    def positive_number(value: Any, name: str) -> float:
        if isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(value) or value <= 0:
            raise ValueError(f"PV config {name} must be a positive finite number")
        return float(value)

    count = config["panelCount"]
    raw_panels = config["panels"]
    if isinstance(count, bool) or not isinstance(count, int) or count < 1 or not isinstance(raw_panels, list) or len(raw_panels) != count:
        raise ValueError("PV config panelCount must match the nonempty panels list")
    panels = []
    for index, panel in enumerate(raw_panels):
        if not isinstance(panel, dict) or set(panel) != {"facingDegrees", "tiltDegrees"}:
            raise ValueError(f"PV config panels[{index}] must specify facingDegrees and tiltDegrees")
        facing = panel["facingDegrees"]
        tilt = panel["tiltDegrees"]
        if any(isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(value) for value in (facing, tilt)) or not 0 <= facing < 360 or not 0 <= tilt <= 90:
            raise ValueError(f"PV config panels[{index}] has invalid facing or tilt")
        panels.append(PvPanel(float(facing), float(tilt)))

    corrections = config["monthlyCorrectionFactors"]
    if not isinstance(corrections, dict) or set(corrections) != {f"{month:02d}" for month in range(1, 13)}:
        raise ValueError("PV config monthlyCorrectionFactors must contain months 01 through 12")
    correction_values = tuple(positive_number(corrections[f"{month:02d}"], f"month {month:02d}") for month in range(1, 13))
    derate = positive_number(config["derateFactor"], "derateFactor")
    if derate > 1:
        raise ValueError("PV config derateFactor must not exceed 1")
    rated_power = positive_number(config["ratedPowerWPerPanel"], "ratedPowerWPerPanel")
    return {
        "panels": tuple(panels),
        "capacity_w": count * rated_power,
        "panel_rated_power_w": rated_power,
        "reference_w_m2": positive_number(config["irradianceReferenceWPerM2"], "irradianceReferenceWPerM2"),
        "derate": derate,
        "corrections": correction_values,
        "clip_w": positive_number(config["outputClipW"], "outputClipW"),
    }


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


def _load_price_conversion(conversion: Dict[str, Any]) -> PriceConversionConfig:
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
    system_config = load_system_config(repo_root / "common" / "config" / "system.json")
    battery = system_config["battery"]
    installation = system_config["installation"]
    schedule = system_config["schedule"]
    data_dir = repo_root / "planner" / "data"
    load_forecast_path = data_dir / LOAD_FORECAST_FILE_NAME
    default_load_forecast_template_path = data_dir / DEFAULT_LOAD_FORECAST_TEMPLATE_FILE_NAME
    configured_profile_path = Path(system_config["forecast"]["householdUsageProfilePath"])
    household_usage_profile_path = (
        configured_profile_path
        if configured_profile_path.is_absolute()
        else repo_root / configured_profile_path
    ).resolve()
    try:
        household_usage_profile_path.relative_to(repo_root)
    except ValueError as error:
        raise ValueError("householdUsageProfilePath must resolve inside the repository") from error
    price_conversion = _load_price_conversion(system_config["priceConversion"])
    pv_config = _load_pv_config(repo_root / "planner" / "pv_config.json")

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

    raw_min_grid = _get_int(schedule.get("minPowerW"), -1600)
    raw_max_grid = _get_int(schedule.get("maxPowerW"), 1600)
    power_step_w = max(1, _get_int(schedule.get("powerStepW"), 100))
    active_hour_deadband_w = _get_int(
        os.getenv("PLANNER_ACTIVE_HOUR_DEADBAND_W"),
        _get_int(schedule.get("activeHourDeadbandW"), 0),
    )
    if active_hour_deadband_w < 0:
        raise ValueError("activeHourDeadbandW must be zero or greater")
    if active_hour_deadband_w % power_step_w != 0:
        raise ValueError("activeHourDeadbandW must be a multiple of powerStepW")

    return PlannerSettings(
        repo_root=repo_root,
        data_dir=data_dir,
        load_forecast_path=load_forecast_path,
        default_load_forecast_template_path=default_load_forecast_template_path,
        household_usage_profile_path=household_usage_profile_path,
        main_config_path=main_config_path,
        timezone=os.getenv("PLANNER_TIMEZONE", installation["timezone"] or TIMEZONE),
        service_host=os.getenv("PLANNER_SERVICE_HOST", SERVICE_HOST),
        service_port=_get_int(os.getenv("PLANNER_SERVICE_PORT"), SERVICE_PORT),
        http_timeout_seconds=_get_int(
            os.getenv("PLANNER_HTTP_TIMEOUT_SECONDS"),
            HTTP_TIMEOUT_SECONDS,
        ),
        price_api_url=price_api_url,
        automation_all_api_url=automation_all_api_url,
        shortwave_api_url=shortwave_api_url,
        latitude=_get_float(installation.get("latitude"), 52.3676),
        longitude=_get_float(installation.get("longitude"), 4.9041),
        base_wh=_get_float(battery.get("capacityWh"), 5760.0),
        min_charge_level=max(0, min(100, _get_int(battery.get("minChargePercent"), 15))),
        max_charge_level=max(
            0,
            min(100, _get_int(battery.get("maxChargePercent"), DEFAULT_MAX_CHARGE_LEVEL)),
        ),
        max_charge_power_w=max(0, raw_max_grid),
        max_discharge_power_w=max(0, abs(raw_min_grid)),
        power_step_w=power_step_w,
        active_hour_deadband_w=active_hour_deadband_w,
        default_household_usage_w_by_hour=[
            max(0, _get_int(value, 0))
            for value in system_config["forecast"]["defaultHouseholdUsageWByHour"]
        ],
        arbitrage_min_spread_eur_per_kwh=_get_float(
            os.getenv("PLANNER_ARBITRAGE_MIN_SPREAD"),
            ARBITRAGE_MIN_SPREAD_EUR_PER_KWH,
        ),
        round_trip_efficiency=max(
            0.01,
            min(
                1.0,
                _get_float(
                    os.getenv("PLANNER_ROUND_TRIP_EFFICIENCY"),
                    _get_float(battery.get("roundTripEfficiency"), ROUND_TRIP_EFFICIENCY),
                ),
            ),
        ),
        battery_wear_cost_eur_per_kwh_discharged=max(
            0.0,
            _get_float(battery.get("wearCostEurPerKwhDischarged"), 0.0),
        ),
        low_soc_discharge_threshold_percent=max(
            0,
            min(100, _get_int(battery.get("lowSocDischargeThresholdPercent"), 0)),
        ),
        low_soc_discharge_cost_cents_per_kwh=max(
            0,
            _get_int(battery.get("lowSocDischargeCostCentsPerKwh"), 0),
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
        shortwave_radiation_reference_w_m2=pv_config["reference_w_m2"],
        pv_system_capacity_w=pv_config["capacity_w"],
        pv_panel_rated_power_w=pv_config["panel_rated_power_w"],
        pv_derate_factor=pv_config["derate"],
        pv_output_clip_w=pv_config["clip_w"],
        pv_panels=pv_config["panels"],
        pv_monthly_correction_factors=pv_config["corrections"],
        min_action_power_w=max(
            0,
            _get_int(os.getenv("PLANNER_MIN_ACTION_POWER_W"), MIN_ACTION_POWER_W),
        ),
        price_conversion=price_conversion,
    )
