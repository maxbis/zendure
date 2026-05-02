from __future__ import annotations

from datetime import datetime
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch

from zoneinfo import ZoneInfo

from planner.models import BatteryState, PlannerResult
from planner.models import LoadForecastRecord, PlannerSlot, ResolvedSlot
from planner.service import PlannerApp
from planner.tests.support import build_test_settings


class ServiceTests(unittest.TestCase):
    def test_handle_load_forecast_payload_persists_record(self) -> None:
        with TemporaryDirectory() as temp_dir:
            settings = build_test_settings(Path(temp_dir))
            app = PlannerApp(settings)
            payload = {
                "date": "2026-04-28",
                "timezone": "Europe/Amsterdam",
                "baseline_load_w_by_hour": [100] * 24,
                "incidentals": {"morning": 0, "afternoon": 0, "evening": 0, "night": 0},
            }
            response = app.handle_load_forecast_payload(payload)
            self.assertTrue(response["ok"])
            stored = app.store.get("2026-04-28")
            self.assertIsNotNone(stored)
            self.assertEqual(stored.baseline_load_w_by_hour[0], 100.0)

    def test_health_reports_upstream_failures(self) -> None:
        with TemporaryDirectory() as temp_dir:
            settings = build_test_settings(Path(temp_dir))
            app = PlannerApp(settings)
            with patch("planner.service.fetch_price_payload", side_effect=RuntimeError("down")):
                payload = app.build_health_payload()
            self.assertFalse(payload["ok"])
            self.assertFalse(payload["upstreams"]["prices"]["ok"])

    def test_build_plan_uses_clients_and_returns_result(self) -> None:
        with TemporaryDirectory() as temp_dir:
            settings = build_test_settings(Path(temp_dir))
            app = PlannerApp(settings)
            app.store.put(
                LoadForecastRecord(
                    date="2026-04-28",
                    timezone="Europe/Amsterdam",
                    baseline_load_w_by_hour=[100.0] * 24,
                    incidentals={"morning": 0.0, "afternoon": 0.0, "evening": 0.0, "night": 0.0},
                    updated_at="2026-04-28T10:00:00+02:00",
                )
            )
            result = PlannerResult(
                success=True,
                plan_slots=[
                    PlannerSlot(
                        start="2026-04-28T10:15:00+02:00",
                        end="2026-04-28T11:00:00+02:00",
                        mode="fixed",
                        target_power=0,
                        min_power=None,
                        max_power=None,
                        reason="idle",
                    )
                ],
                resolved_slots=[
                    ResolvedSlot(time="1015", value=0, key="planner-202604281015")
                ],
                explanations=["idle"],
                meta={"current_date": "2026-04-28", "timezone": "Europe/Amsterdam", "generated_at": "x"},
            )
            with patch("planner.service.fetch_price_payload"), patch(
                "planner.service.fetch_battery_state",
                return_value=BatteryState(50.0, 5760.0, 1200, 1600, 15, 96),
            ), patch("planner.service.fetch_shortwave_payload", return_value={"hourly": {"time": [], "shortwave_radiation": []}}), patch(
                "planner.service.generate_plan",
                return_value=result,
            ):
                built = app.build_plan()
            self.assertTrue(built.success)
            self.assertEqual(built.resolved_slots[0].time, "1015")

    def test_build_plan_uses_default_template_when_local_forecast_is_missing(self) -> None:
        with TemporaryDirectory() as temp_dir:
            settings = build_test_settings(Path(temp_dir))
            settings.default_load_forecast_template_path.parent.mkdir(parents=True, exist_ok=True)
            settings.default_load_forecast_template_path.write_text(
                """{
  "timezone": "Europe/Amsterdam",
  "baseline_load_w_by_hour": [200, 200, 200, 200, 200, 200, 200, 200, 200, 200, 200, 200, 200, 200, 200, 200, 200, 200, 200, 200, 200, 200, 200, 200],
  "incidentals": {
    "morning": 0,
    "afternoon": 0,
    "evening": 0,
    "night": 0
  }
}
""",
                encoding="utf-8",
            )
            app = PlannerApp(settings)
            fixed_now = datetime(2026, 4, 28, 10, 0, tzinfo=ZoneInfo(settings.timezone))
            result = PlannerResult(
                success=True,
                plan_slots=[],
                resolved_slots=[],
                explanations=[],
                meta={"warnings": [], "current_date": "2026-04-28"},
            )
            with patch.object(app, "now", return_value=fixed_now), patch("planner.service.fetch_price_payload"), patch(
                "planner.service.fetch_battery_state",
                return_value=BatteryState(50.0, 5760.0, 1200, 1600, 15, 96),
            ), patch("planner.service.fetch_shortwave_payload", return_value={"hourly": {"time": [], "shortwave_radiation": []}}), patch(
                "planner.service.generate_plan",
                return_value=result,
            ) as mocked_generate:
                app.build_plan()

            load_forecasts = mocked_generate.call_args.kwargs["load_forecasts"]
            self.assertIn("2026-04-28", load_forecasts)
            self.assertEqual(load_forecasts["2026-04-28"].baseline_load_w_by_hour, [200.0] * 24)
            self.assertIn(
                "Using default load forecast template for 2026-04-28",
                result.meta["warnings"],
            )


if __name__ == "__main__":
    unittest.main()
