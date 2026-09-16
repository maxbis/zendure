from __future__ import annotations

from datetime import datetime
import json
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch
from zoneinfo import ZoneInfo

from planner.clients import PricePayload, RuntimeReadings, parse_runtime_readings
from planner.models import BatteryState
from planner.runtime_log import parse_runtime_events, update_runtime_log
from planner.shadow import run_shadow_once
from planner.tests.support import build_test_settings


TZ = ZoneInfo("Europe/Amsterdam")


def readings(at: datetime, *, soc: float = 50.0, grid: float = 100.0, battery: float = 0.0, solar: float = 200.0) -> RuntimeReadings:
    return RuntimeReadings(
        battery_state=BatteryState(soc, 5760.0, 1200, 1800, 15, 91),
        observed_at_timestamp=at.timestamp(),
        p1_timestamp=at.timestamp(),
        zendure_timestamp=at.timestamp(),
        grid_power_w=grid,
        battery_power_w=battery,
        solar_power_w=solar,
        household_power_w=grid + solar - battery,
        net_household_power_w=grid - battery,
    )


def plan(at: datetime, *, current_end_soc: float = 55.0, next_end_soc: float = 60.0) -> dict:
    current_end = datetime.fromtimestamp(at.replace(minute=0, second=0, microsecond=0).timestamp() + 3600, TZ)
    next_end = datetime.fromtimestamp(current_end.timestamp() + 3600, TZ)
    return {
        "generated_at": at.isoformat(),
        "decisions": [
            {
                "start": at.isoformat(),
                "end": current_end.isoformat(),
                "schedule_value": "netzero+",
                "min_power": 0,
                "max_power": 500,
                "battery_power_w": 300,
                "end_soc_percent": current_end_soc,
                "load_w": 220,
                "pv_w": 600,
                "grid_power_w": -80,
            },
            {
                "start": current_end.isoformat(),
                "end": next_end.isoformat(),
                "schedule_value": 300,
                "battery_power_w": 300,
                "end_soc_percent": next_end_soc,
                "load_w": 220,
                "pv_w": 500,
                "grid_power_w": 20,
            },
        ],
    }


