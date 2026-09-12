from __future__ import annotations

from datetime import datetime, timedelta
import json
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest

from zoneinfo import ZoneInfo

from planner.clients import PricePayload
from planner.models import BatteryState
from planner.rolling_optimizer import RollingInputSlot, build_rolling_boundaries, optimize_rolling_schedule
from planner.shadow import append_json_line, build_shadow_slots, write_json_atomic
from planner.tests.support import build_test_settings


class RollingOptimizerTests(unittest.TestCase):
    def test_rolling_horizon_extends_to_midnight_after_minimum_duration(self) -> None:
        tz = ZoneInfo("Europe/Amsterdam")
        now = datetime(2026, 9, 12, 17, 55, tzinfo=tz)

        boundaries = build_rolling_boundaries(now, 24, extend_to_midnight=True)

        self.assertEqual(boundaries[0][0], now)
        self.assertEqual(boundaries[-1][1], datetime(2026, 9, 14, 0, 0, tzinfo=tz))
        self.assertGreater((boundaries[-1][1] - now).total_seconds(), 24 * 3600)

    def test_midnight_start_keeps_exact_24_hour_horizon(self) -> None:
        tz = ZoneInfo("Europe/Amsterdam")
        now = datetime(2026, 9, 12, 0, 0, tzinfo=tz)

        boundaries = build_rolling_boundaries(now, 24, extend_to_midnight=True)

        self.assertEqual(boundaries[-1][1], datetime(2026, 9, 13, 0, 0, tzinfo=tz))

    def test_global_plan_charges_before_profitable_export(self) -> None:
        tz = ZoneInfo("Europe/Amsterdam")
        now = datetime(2026, 9, 12, 10, 0, tzinfo=tz)
        slots = [
            RollingInputSlot(now, now + timedelta(hours=1), 0.05, 0.01, "official", 0.0, 0.0),
            RollingInputSlot(
                now + timedelta(hours=1),
                now + timedelta(hours=2),
                0.45,
                0.40,
                "official",
                0.0,
                0.0,
            ),
        ]
        plan = optimize_rolling_schedule(
            now=now,
            battery_state=BatteryState(15.0, 5760.0, 1200, 1800, 15, 91),
            slots=slots,
            round_trip_efficiency=0.85,
            power_step_w=100,
            soc_step_wh=50.0,
            terminal_value_factor=0.0,
        )
        self.assertGreater(plan.decisions[0].battery_power_w, 0)
        self.assertLess(plan.decisions[1].battery_power_w, 0)
        self.assertEqual(plan.decisions[0].schedule_value, plan.decisions[0].battery_power_w)

    def test_expected_solar_charge_uses_bounded_netzero_plus(self) -> None:
        tz = ZoneInfo("Europe/Amsterdam")
        now = datetime(2026, 9, 12, 12, 0, tzinfo=tz)
        slot = RollingInputSlot(
            now,
            now + timedelta(hours=1),
            0.30,
            -0.10,
            "official",
            100.0,
            1300.0,
        )
        plan = optimize_rolling_schedule(
            now=now,
            battery_state=BatteryState(70.0, 5760.0, 1200, 1800, 15, 91),
            slots=[slot],
            round_trip_efficiency=0.85,
            power_step_w=100,
            soc_step_wh=50.0,
            terminal_value_factor=1.0,
        )

        decision = plan.decisions[0]
        self.assertGreater(decision.battery_power_w, 0)
        self.assertEqual(decision.schedule_value, "netzero+")
        self.assertEqual(decision.min_power, 0)
        self.assertEqual(decision.max_power, decision.battery_power_w)

    def test_unknown_tomorrow_prices_repeat_today_by_hour(self) -> None:
        with TemporaryDirectory() as temp_dir:
            settings = build_test_settings(Path(temp_dir))
            tz = ZoneInfo(settings.timezone)
            now = datetime(2026, 9, 12, 23, 30, tzinfo=tz)
            imports = [0.10 + hour / 100.0 for hour in range(24)]
            exports = [0.01 + hour / 100.0 for hour in range(24)]
            prices = PricePayload(
                today_date="20260912",
                tomorrow_date=None,
                import_today=imports,
                import_tomorrow=[None] * 24,
                export_today=exports,
                export_tomorrow=[None] * 24,
            )
            slots = build_shadow_slots(
                now=now,
                settings=settings,
                prices=prices,
                shortwave_payload={"hourly": {"time": [], "shortwave_radiation": []}},
                horizon_hours=2,
                extend_to_midnight=False,
            )
            tomorrow_midnight = next(slot for slot in slots if slot.start.hour == 0)
            self.assertEqual(tomorrow_midnight.price_source, "repeat_today")
            self.assertAlmostEqual(tomorrow_midnight.import_price_eur_per_kwh, imports[0])

    def test_json_lines_log_appends_complete_records(self) -> None:
        with TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "shadow.log"
            append_json_line(path, {"sequence": 1})
            append_json_line(path, {"sequence": 2})
            records = [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines()]
            self.assertEqual(records, [{"sequence": 1}, {"sequence": 2}])

    def test_latest_schedule_is_replaced_atomically(self) -> None:
        with TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "latest.json"
            write_json_atomic(path, {"sequence": 1})
            write_json_atomic(path, {"sequence": 2})
            self.assertEqual(json.loads(path.read_text(encoding="utf-8")), {"sequence": 2})
            self.assertEqual(list(path.parent.glob("*.tmp")), [])


if __name__ == "__main__":
    unittest.main()
