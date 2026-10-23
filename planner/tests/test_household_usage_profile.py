from __future__ import annotations

from datetime import datetime, timedelta
import json
from pathlib import Path
import subprocess
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch
from zoneinfo import ZoneInfo

from planner.update_household_usage_profile import (
    ProfileNotReady,
    atomic_write_json,
    build_profile,
    fetch_rows,
    linear_percentile,
    round_to_step,
)


TIMEZONE = ZoneInfo("Europe/Amsterdam")
NOW = datetime(2026, 10, 23, 2, 10, tzinfo=TIMEZONE)


def rows_for_day(day: str, base: int) -> list[dict[str, object]]:
    return [
        {
            "local_date": day,
            "local_hour": hour,
            "estimated_home_load_wh": base + hour,
        }
        for hour in range(24)
    ]


class HouseholdUsageProfileTests(unittest.TestCase):
    def test_linear_percentile_interpolates(self) -> None:
        self.assertAlmostEqual(linear_percentile([10, 20, 30, 40], 60), 28.0)

    def test_round_to_step_uses_half_up_rounding(self) -> None:
        self.assertEqual(round_to_step(114.9, 10), 110)
        self.assertEqual(round_to_step(115.0, 10), 120)

    def test_build_profile_uses_latest_complete_days_and_p60(self) -> None:
        rows: list[dict[str, object]] = []
        start = NOW.date() - timedelta(days=4)
        for offset, base in enumerate([100, 200, 300, 400]):
            rows.extend(rows_for_day((start + timedelta(days=offset)).isoformat(), base))

        profile = build_profile(
            rows,
            [50] * 24,
            NOW,
            lookback_days=3,
            min_samples=3,
            percentile=60,
            round_step_w=10,
        )

        self.assertEqual(profile["source_start_date"], "2026-10-20")
        self.assertEqual(profile["source_end_date"], "2026-10-22")
        self.assertEqual(profile["completed_days_used"], 3)
        self.assertEqual(profile["hours"]["00"]["value_w"], 320)
        self.assertEqual(profile["hours"]["00"]["sample_count"], 3)
        self.assertEqual(profile["hours"]["00"]["source"], "historical_p60")
        self.assertEqual(profile["fallback_hours"], [])

    def test_incomplete_day_is_excluded(self) -> None:
        complete = rows_for_day("2026-10-22", 100)
        incomplete = rows_for_day("2026-10-21", 200)[:-1]
        profile = build_profile(
            incomplete + complete,
            [50] * 24,
            NOW,
            lookback_days=2,
            min_samples=1,
        )

        self.assertEqual(profile["completed_days_used"], 1)
        self.assertEqual(profile["source_start_date"], "2026-10-22")

    def test_insufficient_samples_use_config_fallback(self) -> None:
        profile = build_profile(
            rows_for_day("2026-10-22", 100),
            [75] * 24,
            NOW,
            lookback_days=2,
            min_samples=2,
        )

        self.assertEqual(len(profile["fallback_hours"]), 24)
        self.assertEqual(profile["hours"]["18"]["value_w"], 75)
        self.assertEqual(profile["hours"]["18"]["source"], "config_fallback")

    def test_missing_yesterday_does_not_publish_profile(self) -> None:
        with self.assertRaisesRegex(ProfileNotReady, "Latest complete date is 2026-10-21"):
            build_profile(
                rows_for_day("2026-10-21", 100),
                [75] * 24,
                NOW,
                lookback_days=1,
                min_samples=1,
            )

    def test_duplicate_hour_invalidates_the_day(self) -> None:
        rows = rows_for_day("2026-10-22", 100)
        rows.append(dict(rows[0]))
        with self.assertRaises(ProfileNotReady):
            build_profile(rows, [75] * 24, NOW, lookback_days=1, min_samples=1)

    def test_atomic_write_json_replaces_complete_document(self) -> None:
        with TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "profile.json"
            path.write_text('{"old":true}\n', encoding="utf-8")
            atomic_write_json(path, {"schema_version": 1, "hours": {}})

            self.assertEqual(
                json.loads(path.read_text(encoding="utf-8")),
                {"schema_version": 1, "hours": {}},
            )
            self.assertEqual(list(path.parent.glob(f".{path.name}.*.tmp")), [])

    @patch("planner.update_household_usage_profile.subprocess.run")
    def test_fetch_rows_uses_php_fallback_without_pymysql(self, run_mock) -> None:
        run_mock.return_value = subprocess.CompletedProcess(
            args=[],
            returncode=0,
            stdout=(
                '[{"local_date":"2026-10-22","local_hour":0,'
                '"estimated_home_load_wh":"123"}]\n'
            ),
            stderr="",
        )
        database_config = {
            "host": "127.0.0.1",
            "port": 3306,
            "user": "root",
            "password": "secret",
            "database": "sqlite_replication",
        }

        with patch.dict("sys.modules", {"pymysql": None}):
            rows = fetch_rows(database_config, "hourly_report_inputs", NOW.date(), 90)

        self.assertEqual(rows[0]["estimated_home_load_wh"], "123")
        environment = run_mock.call_args.kwargs["env"]
        self.assertEqual(environment["MARIADB_PASSWORD"], "secret")
        self.assertNotIn("secret", run_mock.call_args.args[0])


if __name__ == "__main__":
    unittest.main()
