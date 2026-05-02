from __future__ import annotations

from datetime import datetime
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest

from zoneinfo import ZoneInfo

from planner.forecast import (
    build_segment_boundaries,
    derive_hourly_load_w,
    derive_pv_forecast_by_date,
    normalize_load_forecast_payload,
    select_horizon_dates,
)
from planner.tests.support import build_test_settings


class ForecastTests(unittest.TestCase):
    def test_normalize_and_derive_load_overlay(self) -> None:
        payload = {
            "date": "2026-04-28",
            "timezone": "Europe/Amsterdam",
            "baseline_load_w_by_hour": {f"{hour:02d}": 100 for hour in range(24)},
            "incidentals": {
                "morning": 600,
                "afternoon": 0,
                "evening": 500,
                "night": 70,
            },
        }
        record = normalize_load_forecast_payload(payload, "2026-04-28T10:00:00+02:00")
        derived = derive_hourly_load_w(record)
        self.assertEqual(len(derived), 24)
        self.assertEqual(derived[6], 200.0)
        self.assertEqual(derived[11], 200.0)
        self.assertEqual(derived[18], 200.0)
        self.assertEqual(derived[22], 200.0)
        self.assertAlmostEqual(derived[23], 110.0, places=3)
        self.assertAlmostEqual(derived[0], 110.0, places=3)

    def test_select_horizon_dates_before_and_after_release(self) -> None:
        with TemporaryDirectory() as temp_dir:
            settings = build_test_settings(Path(temp_dir))
            tz = ZoneInfo(settings.timezone)
            before = datetime(2026, 4, 28, 13, 30, tzinfo=tz)
            after = datetime(2026, 4, 28, 14, 5, tzinfo=tz)
            self.assertEqual(select_horizon_dates(before, settings), ["2026-04-28"])
            self.assertEqual(select_horizon_dates(after, settings), ["2026-04-28", "2026-04-29"])

    def test_build_segment_boundaries_includes_immediate_slot(self) -> None:
        tz = ZoneInfo("Europe/Amsterdam")
        now = datetime(2026, 4, 28, 10, 17, tzinfo=tz)
        segments = build_segment_boundaries(now, ["2026-04-28"], "Europe/Amsterdam")
        self.assertEqual(segments[0][0], now.replace(second=0, microsecond=0))
        self.assertEqual(segments[0][1].hour, 11)
        self.assertEqual(segments[0][1].minute, 0)

    def test_derive_pv_forecast_from_shortwave(self) -> None:
        with TemporaryDirectory() as temp_dir:
            settings = build_test_settings(Path(temp_dir))
            payload = {
                "hourly": {
                    "time": [
                        "2026-04-28T10:00",
                        "2026-04-28T11:00",
                        "2026-04-29T10:00",
                    ],
                    "shortwave_radiation": [1000, 500, 0],
                }
            }
            forecast = derive_pv_forecast_by_date(payload, settings)
            self.assertEqual(forecast["2026-04-28"][10], 4250.0)
            self.assertEqual(forecast["2026-04-28"][11], 2125.0)
            self.assertEqual(forecast["2026-04-29"][10], 0.0)


if __name__ == "__main__":
    unittest.main()
