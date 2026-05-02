from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any, Dict, List, Optional


@dataclass
class LoadForecastRecord:
    date: str
    timezone: str
    baseline_load_w_by_hour: List[float]
    incidentals: Dict[str, float]
    updated_at: str

    def to_dict(self) -> Dict[str, Any]:
        return {
            "date": self.date,
            "timezone": self.timezone,
            "baseline_load_w_by_hour": list(self.baseline_load_w_by_hour),
            "incidentals": dict(self.incidentals),
            "updated_at": self.updated_at,
        }


@dataclass
class BatteryState:
    soc_percent: float
    usable_capacity_wh: float
    max_charge_power_w: int
    max_discharge_power_w: int
    min_charge_level_percent: int
    max_charge_level_percent: int


@dataclass
class PlannerSlot:
    start: str
    end: str
    mode: str
    target_power: Optional[int]
    min_power: Optional[int]
    max_power: Optional[int]
    conditions: List[Dict[str, Any]] = field(default_factory=list)
    fallback: Optional[Any] = None
    reason: str = ""

    def to_dict(self) -> Dict[str, Any]:
        data: Dict[str, Any] = {
            "start": self.start,
            "end": self.end,
            "mode": self.mode,
            "target_power": self.target_power,
            "reason": self.reason,
        }
        if self.min_power is not None:
            data["min_power"] = self.min_power
        if self.max_power is not None:
            data["max_power"] = self.max_power
        if self.conditions:
            data["conditions"] = list(self.conditions)
        if self.fallback is not None:
            data["fallback"] = self.fallback
        return data


@dataclass
class ResolvedSlot:
    time: str
    value: Any
    key: str
    min_power: Optional[int] = None
    max_power: Optional[int] = None
    runtime_conditions: Optional[List[Dict[str, Any]]] = None
    fallback_value: Optional[Any] = None
    rule_name: Optional[str] = None
    rule_index: Optional[int] = None

    def to_dict(self) -> Dict[str, Any]:
        data: Dict[str, Any] = {
            "time": self.time,
            "value": self.value,
            "key": self.key,
        }
        if self.min_power is not None:
            data["min_power"] = self.min_power
        if self.max_power is not None:
            data["max_power"] = self.max_power
        if self.runtime_conditions:
            data["runtime_conditions"] = list(self.runtime_conditions)
        if self.fallback_value is not None:
            data["fallback_value"] = self.fallback_value
        if self.rule_name:
            data["rule_name"] = self.rule_name
        if self.rule_index is not None:
            data["rule_index"] = self.rule_index
        return data


@dataclass
class PlannerResult:
    success: bool
    plan_slots: List[PlannerSlot]
    resolved_slots: List[ResolvedSlot]
    explanations: List[str]
    meta: Dict[str, Any]
    error: Optional[str] = None

    def compatibility_payload(self) -> Dict[str, Any]:
        payload: Dict[str, Any] = {
            "success": self.success,
            "resolved": [slot.to_dict() for slot in self.resolved_slots],
        }
        if self.meta.get("current_date"):
            payload["date"] = self.meta["current_date"]
        if self.meta.get("timezone"):
            payload["timezone"] = self.meta["timezone"]
        if self.meta.get("generated_at"):
            payload["generated_at"] = self.meta["generated_at"]
        payload["agent_version"] = self.meta.get("agent_version", "planner-v1")
        payload["meta"] = {
            "source": "planner",
            "plan_horizon_dates": self.meta.get("planned_dates", []),
            "warnings": self.meta.get("warnings", []),
        }
        if not self.success and self.error:
            payload["error"] = self.error
        return payload

    def debug_payload(self) -> Dict[str, Any]:
        payload: Dict[str, Any] = {
            "success": self.success,
            "plan": {"slots": [slot.to_dict() for slot in self.plan_slots]},
            "resolved": [slot.to_dict() for slot in self.resolved_slots],
            "explanations": list(self.explanations),
            "meta": dict(self.meta),
        }
        if self.error:
            payload["error"] = self.error
        return payload

