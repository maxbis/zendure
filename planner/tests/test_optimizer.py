from __future__ import annotations

from datetime import datetime
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest

from zoneinfo import ZoneInfo

from planner.models import BatteryState, LoadForecastRecord
from planner.optimizer import generate_plan
from planner.schedule_emit import planner_slots_to_resolved
from planner.tests.support import build_test_settings


def _baseline(value: float) -> list[float]:
    return [value] * 24


def _load_record(date: str, baseline_value: float) -> LoadForecastRecord:
    return LoadForecastRecord(
        date=date,
        timezone="Europe/Amsterdam",
        baseline_load_w_by_hour=_baseline(baseline_value),
        incidentals={"morning": 0.0, "afternoon": 0.0, "evening": 0.0, "night": 0.0},
        updated_at="2026-04-28T00:00:00+02:00",
    )


def _shortwave_payload(date: str, values: dict[int, float]) -> dict:
    times = []
    radiation = []
    for hour in range(24):
        times.append(f"{date}T{hour:02d}:00")
        radiation.append(values.get(hour, 0.0))
    return {"hourly": {"time": times, "shortwave_radiation": radiation}}


class OptimizerTests(unittest.TestCase):
    def test_negative_price_surplus_uses_netzero_plus(self) -> None:
        with TemporaryDirectory() as temp_dir:
            settings = build_test_settings(Path(temp_dir))
            tz = ZoneInfo(settings.timezone)
            now = datetime(2026, 4, 28, 10, 30, tzinfo=tz)
            result = generate_plan(
                now=now,
                settings=settings,
                battery_state=BatteryState(40.0, settings.base_wh, 1200, 1600, 15, 96),
                load_forecasts={"2026-04-28": _load_record("2026-04-28", 200.0)},
                import_today=[-0.10] * 24,
                import_tomorrow=[None] * 24,
                export_today=[-0.22] * 24,
                export_tomorrow=[None] * 24,
                shortwave_payload=_shortwave_payload("2026-04-28", {10: 1000.0}),
            )
            self.assertTrue(result.success)
            self.assertEqual(result.plan_slots[0].mode, "netzero+")

    def test_arbitrage_below_threshold_stays_idle(self) -> None:
        with TemporaryDirectory() as temp_dir:
            settings = build_test_settings(Path(temp_dir))
            tz = ZoneInfo(settings.timezone)
            now = datetime(2026, 4, 28, 9, 0, tzinfo=tz)
            import_today = [0.20] * 24
            import_today[20] = 0.25
            result = generate_plan(
                now=now,
                settings=settings,
                battery_state=BatteryState(50.0, settings.base_wh, 1200, 1600, 15, 96),
                load_forecasts={"2026-04-28": _load_record("2026-04-28", 0.0)},
                import_today=import_today,
                import_tomorrow=[None] * 24,
                export_today=[0.05] * 24,
                export_tomorrow=[None] * 24,
                shortwave_payload=_shortwave_payload("2026-04-28", {}),
            )
            self.assertTrue(result.success)
            self.assertEqual(result.plan_slots[0].mode, "fixed")
            self.assertEqual(result.plan_slots[0].target_power, 0)

    def test_profitable_cheap_hour_charges(self) -> None:
        with TemporaryDirectory() as temp_dir:
            settings = build_test_settings(Path(temp_dir))
            tz = ZoneInfo(settings.timezone)
            now = datetime(2026, 4, 28, 3, 0, tzinfo=tz)
            import_today = [0.30] * 24
            import_today[3] = 0.05
            result = generate_plan(
                now=now,
                settings=settings,
                battery_state=BatteryState(30.0, settings.base_wh, 1200, 1600, 15, 96),
                load_forecasts={"2026-04-28": _load_record("2026-04-28", 0.0)},
                import_today=import_today,
                import_tomorrow=[None] * 24,
                export_today=[0.02] * 24,
                export_tomorrow=[None] * 24,
                shortwave_payload=_shortwave_payload("2026-04-28", {}),
            )
            self.assertTrue(result.success)
            self.assertEqual(result.plan_slots[0].mode, "fixed")
            self.assertGreater(result.plan_slots[0].target_power or 0, 0)

    def test_market_price_threshold_prefers_netzero(self) -> None:
        with TemporaryDirectory() as temp_dir:
            settings = build_test_settings(Path(temp_dir))
            tz = ZoneInfo(settings.timezone)
            now = datetime(2026, 4, 28, 19, 0, tzinfo=tz)
            result = generate_plan(
                now=now,
                settings=settings,
                battery_state=BatteryState(50.0, settings.base_wh, 1200, 1600, 15, 96),
                load_forecasts={"2026-04-28": _load_record("2026-04-28", 900.0)},
                import_today=[0.30] * 24,
                import_tomorrow=[None] * 24,
                export_today=[0.18] * 24,
                export_tomorrow=[None] * 24,
                shortwave_payload=_shortwave_payload("2026-04-28", {}),
            )
            self.assertTrue(result.success)
            self.assertEqual(result.plan_slots[0].mode, "netzero")
            self.assertEqual(result.plan_slots[0].reason, "offset grid import above market threshold")

    def test_reserve_floor_prevents_discharge(self) -> None:
        with TemporaryDirectory() as temp_dir:
            settings = build_test_settings(Path(temp_dir))
            tz = ZoneInfo(settings.timezone)
            now = datetime(2026, 4, 28, 19, 0, tzinfo=tz)
            import_today = [0.10] * 24
            import_today[19] = 0.40
            result = generate_plan(
                now=now,
                settings=settings,
                battery_state=BatteryState(15.0, settings.base_wh, 1200, 1600, 15, 96),
                load_forecasts={"2026-04-28": _load_record("2026-04-28", 900.0)},
                import_today=import_today,
                import_tomorrow=[None] * 24,
                export_today=[0.05] * 24,
                export_tomorrow=[None] * 24,
                shortwave_payload=_shortwave_payload("2026-04-28", {}),
            )
            self.assertTrue(result.success)
            first = result.plan_slots[0]
            self.assertNotEqual(first.mode, "netzero")
            self.assertGreaterEqual(first.target_power or 0, 0)

    def test_schedule_emit_maps_conditions(self) -> None:
        from planner.models import PlannerSlot

        slots = [
            PlannerSlot(
                start="2026-04-28T15:00:00+02:00",
                end="2026-04-28T16:00:00+02:00",
                mode="netzero",
                target_power=None,
                min_power=-700,
                max_power=0,
                conditions=[{"field": "battery_soc", "op": ">=", "value": 60}],
                fallback=0,
                reason="offset expensive grid import",
            )
        ]
        resolved = planner_slots_to_resolved(slots, "Europe/Amsterdam")
        self.assertEqual(resolved[0].time, "1500")
        self.assertEqual(resolved[0].value, "netzero")
        self.assertEqual(resolved[0].runtime_conditions[0]["field"], "electricity_level")
        self.assertEqual(resolved[0].fallback_value, 0)


if __name__ == "__main__":
    unittest.main()
