"""Strict loader for the shared Zendure system configuration."""

from __future__ import annotations

import json
import math
from pathlib import Path
from typing import Any, Optional, Union
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError


DEFAULT_SYSTEM_CONFIG_PATH = Path(__file__).resolve().parents[1] / "config" / "system.json"


class SystemConfigError(ValueError):
    """Raised when the shared system configuration is missing or invalid."""


def load_system_config(config_path: Optional[Union[Path, str]] = None) -> dict[str, Any]:
    """Load, validate and normalize the shared system configuration."""

    path = Path(config_path) if config_path is not None else DEFAULT_SYSTEM_CONFIG_PATH
    if not path.is_file():
        raise SystemConfigError(f"System configuration file not found: {path}")

    try:
        raw = path.read_text(encoding="utf-8")
    except OSError as error:
        raise SystemConfigError(f"Unable to read system configuration file: {path}") from error

    try:
        decoded = json.loads(raw)
    except json.JSONDecodeError as error:
        raise SystemConfigError(
            f"Invalid JSON in system configuration file {path}: "
            f"line {error.lineno} column {error.colno}"
        ) from error

    if not isinstance(decoded, dict):
        raise SystemConfigError("Expected an object at $.")
    return validate_system_config(decoded)


def validate_system_config(config: dict[str, Any]) -> dict[str, Any]:
    """Validate and normalize an already decoded system configuration."""

    _assert_exact_keys(
        config,
        {"schemaVersion", "battery", "installation", "priceConversion"},
        "$",
    )

    schema_version = _require_integer(config["schemaVersion"], "$.schemaVersion", 1, 1)
    battery = _require_object(config["battery"], "$.battery")
    installation = _require_object(config["installation"], "$.installation")
    price_conversion = _require_object(config["priceConversion"], "$.priceConversion")

    _assert_exact_keys(
        battery,
        {"capacityWh", "minChargePercent", "maxChargePercent"},
        "$.battery",
    )
    capacity_wh = _require_integer(battery["capacityWh"], "$.battery.capacityWh", 1)
    min_charge_percent = _require_integer(
        battery["minChargePercent"], "$.battery.minChargePercent", 0, 99
    )
    max_charge_percent = _require_integer(
        battery["maxChargePercent"], "$.battery.maxChargePercent", 1, 100
    )
    if min_charge_percent >= max_charge_percent:
        raise SystemConfigError(
            "$.battery.minChargePercent must be lower than $.battery.maxChargePercent."
        )

    _assert_exact_keys(
        installation,
        {"name", "latitude", "longitude", "timezone"},
        "$.installation",
    )
    name = _require_non_blank_string(installation["name"], "$.installation.name")
    latitude = _require_number(installation["latitude"], "$.installation.latitude", -90.0, 90.0)
    longitude = _require_number(
        installation["longitude"], "$.installation.longitude", -180.0, 180.0
    )
    timezone = _require_non_blank_string(installation["timezone"], "$.installation.timezone")
    try:
        ZoneInfo(timezone)
    except (ZoneInfoNotFoundError, ValueError) as error:
        raise SystemConfigError("$.installation.timezone is not a recognized IANA timezone.") from error

    _assert_exact_keys(
        price_conversion,
        {
            "supplierMarkupEurPerKwh",
            "energyTaxEurPerKwh",
            "vatMultiplier",
            "consumerPrecision",
            "spotPrecision",
        },
        "$.priceConversion",
    )
    supplier_markup = _require_number(
        price_conversion["supplierMarkupEurPerKwh"],
        "$.priceConversion.supplierMarkupEurPerKwh",
        0.0,
    )
    energy_tax = _require_number(
        price_conversion["energyTaxEurPerKwh"],
        "$.priceConversion.energyTaxEurPerKwh",
        0.0,
    )
    vat_multiplier = _require_number(
        price_conversion["vatMultiplier"],
        "$.priceConversion.vatMultiplier",
        0.0,
        exclusive_minimum=True,
    )
    consumer_precision = _require_integer(
        price_conversion["consumerPrecision"],
        "$.priceConversion.consumerPrecision",
        0,
        12,
    )
    spot_precision = _require_integer(
        price_conversion["spotPrecision"],
        "$.priceConversion.spotPrecision",
        0,
        12,
    )

    return {
        "schemaVersion": schema_version,
        "battery": {
            "capacityWh": capacity_wh,
            "minChargePercent": min_charge_percent,
            "maxChargePercent": max_charge_percent,
        },
        "installation": {
            "name": name,
            "latitude": latitude,
            "longitude": longitude,
            "timezone": timezone,
        },
        "priceConversion": {
            "supplierMarkupEurPerKwh": supplier_markup,
            "energyTaxEurPerKwh": energy_tax,
            "vatMultiplier": vat_multiplier,
            "consumerPrecision": consumer_precision,
            "spotPrecision": spot_precision,
        },
    }


def _require_object(value: Any, path: str) -> dict[str, Any]:
    if not isinstance(value, dict):
        raise SystemConfigError(f"Expected an object at {path}.")
    return value


def _assert_exact_keys(value: dict[str, Any], expected: set[str], path: str) -> None:
    actual = set(value)
    missing = sorted(expected - actual)
    unknown = sorted(actual - expected)
    if not missing and not unknown:
        return

    details = []
    if missing:
        details.append(f"missing: {', '.join(missing)}")
    if unknown:
        details.append(f"unknown: {', '.join(unknown)}")
    raise SystemConfigError(f"Invalid properties at {path} ({'; '.join(details)}).")


def _require_integer(value: Any, path: str, minimum: int, maximum: Optional[int] = None) -> int:
    if isinstance(value, bool) or not isinstance(value, int):
        raise SystemConfigError(f"{path} must be an integer.")
    if value < minimum:
        raise SystemConfigError(f"{path} must be at least {minimum}.")
    if maximum is not None and value > maximum:
        raise SystemConfigError(f"{path} must be at most {maximum}.")
    return value


def _require_number(
    value: Any,
    path: str,
    minimum: float,
    maximum: Optional[float] = None,
    *,
    exclusive_minimum: bool = False,
) -> float:
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise SystemConfigError(f"{path} must be a number.")

    number = float(value)
    if not math.isfinite(number):
        raise SystemConfigError(f"{path} must be a finite number.")
    if (exclusive_minimum and number <= minimum) or (not exclusive_minimum and number < minimum):
        comparison = "greater than" if exclusive_minimum else "at least"
        raise SystemConfigError(f"{path} must be {comparison} {_format_number(minimum)}.")
    if maximum is not None and number > maximum:
        raise SystemConfigError(f"{path} must be at most {_format_number(maximum)}.")
    return number


def _require_non_blank_string(value: Any, path: str) -> str:
    if not isinstance(value, str) or not value.strip():
        raise SystemConfigError(f"{path} must be a non-blank string.")
    return value


def _format_number(value: float) -> str:
    return f"{value:.12f}".rstrip("0").rstrip(".")
