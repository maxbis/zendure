#!/usr/bin/env python
"""Integration checks for the new GUI's shared system configuration wiring."""

from __future__ import annotations

import json
import re
import subprocess
from pathlib import Path

import pytest


REPO_ROOT = Path(__file__).resolve().parents[2]
APP_INDEX = REPO_ROOT / "app" / "index.php"
CURRENT_ENERGY_STATUS = REPO_ROOT / "app" / "assets" / "js" / "current-energy-status.js"
PRICE_PLAN = REPO_ROOT / "app" / "assets" / "js" / "price-plan.js"
SYSTEM_CONFIG = REPO_ROOT / "common" / "config" / "system.json"
VALID_KEYS = REPO_ROOT / "login" / "validkeys.txt"


def _render_app() -> str:
    php_code = (
        f'$_COOKIE["validation"]=trim((string)file({json.dumps(str(VALID_KEYS))})[0]);'
        '$_SERVER["REQUEST_METHOD"]="GET";'
        f'include {json.dumps(str(APP_INDEX))};'
    )
    result = subprocess.run(
        ["php", "-r", php_code],
        capture_output=True,
        text=True,
        check=False,
    )
    assert result.returncode == 0, result.stderr
    return result.stdout


def _injected_config(html: str) -> dict:
    match = re.search(r"window\.GRAPHITE_APP_CONFIG\s*=\s*(\{.*?\});", html, re.DOTALL)
    assert match is not None, "New GUI did not inject GRAPHITE_APP_CONFIG"
    return json.loads(match.group(1))


@pytest.fixture
def restore_system_config():
    original = SYSTEM_CONFIG.read_text(encoding="utf-8")
    try:
        yield
    finally:
        SYSTEM_CONFIG.write_text(original, encoding="utf-8")


def test_new_gui_source_uses_common_loader_for_shared_values():
    source = APP_INDEX.read_text(encoding="utf-8")

    assert "../common/php/system_config.php" in source
    assert "loadSystemConfig()" in source
    assert "APP_SOLAR_LOCATION" not in source
    assert "getPriceConversionConfig" not in source
    assert "ConfigLoader::get('MIN_CHARGE_LEVEL'" not in source
    assert "ConfigLoader::get('MAX_CHARGE_LEVEL'" not in source
    assert "ConfigLoader::get('baseWh'" not in source
    assert "ConfigLoader::get('minGridPower'" not in source
    assert "ConfigLoader::get('maxGridPower'" not in source


def test_new_gui_javascript_has_no_shared_value_fallbacks():
    status_source = CURRENT_ENERGY_STATUS.read_text(encoding="utf-8")
    price_source = PRICE_PLAN.read_text(encoding="utf-8")

    assert "minChargePercent: 20" not in status_source
    assert "maxChargePercent: 90" not in status_source
    assert "capacityWh: 5760" not in status_source
    assert "finiteNumber(config.minChargePercent, 20)" not in status_source
    assert "finiteNumber(config.maxChargePercent, 90)" not in status_source
    assert "finiteNumber(config.capacityWh, 5760)" not in status_source
    assert 'requiredSharedNumber("minChargePercent")' in status_source
    assert 'requiredSharedNumber("maxChargePercent")' in status_source
    assert 'requiredSharedNumber("capacityWh")' in status_source

    assert "|| 1.21" not in price_source
    assert "Number(conversion.supplierMarkupEurPerKwh) || 0" not in price_source
    assert "Number(conversion.energyTaxEurPerKwh) || 0" not in price_source
    assert "shared price-conversion settings are missing or invalid" in price_source
    assert "DEFAULT_HOUSEHOLD_USAGE_W_BY_HOUR" not in (REPO_ROOT / "app" / "assets" / "js" / "battery-forecast.js").read_text(encoding="utf-8")
    assert "POWER_EFFICIENCY" not in (REPO_ROOT / "app" / "assets" / "js" / "battery-forecast.js").read_text(encoding="utf-8")
    assert "Number(config.powerMinW) ||" not in price_source
    assert "Number(config.powerMaxW) ||" not in price_source


@pytest.mark.skipif(not VALID_KEYS.is_file(), reason="Local authentication fixture is unavailable")
def test_new_gui_injects_all_shared_forecast_and_schedule_values():
    shared = json.loads(SYSTEM_CONFIG.read_text(encoding="utf-8"))
    html = _render_app()
    config = _injected_config(html)

    assert "Configuration error" not in html
    assert config["minChargePercent"] == shared["battery"]["minChargePercent"] == 15
    assert config["maxChargePercent"] == shared["battery"]["maxChargePercent"] == 91
    assert config["capacityWh"] == shared["battery"]["capacityWh"] == 5760
    assert config["solarLocation"] == shared["installation"]
    assert config["priceConversion"] == shared["priceConversion"]
    assert config["batteryEfficiency"] == shared["battery"]["efficiency"] == 0.9
    assert config["forecastHouseholdUsageWByHour"] == shared["forecast"]["defaultHouseholdUsageWByHour"]
    assert config["powerMinW"] == shared["schedule"]["minPowerW"] == -1600
    assert config["powerMaxW"] == shared["schedule"]["maxPowerW"] == 1600
    assert config["powerStepW"] == shared["schedule"]["powerStepW"] == 100
    assert config["solarEvents"]


@pytest.mark.skipif(not VALID_KEYS.is_file(), reason="Local authentication fixture is unavailable")
def test_new_gui_shows_configuration_error_instead_of_shared_fallbacks(restore_system_config):
    SYSTEM_CONFIG.write_text('{"schemaVersion":', encoding="utf-8")

    html = _render_app()
    config = _injected_config(html)

    assert "Configuration error" in html
    assert "Shared system configuration:" in html
    assert 'data-component="current-energy-status"' not in html
    assert config["minChargePercent"] is None
    assert config["maxChargePercent"] is None
    assert config["capacityWh"] is None
    assert config["batteryEfficiency"] is None
    assert config["forecastHouseholdUsageWByHour"] is None
    assert config["powerMinW"] is None
    assert config["powerMaxW"] is None
    assert config["powerStepW"] is None
    assert config["priceConversion"] is None
    assert config["solarLocation"] is None
    assert config["solarEvents"] == []
