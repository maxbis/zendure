from __future__ import annotations

from datetime import datetime
import json
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest
from zoneinfo import ZoneInfo

from planner.household_usage import select_household_usage


NOW = datetime(2026, 10, 23, 12, 0, tzinfo=ZoneInfo("Europe/Amsterdam"))


def valid_profile() -> dict:
    return {
        "schema_version": 1,
        "timezone": "Europe/Amsterdam",
        "generated_at": "2026-10-23T02:00:00+02:00",
        "source_end_date": "2026-10-22",
        "method": "p60",
        "hours": {
            f"{hour:02d}": {"value_w": 100 + hour}
            for hour in range(24)
        },
    }


class HouseholdUsageSelectionTests(unittest.TestCase):
    def select(self, path: Path):
        return select_household_usage(
            profile_path=path,
            configured_values_w_by_hour=[50] * 24,
            timezone="Europe/Amsterdam",
            now=NOW,
        )

    def test_valid_profile_is_selected(self) -> None:
        with TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "profile.json"
            path.write_text(json.dumps(valid_profile()), encoding="utf-8")

            selected = self.select(path)

            self.assertEqual(selected.source, "historical_p60")
            self.assertEqual(selected.values_w_by_hour, [float(100 + hour) for hour in range(24)])
            self.assertIsNone(selected.fallback_reason)
            self.assertEqual(selected.profile_source_end_date, "2026-10-22")

    def test_missing_profile_uses_config(self) -> None:
        with TemporaryDirectory() as temp_dir:
            selected = self.select(Path(temp_dir) / "missing.json")

            self.assertEqual(selected.source, "config_default")
            self.assertEqual(selected.values_w_by_hour, [50.0] * 24)
            self.assertEqual(selected.fallback_reason, "profile_missing")

    def test_invalid_json_uses_config(self) -> None:
        with TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "profile.json"
            path.write_text("{", encoding="utf-8")

            selected = self.select(path)

            self.assertEqual(selected.source, "config_default")
            self.assertEqual(selected.fallback_reason, "invalid_json")

    def test_stale_profile_uses_config(self) -> None:
        with TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "profile.json"
            payload = valid_profile()
            payload["source_end_date"] = "2026-10-21"
            path.write_text(json.dumps(payload), encoding="utf-8")

            selected = self.select(path)

            self.assertEqual(selected.source, "config_default")
            self.assertEqual(selected.fallback_reason, "profile_stale")

    def test_timezone_mismatch_uses_config(self) -> None:
        with TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "profile.json"
            payload = valid_profile()
            payload["timezone"] = "UTC"
            path.write_text(json.dumps(payload), encoding="utf-8")

            selected = self.select(path)

            self.assertEqual(selected.source, "config_default")
            self.assertEqual(selected.fallback_reason, "timezone_mismatch")

    def test_partial_hours_use_config_without_mixing(self) -> None:
        with TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "profile.json"
            payload = valid_profile()
            payload["hours"].pop("23")
            path.write_text(json.dumps(payload), encoding="utf-8")

            selected = self.select(path)

            self.assertEqual(selected.source, "config_default")
            self.assertEqual(selected.values_w_by_hour, [50.0] * 24)
            self.assertEqual(selected.fallback_reason, "invalid_hours")

    def test_log_fields_include_source_values_and_fallback_reason(self) -> None:
        with TemporaryDirectory() as temp_dir:
            selected = self.select(Path(temp_dir) / "missing.json")

            fields = selected.log_fields()

            self.assertEqual(fields["household_forecast_source"], "config_default")
            self.assertEqual(fields["household_forecast_w_by_hour"], [50.0] * 24)
            self.assertEqual(fields["household_forecast_fallback_reason"], "profile_missing")


if __name__ == "__main__":
    unittest.main()
