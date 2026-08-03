#!/usr/bin/env python3
"""Integration checks for shared settings behind the old GUI runtime."""

from __future__ import annotations

import json
import subprocess
from pathlib import Path

import pytest


REPO_ROOT = Path(__file__).resolve().parents[2]
SYSTEM_CONFIG = REPO_ROOT / "common" / "config" / "system.json"
ENERGY_GRAPH_API = REPO_ROOT / "main" / "api" / "energy_graph_proxy.php"
SHORTWAVE_API = REPO_ROOT / "main" / "api" / "shortwave_radiation_api.php"
CHARGE_SCHEDULE_API = REPO_ROOT / "main" / "api" / "charge_schedule_api.php"
CALCULATE_SCHEDULE_API = REPO_ROOT / "main" / "api" / "calculate_schedule_api.php"
SCHEDULE_RENDERER = REPO_ROOT / "main" / "assets" / "js" / "schedule_renderer.js"
PRICE_OVERVIEW = REPO_ROOT / "main" / "assets" / "js" / "price_overview_bar.js"
PRICE_CONVERSION = REPO_ROOT / "main" / "assets" / "js" / "price_conversion.js"
ENERGY_GRAPH_JS = REPO_ROOT / "main" / "assets" / "js" / "energy_graph_refresh.js"


def _run_php(code: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["php", "-r", code],
        capture_output=True,
        text=True,
        check=False,
    )


def _run_node(code: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["node", "-e", code],
        capture_output=True,
        text=True,
        check=False,
    )


@pytest.fixture
def restore_system_config():
    original = SYSTEM_CONFIG.read_text(encoding="utf-8")
    try:
        yield
    finally:
        SYSTEM_CONFIG.write_text(original, encoding="utf-8")


def test_old_gui_backend_sources_use_common_settings():
    energy_source = ENERGY_GRAPH_API.read_text(encoding="utf-8")
    shortwave_source = SHORTWAVE_API.read_text(encoding="utf-8")
    charge_source = CHARGE_SCHEDULE_API.read_text(encoding="utf-8")
    calculate_source = CALCULATE_SCHEDULE_API.read_text(encoding="utf-8")

    assert "common/php/system_config.php" in energy_source
    assert "ConfigLoader::get('baseWh'" not in energy_source
    assert "$energyGraphSystemConfig['battery']['capacityWh']" in energy_source
    assert "$cached['baseWh'] = $baseWh" in energy_source
    assert "date_default_timezone_set('Europe/Amsterdam')" not in energy_source

    assert "common/php/system_config.php" in shortwave_source
    assert ": 52.3" not in shortwave_source
    assert ": 4.863" not in shortwave_source
    assert ": 'Europe/Amsterdam'" not in shortwave_source
    assert "$installation['latitude']" in shortwave_source
    assert "$installation['longitude']" in shortwave_source
    assert "$installation['timezone']" in shortwave_source

    for source in (charge_source, calculate_source):
        assert "common/php/system_config.php" in source
        assert "date_default_timezone_set('Europe/Amsterdam')" not in source


def test_old_gui_javascript_has_no_shared_value_fallbacks():
    sources = {
        "renderer": SCHEDULE_RENDERER.read_text(encoding="utf-8"),
        "overview": PRICE_OVERVIEW.read_text(encoding="utf-8"),
        "conversion": PRICE_CONVERSION.read_text(encoding="utf-8"),
        "energy": ENERGY_GRAPH_JS.read_text(encoding="utf-8"),
    }
    combined = "\n".join(sources.values())

    for literal in ("5760", "0.0219", "0.0898", "1.21"):
        assert literal not in combined
    assert ": 20;" not in sources["renderer"]
    assert ": 90;" not in sources["renderer"]
    assert ": 20;" not in sources["overview"]
    assert ": 90;" not in sources["overview"]
    assert "getRequiredScheduleBatterySettings" in sources["renderer"]
    assert "getRequiredPriceOverviewChargeLevels" in sources["overview"]
    assert "Missing required shared price-conversion" in sources["conversion"]
    assert "energy graph response is missing shared battery capacity" in sources["energy"]


def test_old_gui_javascript_accepts_the_canonical_shared_values():
    shared = json.loads(SYSTEM_CONFIG.read_text(encoding="utf-8"))
    price_script = PRICE_CONVERSION.read_text(encoding="utf-8")
    price_code = (
        f"global.window={{PRICE_CONVERSION_CONFIG:{json.dumps(shared['priceConversion'])}}};"
        f"eval({json.dumps(price_script)});"
        "process.stdout.write(JSON.stringify(window.getPriceConversionConfig()));"
    )
    price_result = _run_node(price_code)
    assert price_result.returncode == 0, price_result.stderr
    assert json.loads(price_result.stdout) == shared["priceConversion"]

    battery = shared["battery"]
    renderer_script = SCHEDULE_RENDERER.read_text(encoding="utf-8")
    renderer_code = (
        f"const CHARGE_STATUS_MIN_CHARGE_LEVEL={battery['minChargePercent']};"
        f"const CHARGE_STATUS_MAX_CHARGE_LEVEL={battery['maxChargePercent']};"
        f"const BASE_WH={battery['capacityWh']};"
        f"eval({json.dumps(renderer_script + ';process.stdout.write(JSON.stringify(getRequiredScheduleBatterySettings()));')});"
    )
    renderer_result = _run_node(renderer_code)
    assert renderer_result.returncode == 0, renderer_result.stderr
    assert json.loads(renderer_result.stdout) == {
        "minimumPercent": battery["minChargePercent"],
        "maximumPercent": battery["maxChargePercent"],
        "capacityWh": battery["capacityWh"],
    }


def test_old_gui_json_endpoints_fail_closed_on_invalid_shared_config(restore_system_config):
    SYSTEM_CONFIG.write_text('{"schemaVersion":', encoding="utf-8")
    endpoints = [
        ENERGY_GRAPH_API,
        SHORTWAVE_API,
        CHARGE_SCHEDULE_API,
        CALCULATE_SCHEDULE_API,
    ]

    for endpoint in endpoints:
        php = (
            '$_SERVER["REQUEST_METHOD"]="GET";'
            f'include {json.dumps(str(endpoint))};'
        )
        result = _run_php(php)
        assert result.returncode == 0, result.stderr
        payload = json.loads(result.stdout)
        assert payload.get("success") is False or "error" in payload
        assert "Shared system configuration:" in payload["error"]
