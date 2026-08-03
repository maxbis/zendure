#!/usr/bin/env python
"""Integration checks for the old GUI's shared system configuration wiring."""

from __future__ import annotations

import json
import re
import subprocess
from pathlib import Path

import pytest


REPO_ROOT = Path(__file__).resolve().parents[2]
OLD_GUI_INDEX = REPO_ROOT / "main" / "charge_schedule_mobile.php"
SYSTEM_CONFIG = REPO_ROOT / "common" / "config" / "system.json"
MAIN_CONFIG = REPO_ROOT / "main" / "config" / "config.json"
VALID_KEYS = REPO_ROOT / "login" / "validkeys.txt"


def _render_old_gui(*, append_timezone: bool = False) -> str:
    php_code = (
        f'$_COOKIE["validation"]=trim((string)file({json.dumps(str(VALID_KEYS))})[0]);'
        '$_SERVER["REQUEST_METHOD"]="GET";'
        f'include {json.dumps(str(OLD_GUI_INDEX))};'
    )
    if append_timezone:
        php_code += 'echo "\\nTIMEZONE=" . date_default_timezone_get();'

    result = subprocess.run(
        ["php", "-r", php_code],
        capture_output=True,
        text=True,
        check=False,
    )
    assert result.returncode == 0, result.stderr
    return result.stdout


def _javascript_number(html: str, name: str) -> int | float:
    match = re.search(rf"(?:const\s+)?{re.escape(name)}\s*:\s*(-?\d+(?:\.\d+)?)", html)
    if match is None:
        match = re.search(rf"const\s+{re.escape(name)}\s*=\s*(-?\d+(?:\.\d+)?)", html)
    assert match is not None, f"Old GUI did not inject {name}"
    value = float(match.group(1))
    return int(value) if value.is_integer() else value


@pytest.fixture
def restore_system_config():
    original = SYSTEM_CONFIG.read_text(encoding="utf-8")
    try:
        yield
    finally:
        SYSTEM_CONFIG.write_text(original, encoding="utf-8")


def test_old_gui_source_uses_common_loader_for_shared_values():
    source = OLD_GUI_INDEX.read_text(encoding="utf-8")

    assert "../common/php/system_config.php" in source
    assert "loadSystemConfig()" in source
    assert "getPriceConversionConfig" not in source
    assert "ConfigLoader::get('MIN_CHARGE_LEVEL'" not in source
    assert "ConfigLoader::get('MAX_CHARGE_LEVEL'" not in source
    assert "ConfigLoader::get('baseWh'" not in source
    assert "date_default_timezone_set('Europe/Amsterdam')" not in source


@pytest.mark.skipif(not VALID_KEYS.is_file(), reason="Local authentication fixture is unavailable")
def test_old_gui_injects_common_values_and_keeps_web_values_separate():
    shared = json.loads(SYSTEM_CONFIG.read_text(encoding="utf-8"))
    web = json.loads(MAIN_CONFIG.read_text(encoding="utf-8"))
    html = _render_old_gui(append_timezone=True)

    assert "Configuration Error" not in html
    assert _javascript_number(html, "CHARGE_STATUS_MIN_CHARGE_LEVEL") == shared["battery"]["minChargePercent"] == 15
    assert _javascript_number(html, "CHARGE_STATUS_MAX_CHARGE_LEVEL") == shared["battery"]["maxChargePercent"] == 91
    assert _javascript_number(html, "BASE_WH") == shared["battery"]["capacityWh"] == 5760
    assert _javascript_number(html, "GRID_MIN_POWER") == web["minGridPower"] == -1600
    assert _javascript_number(html, "GRID_MAX_POWER") == web["maxGridPower"] == 1600
    assert _javascript_number(html, "supplierMarkupEurPerKwh") == shared["priceConversion"]["supplierMarkupEurPerKwh"]
    assert _javascript_number(html, "energyTaxEurPerKwh") == shared["priceConversion"]["energyTaxEurPerKwh"]
    assert _javascript_number(html, "vatMultiplier") == shared["priceConversion"]["vatMultiplier"]
    assert _javascript_number(html, "consumerPrecision") == shared["priceConversion"]["consumerPrecision"]
    assert _javascript_number(html, "spotPrecision") == shared["priceConversion"]["spotPrecision"]
    assert f'TIMEZONE={shared["installation"]["timezone"]}' in html


@pytest.mark.skipif(not VALID_KEYS.is_file(), reason="Local authentication fixture is unavailable")
def test_old_gui_shows_configuration_error_instead_of_shared_fallbacks(restore_system_config):
    SYSTEM_CONFIG.write_text('{"schemaVersion":', encoding="utf-8")

    html = _render_old_gui()

    assert "Configuration Error" in html
    assert "Shared system configuration:" in html
    assert "charge-status-wrapper" not in html
    assert "CHARGE_STATUS_MIN_CHARGE_LEVEL" not in html
