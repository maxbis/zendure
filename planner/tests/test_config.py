from __future__ import annotations

import unittest
from unittest.mock import patch

from planner.config import _derive_automation_all_url, _resolve_config_string, load_settings


class ConfigTests(unittest.TestCase):
    def test_optimizer_settings_use_shared_operating_limits(self) -> None:
        settings = load_settings()

        self.assertEqual(settings.base_wh, 5760.0)
        self.assertEqual(settings.min_charge_level, 15)
        self.assertEqual(settings.max_charge_level, 91)
        self.assertEqual(settings.max_charge_power_w, 1200)
        self.assertEqual(settings.max_discharge_power_w, 1800)
        self.assertEqual(settings.active_hour_deadband_w, 0)
        self.assertEqual(settings.round_trip_efficiency, 0.85)
        self.assertEqual(settings.battery_wear_cost_eur_per_kwh_discharged, 0.0005)

    def test_active_hour_deadband_can_be_overridden(self) -> None:
        with patch.dict("os.environ", {"PLANNER_ACTIVE_HOUR_DEADBAND_W": "300"}):
            settings = load_settings()

        self.assertEqual(settings.active_hour_deadband_w, 300)

    def test_active_hour_deadband_must_match_power_step(self) -> None:
        with patch.dict("os.environ", {"PLANNER_ACTIVE_HOUR_DEADBAND_W": "250"}):
            with self.assertRaisesRegex(ValueError, "multiple of powerStepW"):
                load_settings()

    def test_resolve_config_string_expands_placeholders(self) -> None:
        config = {
            "apiBaseUrlPiControl": "http://127.0.0.1:1611",
            "allApi": "${apiBaseUrlPiControl}/api/all",
        }
        self.assertEqual(
            _resolve_config_string("${apiBaseUrlPiControl}/api/all", config),
            "http://127.0.0.1:1611/api/all",
        )

    def test_derive_automation_all_url_uses_resolved_placeholder(self) -> None:
        config = {
            "apiBaseUrlPiControl": "http://127.0.0.1:1611",
            "allApi": "${apiBaseUrlPiControl}/api/all",
        }
        self.assertEqual(
            _derive_automation_all_url(config),
            "http://127.0.0.1:1611/api/all",
        )


if __name__ == "__main__":
    unittest.main()
