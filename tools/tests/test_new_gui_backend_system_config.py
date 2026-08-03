#!/usr/bin/env python3
"""Integration checks for shared settings behind the new GUI PHP endpoints."""

from __future__ import annotations

import json
import subprocess
from pathlib import Path

import pytest


REPO_ROOT = Path(__file__).resolve().parents[2]
SYSTEM_CONFIG = REPO_ROOT / "common" / "config" / "system.json"
PRICE_CONVERSION = REPO_ROOT / "main" / "includes" / "price_conversion.php"
SCHEDULE_RESOLVER = REPO_ROOT / "main" / "data" / "resolve_schedule_conditions.php"
SCHEDULE_API = REPO_ROOT / "main" / "data" / "api" / "data_api.php"
ENERGY_HISTORY_API = REPO_ROOT / "main" / "api" / "app_energy_history.php"
REPORT_COMMON = REPO_ROOT / "daily_report" / "includes" / "report_api_common.php"
REPORT_SMART_COMMON = REPO_ROOT / "daily_report" / "includes" / "report_smart_common.php"


def _run_php(code: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["php", "-r", code],
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


def test_backend_sources_use_common_settings_and_keep_web_settings_separate():
    price_source = PRICE_CONVERSION.read_text(encoding="utf-8")
    resolver_source = SCHEDULE_RESOLVER.read_text(encoding="utf-8")
    schedule_api_source = SCHEDULE_API.read_text(encoding="utf-8")
    energy_source = ENERGY_HISTORY_API.read_text(encoding="utf-8")
    report_source = REPORT_COMMON.read_text(encoding="utf-8")
    smart_report_source = REPORT_SMART_COMMON.read_text(encoding="utf-8")

    assert "common/php/system_config.php" in price_source
    assert "ConfigLoader::get('priceConversion." not in price_source
    assert "common/php/system_config.php" in resolver_source
    assert "MAIN_CONFIG_FILE" not in resolver_source
    assert "DEFAULT_LATITUDE" not in resolver_source
    assert "DEFAULT_LONGITUDE" not in resolver_source
    assert "new DateTimeZone('Europe/Amsterdam')" not in resolver_source
    assert "common/php/system_config.php" in schedule_api_source
    assert "MAIN_CONFIG_PATH" in schedule_api_source
    assert "include_conditions" in schedule_api_source
    assert "ConfigLoader::get('baseWh'" not in energy_source
    assert "$systemConfig['battery']['capacityWh']" in energy_source
    assert "common/php/system_config.php" in report_source
    assert "new DateTimeZone('Europe/Amsterdam')" not in report_source
    assert "--timezone" in report_source
    assert "--timezone" in smart_report_source


def test_backend_helpers_return_the_canonical_shared_values():
    shared = json.loads(SYSTEM_CONFIG.read_text(encoding="utf-8"))
    php = (
        f'require {json.dumps(str(PRICE_CONVERSION))};'
        f'require {json.dumps(str(REPORT_COMMON))};'
        'echo json_encode(['
        '"priceConversion"=>getPriceConversionConfig(),'
        '"timezone"=>dailyReportTimezone()->getName(),'
        '"capacityWh"=>dailyReportSystemConfig()["battery"]["capacityWh"]'
        ']);'
    )

    result = _run_php(php)

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)
    assert payload["priceConversion"] == shared["priceConversion"]
    assert payload["timezone"] == shared["installation"]["timezone"]
    assert payload["capacityWh"] == shared["battery"]["capacityWh"]


def test_migrated_json_endpoints_fail_closed_on_invalid_shared_config(restore_system_config):
    SYSTEM_CONFIG.write_text('{"schemaVersion":', encoding="utf-8")

    endpoint_calls = [
        (
            '$_SERVER["REQUEST_METHOD"]="GET";$_GET["type"]="schedule";'
            f'include {json.dumps(str(SCHEDULE_API))};'
        ),
        (
            '$_SERVER["REQUEST_METHOD"]="GET";'
            f'include {json.dumps(str(ENERGY_HISTORY_API))};'
        ),
        f'include {json.dumps(str(SCHEDULE_RESOLVER))};',
    ]

    for php in endpoint_calls:
        result = _run_php(php)
        assert result.returncode == 0, result.stderr
        payload = json.loads(result.stdout)
        assert payload.get("success") is False or "error" in payload
        assert "Shared system configuration:" in payload["error"]


def test_price_conversion_rejects_invalid_shared_config(restore_system_config):
    SYSTEM_CONFIG.write_text('{"schemaVersion":', encoding="utf-8")
    php = (
        f'require {json.dumps(str(PRICE_CONVERSION))};'
        'echo convertSpotToConsumerPrice(0.10);'
    )

    result = _run_php(php)

    assert result.returncode != 0
    assert "Invalid JSON in system configuration file" in result.stderr
