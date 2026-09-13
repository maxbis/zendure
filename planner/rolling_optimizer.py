from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, time, timedelta
import math
from statistics import fmean
from typing import Dict, List, Optional, Tuple

from planner.models import BatteryState


@dataclass(frozen=True)
class RollingInputSlot:
    start: datetime
    end: datetime
    import_price_eur_per_kwh: float
    export_price_eur_per_kwh: float
    price_source: str
    load_w: float
    pv_w: float

    @property
    def duration_hours(self) -> float:
        return max(0.0, (self.end - self.start).total_seconds() / 3600.0)


@dataclass(frozen=True)
class RollingDecision:
    start: str
    end: str
    battery_power_w: int
    start_soc_percent: float
    end_soc_percent: float
    import_price_eur_per_kwh: float
    export_price_eur_per_kwh: float
    price_source: str
    load_w: float
    pv_w: float
    grid_power_w: float
    expected_cost_eur: float
    schedule_value: object
    min_power: Optional[int]
    max_power: Optional[int]
    reason: str

    def to_dict(self) -> dict:
        data = {
            "start": self.start,
            "end": self.end,
            "battery_power_w": self.battery_power_w,
            "start_soc_percent": round(self.start_soc_percent, 2),
            "end_soc_percent": round(self.end_soc_percent, 2),
            "import_price_eur_per_kwh": self.import_price_eur_per_kwh,
            "export_price_eur_per_kwh": self.export_price_eur_per_kwh,
            "price_source": self.price_source,
            "load_w": round(self.load_w, 1),
            "pv_w": round(self.pv_w, 1),
            "grid_power_w": round(self.grid_power_w, 1),
            "expected_cost_eur": round(self.expected_cost_eur, 6),
            "schedule_value": self.schedule_value,
            "reason": self.reason,
        }
        if self.min_power is not None:
            data["min_power"] = self.min_power
        if self.max_power is not None:
            data["max_power"] = self.max_power
        return data


@dataclass(frozen=True)
class RollingPlan:
    generated_at: str
    horizon_start: str
    horizon_end: str
    starting_soc_percent: float
    ending_soc_percent: float
    expected_energy_cost_eur: float
    terminal_energy_value_eur: float
    objective_eur: float
    round_trip_efficiency: float
    decisions: List[RollingDecision]

    def to_dict(self) -> dict:
        return {
            "generated_at": self.generated_at,
            "horizon_start": self.horizon_start,
            "horizon_end": self.horizon_end,
            "starting_soc_percent": round(self.starting_soc_percent, 2),
            "ending_soc_percent": round(self.ending_soc_percent, 2),
            "expected_energy_cost_eur": round(self.expected_energy_cost_eur, 6),
            "terminal_energy_value_eur": round(self.terminal_energy_value_eur, 6),
            "objective_eur": round(self.objective_eur, 6),
            "round_trip_efficiency": self.round_trip_efficiency,
            "decisions": [decision.to_dict() for decision in self.decisions],
        }


@dataclass(frozen=True)
class _ActionCandidate:
    power_w: int
    schedule_value: Optional[object] = None
    min_power: Optional[int] = None
    max_power: Optional[int] = None
    reason: Optional[str] = None
    opportunistic: bool = False


def build_rolling_boundaries(
    now: datetime,
    horizon_hours: int,
    *,
    extend_to_midnight: bool = False,
) -> List[Tuple[datetime, datetime]]:
    if horizon_hours <= 0:
        raise ValueError("horizon_hours must be greater than zero")
    horizon_end = now + timedelta(hours=horizon_hours)
    if extend_to_midnight and horizon_end.timetz().replace(tzinfo=None) != time.min:
        following_date = horizon_end.date() + timedelta(days=1)
        horizon_end = datetime.combine(following_date, time.min, tzinfo=now.tzinfo)
    boundaries = [now]
    next_hour = now.replace(minute=0, second=0, microsecond=0) + timedelta(hours=1)
    while next_hour < horizon_end:
        boundaries.append(next_hour)
        next_hour += timedelta(hours=1)
    boundaries.append(horizon_end)
    return list(zip(boundaries, boundaries[1:]))