class RuntimeLogTests(unittest.TestCase):
    def test_runtime_payload_extracts_live_power_flows(self) -> None:
        with TemporaryDirectory() as temp_dir:
            settings = build_test_settings(Path(temp_dir))
            payload = {
                "zendure": {
                    "timestamp": 1_700_000_000,
                    "readings": {
                        "properties": {
                            "electricLevel": 61,
                            "outputPackPower": 400,
                            "outputHomePower": 0,
                            "solarInputPower": 700,
                        }
                    },
                },
                "p1": {"timestamp": 1_700_000_001, "readings": {"total_power": -100}},
            }

            result = parse_runtime_readings(payload, settings)

            self.assertEqual(result.battery_state.soc_percent, 61)
            self.assertEqual(result.grid_power_w, -100)
            self.assertEqual(result.battery_power_w, 400)
            self.assertEqual(result.solar_power_w, 700)
            self.assertEqual(result.net_household_power_w, -500)
            self.assertEqual(result.household_power_w, 200)

    def test_commanded_limit_is_not_logged_as_actual_battery_power(self) -> None:
        with TemporaryDirectory() as temp_dir:
            settings = build_test_settings(Path(temp_dir))
            payload = {
                "zendure": {
                    "timestamp": 1_700_000_000,
                    "readings": {
                        "properties": {
                            "electricLevel": 61,
                            "outputPackPower": 0,
                            "outputHomePower": 0,
                            "solarInputPower": 700,
                            "acMode": 1,
                            "inputLimit": 500,
                        }
                    },
                },
                "p1": {"timestamp": 1_700_000_001, "readings": {"total_power": -100}},
            }

            result = parse_runtime_readings(payload, settings)

            self.assertIsNone(result.battery_power_w)
            self.assertIsNone(result.household_power_w)

    def test_first_run_opens_hour_and_logs_current_and_next_schedule(self) -> None:
        with TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "runtime.jsonl"
            at = datetime(2026, 9, 16, 14, 2, tzinfo=TZ)

            update_runtime_log(path, observed_at=at, timezone="Europe/Amsterdam", plan=plan(at), readings=readings(at))

            lines = path.read_text(encoding="utf-8").splitlines()
            raw_events = [json.loads(line) for line in lines]
            self.assertTrue(all(isinstance(event, dict) for event in raw_events))
            self.assertIsInstance(next(event for event in raw_events if event["event"] == "SAMPLE")["actual_household_w"], float)
            events = parse_runtime_events(lines)
            opened = next(event for event in events if event.fields["event"] == "HOUR_OPENED")
            sample = next(event for event in events if event.fields["event"] == "SAMPLE")
            self.assertEqual(opened.fields["current_schedule"], "netzero+")
            self.assertEqual(opened.fields["current_min_power_w"], 0)
            self.assertEqual(opened.fields["current_max_power_w"], 500)
            self.assertEqual(opened.fields["current_predicted_end_soc"], 55.0)
            self.assertEqual(opened.fields["next_schedule"], 300)
            self.assertEqual(opened.fields["next_predicted_end_soc"], 60.0)
            self.assertEqual(sample.fields["actual_household_w"], 300.0)

    def test_first_run_after_boundary_closes_hour_once(self) -> None:
        with TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "runtime.jsonl"
            for minute in (0, 15, 30, 45):
                at = datetime(2026, 9, 16, 14, minute, tzinfo=TZ)
                update_runtime_log(path, observed_at=at, timezone="Europe/Amsterdam", plan=plan(at), readings=readings(at))

            after = datetime(2026, 9, 16, 15, 2, tzinfo=TZ)
            update_runtime_log(path, observed_at=after, timezone="Europe/Amsterdam", plan=plan(after), readings=readings(after, soc=54.0))
            update_runtime_log(
                path,
                observed_at=datetime(2026, 9, 16, 15, 17, tzinfo=TZ),
                timezone="Europe/Amsterdam",
                plan=plan(datetime(2026, 9, 16, 15, 17, tzinfo=TZ)),
                readings=readings(datetime(2026, 9, 16, 15, 17, tzinfo=TZ), soc=55.0),
            )

            events = parse_runtime_events(path.read_text(encoding="utf-8").splitlines())
            closures = [event for event in events if event.fields.get("event") == "HOUR_CLOSED"]
            self.assertEqual(len(closures), 1)
            self.assertEqual(closures[0].fields["status"], "complete")
            self.assertEqual(closures[0].fields["predicted_usage_wh"], 220.0)
            self.assertEqual(closures[0].fields["actual_usage_wh"], 300.0)
            self.assertEqual(closures[0].fields["actual_grid_wh"], 100.0)
            self.assertEqual(closures[0].fields["actual_solar_wh"], 200.0)
            self.assertEqual(closures[0].fields["actual_soc"], 54.0)
            self.assertEqual(closures[0].fields["soc_error_ppt"], -1.0)

    def test_missed_hour_is_closed_as_incomplete(self) -> None:
        with TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "runtime.jsonl"
            first = datetime(2026, 9, 16, 14, 0, tzinfo=TZ)
            update_runtime_log(path, observed_at=first, timezone="Europe/Amsterdam", plan=plan(first), readings=readings(first))
            later = datetime(2026, 9, 16, 16, 5, tzinfo=TZ)
            update_runtime_log(path, observed_at=later, timezone="Europe/Amsterdam", plan=plan(later), readings=readings(later))

            events = parse_runtime_events(path.read_text(encoding="utf-8").splitlines())
            closures = [event for event in events if event.fields.get("event") == "HOUR_CLOSED"]
            self.assertEqual(len(closures), 2)
            self.assertEqual([event.fields["status"] for event in closures], ["incomplete", "incomplete"])
            self.assertIsNone(closures[1].fields["actual_usage_wh"])
            self.assertEqual(closures[1].fields["actual_soc"], 50.0)

    def test_malformed_trailing_line_is_ignored(self) -> None:
        with TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "runtime.jsonl"
            at = datetime(2026, 9, 16, 14, 0, tzinfo=TZ)
            update_runtime_log(path, observed_at=at, timezone="Europe/Amsterdam", plan=plan(at), readings=readings(at))
            with path.open("a", encoding="utf-8") as handle:
                handle.write("[broken partial line")
            after = datetime(2026, 9, 16, 15, 1, tzinfo=TZ)

            update_runtime_log(path, observed_at=after, timezone="Europe/Amsterdam", plan=plan(after), readings=readings(after))

            events = parse_runtime_events(path.read_text(encoding="utf-8").splitlines())
            self.assertEqual(sum(event.fields.get("event") == "HOUR_CLOSED" for event in events), 1)

    def test_shadow_run_creates_runtime_log_without_a_second_cron_entry(self) -> None:
        with TemporaryDirectory() as temp_dir:
            temp_path = Path(temp_dir)
            settings = build_test_settings(temp_path)
            at = datetime(2026, 9, 16, 14, 0, tzinfo=TZ)
            price_payload = PricePayload(
                today_date="20260916",
                tomorrow_date="20260917",
                import_today=[0.20] * 24,
                import_tomorrow=[0.20] * 24,
                export_today=[0.10] * 24,
                export_tomorrow=[0.10] * 24,
            )
            runtime_path = temp_path / "runtime.jsonl"
            shortwave = {
                "hourly": {
                    "time": ["2026-09-16T14:00", "2026-09-16T15:00"],
                    "shortwave_radiation": [500, 400],
                }
            }

            with patch("planner.shadow.fetch_price_payload", return_value=price_payload), patch(
                "planner.shadow.fetch_runtime_readings", return_value=readings(at)
            ), patch("planner.shadow.fetch_shortwave_payload", return_value=shortwave):
                result = run_shadow_once(
                    settings,
                    now=at,
                    output_path=temp_path / "plans.log",
                    latest_path=temp_path / "latest.json",
                    runtime_output_path=runtime_path,
                )

            self.assertNotIn("runtime_log_error", result)
            self.assertTrue(runtime_path.exists())
            events = parse_runtime_events(runtime_path.read_text(encoding="utf-8").splitlines())
            self.assertTrue(any(event.fields.get("event") == "SAMPLE" for event in events))


if __name__ == "__main__":
    unittest.main()
