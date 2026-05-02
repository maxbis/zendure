from __future__ import annotations

from datetime import datetime
from typing import List

from zoneinfo import ZoneInfo

from planner.models import PlannerSlot, ResolvedSlot


def planner_slots_to_resolved(
    slots: List[PlannerSlot],
    timezone: str,
) -> List[ResolvedSlot]:
    tz = ZoneInfo(timezone)
    resolved: List[ResolvedSlot] = []
    for index, slot in enumerate(slots):
        start_dt = datetime.fromisoformat(slot.start).astimezone(tz)
        value = slot.mode if slot.mode in ("netzero", "netzero+", "netzero-") else int(slot.target_power or 0)
        key = f"planner-{start_dt.strftime('%Y%m%d%H%M')}"
        runtime_conditions = None
        fallback_value = None
        if slot.conditions:
            runtime_conditions = []
            for condition in slot.conditions:
                if condition.get("field") == "battery_soc":
                    runtime_conditions.append(
                        {
                            "field": "electricity_level",
                            "op": condition.get("op"),
                            "value": condition.get("value"),
                        }
                    )
                else:
                    runtime_conditions.append(dict(condition))
        if slot.fallback is not None:
            fallback_value = slot.fallback
        resolved.append(
            ResolvedSlot(
                time=start_dt.strftime("%H%M"),
                value=value,
                key=key,
                min_power=slot.min_power,
                max_power=slot.max_power,
                runtime_conditions=runtime_conditions,
                fallback_value=fallback_value,
                rule_name=slot.reason or None,
                rule_index=index,
            )
        )
    return resolved

