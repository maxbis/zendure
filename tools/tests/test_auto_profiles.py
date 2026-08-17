import importlib.util
import json
import subprocess
import tempfile
import unittest
from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo


ROOT = Path(__file__).resolve().parents[2]
MODULE_PATH = ROOT / "tools/evaluate_auto_profiles.py"
SPEC = importlib.util.spec_from_file_location("evaluate_auto_profiles", MODULE_PATH)
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(MODULE)


class AutoProfileEvaluationTest(unittest.TestCase):
    def setUp(self):
        self.profiles = {
            "selection_mode": "auto",
            "active_profile_id": "profile_b",
            "profiles": [
                {"id": "profile_a", "swr_min_wh_m2": None, "swr_max_wh_m2": 2000},
                {"id": "profile_b", "swr_min_wh_m2": 2000, "swr_max_wh_m2": 4000},
                {"id": "profile_c", "swr_min_wh_m2": 4000, "swr_max_wh_m2": None},
            ],
        }
        self.now = datetime(2026, 8, 16, 23, 55, tzinfo=ZoneInfo("Europe/Amsterdam"))

    def test_boundaries_are_lower_inclusive_and_upper_exclusive(self):
        self.assertEqual(MODULE.select_profile(self.profiles["profiles"], 1999)[0], "profile_a")
        self.assertEqual(MODULE.select_profile(self.profiles["profiles"], 2000)[0], "profile_b")
        self.assertEqual(MODULE.select_profile(self.profiles["profiles"], 4000)[0], "profile_c")

    def test_first_matching_profile_wins_when_ranges_overlap(self):
        profiles = [
            {"id": "first", "swr_min_wh_m2": 0, "swr_max_wh_m2": 5000},
            {"id": "second", "swr_min_wh_m2": 2000, "swr_max_wh_m2": 3000},
        ]
        self.assertEqual(MODULE.select_profile(profiles, 2500)[0], "first")

    def test_no_match_uses_first_profile(self):
        profiles = [
            {"id": "first", "swr_min_wh_m2": 1000, "swr_max_wh_m2": 2000},
            {"id": "second", "swr_min_wh_m2": 3000, "swr_max_wh_m2": 4000},
        ]
        selected, reason, _ = MODULE.select_profile(profiles, 2500)
        self.assertEqual((selected, reason), ("first", "default_no_match"))

    def test_failed_refresh_reuses_stored_swr(self):
        old = {
            "days": {
                "2026-08-17": {
                    "swr_wh_m2": 4500,
                    "profile_id": "profile_a",
                    "forecast_cached_at": 123,
                }
            }
        }
        state = MODULE.evaluate(self.profiles, old, None, self.now, False, "offline")
        day = state["days"]["2026-08-17"]
        self.assertEqual(day["profile_id"], "profile_c")
        self.assertEqual(day["reason"], "stale_forecast_matched")
        self.assertEqual(state["refresh_error"], "offline")

    def test_missing_swr_retains_existing_selection(self):
        old = {"days": {"2026-08-17": {"swr_wh_m2": None, "profile_id": "profile_c"}}}
        state = MODULE.evaluate(self.profiles, old, None, self.now, False, "offline")
        self.assertEqual(state["days"]["2026-08-17"]["profile_id"], "profile_c")
        self.assertEqual(state["days"]["2026-08-17"]["reason"], "retained_selection")

    def test_cli_writes_future_dates_and_ignores_today_by_default(self):
        payload = {
            "success": True,
            "cacheStatus": "fresh",
            "cachedAt": 123,
            "days": [
                {"date": "2026-08-16", "value": 1000},
                {"date": "2026-08-17", "value": 3000},
            ],
        }
        system = {"installation": {"timezone": "Europe/Amsterdam"}}
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            profiles_path = base / "profiles.json"
            payload_path = base / "payload.json"
            system_path = base / "system.json"
            state_path = base / "state.json"
            profiles_path.write_text(json.dumps(self.profiles), encoding="utf-8")
            payload_path.write_text(json.dumps(payload), encoding="utf-8")
            system_path.write_text(json.dumps(system), encoding="utf-8")
            result = subprocess.run(
                [
                    "python3", str(MODULE_PATH), "--profiles", str(profiles_path),
                    "--payload-file", str(payload_path), "--system-config", str(system_path),
                    "--state", str(state_path), "--now", self.now.isoformat(),
                ],
                check=False, capture_output=True, text=True,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            state = json.loads(state_path.read_text(encoding="utf-8"))
            self.assertNotIn("2026-08-16", state["days"])
            self.assertEqual(state["days"]["2026-08-17"]["profile_id"], "profile_b")

    def test_local_php_endpoint_can_supply_payload(self):
        with tempfile.TemporaryDirectory() as directory:
            endpoint = Path(directory) / "shortwave.php"
            endpoint.write_text('<?php echo json_encode(["success" => true, "days" => []]);', encoding="utf-8")
            payload = MODULE.fetch_shortwave_from_php(endpoint, 5)
            self.assertTrue(payload["success"])


if __name__ == "__main__":
    unittest.main()
