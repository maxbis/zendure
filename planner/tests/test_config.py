from __future__ import annotations

import unittest
import json
from pathlib import Path
from tempfile import TemporaryDirectory
from unittest.mock import patch

from planner.config import _derive_automation_all_url, _load_pv_config, _resolve_config_string, load_settings


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
        self.assertEqual(settings.low_soc_discharge_threshold_percent, 26)
        self.assertEqual(settings.low_soc_discharge_cost_cents_per_kwh, 10)
        self.assertEqual(settings.pv_system_capacity_w, 2640.0)
        self.assertEqual(len(settings.pv_panels), 6)
        self.assertEqual([(p.facing_degrees, p.tilt_degrees) for p in settings.pv_panels],
                         [(90.0, 15.0)] * 2 + [(270.0, 15.0)] * 2 + [(270.0, 35.0)] * 2)
        self.assertEqual(settings.pv_monthly_correction_factors, (1.0,) * 12)

    def test_active_hour_deadband_can_be_overridden(self) -> None:
        with patch.dict("os.environ", {"PLANNER_ACTIVE_HOUR_DEADBAND_W": "300"}):
            settings = load_settings()

        self.assertEqual(settings.active_hour_deadband_w, 300)

    def test_pv_config_rejects_panel_count_mismatch(self) -> None:
        source = Path(__file__).resolve().parents[1] / "pv_config.json"
        config = json.loads(source.read_text(encoding="utf-8"))
        config["panelCount"] = 5
        with TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "pv.json"
            path.write_text(json.dumps(config), encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "panelCount must match"):
                _load_pv_config(path)

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