def _quantize_energy(energy_wh: float, step_wh: float) -> int:
    return int(round(energy_wh / step_wh))


def _hour_cost(grid_power_w: float, duration_hours: float, import_price: float, export_price: float) -> float:
    grid_kwh = grid_power_w * duration_hours / 1000.0
    if grid_kwh >= 0:
        return grid_kwh * import_price
    return grid_kwh * export_price


def _schedule_command(
    battery_power_w: int,
    load_w: float,
    pv_w: float,
    max_discharge_power_w: int,
    power_step_w: int,
) -> Tuple[object, Optional[int], Optional[int], str]:
    if battery_power_w > 0:
        expected_surplus_w = max(0.0, pv_w - load_w)
        if battery_power_w <= expected_surplus_w + (power_step_w / 2.0):
            return "netzero+", 0, battery_power_w, "absorb expected solar surplus"
        return battery_power_w, None, None, "charge at fixed power from grid and available solar"

    if battery_power_w < 0:
        residual_load_w = max(0.0, load_w - pv_w)
        if abs(battery_power_w) <= residual_load_w + (power_step_w / 2.0):
            return "netzero-", max(battery_power_w, -max_discharge_power_w), 0, "offset expected household import"
        return battery_power_w, None, None, "discharge beyond household load for export"

    return 0, None, None, "idle"


def _opportunistic_netzero_plus_candidate(
    *,
    slot: RollingInputSlot,
    energy_wh: float,
    max_energy_wh: float,
    charge_efficiency: float,
    max_charge_power_w: int,
    terminal_price: float,
    terminal_value_factor: float,
    future_import_prices: List[float],
) -> Optional[_ActionCandidate]:
    """Return a daylight NZ+ choice when stored solar is worth more than export."""
    if slot.pv_w <= 0 or slot.duration_hours <= 0 or max_charge_power_w <= 0:
        return None

    future_consumer_value = max(
        [terminal_price * max(0.0, terminal_value_factor)] + future_import_prices
    )
    stored_solar_value = future_consumer_value * charge_efficiency * charge_efficiency
    if slot.export_price_eur_per_kwh >= stored_solar_value - 1e-12:
        return None

    available_input_w = max(
        0.0,
        (max_energy_wh - energy_wh)
        / max(charge_efficiency * slot.duration_hours, 0.000001),
    )
    expected_surplus_w = max(0.0, slot.pv_w - slot.load_w)
    modeled_power_w = int(
        round(min(expected_surplus_w, float(max_charge_power_w), available_input_w))
    )

    return _ActionCandidate(
        power_w=max(0, modeled_power_w),
        schedule_value="netzero+",
        min_power=0,
        max_power=max_charge_power_w,
        reason="opportunistically absorb actual solar surplus",
        opportunistic=True,
    )


def _tie_rank(candidate: _ActionCandidate) -> Tuple[int, int]:
    """Prefer gentler actions, then adaptive NZ+ over fixed idle on exact ties."""
    return (abs(candidate.power_w), 0 if candidate.opportunistic else 1)


def _bucket_adjusted_cost(
    cash_cost: float,
    energy_wh: float,
    discharge_efficiency: float,
    continuation_consumer_price: float,
) -> float:
    """Compare paths inside one approximate SoC bucket without discarding useful energy."""
    deliverable_kwh = energy_wh * discharge_efficiency / 1000.0
    return cash_cost - deliverable_kwh * continuation_consumer_price


