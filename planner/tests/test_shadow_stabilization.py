from __future__ import annotations

from datetime import datetime, timedelta
import unittest
from zoneinfo import ZoneInfo

from planner.models import BatteryState
from planner.shadow import stabilize_active_hour_plan


TZ = ZoneInfo("Europe/Amsterdam")


def decision(start: datetime, power_w: int, *, value: object | None = None) -> dict:
    end = start.replace(minute=0, second=0, microsecond=0) + timedelta(hours=1)
    return {
        "start": start.isoformat(),
        "end": end.isoformat(),
        "battery_power_w": power_w,
        "start_soc_percent": 50.0,
        "end_soc_percent": 55.0,
        "import_price_eur_per_kwh": 0.25,
        "export_price_eur_per_kwh": 0.10,
        "price_source": "official",
        "load_w": 200.0,
        "pv_w": 0.0,
        "grid_power_w": 200.0 + power_w,
        "expected_cost_eur": 0.0,
        "schedule_value": power_w if value is None else value,
        "reason": "test action",
    }


def plan(generated_at: datetime, power_w: int, *, value: object | None = None) -> dict:
    current = decision(generated_at, power_w, value=value)
    return {
        "generated_at": generated_at.isoformat(),
        "horizon_start": generated_at.isoformat(),
        "horizon_end": current["end"],
        "starting_soc_percent": 50.0,
        "ending_soc_percent": 55.0,
        "expected_energy_cost_eur": 0.0,
        "terminal_energy_value_eur": 0.0,
        "objective_eur": 0.0,
        "round_trip_efficiency": 0.85,
        "decisions": [current],
    }


def executable(plan_payload: dict) -> dict:
    return {"type": "optimizer_executable_schedule", "version": 1, "plan": plan_payload}


class ActiveHourStabilizationTests(unittest.TestCase):
    def setUp(self) -> None:
        self.now = datetime(2026, 9, 22, 14, 21, tzinfo=TZ)
        self.battery = BatteryState(50.0, 5760.0, 1200, 1800, 15, 91)

    def stabilize(self, proposed: dict, previous: dict | None, deadband_w: int = 300):
        return stabilize_active_hour_plan(
            proposed,
            previous,
            now=self.now,
            battery_state=self.battery,
            round_trip_efficiency=0.85,
            deadband_w=deadband_w,
        )

    def test_zero_deadband_preserves_the_proposed_plan(self) -> None:
        proposed = plan(self.now, 1100)

        published, metadata = self.stabilize(proposed, executable(plan(self.now, 1000)), deadband_w=0)

        self.assertIs(published, proposed)
        self.assertFalse(metadata["applied"])
        self.assertEqual(metadata["reason"], "disabled")

    def test_change_below_deadband_retains_active_command(self) -> None:
        proposed = plan(self.now, 1100)
        previous = executable(plan(self.now.replace(minute=1), 1000))

        published, metadata = self.stabilize(proposed, previous)

        self.assertEqual(proposed["decisions"][0]["battery_power_w"], 1100)
        self.assertEqual(published["decisions"][0]["battery_power_w"], 1000)
        self.assertTrue(metadata["applied"])
        self.assertEqual(metadata["difference_w"], 100)
        self.assertEqual(metadata["reason"], "below_active_hour_deadband")

    def test_change_at_deadband_is_published(self) -> None:
        proposed = plan(self.now, 1000)
        previous = executable(plan(self.now.replace(minute=1), 700))

        published, metadata = self.stabilize(proposed, previous)

        self.assertIs(published, proposed)
        self.assertFalse(metadata["applied"])
        self.assertEqual(metadata["reason"], "threshold_met")

    def test_first_plan_of_new_hour_is_not_stabilized(self) -> None:
        proposed = plan(self.now, 1100)
        previous = executable(plan(self.now.replace(hour=13, minute=41), 1000))
        previous["plan"]["decisions"] = [decision(self.now.replace(minute=0), 1000)]

        published, metadata = self.stabilize(proposed, previous)

        self.assertIs(published, proposed)
        self.assertFalse(metadata["applied"])
        self.assertEqual(metadata["reason"], "new_hour")

    def test_soc_risk_bypasses_deadband(self) -> None:
        near_maximum = BatteryState(90.5, 5760.0, 1200, 1800, 15, 91)
        proposed = plan(self.now, 0)
        previous = executable(plan(self.now.replace(minute=1), 100))

        published, metadata = stabilize_active_hour_plan(
            proposed,
            previous,
            now=self.now,
            battery_state=near_maximum,
            round_trip_efficiency=0.85,
            deadband_w=300,
        )

        self.assertIs(published, proposed)
        self.assertFalse(metadata["applied"])
        self.assertEqual(metadata["reason"], "soc_safety_override")


if __name__ == "__main__":
    unittest.main()
