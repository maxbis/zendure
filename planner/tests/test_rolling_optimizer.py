from __future__ import annotations

from datetime import datetime, timedelta
import json
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest

from zoneinfo import ZoneInfo

from planner.clients import PricePayload
from planner.models import BatteryState
from planner.rolling_optimizer import (
    RollingInputSlot,
    _adaptive_netzero_bidirectional_discharge_limit_w,
    _adaptive_netzero_minus_limit_w,
    _battery_wear_cost,
    _linear_netzero_minus_price_score,
    _normalize_netzero_plus_charge_limit,
    build_rolling_boundaries,
    optimize_rolling_schedule,
)
from planner.shadow import append_json_line, build_shadow_slots, retained_energy_valuation, solar_forecast_updated_at, write_json_atomic
from planner.tests.support import build_test_settings


class RollingOptimizerTests(unittest.TestCase):
    def test_battery_wear_cost_is_applied_to_discharge_only(self) -> None:
        self.assertEqual(_battery_wear_cost(1000, 1.0, 0.0005), 0.0)
        self.assertEqual(_battery_wear_cost(0, 1.0, 0.0005), 0.0)
        self.assertEqual(_battery_wear_cost(-1000, 1.0, 0.0005), 0.0005)

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

    def test_discharge_wear_cost_can_make_marginal_arbitrage_unprofitable(self) -> None:
        tz = ZoneInfo("Europe/Amsterdam")
        now = datetime(2026, 9, 12, 10, 0, tzinfo=tz)
        slots = [
            RollingInputSlot(now, now + timedelta(hours=1), 0.10, 0.10, "official", 0.0, 0.0),
            RollingInputSlot(
                now + timedelta(hours=1),
                now + timedelta(hours=2),
                0.1004,
                0.1004,
                "official",
                0.0,
                0.0,
            ),
        ]
        battery_state = BatteryState(0.0, 1000.0, 1000, 1000, 0, 100)

        without_wear = optimize_rolling_schedule(
            now=now,
            battery_state=battery_state,
            slots=slots,
            round_trip_efficiency=1.0,
            power_step_w=1000,
            soc_step_wh=1000.0,
            terminal_value_factor=0.0,
        )
        with_wear = optimize_rolling_schedule(
            now=now,
            battery_state=battery_state,
            slots=slots,
            round_trip_efficiency=1.0,
            power_step_w=1000,
            soc_step_wh=1000.0,
            terminal_value_factor=0.0,
            battery_wear_cost_eur_per_kwh_discharged=0.0005,
        )

        self.assertEqual([decision.battery_power_w for decision in without_wear.decisions], [1000, -1000])
        self.assertEqual([decision.battery_power_w for decision in with_wear.decisions], [0, 0])
        self.assertEqual(with_wear.expected_energy_cost_eur, 0.0)
        self.assertEqual(with_wear.expected_battery_wear_cost_eur, 0.0)
        self.assertEqual(with_wear.battery_wear_cost_eur_per_kwh_discharged, 0.0005)

    def test_netzero_minus_price_score_is_linear_above_the_median(self) -> None:
        score = _linear_netzero_minus_price_score(0.35, [0.25, 0.25, 0.35, 0.45])

        self.assertAlmostEqual(score, 1.0 / 3.0)

    def test_netzero_minus_price_score_is_zero_at_or_below_the_median(self) -> None:
        self.assertEqual(_linear_netzero_minus_price_score(0.25, [0.20, 0.25, 0.30]), 0.0)
        self.assertEqual(_linear_netzero_minus_price_score(0.25, [0.25, 0.25]), 0.0)
        self.assertEqual(_linear_netzero_minus_price_score(-0.05, [-0.10, -0.05, -0.01]), 0.0)

    def test_netzero_minus_repeated_highest_price_gets_full_score(self) -> None:
        self.assertEqual(_linear_netzero_minus_price_score(0.45, [0.45, 0.45, 0.20]), 1.0)

    def test_netzero_minus_highest_price_gets_full_feasible_range(self) -> None:
        limit = _adaptive_netzero_minus_limit_w(
            modeled_discharge_w=200,
            current_import_price=0.45,
            remaining_import_prices=[0.45, 0.25, 0.20],
            start_energy_wh=4000.0,
            min_energy_wh=1000.0,
            discharge_efficiency=0.9,
            duration_hours=1.0,
            max_discharge_power_w=1800,
            power_step_w=100,
        )

        self.assertEqual(limit, 1800)

    def test_netzero_minus_limit_interpolates_linearly(self) -> None:
        limit = _adaptive_netzero_minus_limit_w(
            modeled_discharge_w=200,
            current_import_price=0.35,
            remaining_import_prices=[0.25, 0.25, 0.35, 0.45],
            start_energy_wh=4000.0,
            min_energy_wh=1000.0,
            discharge_efficiency=0.9,
            duration_hours=1.0,
            max_discharge_power_w=1800,
            power_step_w=100,
        )

        self.assertEqual(limit, 700)

    def test_netzero_minus_headroom_respects_available_energy(self) -> None:
        limit = _adaptive_netzero_minus_limit_w(
            modeled_discharge_w=200,
            current_import_price=0.45,
            remaining_import_prices=[0.45, 0.25, 0.20],
            start_energy_wh=1500.0,
            min_energy_wh=1000.0,
            discharge_efficiency=0.9,
            duration_hours=1.0,
            max_discharge_power_w=1800,
            power_step_w=100,
        )

        self.assertEqual(limit, 400)

    def test_selected_household_discharge_emits_price_adaptive_netzero_minus(self) -> None:
        tz = ZoneInfo("Europe/Amsterdam")
        now = datetime(2026, 9, 12, 10, 0, tzinfo=tz)
        slots = [
            RollingInputSlot(now, now + timedelta(hours=1), 0.45, 0.0, "official", 200.0, 0.0),
            RollingInputSlot(
                now + timedelta(hours=1),
                now + timedelta(hours=2),
                0.20,
                0.0,
                "official",
                0.0,
                0.0,
            ),
        ]
        plan = optimize_rolling_schedule(
            now=now,
            battery_state=BatteryState(50.0, 5760.0, 1200, 1800, 15, 91),
            slots=slots,
            round_trip_efficiency=0.85,
            power_step_w=100,
            soc_step_wh=50.0,
            terminal_value_factor=1.0,
        )

        decision = plan.decisions[0]
        self.assertEqual(decision.battery_power_w, -200)
        self.assertEqual(decision.schedule_value, "netzero-")
        self.assertEqual(decision.min_power, -1800)
        self.assertEqual(decision.max_power, 0)
        self.assertEqual(decision.min_power % 100, 0)
        self.assertEqual(
            decision.reason,
            "offset actual household import with price-based adaptive headroom",
        )

    def test_bidirectional_headroom_starts_at_half_price_score(self) -> None:
        common = {
            "remaining_import_prices": [0.20, 0.30, 0.40],
            "start_energy_wh": 4000.0,
            "min_energy_wh": 1000.0,
            "discharge_efficiency": 0.9,
            "duration_hours": 1.0,
            "max_discharge_power_w": 1800,
            "power_step_w": 100,
        }

        below_threshold = _adaptive_netzero_bidirectional_discharge_limit_w(
            current_import_price=0.34,
            **common,
        )
        at_threshold = _adaptive_netzero_bidirectional_discharge_limit_w(
            current_import_price=0.35,
            **common,
        )

        self.assertEqual(below_threshold, 0)
        self.assertEqual(at_threshold, 900)

    def test_zero_minimum_netzero_plus_uses_full_configured_charge_range(self) -> None:
        schedule_value, min_power, max_power = _normalize_netzero_plus_charge_limit(
            schedule_value="netzero+",
            min_power=0,
            max_power=200,
            max_charge_power_w=1200,
            power_step_w=100,
        )

        self.assertEqual(schedule_value, "netzero+")
        self.assertEqual(min_power, 0)
        self.assertEqual(max_power, 1200)

    def test_netzero_plus_normalization_does_not_change_other_commands(self) -> None:
        command = _normalize_netzero_plus_charge_limit(
            schedule_value="netzero",
            min_power=-600,
            max_power=200,
            max_charge_power_w=1200,
            power_step_w=100,
        )

        self.assertEqual(command, ("netzero", -600, 200))

    def test_high_price_zero_minimum_netzero_plus_becomes_bidirectional(self) -> None:
        tz = ZoneInfo("Europe/Amsterdam")
        now = datetime(2026, 9, 12, 12, 0, tzinfo=tz)
        slots = [
            RollingInputSlot(now, now + timedelta(hours=1), 0.45, -0.10, "official", 100.0, 1300.0),
            RollingInputSlot(
                now + timedelta(hours=1),
                now + timedelta(hours=2),
                0.20,
                0.0,
                "official",
                0.0,
                0.0,
            ),
        ]
        plan = optimize_rolling_schedule(
            now=now,
            battery_state=BatteryState(70.0, 5760.0, 1200, 1800, 15, 91),
            slots=slots,
            round_trip_efficiency=0.85,
            power_step_w=100,
            soc_step_wh=50.0,
            terminal_value_factor=1.0,
        )

        decision = plan.decisions[0]
        self.assertGreaterEqual(decision.battery_power_w, 0)
        self.assertEqual(decision.schedule_value, "netzero")
        self.assertEqual(decision.min_power, -1800)
        self.assertEqual(decision.max_power, 1200)
        self.assertEqual(
            decision.reason,
            "absorb actual solar surplus and offset high-value household import",
        )

    def test_lower_price_zero_minimum_netzero_plus_remains_charge_only(self) -> None:
        tz = ZoneInfo("Europe/Amsterdam")
        now = datetime(2026, 9, 12, 12, 0, tzinfo=tz)
        slots = [
            RollingInputSlot(now, now + timedelta(hours=1), 0.30, -0.10, "official", 100.0, 1300.0),
            RollingInputSlot(
                now + timedelta(hours=1),
                now + timedelta(hours=2),
                0.60,
                0.0,
                "official",
                0.0,
                0.0,
            ),
        ]
        plan = optimize_rolling_schedule(
            now=now,
            battery_state=BatteryState(70.0, 5760.0, 1200, 1800, 15, 91),
            slots=slots,
            round_trip_efficiency=0.85,
            power_step_w=100,
            soc_step_wh=50.0,
            terminal_value_factor=1.0,
        )

        decision = plan.decisions[0]
        self.assertEqual(decision.schedule_value, "netzero+")
        self.assertEqual(decision.min_power, 0)
        self.assertEqual(decision.max_power, 1200)

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
        self.assertEqual(decision.max_power, 1200)

    def test_daylight_idle_uses_opportunistic_netzero_plus(self) -> None:
        tz = ZoneInfo("Europe/Amsterdam")
        now = datetime(2026, 9, 13, 13, 0, tzinfo=tz)
        slot = RollingInputSlot(
            now,
            now + timedelta(hours=1),
            0.35,
            0.10,
            "official",
            220.0,
            110.0,
        )

        plan = optimize_rolling_schedule(
            now=now,
            battery_state=BatteryState(15.0, 5760.0, 1200, 1800, 15, 91),
            slots=[slot],
            round_trip_efficiency=0.85,
            power_step_w=100,
            soc_step_wh=50.0,
            terminal_value_factor=1.0,
        )

        decision = plan.decisions[0]
        self.assertEqual(decision.battery_power_w, 0)
        self.assertEqual(decision.schedule_value, "netzero+")
        self.assertEqual(decision.min_power, 0)
        self.assertEqual(decision.max_power, 1200)
        self.assertEqual(decision.reason, "opportunistically absorb actual solar surplus")

    def test_small_solar_surplus_below_power_step_is_modeled_by_netzero_plus(self) -> None:
        tz = ZoneInfo("Europe/Amsterdam")
        now = datetime(2026, 9, 13, 16, 0, tzinfo=tz)
        slot = RollingInputSlot(
            now,
            now + timedelta(hours=1),
            0.40,
            0.05,
            "official",
            220.0,
            260.0,
        )

        plan = optimize_rolling_schedule(
            now=now,
            battery_state=BatteryState(50.0, 5760.0, 1200, 1800, 15, 91),
            slots=[slot],
            round_trip_efficiency=0.85,
            power_step_w=100,
            soc_step_wh=50.0,
            terminal_value_factor=1.0,
        )

        decision = plan.decisions[0]
        self.assertEqual(decision.battery_power_w, 40)
        self.assertEqual(decision.schedule_value, "netzero+")
        self.assertEqual(decision.max_power, 1200)

    def test_expensive_export_keeps_daylight_idle_fixed_at_zero(self) -> None:
        tz = ZoneInfo("Europe/Amsterdam")
        now = datetime(2026, 9, 13, 13, 0, tzinfo=tz)
        slot = RollingInputSlot(
            now,
            now + timedelta(hours=1),
            0.20,
            0.30,
            "official",
            220.0,
            110.0,
        )

        plan = optimize_rolling_schedule(
            now=now,
            battery_state=BatteryState(15.0, 5760.0, 1200, 1800, 15, 91),
            slots=[slot],
            round_trip_efficiency=0.85,
            power_step_w=100,
            soc_step_wh=50.0,
            terminal_value_factor=1.0,
        )

        decision = plan.decisions[0]
        self.assertEqual(decision.battery_power_w, 0)
        self.assertEqual(decision.schedule_value, 0)

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

    def test_solar_forecast_cache_timestamp_is_recorded_in_local_timezone(self) -> None:
        timezone = ZoneInfo("Europe/Amsterdam")

        updated_at = solar_forecast_updated_at({"cachedAt": 1789236000}, timezone)

        self.assertEqual(updated_at, "2026-09-12T20:00:00+02:00")

    def test_missing_solar_forecast_cache_timestamp_remains_unknown(self) -> None:
        self.assertIsNone(solar_forecast_updated_at({}, ZoneInfo("Europe/Amsterdam")))

    def test_retained_energy_valuation_uses_latest_24_official_prices(self) -> None:
        prices = PricePayload(
            today_date="20260912",
            tomorrow_date="20260913",
            import_today=[0.10] * 24,
            import_tomorrow=[0.30] * 24,
            export_today=[0.0] * 24,
            export_tomorrow=[0.0] * 24,
        )

        valuation = retained_energy_valuation(prices)

        self.assertEqual(valuation["retained_energy_valuation_price_eur_per_kwh"], 0.30)
        self.assertEqual(valuation["retained_energy_valuation_hour_count"], 24)
        self.assertEqual(valuation["retained_energy_valuation_dates"], ["20260913"])

    def test_retained_energy_valuation_requires_24_prices_and_never_goes_negative(self) -> None:
        incomplete = PricePayload("20260912", None, [0.20] * 23 + [None], [], [], [])
        negative = PricePayload("20260912", None, [-0.10] * 24, [], [], [])

        self.assertIsNone(retained_energy_valuation(incomplete)["retained_energy_valuation_price_eur_per_kwh"])
        self.assertEqual(retained_energy_valuation(negative)["retained_energy_valuation_price_eur_per_kwh"], 0.0)


if __name__ == "__main__":
    unittest.main()
