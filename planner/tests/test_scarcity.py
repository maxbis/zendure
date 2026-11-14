from __future__ import annotations

from datetime import datetime, timedelta
import json
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest
from zoneinfo import ZoneInfo

from planner.models import BatteryState
from planner.rolling_optimizer import RollingInputSlot
from planner.scarcity import (
    build_price_distribution,
    build_scarcity_context,
    build_scheduled_refill,
    resolve_refill_activation,
    weighted_percentile,
)


class ScarcityTests(unittest.TestCase):
    def test_weighted_percentile_respects_weights(self) -> None:
        self.assertEqual(weighted_percentile([(0.10, 1.0), (0.50, 9.0)], 75), 0.50)

    def test_price_distribution_reports_hours_and_weighted_share(self) -> None:
        distribution = build_price_distribution([(0.10, 1.0), (0.11, 1.0), (0.30, 8.0)], target_bins=10)
        buckets = distribution["buckets"]
        self.assertEqual(distribution["sample_count"], 3)
        self.assertEqual(sum(bucket["hour_count"] for bucket in buckets), 3)
        self.assertAlmostEqual(sum(bucket["raw_percent"] for bucket in buckets), 100.0, places=4)
        self.assertAlmostEqual(sum(bucket["weighted_percent"] for bucket in buckets), 100.0, places=4)
        self.assertEqual(buckets[-1]["cumulative_weighted_percent"], 100.0)

    def test_context_reports_refill_and_applies_matching_bands(self) -> None:
        tz = ZoneInfo("Europe/Amsterdam")
        now = datetime(2026, 11, 3, 12, tzinfo=tz)
        state = BatteryState(30.0, 1000.0, 500, 500, 15, 90)
        slots = [RollingInputSlot(now, now + timedelta(hours=1), 0.30, 0.10, "official", 100.0, 100.0)]
        config = {
            "enabled": True,
            "priceHistoryDays": 7,
            "priceHistoryHalfLifeDays": 7.0,
            "referencePriceCapEurPerKwh": 0.80,
            "cheapGridChargePercentile": 25.0,
            "bands": [
                {"fromSocPercent": 15, "toSocPercent": 25, "pricePercentile": 95.0, "activateWhenExpectedRefillBelowPercent": 100.0},
                {"fromSocPercent": 25, "toSocPercent": 40, "pricePercentile": 85.0, "activateWhenExpectedRefillBelowPercent": 60.0},
            ],
        }
        with TemporaryDirectory() as temporary:
            root = Path(temporary)
            for offset in range(1, 8):
                day = now.date() - timedelta(days=offset)
                path = root / day.strftime("%Y%m") / f"price{day:%Y%m%d}.json"
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(json.dumps({f"{hour:02d}": 0.20 + hour / 100 for hour in range(24)}), encoding="utf-8")
            context = build_scarcity_context(
                now=now,
                battery_state=state,
                slots=slots,
                round_trip_efficiency=1.0,
                normal_terminal_price=0.25,
                config=config,
                price_root=root,
                refill_activation={"percent": 0.0, "source": "conservative_fallback"},
            )
        self.assertTrue(context["calculation_available"])
        self.assertEqual(context["refill"]["expected_refill_percent"], 0.0)
        self.assertTrue(all(band["active"] and band["applied"] for band in context["bands"]))
        self.assertGreater(context["bands"][0]["additional_scarcity_value_eur_per_kwh"], 0.0)
        self.assertEqual(len(context["price_percentile_curve"]), 21)
        self.assertEqual(context["price_percentile_curve"][0]["percentile"], 0.0)
        self.assertEqual(context["price_percentile_curve"][-1]["percentile"], 100.0)
        self.assertEqual(context["price_distribution"]["sample_count"], 168)
        self.assertEqual(sum(bucket["hour_count"] for bucket in context["price_distribution"]["buckets"]), 168)
        self.assertEqual(context["cheap_grid_charge_percentile"], 25.0)
        self.assertEqual(
            context["price_percentile_curve"][5]["price_eur_per_kwh"],
            context["cheap_grid_charge_price_eur_per_kwh"],
        )

    def test_disabled_algorithm_calculates_but_does_not_apply(self) -> None:
        config = {
            "enabled": False,
            "priceHistoryDays": 7,
            "priceHistoryHalfLifeDays": 7.0,
            "referencePriceCapEurPerKwh": 0.80,
            "cheapGridChargePercentile": 25.0,
            "bands": [{"fromSocPercent": 15, "toSocPercent": 25, "pricePercentile": 95.0, "activateWhenExpectedRefillBelowPercent": 100.0}],
        }
        tz = ZoneInfo("Europe/Amsterdam")
        now = datetime(2026, 11, 3, 12, tzinfo=tz)
        state = BatteryState(30.0, 1000.0, 500, 500, 15, 90)
        slot = RollingInputSlot(now, now + timedelta(hours=1), 0.30, 0.10, "official", 100.0, 100.0)
        with TemporaryDirectory() as temporary:
            root = Path(temporary)
            for offset in range(1, 8):
                day = now.date() - timedelta(days=offset)
                path = root / day.strftime("%Y%m") / f"price{day:%Y%m%d}.json"
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(json.dumps({f"{hour:02d}": 0.40 for hour in range(24)}), encoding="utf-8")
            context = build_scarcity_context(
                now=now,
                battery_state=state,
                slots=[slot],
                round_trip_efficiency=1.0,
                normal_terminal_price=0.20,
                config=config,
                price_root=root,
                refill_activation={"percent": 0.0, "source": "conservative_fallback"},
            )
        self.assertTrue(context["calculation_available"])
        self.assertTrue(context["bands"][0]["active"])
        self.assertFalse(context["bands"][0]["applied"])

    def test_refill_equal_to_activation_boundary_activates_band(self) -> None:
        config = {
            "enabled": True,
            "priceHistoryDays": 7,
            "priceHistoryHalfLifeDays": 7.0,
            "referencePriceCapEurPerKwh": 0.80,
            "cheapGridChargePercentile": 25.0,
            "bands": [
                {"fromSocPercent": 15, "toSocPercent": 25, "pricePercentile": 95.0, "activateWhenExpectedRefillBelowPercent": 60.0},
                {"fromSocPercent": 25, "toSocPercent": 40, "pricePercentile": 85.0, "activateWhenExpectedRefillBelowPercent": 50.0},
            ],
        }
        tz = ZoneInfo("Europe/Amsterdam")
        now = datetime(2026, 11, 3, 12, tzinfo=tz)
        state = BatteryState(30.0, 1000.0, 360, 500, 15, 90)
        slot = RollingInputSlot(now, now + timedelta(hours=1), 0.30, 0.10, "official", 100.0, 100.0)
        with TemporaryDirectory() as temporary:
            root = Path(temporary)
            for offset in range(1, 8):
                day = now.date() - timedelta(days=offset)
                path = root / day.strftime("%Y%m") / f"price{day:%Y%m%d}.json"
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(json.dumps({f"{hour:02d}": 0.40 for hour in range(24)}), encoding="utf-8")
            context = build_scarcity_context(
                now=now,
                battery_state=state,
                slots=[slot],
                round_trip_efficiency=1.0,
                normal_terminal_price=0.20,
                config=config,
                price_root=root,
                refill_activation={"percent": 60.0, "source": "previous_schedule"},
            )
        self.assertEqual(context["refill"]["expected_refill_percent"], 60.0)
        self.assertTrue(context["bands"][0]["active"])
        self.assertTrue(context["bands"][0]["applied"])
        self.assertFalse(context["bands"][1]["active"])
        self.assertFalse(context["bands"][1]["applied"])

    def test_full_refill_activates_band_with_100_percent_boundary(self) -> None:
        config = {
            "enabled": True,
            "priceHistoryDays": 7,
            "priceHistoryHalfLifeDays": 7.0,
            "referencePriceCapEurPerKwh": 0.80,
            "cheapGridChargePercentile": 25.0,
            "bands": [
                {"fromSocPercent": 15, "toSocPercent": 25, "pricePercentile": 95.0, "activateWhenExpectedRefillBelowPercent": 100.0},
            ],
        }
        tz = ZoneInfo("Europe/Amsterdam")
        now = datetime(2026, 11, 3, 12, tzinfo=tz)
        state = BatteryState(30.0, 1000.0, 600, 500, 15, 90)
        slot = RollingInputSlot(now, now + timedelta(hours=1), 0.30, 0.10, "official", 100.0, 100.0)
        with TemporaryDirectory() as temporary:
            root = Path(temporary)
            for offset in range(1, 8):
                day = now.date() - timedelta(days=offset)
                path = root / day.strftime("%Y%m") / f"price{day:%Y%m%d}.json"
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(json.dumps({f"{hour:02d}": 0.40 for hour in range(24)}), encoding="utf-8")
            context = build_scarcity_context(
                now=now,
                battery_state=state,
                slots=[slot],
                round_trip_efficiency=1.0,
                normal_terminal_price=0.20,
                config=config,
                price_root=root,
                refill_activation={"percent": 100.0, "source": "previous_schedule"},
            )
        self.assertEqual(context["refill"]["expected_refill_percent"], 100.0)
        self.assertTrue(context["bands"][0]["active"])
        self.assertTrue(context["bands"][0]["applied"])

    def test_scheduled_refill_uses_highest_soc_above_schedule_start(self) -> None:
        plan = {
            "generated_at": "2026-11-14T12:00:00+01:00",
            "horizon_end": "2026-11-15T00:00:00+01:00",
            "starting_soc_percent": 30.0,
            "decisions": [
                {"start_soc_percent": 30.0, "end_soc_percent": 20.0},
                {"start_soc_percent": 20.0, "end_soc_percent": 60.0},
                {"start_soc_percent": 60.0, "end_soc_percent": 40.0},
            ],
        }
        refill = build_scheduled_refill(plan, maximum_soc_percent=90.0)
        self.assertIsNotNone(refill)
        self.assertEqual(refill["maximum_scheduled_soc_percent"], 60.0)
        self.assertEqual(refill["scheduled_gain_percent_points"], 30.0)
        self.assertEqual(refill["scheduled_refill_percent"], 50.0)

    def test_scheduled_refill_is_full_when_battery_starts_at_maximum(self) -> None:
        plan = {
            "generated_at": "2026-11-14T12:00:00+01:00",
            "horizon_end": "2026-11-15T00:00:00+01:00",
            "starting_soc_percent": 90.0,
            "decisions": [{"start_soc_percent": 90.0, "end_soc_percent": 80.0}],
        }
        refill = build_scheduled_refill(plan, maximum_soc_percent=90.0)
        self.assertEqual(refill["scheduled_refill_percent"], 100.0)

    def test_previous_scheduled_refill_is_used_when_fresh_and_compatible(self) -> None:
        now = datetime(2026, 11, 14, 12, 5, tzinfo=ZoneInfo("Europe/Amsterdam"))
        state = BatteryState(30.0, 1000.0, 500, 500, 15, 90)
        payload = {
            "type": "optimizer_executable_schedule",
            "inputs": {"battery_capacity_wh": 1000, "min_soc_percent": 15, "max_soc_percent": 90},
            "plan": {
                "generated_at": "2026-11-14T12:00:00+01:00",
                "scarcity": {"scheduled_refill": {
                    "starting_soc_percent": 30.0,
                    "maximum_scheduled_soc_percent": 60.0,
                    "scheduled_gain_percent_points": 30.0,
                    "scheduled_refill_percent": 50.0,
                    "calculated_at": "2026-11-14T12:00:00+01:00",
                    "horizon_end": "2026-11-15T00:00:00+01:00",
                }},
            },
        }
        activation = resolve_refill_activation(payload, now=now, battery_state=state)
        self.assertEqual(activation["source"], "previous_schedule")
        self.assertEqual(activation["percent"], 50.0)
        self.assertEqual(activation["source_age_seconds"], 300.0)

    def test_missing_or_stale_previous_refill_falls_back_to_zero(self) -> None:
        now = datetime(2026, 11, 14, 12, 30, tzinfo=ZoneInfo("Europe/Amsterdam"))
        state = BatteryState(30.0, 1000.0, 500, 500, 15, 90)
        missing = resolve_refill_activation(None, now=now, battery_state=state)
        self.assertEqual(missing["percent"], 0.0)
        self.assertEqual(missing["reason"], "previous_schedule_missing")
        payload = {
            "type": "optimizer_executable_schedule",
            "inputs": {"battery_capacity_wh": 1000, "min_soc_percent": 15, "max_soc_percent": 90},
            "plan": {
                "generated_at": "2026-11-14T12:00:00+01:00",
                "scarcity": {"scheduled_refill": {
                    "starting_soc_percent": 30.0,
                    "maximum_scheduled_soc_percent": 60.0,
                    "scheduled_gain_percent_points": 30.0,
                    "scheduled_refill_percent": 50.0,
                    "calculated_at": "2026-11-14T12:00:00+01:00",
                }},
            },
        }
        stale = resolve_refill_activation(payload, now=now, battery_state=state)
        self.assertEqual(stale["percent"], 0.0)
        self.assertEqual(stale["reason"], "previous_schedule_stale")


if __name__ == "__main__":
    unittest.main()
