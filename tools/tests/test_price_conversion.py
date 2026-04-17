#!/usr/bin/env python3
"""Tests for shared PHP/JS price conversion wiring."""

from __future__ import annotations

import json
import subprocess
from datetime import datetime, timedelta
from pathlib import Path
from zoneinfo import ZoneInfo

import pytest


REPO_ROOT = Path(__file__).resolve().parents[2]
MAIN_CONFIG_FILE = REPO_ROOT / "main" / "config" / "config.json"
PRICE_HELPER_FILE = REPO_ROOT / "main" / "includes" / "price_conversion.php"
CHARGE_SCHEDULE_MOBILE_FILE = REPO_ROOT / "main" / "charge_schedule_mobile.php"
PRICE_CONVERSION_JS_FILE = REPO_ROOT / "main" / "assets" / "js" / "price_conversion.js"
PRICE_OVERVIEW_BAR_FILE = REPO_ROOT / "main" / "assets" / "js" / "price_overview_bar.js"
DAILY_REPORT_INDEX_FILE = REPO_ROOT / "daily_report" / "index.php"
DAILY_REPORT_JS_FILE = REPO_ROOT / "daily_report" / "assets" / "js" / "daily_report.js"
MAIN_PRICE_DIR = REPO_ROOT / "main" / "data" / "price"
TZ_NL = ZoneInfo("Europe/Amsterdam")

PRICE_ENTRYPOINTS = [
    REPO_ROOT / "main" / "prices" / "get_prices_v5.php",
    REPO_ROOT / "main" / "prices" / "get_prices_v5.1.php",
    REPO_ROOT / "main" / "prices" / "get_prices_v6.php",
    REPO_ROOT / "main" / "prices" / "get_prices_v7.php",
]


def _run_php_json(args: list[str]) -> dict:
    proc = subprocess.run(args, capture_output=True, text=True, check=False)
    if proc.returncode != 0:
        raise RuntimeError(f"PHP command failed ({proc.returncode}): {proc.stderr.strip()}")
    raw = proc.stdout.strip()
    if not raw:
        raise RuntimeError("PHP command returned empty output")
    return json.loads(raw)


def _hourly_prices(base: float) -> dict[str, float]:
    return {f"{hour:02d}": round(base + (hour * 0.01), 4) for hour in range(24)}


def _today_and_tomorrow_ymd() -> tuple[str, str]:
    now = datetime.now(TZ_NL)
    today = now.strftime("%Y%m%d")
    tomorrow = (now + timedelta(days=1)).strftime("%Y%m%d")
    return today, tomorrow


def _price_file_path(yyyymmdd: str) -> Path:
    return MAIN_PRICE_DIR / yyyymmdd[:6] / f"price{yyyymmdd}.json"


def _write_json(path: Path, payload: object) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2), encoding="utf-8")


@pytest.fixture
def backup_and_restore_main_config():
    exists = MAIN_CONFIG_FILE.exists()
    original = MAIN_CONFIG_FILE.read_text(encoding="utf-8") if exists else None
    try:
        yield
    finally:
        if exists and original is not None:
            MAIN_CONFIG_FILE.write_text(original, encoding="utf-8")
        elif MAIN_CONFIG_FILE.exists():
            MAIN_CONFIG_FILE.unlink()


@pytest.fixture
def backup_and_restore_price_files():
    today, tomorrow = _today_and_tomorrow_ymd()
    paths = [_price_file_path(today), _price_file_path(tomorrow)]
    backups: dict[Path, str] = {}
    exists: dict[Path, bool] = {}

    for path in paths:
        exists[path] = path.exists()
        if path.exists():
            backups[path] = path.read_text(encoding="utf-8")

    try:
        yield today, tomorrow
    finally:
        for path in paths:
            if exists[path]:
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(backups[path], encoding="utf-8")
            elif path.exists():
                path.unlink()


def test_php_helper_converts_both_directions_and_returns_null():
    php_code = (
        f'require "{PRICE_HELPER_FILE.as_posix()}";'
        'echo json_encode(['
        '"consumer"=>convertSpotToConsumerPrice(0.10),'
        '"spot"=>convertConsumerToSpotPrice(0.2566),'
        '"consumer_override"=>convertSpotToConsumerPrice(0.10, 2),'
        '"spot_override"=>convertConsumerToSpotPrice(0.2566, 3),'
        '"null_consumer"=>convertSpotToConsumerPrice(null),'
        '"null_spot"=>convertConsumerToSpotPrice(null)'
        ']);'
    )
    payload = _run_php_json(["php", "-r", php_code])

    expected_consumer = round((0.10 + 0.0219 + 0.0898) * 1.21, 4)
    expected_spot = round((0.2566 / 1.21) - 0.0219 - 0.0898, 6)

    assert payload["consumer"] == pytest.approx(expected_consumer)
    assert payload["spot"] == pytest.approx(expected_spot)
    assert payload["consumer_override"] == pytest.approx(round(expected_consumer, 2))
    assert payload["spot_override"] == pytest.approx(round(expected_spot, 3))
    assert payload["null_consumer"] is None
    assert payload["null_spot"] is None


