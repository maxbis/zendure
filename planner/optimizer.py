from __future__ import annotations

from datetime import datetime, timedelta
from typing import Any, Dict, List, Optional, Tuple

from zoneinfo import ZoneInfo

from planner.battery import BatteryTracker
from planner.config import PlannerSettings
from planner.forecast import build_segment_boundaries, derive_hourly_load_w, derive_pv_forecast_by_date, select_horizon_dates
from planner.models import BatteryState, LoadForecastRecord, PlannerResult, PlannerSlot
from planner.schedule_emit import planner_slots_to_resolved


def _next_date_text(date_text: str) -> str:
    day = datetime.strptime(date_text, "%Y-%m-%d").date()
    return (day + timedelta(days=1)).strftime("%Y-%m-%d")


def _safe_value(values: List[Optional[float]], hour: int) -> Optional[float]:
    if hour < 0 or hour >= len(values):
        return None
    return values[hour]


def _series_value(series_by_date: Dict[str, List[float]], date_text: str, hour: int) -> float:
    if date_text not in series_by_date:
        return 0.0
    values = series_by_date[date_text]
    if hour < 0 or hour >= len(values):
        return 0.0
    return float(values[hour])


def _flatten_imports(
    horizon_dates: List[str],
    import_today: List[Optional[float]],
    import_tomorrow: List[Optional[float]],
) -> Dict[str, List[Optional[float]]]:
    result: Dict[str, List[Optional[float]]] = {}
    if horizon_dates:
        result[horizon_dates[0]] = import_today
    if len(horizon_dates) > 1:
        result[horizon_dates[1]] = import_tomorrow
    return result


def _flatten_exports(
    horizon_dates: List[str],
    export_today: List[Optional[float]],
    export_tomorrow: List[Optional[float]],
) -> Dict[str, List[Optional[float]]]:
    result: Dict[str, List[Optional[float]]] = {}
    if horizon_dates:
        result[horizon_dates[0]] = export_today
    if len(horizon_dates) > 1:
        result[horizon_dates[1]] = export_tomorrow
    return result


def _collect_future_values(
    current_index: int,
    segments: List[Tuple[datetime, datetime]],
    import_by_date: Dict[str, List[Optional[float]]],
    export_by_date: Dict[str, List[Optional[float]]],
) -> Tuple[List[float], List[float]]:
    future_imports: List[float] = []
    future_exports: List[float] = []
    for index in range(current_index + 1, len(segments)):
        start, _end = segments[index]
        date_text = start.strftime("%Y-%m-%d")
        hour = start.hour
        import_price = _safe_value(import_by_date.get(date_text, []), hour)
        export_price = _safe_value(export_by_date.get(date_text, []), hour)
        if import_price is not None:
            future_imports.append(import_price)
        if export_price is not None:
            future_exports.append(export_price)
    return future_imports, future_exports


def _resolve_planner_slot(
    tracker: BatteryTracker,
    state: BatteryState,
    settings: PlannerSettings,
    segment_start: datetime,
    segment_end: datetime,
    load_w: float,
    pv_w: float,
    import_price: Optional[float],
    export_price: Optional[float],
    future_imports: List[float],
    future_exports: List[float],
) -> PlannerSlot:
    duration_hours = max((segment_end - segment_start).total_seconds() / 3600.0, 0.0)
    current_import = float(import_price or 0.0)
    current_export = float(export_price or 0.0)
    current_market_price = current_export
    future_best_storage_value = max(
        [current_import] + future_imports + future_exports or [current_import]
    )
    future_min_import = min([current_import] + future_imports or [current_import])
    future_max_import = max([current_import] + future_imports or [current_import])

    net_surplus_w = max(0.0, pv_w - load_w)
    residual_load_w = max(0.0, load_w - pv_w)
    max_charge_now = tracker.max_charge_power_for_duration(duration_hours, state.max_charge_power_w)
    max_discharge_now = tracker.max_discharge_power_for_duration(duration_hours, state.max_discharge_power_w)

    mode = "fixed"
    target_power = 0
    min_power = None
    max_power = None
    reason = "idle"

    cheap_now = current_import <= (future_min_import + settings.cheap_hour_tolerance_eur_per_kwh)
    expensive_now = current_import >= (future_max_import - settings.expensive_hour_tolerance_eur_per_kwh)
    profitable_charge_margin = future_best_storage_value - current_import
    profitable_discharge_margin = current_import - future_min_import
    profitable_export_margin = current_export - future_min_import

    if (
        net_surplus_w > settings.min_action_power_w
        and max_charge_now > settings.min_action_power_w
        and (
            settings.negative_export_avoidance_enabled and current_export < 0
            or future_best_storage_value - current_export >= settings.arbitrage_min_spread_eur_per_kwh
        )
    ):
        mode = "netzero+"
        target_power = None
        min_power = 0
        max_power = max_charge_now
        reason = "absorb solar surplus"
    elif (
        max_charge_now > settings.min_action_power_w
        and (
            current_import < 0
            or (
                cheap_now
                and profitable_charge_margin >= settings.arbitrage_min_spread_eur_per_kwh
            )
        )
    ):
        mode = "fixed"
        target_power = max_charge_now
        reason = "charge from cheap grid hour"
    elif (
        residual_load_w > settings.min_action_power_w
        and max_discharge_now > settings.min_action_power_w
        and (
            current_market_price >= settings.netzero_market_price_threshold_eur_per_kwh
            or (
                expensive_now
                and profitable_discharge_margin >= settings.arbitrage_min_spread_eur_per_kwh
            )
        )
    ):
        mode = "netzero"
        target_power = None
        min_power = -max_discharge_now
        max_power = 0
        reason = (
            "offset grid import above market threshold"
            if current_market_price >= settings.netzero_market_price_threshold_eur_per_kwh
            else "offset expensive grid import"
        )
    elif (
        max_discharge_now > settings.min_action_power_w
        and current_export > 0
        and profitable_export_margin >= settings.arbitrage_min_spread_eur_per_kwh
    ):
        mode = "fixed"
        target_power = -max_discharge_now
        reason = "export during profitable spot price"
    elif (
        current_export < 0
        and settings.negative_export_avoidance_enabled
        and max_charge_now > settings.min_action_power_w
    ):
        mode = "fixed"
        target_power = max_charge_now
        reason = "avoid negative-price export"

    applied_power = int(target_power or 0)
    if mode == "netzero+":
        applied_power = min(int(net_surplus_w), max_charge_now)
    elif mode == "netzero":
        applied_power = -min(int(residual_load_w), max_discharge_now)

    tracker.apply_power(applied_power, duration_hours)

    return PlannerSlot(
        start=segment_start.isoformat(),
        end=segment_end.isoformat(),
        mode=mode,
        target_power=target_power,
        min_power=min_power,
        max_power=max_power,
        reason=reason,
    )


