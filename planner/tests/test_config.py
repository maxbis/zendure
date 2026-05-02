from __future__ import annotations

import unittest

from planner.config import _derive_automation_all_url, _resolve_config_string


class ConfigTests(unittest.TestCase):
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