def test_php_helper_uses_config_backed_override(backup_and_restore_main_config):
    config = json.loads(MAIN_CONFIG_FILE.read_text(encoding="utf-8"))
    config["priceConversion"] = {
        "supplierMarkupEurPerKwh": 0.05,
        "energyTaxEurPerKwh": 0.01,
        "vatMultiplier": 1.1,
        "consumerPrecision": 3,
        "spotPrecision": 5,
    }
    MAIN_CONFIG_FILE.write_text(json.dumps(config, indent=2), encoding="utf-8")

    php_code = (
        f'require "{PRICE_HELPER_FILE.as_posix()}";'
        'echo json_encode(['
        '"consumer"=>convertSpotToConsumerPrice(0.2),'
        '"spot"=>convertConsumerToSpotPrice(0.286),'
        '"config"=>getPriceConversionConfig()'
        ']);'
    )
    payload = _run_php_json(["php", "-r", php_code])

    expected_consumer = round((0.2 + 0.05 + 0.01) * 1.1, 3)
    expected_spot = round((0.286 / 1.1) - 0.05 - 0.01, 5)

    assert payload["consumer"] == pytest.approx(expected_consumer)
    assert payload["spot"] == pytest.approx(expected_spot)
    assert payload["config"]["supplierMarkupEurPerKwh"] == pytest.approx(0.05)
    assert payload["config"]["energyTaxEurPerKwh"] == pytest.approx(0.01)
    assert payload["config"]["vatMultiplier"] == pytest.approx(1.1)
    assert payload["config"]["consumerPrecision"] == 3
    assert payload["config"]["spotPrecision"] == 5


@pytest.mark.parametrize("entrypoint", PRICE_ENTRYPOINTS, ids=lambda path: path.name)
def test_price_entrypoints_keep_cached_response_shape(entrypoint: Path, backup_and_restore_price_files):
    today, tomorrow = backup_and_restore_price_files
    today_prices = _hourly_prices(0.20)
    tomorrow_prices = _hourly_prices(0.30)
    _write_json(_price_file_path(today), today_prices)
    _write_json(_price_file_path(tomorrow), tomorrow_prices)

    payload = _run_php_json(["php", str(entrypoint)])

    assert set(payload.keys()) >= {"today", "tomorrow", "dates", "updateResults"}
    assert payload["today"] == today_prices
    assert payload["tomorrow"] == tomorrow_prices
    assert payload["dates"]["today"] == today
    assert payload["dates"]["tomorrow"] == tomorrow
    assert payload["updateResults"]["today"] is False
    assert payload["updateResults"]["tomorrow"] is False
    assert set(payload["today"].keys()) == {f"{hour:02d}" for hour in range(24)}
    assert all(isinstance(value, (int, float)) for value in payload["today"].values())
    assert all(not isinstance(value, dict) for value in payload["today"].values())


def test_price_popup_uses_shared_js_helper():
    price_conversion_js = PRICE_CONVERSION_JS_FILE.read_text(encoding="utf-8")
    price_overview_bar_js = PRICE_OVERVIEW_BAR_FILE.read_text(encoding="utf-8")
    page_php = CHARGE_SCHEDULE_MOBILE_FILE.read_text(encoding="utf-8")

    assert "window.convertConsumerToSpotPrice" in price_conversion_js
    assert "window.convertSpotToConsumerPrice" in price_conversion_js
    assert "function spotPriceFromIncl" not in price_overview_bar_js
    assert "convertConsumerToSpotPrice(priceValue)" in price_overview_bar_js
    assert "window.PRICE_CONVERSION_CONFIG" in page_php
    assert page_php.index("assets/js/price_conversion.js") < page_php.index("assets/js/price_overview_bar.js")


def test_daily_report_uses_shared_js_helper_for_spot_price_column():
    page_php = DAILY_REPORT_INDEX_FILE.read_text(encoding="utf-8")
    daily_report_js = DAILY_REPORT_JS_FILE.read_text(encoding="utf-8")

    assert "<th>Spot Price</th>" in page_php
    assert 'colspan="14"' in page_php
    assert "window.PRICE_CONVERSION_CONFIG" in page_php
    assert "../main/assets/js/price_conversion.js" in page_php
    assert page_php.index("../main/assets/js/price_conversion.js") < page_php.index("assets/js/daily_report.js")
    assert "convertConsumerToSpotPrice" in daily_report_js
    assert "formatPrice(spotPrice)" in daily_report_js
    assert 'data-role="battery-delta-range"' in page_php
    assert 'data-role="battery-delta-extrema"' in page_php
    assert 'data-role="net-cost-spot-total"' in page_php
    assert 'data-role="charge-cost-spot-total"' in page_php
    assert 'data-role="pnl-spot-total"' in page_php
    assert "computeBatteryStats(report.hours)" in daily_report_js
    assert "Math.abs(batteryStats.max - batteryStats.min)" in daily_report_js
    assert "`Min ${formatPercentNeutral(batteryStats.min)} / Max ${formatPercentNeutral(batteryStats.max)}`" in daily_report_js
    assert "computeSpotNetCost(report.hours)" in daily_report_js
    assert "`Spot ${formatEur(spotNetCost)}`" in daily_report_js
    assert "computeSpotChargeCost(report.hours)" in daily_report_js
    assert "`Spot ${formatEur(spotChargeCost)}`" in daily_report_js
    assert "(spotChargeCost - savings + spotNetCost) * -1" in daily_report_js
    assert "`Spot ${formatEur(spotPnl)}`" in daily_report_js