def generate_plan(
    *,
    now: datetime,
    settings: PlannerSettings,
    battery_state: BatteryState,
    load_forecasts: Dict[str, LoadForecastRecord],
    import_today: List[Optional[float]],
    import_tomorrow: List[Optional[float]],
    export_today: List[Optional[float]],
    export_tomorrow: List[Optional[float]],
    shortwave_payload: Dict[str, Any],
) -> PlannerResult:
    tz = ZoneInfo(settings.timezone)
    now = now.astimezone(tz)
    horizon_dates = select_horizon_dates(now, settings)
    warnings: List[str] = []
    load_by_date: Dict[str, List[float]] = {}
    for date_text in horizon_dates:
        record = load_forecasts.get(date_text)
        if record is None:
            warnings.append(f"Missing load forecast for {date_text}")
            continue
        load_by_date[date_text] = derive_hourly_load_w(record)

    pv_by_date = derive_pv_forecast_by_date(shortwave_payload, settings)
    import_by_date = _flatten_imports(horizon_dates, import_today, import_tomorrow)
    export_by_date = _flatten_exports(horizon_dates, export_today, export_tomorrow)
    segments = build_segment_boundaries(now, horizon_dates, settings.timezone)
    tracker = BatteryTracker.from_state(battery_state, settings.round_trip_efficiency)

    plan_slots: List[PlannerSlot] = []
    explanations: List[str] = []
    for index, (segment_start, segment_end) in enumerate(segments):
        date_text = segment_start.strftime("%Y-%m-%d")
        hour = segment_start.hour
        if date_text not in load_by_date:
            continue
        load_w = _series_value(load_by_date, date_text, hour)
        pv_w = _series_value(pv_by_date, date_text, hour)
        import_price = _safe_value(import_by_date.get(date_text, []), hour)
        export_price = _safe_value(export_by_date.get(date_text, []), hour)
        future_imports, future_exports = _collect_future_values(
            index,
            segments,
            import_by_date,
            export_by_date,
        )
        slot = _resolve_planner_slot(
            tracker=tracker,
            state=battery_state,
            settings=settings,
            segment_start=segment_start,
            segment_end=segment_end,
            load_w=load_w,
            pv_w=pv_w,
            import_price=import_price,
            export_price=export_price,
            future_imports=future_imports,
            future_exports=future_exports,
        )
        plan_slots.append(slot)
        if slot.reason and slot.reason not in explanations:
            explanations.append(slot.reason)

    current_day_slots = [slot for slot in plan_slots if datetime.fromisoformat(slot.start).astimezone(tz).strftime("%Y-%m-%d") == horizon_dates[0]]
    resolved_slots = planner_slots_to_resolved(current_day_slots, settings.timezone)

    meta = {
        "agent_version": "planner-v1",
        "generated_at": now.isoformat(),
        "timezone": settings.timezone,
        "current_date": horizon_dates[0],
        "planned_dates": horizon_dates,
        "warnings": warnings,
        "battery_soc_percent_end_estimate": round(tracker.soc_percent(), 2),
    }
    success = bool(resolved_slots)
    error = None if success else "Unable to build a resolved schedule from available inputs"
    return PlannerResult(
        success=success,
        plan_slots=plan_slots,
        resolved_slots=resolved_slots,
        explanations=explanations,
        meta=meta,
        error=error,
    )