def optimize_rolling_schedule(
    *,
    now: datetime,
    battery_state: BatteryState,
    slots: List[RollingInputSlot],
    round_trip_efficiency: float,
    power_step_w: int,
    soc_step_wh: float,
    terminal_value_factor: float = 1.0,
) -> RollingPlan:
    if not slots:
        raise ValueError("At least one rolling input slot is required")
    if power_step_w <= 0 or soc_step_wh <= 0:
        raise ValueError("Power and SoC steps must be greater than zero")

    rte = max(0.01, min(1.0, float(round_trip_efficiency)))
    charge_efficiency = math.sqrt(rte)
    discharge_efficiency = charge_efficiency
    capacity_wh = float(battery_state.usable_capacity_wh)
    min_energy_wh = capacity_wh * battery_state.min_charge_level_percent / 100.0
    max_energy_wh = capacity_wh * battery_state.max_charge_level_percent / 100.0
    starting_energy_wh = max(
        min_energy_wh,
        min(max_energy_wh, capacity_wh * battery_state.soc_percent / 100.0),
    )

    min_key = int(math.ceil(min_energy_wh / soc_step_wh))
    max_key = int(math.floor(max_energy_wh / soc_step_wh))
    start_key = max(min_key, min(max_key, _quantize_energy(starting_energy_wh, soc_step_wh)))
    discharge_actions = [
        -power
        for power in range(power_step_w, int(battery_state.max_discharge_power_w) + 1, power_step_w)
    ]
    charge_actions = list(
        range(power_step_w, int(battery_state.max_charge_power_w) + 1, power_step_w)
    )
    actions = [
        _ActionCandidate(power_w=power)
        for power in sorted(discharge_actions + [0] + charge_actions)
    ]

    terminal_price = fmean(slot.import_price_eur_per_kwh for slot in slots)

    states: Dict[int, Tuple[float, float, List[Tuple[_ActionCandidate, float, float, float]]]] = {
        start_key: (0.0, starting_energy_wh, [])
    }
    for slot_index, slot in enumerate(slots):
        next_states: Dict[int, Tuple[float, float, List[Tuple[_ActionCandidate, float, float, float]]]] = {}
        duration = slot.duration_hours
        future_import_prices = [
            future_slot.import_price_eur_per_kwh
            for future_slot in slots[slot_index + 1:]
        ]
        continuation_consumer_price = max(
            [terminal_price * max(0.0, terminal_value_factor)] + future_import_prices
        )
        for _energy_key, (cost_so_far, energy_wh, path) in states.items():
            candidates = list(actions)
            opportunistic = _opportunistic_netzero_plus_candidate(
                slot=slot,
                energy_wh=energy_wh,
                max_energy_wh=max_energy_wh,
                charge_efficiency=charge_efficiency,
                max_charge_power_w=int(battery_state.max_charge_power_w),
                terminal_price=terminal_price,
                terminal_value_factor=terminal_value_factor,
                future_import_prices=future_import_prices,
            )
            if opportunistic is not None:
                candidates.append(opportunistic)

            for candidate in candidates:
                action_w = candidate.power_w
                if action_w >= 0:
                    next_energy_wh = energy_wh + action_w * duration * charge_efficiency
                else:
                    next_energy_wh = energy_wh - abs(action_w) * duration / discharge_efficiency
                if next_energy_wh < min_energy_wh - 0.001 or next_energy_wh > max_energy_wh + 0.001:
                    continue
                next_key = max(min_key, min(max_key, _quantize_energy(next_energy_wh, soc_step_wh)))
                grid_power_w = slot.load_w - slot.pv_w + action_w
                hour_cost = _hour_cost(
                    grid_power_w,
                    duration,
                    slot.import_price_eur_per_kwh,
                    slot.export_price_eur_per_kwh,
                )
                candidate_cost = cost_so_far + hour_cost
                previous = next_states.get(next_key)
                previous_action = (
                    previous[2][-1][0]
                    if previous is not None and previous[2]
                    else _ActionCandidate(0)
                )
                compare_stored_value = candidate.opportunistic or previous_action.opportunistic
                if compare_stored_value:
                    candidate_comparison_cost = _bucket_adjusted_cost(
                        candidate_cost,
                        next_energy_wh,
                        discharge_efficiency,
                        continuation_consumer_price,
                    )
                    previous_comparison_cost = (
                        float("inf")
                        if previous is None
                        else _bucket_adjusted_cost(
                            previous[0],
                            previous[1],
                            discharge_efficiency,
                            continuation_consumer_price,
                        )
                    )
                else:
                    candidate_comparison_cost = candidate_cost
                    previous_comparison_cost = float("inf") if previous is None else previous[0]

                if previous is None or candidate_comparison_cost < previous_comparison_cost - 1e-12:
                    next_states[next_key] = (
                        candidate_cost,
                        next_energy_wh,
                        path + [(candidate, energy_wh, next_energy_wh, grid_power_w)],
                    )
                elif previous is not None and abs(candidate_comparison_cost - previous_comparison_cost) <= 1e-12:
                    if _tie_rank(candidate) < _tie_rank(previous_action):
                        next_states[next_key] = (
                            candidate_cost,
                            next_energy_wh,
                            path + [(candidate, energy_wh, next_energy_wh, grid_power_w)],
                        )
        if not next_states:
            raise RuntimeError("No feasible battery states remain in the rolling horizon")
        states = next_states

    best_key: Optional[int] = None
    best_objective = float("inf")
    best_energy_cost = 0.0
    best_path: List[Tuple[_ActionCandidate, float, float, float]] = []
    best_terminal_value = 0.0
    best_ending_energy_wh = starting_energy_wh
    for energy_key, (energy_cost, energy_wh, path) in states.items():
        terminal_value = (
            max(0.0, energy_wh - min_energy_wh)
            * discharge_efficiency
            / 1000.0
            * terminal_price
            * max(0.0, terminal_value_factor)
        )
        objective = energy_cost - terminal_value
        if objective < best_objective:
            best_key = energy_key
            best_objective = objective
            best_energy_cost = energy_cost
            best_path = path
            best_terminal_value = terminal_value
            best_ending_energy_wh = energy_wh

    if best_key is None:
        raise RuntimeError("Unable to select a feasible rolling plan")

    decisions: List[RollingDecision] = []
    for slot, (candidate, start_energy_wh, end_energy_wh, grid_power_w) in zip(slots, best_path):
        action_w = candidate.power_w
        duration = slot.duration_hours
        if candidate.schedule_value is not None:
            schedule_value = candidate.schedule_value
            min_power = candidate.min_power
            max_power = candidate.max_power
            reason = candidate.reason or "opportunistic dynamic action"
        else:
            schedule_value, min_power, max_power, reason = _schedule_command(
                action_w,
                slot.load_w,
                slot.pv_w,
                battery_state.max_discharge_power_w,
                power_step_w,
            )
        decisions.append(
            RollingDecision(
                start=slot.start.isoformat(),
                end=slot.end.isoformat(),
                battery_power_w=action_w,
                start_soc_percent=start_energy_wh / capacity_wh * 100.0,
                end_soc_percent=end_energy_wh / capacity_wh * 100.0,
                import_price_eur_per_kwh=slot.import_price_eur_per_kwh,
                export_price_eur_per_kwh=slot.export_price_eur_per_kwh,
                price_source=slot.price_source,
                load_w=slot.load_w,
                pv_w=slot.pv_w,
                grid_power_w=grid_power_w,
                expected_cost_eur=_hour_cost(
                    grid_power_w,
                    duration,
                    slot.import_price_eur_per_kwh,
                    slot.export_price_eur_per_kwh,
                ),
                schedule_value=schedule_value,
                min_power=min_power,
                max_power=max_power,
                reason=reason,
            )
        )

    return RollingPlan(
        generated_at=now.isoformat(),
        horizon_start=slots[0].start.isoformat(),
        horizon_end=slots[-1].end.isoformat(),
        starting_soc_percent=starting_energy_wh / capacity_wh * 100.0,
        ending_soc_percent=best_ending_energy_wh / capacity_wh * 100.0,
        expected_energy_cost_eur=best_energy_cost,
        terminal_energy_value_eur=best_terminal_value,
        objective_eur=best_objective,
        round_trip_efficiency=rte,
        decisions=decisions,
    )
