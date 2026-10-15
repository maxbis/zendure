from __future__ import annotations

from pathlib import Path
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch
from urllib.parse import parse_qs, urlparse

from planner.clients import fetch_shortwave_payload
from planner.config import PvPanel
from planner.tests.support import build_test_settings


class SolarClientTests(unittest.TestCase):
    def test_fetches_each_unique_panel_orientation_once(self) -> None:
        with TemporaryDirectory() as temp_dir:
            settings = build_test_settings(Path(temp_dir))
            settings = settings.__class__(**{
                **settings.__dict__,
                "pv_panels": (PvPanel(90, 15), PvPanel(90, 15), PvPanel(270, 35)),
            })
            requested = []

            def fake_fetch(url: str, _timeout: int) -> dict:
                query = parse_qs(urlparse(url).query)
                requested.append(query)
                hourly = {"time": ["2026-10-15T12:00"], "shortwave_radiation": [200]}
                if "tilt" in query:
                    hourly["global_tilted_irradiance"] = [250]
                return {"success": True, "cachedAt": 100, "hourly": hourly}

            with patch("planner.clients.fetch_json", side_effect=fake_fetch):
                result = fetch_shortwave_payload(settings)

            self.assertEqual(len(requested), 3)
            self.assertEqual([(query["azimuth"][0], query["tilt"][0]) for query in requested[1:]],
                             [("-90.0", "15"), ("90.0", "35")])
            self.assertEqual([group["count"] for group in result["panel_irradiance"]], [2, 1])


if __name__ == "__main__":
    unittest.main()
