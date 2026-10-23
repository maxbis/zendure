from __future__ import annotations

from dataclasses import dataclass
from datetime import date, datetime, timedelta
import json
import math
from pathlib import Path
from typing import Any, Sequence


PROFILE_SCHEMA_VERSION = 1
PROFILE_METHOD = "p60"


@dataclass(frozen=True)
class HouseholdUsageSelection:
    values_w_by_hour: list[float]
    source: str
    profile_path: Path
    fallback_reason: str | None = None
    profile_generated_at: str | None = None
    profile_source_end_date: str | None = None

    def log_fields(self) -> dict[str, Any]:
        return {
            "household_forecast_source": self.source,
            "household_forecast_w_by_hour": list(self.values_w_by_hour),
            "household_forecast_profile_path": str(self.profile_path),
            "household_forecast_profile_generated_at": self.profile_generated_at,
            "household_forecast_profile_source_end_date": self.profile_source_end_date,
            "household_forecast_fallback_reason": self.fallback_reason,
        }


class InvalidHouseholdUsageProfile(ValueError):
    def __init__(self, reason: str):
        super().__init__(reason)
        self.reason = reason


def _parse_profile(
    payload: Any,
    *,
    timezone: str,
    expected_source_end_date: date,
) -> tuple[list[float], str, str]:
    if not isinstance(payload, dict):
        raise InvalidHouseholdUsageProfile("invalid_schema")
    if payload.get("schema_version") != PROFILE_SCHEMA_VERSION:
        raise InvalidHouseholdUsageProfile("invalid_schema")
    if payload.get("method") != PROFILE_METHOD:
        raise InvalidHouseholdUsageProfile("invalid_method")
    if payload.get("timezone") != timezone:
        raise InvalidHouseholdUsageProfile("timezone_mismatch")

    generated_at = payload.get("generated_at")
    if not isinstance(generated_at, str):
        raise InvalidHouseholdUsageProfile("invalid_generated_at")
    try:
        generated_at_value = datetime.fromisoformat(generated_at)
    except ValueError as error:
        raise InvalidHouseholdUsageProfile("invalid_generated_at") from error
    if generated_at_value.tzinfo is None:
        raise InvalidHouseholdUsageProfile("invalid_generated_at")

    source_end_date = payload.get("source_end_date")
    if not isinstance(source_end_date, str):
        raise InvalidHouseholdUsageProfile("invalid_source_end_date")
    try:
        parsed_source_end_date = date.fromisoformat(source_end_date)
    except ValueError as error:
        raise InvalidHouseholdUsageProfile("invalid_source_end_date") from error
    if parsed_source_end_date != expected_source_end_date:
        raise InvalidHouseholdUsageProfile("profile_stale")

    hours = payload.get("hours")
    expected_hours = {f"{hour:02d}" for hour in range(24)}
    if not isinstance(hours, dict) or set(hours) != expected_hours:
        raise InvalidHouseholdUsageProfile("invalid_hours")

    values: list[float] = []
    for hour in range(24):
        record = hours[f"{hour:02d}"]
        if not isinstance(record, dict):
            raise InvalidHouseholdUsageProfile("invalid_hours")
        raw_value = record.get("value_w")
        if isinstance(raw_value, bool) or not isinstance(raw_value, (int, float)):
            raise InvalidHouseholdUsageProfile("invalid_hours")
        value = float(raw_value)
        if not math.isfinite(value) or value < 0:
            raise InvalidHouseholdUsageProfile("invalid_hours")
        values.append(value)
    return values, generated_at, source_end_date


def select_household_usage(
    *,
    profile_path: Path,
    configured_values_w_by_hour: Sequence[int | float],
    timezone: str,
    now: datetime,
) -> HouseholdUsageSelection:
    fallback_values = [float(value) for value in configured_values_w_by_hour]
    if len(fallback_values) != 24:
        raise ValueError("Configured household usage must contain exactly 24 values.")

    fallback_reason: str
    try:
        raw = profile_path.read_text(encoding="utf-8")
    except FileNotFoundError:
        fallback_reason = "profile_missing"
    except OSError:
        fallback_reason = "profile_unreadable"
    else:
        try:
            payload = json.loads(raw)
        except json.JSONDecodeError:
            fallback_reason = "invalid_json"
        else:
            try:
                values, generated_at, source_end_date = _parse_profile(
                    payload,
                    timezone=timezone,
                    expected_source_end_date=now.date() - timedelta(days=1),
                )
            except InvalidHouseholdUsageProfile as error:
                fallback_reason = error.reason
            else:
                return HouseholdUsageSelection(
                    values_w_by_hour=values,
                    source="historical_p60",
                    profile_path=profile_path,
                    profile_generated_at=generated_at,
                    profile_source_end_date=source_end_date,
                )

    return HouseholdUsageSelection(
        values_w_by_hour=fallback_values,
        source="config_default",
        profile_path=profile_path,
        fallback_reason=fallback_reason,
    )
