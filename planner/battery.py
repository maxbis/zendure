from __future__ import annotations

import math
from dataclasses import dataclass

from planner.models import BatteryState


@dataclass
class BatteryTracker:
    usable_capacity_wh: float
    min_energy_wh: float
    max_energy_wh: float
    energy_wh: float
    charge_efficiency: float
    discharge_efficiency: float

    @classmethod
    def from_state(cls, state: BatteryState, round_trip_efficiency: float) -> "BatteryTracker":
        charge_efficiency = math.sqrt(max(0.0001, min(round_trip_efficiency, 1.0)))
        discharge_efficiency = charge_efficiency
        min_energy_wh = state.usable_capacity_wh * (state.min_charge_level_percent / 100.0)
        max_energy_wh = state.usable_capacity_wh * (state.max_charge_level_percent / 100.0)
        energy_wh = state.usable_capacity_wh * (state.soc_percent / 100.0)
        energy_wh = max(min_energy_wh, min(max_energy_wh, energy_wh))
        return cls(
            usable_capacity_wh=state.usable_capacity_wh,
            min_energy_wh=min_energy_wh,
            max_energy_wh=max_energy_wh,
            energy_wh=energy_wh,
            charge_efficiency=charge_efficiency,
            discharge_efficiency=discharge_efficiency,
        )

    def max_charge_power_for_duration(self, duration_hours: float, hard_limit_w: int) -> int:
        if duration_hours <= 0:
            return 0
        room_wh = max(0.0, self.max_energy_wh - self.energy_wh)
        energy_limited = room_wh / max(self.charge_efficiency * duration_hours, 0.000001)
        return max(0, min(hard_limit_w, int(energy_limited)))

    def max_discharge_power_for_duration(self, duration_hours: float, hard_limit_w: int) -> int:
        if duration_hours <= 0:
            return 0
        available_wh = max(0.0, self.energy_wh - self.min_energy_wh)
        energy_limited = available_wh * self.discharge_efficiency / max(duration_hours, 0.000001)
        return max(0, min(hard_limit_w, int(energy_limited)))

    def apply_power(self, power_w: int, duration_hours: float) -> None:
        if power_w == 0 or duration_hours <= 0:
            return
        if power_w > 0:
            stored_wh = power_w * duration_hours * self.charge_efficiency
            self.energy_wh = min(self.max_energy_wh, self.energy_wh + stored_wh)
            return
        delivered_wh = abs(power_w) * duration_hours
        internal_wh = delivered_wh / max(self.discharge_efficiency, 0.000001)
        self.energy_wh = max(self.min_energy_wh, self.energy_wh - internal_wh)

    def soc_percent(self) -> float:
        if self.usable_capacity_wh <= 0:
            return 0.0
        return max(0.0, min(100.0, (self.energy_wh / self.usable_capacity_wh) * 100.0))

