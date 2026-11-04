from __future__ import annotations

from datetime import datetime, timedelta
import json
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest
from zoneinfo import ZoneInfo

from planner.models import BatteryState
from planner.rolling_optimizer import RollingInputSlot
from planner.scarcity import build_scarcity_context, weighted_percentile


class ScarcityTests(unittest.TestCase):
    def test_weighted_percentile_respects_weights(self) -> None:
        self.assertEqual(weighted_percentile([(0.10, 1.0), (0.50, 9.0)], 75), 0.50)

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
            )
        self.assertTrue(context["calculation_available"])
        self.assertEqual(context["refill"]["expected_refill_percent"], 0.0)
        self.assertTrue(all(band["active"] and band["applied"] for band in context["bands"]))
        self.assertGreater(context["bands"][0]["additional_scarcity_value_eur_per_kwh"], 0.0)

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
            context = build_scarcity_context(now=now, battery_state=state, slots=[slot], round_trip_efficiency=1.0, normal_terminal_price=0.20, config=config, price_root=root)
        self.assertTrue(context["calculation_available"])
        self.assertTrue(context["bands"][0]["active"])
        self.assertFalse(context["bands"][0]["applied"])


if __name__ == "__main__":
    unittest.main()
