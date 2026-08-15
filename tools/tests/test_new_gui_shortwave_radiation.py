#!/usr/bin/env python3
"""Integration and chart-model checks for the new GUI radiation dialog."""

from __future__ import annotations

import json
import subprocess
from pathlib import Path

import pytest


REPO_ROOT = Path(__file__).resolve().parents[2]
APP_INDEX = REPO_ROOT / "app" / "index.php"
SHORTWAVE_JS = REPO_ROOT / "app" / "assets" / "js" / "shortwave-radiation.js"
FOOTER_PARTIAL = REPO_ROOT / "themes" / "graphite-signal-dark" / "partials" / "footer-more.php"
VALID_KEYS = REPO_ROOT / "login" / "validkeys.txt"


def _render_app() -> str:
    php_code = (
        f'$_COOKIE["validation"]=trim((string)file({json.dumps(str(VALID_KEYS))})[0]);'
        '$_SERVER["REQUEST_METHOD"]="GET";'
        f'include {json.dumps(str(APP_INDEX))};'
    )
    result = subprocess.run(["php", "-r", php_code], capture_output=True, text=True, check=False)
    assert result.returncode == 0, result.stderr
    return result.stdout


def _run_chart_helper(assertions: str) -> subprocess.CompletedProcess[str]:
    bootstrap = f"""
global.window = {{}};
global.document = {{ querySelector: () => null }};
global.HTMLDialogElement = class {{}};
global.HTMLButtonElement = class {{}};
require({json.dumps(str(SHORTWAVE_JS))});
const helpers = window.GraphiteShortwaveRadiation;
{assertions}
"""
    return subprocess.run(["node", "-e", bootstrap], capture_output=True, text=True, check=False)


def test_footer_partial_supports_semantic_dialog_items():
    source = FOOTER_PARTIAL.read_text(encoding="utf-8")

    assert "$item['dialogId']" in source
    assert 'aria-haspopup="dialog"' in source
    assert 'data-gsd-dialog-target=' in source
    assert '<button' in source


@pytest.mark.skipif(not VALID_KEYS.is_file(), reason="Local authentication fixture is unavailable")
def test_new_gui_renders_shortwave_menu_item_and_dialog():
    html = _render_app()

    assert '"shortwaveRadiationUrl":"../main/api/shortwave_radiation_api.php"' in html
    assert 'assets/js/shortwave-radiation.js' in html
    assert 'id="app-shortwave-radiation-dialog"' in html
    assert 'data-gsd-dialog-target="app-shortwave-radiation-dialog"' in html
    assert "Shortwave Radiation" in html
    assert 'data-role="shortwave-refresh"' in html
    assert "Use left and right arrow keys to scroll" in html
    assert 'data-role="fetch-toggle"' not in html


@pytest.mark.skipif(not SHORTWAVE_JS.is_file(), reason="Shortwave chart module is unavailable")
def test_chart_helpers_group_hourly_values_into_daily_totals():
    payload = {
        "success": True,
        "unit": "Wh/m²",
        "hourly_units": {"shortwave_radiation": "W/m²"},
        "hourly": {
            "time": [
                "2026-08-15T00:00",
                "2026-08-15T01:00",
                "2026-08-16T00:00",
                "2026-08-16T01:00",
            ],
            "shortwave_radiation": [0, 125, 20, 180],
        },
    }
    assertions = f"""
const normalized = helpers.normalizePayload({json.dumps(payload)});
if (normalized.days.length !== 2) throw new Error("Expected two days");
if (normalized.days[0].total !== 125) throw new Error("Unexpected first total");
if (normalized.days[1].total !== 200) throw new Error("Unexpected second total");
const model = helpers.buildChartModel(normalized, 320);
if (model.points.length !== 4 || model.width < 320) throw new Error("Invalid chart model");
if (!helpers.renderChart(model).includes("Hourly shortwave radiation forecast")) throw new Error("Missing accessible title");
"""

    result = _run_chart_helper(assertions)
    assert result.returncode == 0, result.stderr


def test_chart_helpers_reject_mismatched_hourly_arrays():
    assertions = """
let rejected = false;
try {
    helpers.normalizePayload({ hourly: { time: ["2026-08-15T00:00"], shortwave_radiation: [] } });
} catch (error) {
    rejected = /unavailable/.test(error.message);
}
if (!rejected) throw new Error("Invalid payload was accepted");
"""

    result = _run_chart_helper(assertions)
    assert result.returncode == 0, result.stderr
