#!/usr/bin/env python
"""Behavior checks for the battery Health Wi-Fi signal scale."""

import json
import subprocess
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
SCALE_JS = REPO_ROOT / "app" / "assets" / "js" / "health-metric-color-scale.js"


def _wifi_details(values):
    source = SCALE_JS.read_text(encoding="utf-8")
    bootstrap = f"""
global.window = global;
eval({json.dumps(source)});
const values = {json.dumps(values)};
console.log(JSON.stringify(values.map((value) => GraphiteHealthMetricColorScale.wifiRssiDetails(value))));
"""
    result = subprocess.run(["node", "-e", bootstrap], capture_output=True, text=True, check=False)
    assert result.returncode == 0, result.stderr
    return json.loads(result.stdout)


def test_wifi_rssi_band_boundaries():
    values = [-91, -90, -86, -85, -83, -82, -81, -80, -76, -75, -71, -70, -68, -67, -64, -63, -58, -57, -50, -49]
    assert [item["score"] for item in _wifi_details(values)] == [
        0, 1, 1, 2, 2, 3, 3, 4, 4, 5, 5, 6, 6, 7, 7, 8, 8, 9, 9, 10
    ]


def test_wifi_rssi_bands_expose_requested_descriptions_and_colors():
    details = _wifi_details([-91, -88, -84, -82, -78, -73, -69, -65, -60, -53, -49])
    assert [item["description"] for item in details] == [
        "No reception", "Almost none", "Extremely poor", "Very poor", "Poor", "Just enough",
        "Enough", "Good", "Very good", "Excellent", "Near perfect"
    ]
    assert [item["color"] for item in details] == [
        "#6B7280", "#991B1B", "#DC2626", "#EA580C", "#F97316", "#F59E0B",
        "#EAB308", "#84CC16", "#22C55E", "#16A34A", "#15803D"
    ]


def test_wifi_rssi_rejects_non_finite_values():
    source = SCALE_JS.read_text(encoding="utf-8")
    bootstrap = f"""
global.window = global;
eval({json.dumps(source)});
const scale = GraphiteHealthMetricColorScale;
console.log(JSON.stringify([scale.wifiRssiDetails(null), scale.wifiRssiDetails(undefined), scale.wifiRssiDetails("not-a-number")]));
"""
    result = subprocess.run(["node", "-e", bootstrap], capture_output=True, text=True, check=False)
    assert result.returncode == 0, result.stderr
    assert json.loads(result.stdout) == [None, None, None]
