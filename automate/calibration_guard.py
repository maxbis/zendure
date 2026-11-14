"""Track Zendure automatic battery-calibration state from live telemetry."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Optional


def read_calibration_active(zendure_data: Any) -> Optional[bool]:
    """Return the reported calibration state, or None when it is unavailable."""
    if not isinstance(zendure_data, dict):
        return None
    properties = zendure_data.get("properties")
    if not isinstance(properties, dict):
        return None
    raw_status = properties.get("socStatus")
    if isinstance(raw_status, bool):
        return None
    try:
        status = int(raw_status)
    except (TypeError, ValueError):
        return None
    if status not in (0, 1):
        return None
    return status == 1


@dataclass(frozen=True)
class CalibrationUpdate:
    active: bool
    entered: bool = False
    exited: bool = False
    status_known: bool = True


class CalibrationGuard:
    """Hold the last explicit calibration state across transient missing readings."""

    def __init__(self) -> None:
        self.active = False

    def update(self, zendure_data: Any) -> CalibrationUpdate:
        observed = read_calibration_active(zendure_data)
        if observed is None:
            return CalibrationUpdate(active=self.active, status_known=False)

        previous = self.active
        self.active = observed
        return CalibrationUpdate(
            active=self.active,
            entered=self.active and not previous,
            exited=previous and not self.active,
        )
