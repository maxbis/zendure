#!/usr/bin/env python3
"""Tests for shared PHP/JS price conversion wiring."""

from __future__ import annotations

import json
import subprocess
import sys
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
DAILY_REPORT_API_FILE = REPO_ROOT / "daily_report" / "api" / "report_data.php"
DAILY_REPORT_JS_FILE = REPO_ROOT / "daily_report" / "assets" / "js" / "daily_report.js"
DAILY_REPORT_SAMPLE_FILE = REPO_ROOT / "daily_report" / "data" / "202604" / "daily_report_20260416.json"
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


def _write_daily_report_fixture(path: Path, date: str) -> None:
    _write_json(path, {
        "date": date,
        "timezone": "Europe/Amsterdam",
        "hours": [],
        "totals": {}
    })


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


def test_daily_report_api_get_saved_report_enables_regenerate(tmp_path: Path):
    data_dir = tmp_path / "daily-report-data"
    report_path = data_dir / "202604" / "daily_report_20260416.json"
    _write_daily_report_fixture(report_path, "2026-04-16")

    php_code = (
        f'putenv("DAILY_REPORT_DATA_DIR={data_dir.as_posix()}");'
        f'putenv("DAILY_REPORT_SMART_FIXTURE_DIR={data_dir.as_posix()}");'
        '$_SERVER["REQUEST_METHOD"] = "GET";'
        '$_GET["date"] = "2026-04-16";'
        f'require "{DAILY_REPORT_API_FILE.as_posix()}";'
    )
    payload = _run_php_json(["php", "-r", php_code])

    assert payload["success"] is True
    assert payload["requestedDate"] == "2026-04-16"
    assert payload["source"] == "aggregate_saved"
    assert payload["canRegenerate"] is True
    assert payload["savedAt"]
    assert payload["report"]["date"] == "2026-04-16"


def test_daily_report_api_post_regenerate_forces_overwrite_and_enables_button(tmp_path: Path):
    data_dir = tmp_path / "daily-report-data"
    generator_script = tmp_path / "stub_generator.py"
    generator_script.write_text(
        "\n".join([
            "import argparse",
            "import json",
            "from pathlib import Path",
            "",
            "parser = argparse.ArgumentParser()",
            "parser.add_argument('--date', required=True)",
            "parser.add_argument('--output')",
            "args = parser.parse_args()",
            "",
            "payload = {",
            "    'date': args.date,",
            "    'timezone': 'Europe/Amsterdam',",
            "    'generated_at': '2026-04-18T12:00:00+02:00',",
            "    'hours': [],",
            "    'totals': {}",
            "}",
            "print(json.dumps(payload))",
        ]),
        encoding="utf-8",
    )
    today = datetime.now(TZ_NL).strftime("%Y-%m-%d")

    php_code = (
        f'putenv("DAILY_REPORT_DATA_DIR={data_dir.as_posix()}");'
        f'putenv("PYTHON_BIN={sys.executable}");'
        f'define("DAILY_REPORT_GENERATOR_SCRIPT", "{generator_script.as_posix()}");'
        '$_SERVER["REQUEST_METHOD"] = "POST";'
        f'$_POST["date"] = "{today}";'
        '$_POST["action"] = "regenerate";'
        f'require "{DAILY_REPORT_API_FILE.as_posix()}";'
    )
    payload = _run_php_json(["php", "-r", php_code])
    saved_report = data_dir / today.replace("-", "")[:6] / f"daily_report_{today.replace('-', '')}.json"

    assert payload["success"] is True
    assert payload["requestedDate"] == today
    assert payload["source"] == "live_today_regenerated"
    assert payload["canRegenerate"] is True
    assert payload["savedAt"]
    assert payload["report"]["date"] == today
    assert not saved_report.exists()

    php_code_invalid = (
        f'putenv("DAILY_REPORT_DATA_DIR={data_dir.as_posix()}");'
        '$_SERVER["REQUEST_METHOD"] = "POST";'
        '$_POST["date"] = "2026-04-17";'
        '$_POST["action"] = "invalid";'
        f'require "{DAILY_REPORT_API_FILE.as_posix()}";'
    )
    invalid_payload = _run_php_json(["php", "-r", php_code_invalid])

    assert invalid_payload["success"] is False
    assert "Invalid action" in invalid_payload["error"]


def test_daily_report_uses_shared_js_helper_for_spot_price_column():
    page_php = DAILY_REPORT_INDEX_FILE.read_text(encoding="utf-8")
    daily_report_js = DAILY_REPORT_JS_FILE.read_text(encoding="utf-8")
    daily_report_css = (REPO_ROOT / "daily_report" / "assets" / "css" / "daily_report.css").read_text(encoding="utf-8")

    assert "<th>Spot Price</th>" in page_php
    assert "<th>Savings</th>" in page_php
    assert 'colspan="15"' in page_php
    assert "window.PRICE_CONVERSION_CONFIG" in page_php
    assert "../main/assets/js/price_conversion.js" in page_php
    assert page_php.index("../main/assets/js/price_conversion.js") < page_php.index("assets/js/daily_report.js")
    assert 'data-role="report-regenerate"' in page_php
    assert 'data-role="avg-charge-price-total"' in page_php
    assert 'data-role="avg-discharge-price-total"' in page_php
    assert 'data-role="avg-price-diff-total"' in page_php
    assert 'data-role="battery-savings-total"' in page_php
    assert 'data-role="battery-charge-cost-total"' in page_php
    assert 'data-role="battery-pnl-total"' in page_php
    assert "convertConsumerToSpotPrice" in daily_report_js
    assert "formatPrice(spotPrice)" in daily_report_js
    assert "const regenerateButtonEl = document.querySelector('[data-role=\"report-regenerate\"]');" in daily_report_js
    assert "setRegenerateButtonState(Boolean(payload && payload.canRegenerate), false);" in daily_report_js
    assert "method: 'POST'" in daily_report_js
    assert "action: 'regenerate'" in daily_report_js
    assert "new URLSearchParams({" in daily_report_js
    assert 'data-role="battery-delta-range"' in page_php
    assert 'data-role="battery-delta-extrema"' in page_php
    assert 'data-role="price-variation-total"' in page_php
    assert 'data-role="price-variation-range"' in page_php
    assert 'data-role="price-variation-indicator"' in page_php
    assert 'data-role="net-cost-spot-total"' in page_php
    assert 'data-role="charge-cost-spot-total"' in page_php
    assert 'data-role="pnl-spot-total"' in page_php
    assert "const consumerChargeCost = Number(totals.charge_cost_eur);" in daily_report_js
    assert "const chargeCost = Number.isFinite(spotChargeCost) ? spotChargeCost : consumerChargeCost;" in daily_report_js
    assert "const avgChargePrice = Number.isFinite(chargeCost) && Number.isFinite(chargedKwh) && chargedKwh > 0" in daily_report_js
    assert "const avgDischargePrice = Number.isFinite(savings) && Number.isFinite(dischargedKwh) && dischargedKwh > 0" in daily_report_js
    assert "const avgPriceDiff = Number.isFinite(avgDischargePrice) && Number.isFinite(avgChargePrice)" in daily_report_js
    assert "const batteryOnlyPnl = Number.isFinite(savings) && Number.isFinite(chargeCost)" in daily_report_js
    assert "setText('avg-charge-price-total', formatPrice(avgChargePrice));" in daily_report_js
    assert "setText('avg-discharge-price-total', formatPrice(avgDischargePrice));" in daily_report_js
    assert "setText('avg-price-diff-total', formatPrice(avgPriceDiff));" in daily_report_js
    assert "setText('battery-savings-total', formatEur(savings));" in daily_report_js
    assert "setText('battery-charge-cost-total', formatEur(chargeCost));" in daily_report_js
    assert "setText('battery-pnl-total', formatEur(batteryOnlyPnl));" in daily_report_js
    assert "computeBatteryStats(report.hours)" in daily_report_js
    assert "computePriceVariationStats(report.hours)" in daily_report_js
    assert "renderTable(payload.report || {});" in daily_report_js
    assert "row && row.price_eur_per_kwh" in daily_report_js
    assert "formatEur(Number(row.savings_eur))" in daily_report_js
    assert 'class="hourly-table__total-row"' in daily_report_js
    assert ".hourly-table__total-row" in daily_report_css
    assert "const range = max - min;" in daily_report_js
    assert "const cvPct = mean > 0 ? (stddev / mean) * 100 : null;" in daily_report_js
    assert "formatPrice(priceVariation.range)" in daily_report_js
    assert "`Min ${formatPrice(priceVariation.min)}\\nMax ${formatPrice(priceVariation.max)}`" in daily_report_js
    assert "`CV ${formatPercentNeutral(priceVariation.cvPct)}`" in daily_report_js
    assert "Math.abs(batteryStats.max - batteryStats.min)" in daily_report_js
    assert "data-role=\"battery-delta-total\"" in page_php
    assert "data-role=\"battery-delta-range\"" in page_php
    assert "data-role=\"battery-delta-extrema\"" in page_php
    assert "`${formatPercentNeutral(batteryStats.start)} - ${formatPercentNeutral(batteryStats.end)}`" in daily_report_js
    assert "`${formatPercentNeutral(batteryStats.min)} - ${formatPercentNeutral(batteryStats.max)}`" in daily_report_js
    assert "computeSpotNetCost(report.hours)" in daily_report_js
    assert "`Spot ${formatEur(spotNetCost)}`" in daily_report_js
    assert "computeSpotChargeCost(report.hours)" in daily_report_js
    assert "`Consumer ${formatEur(consumerChargeCost)}`" in daily_report_js
    assert "(chargeCost - savings + spotNetCost) * -1" in daily_report_js
    assert "`Consumer ${formatEur(consumerPnl)}`" in daily_report_js


def test_daily_report_energy_chart_includes_cumulative_pnl_line():
    page_php = DAILY_REPORT_INDEX_FILE.read_text(encoding="utf-8")
    daily_report_js = DAILY_REPORT_JS_FILE.read_text(encoding="utf-8")
    daily_report_css = (REPO_ROOT / "daily_report" / "assets" / "css" / "daily_report.css").read_text(encoding="utf-8")

    assert "Cumulative P&amp;L" in page_php
    assert "--accent-pnl-line" in daily_report_css
    assert ".legend-swatch--pnl-line" in daily_report_css
    assert "function computeHourlyPnl(row)" in daily_report_js
    assert "return savings - chargeCost - netCost;" in daily_report_js
    assert "const cumulativePnlValues = buildCumulativePnlValues(hours);" in daily_report_js
    assert "const cumulativePnlCents = buildCumulativePnlValues(hours)" in daily_report_js
    assert 'stroke="${palette.pnlLine}" stroke-width="1"' in daily_report_js
    assert daily_report_js.count('stroke="${palette.pnlLine}" stroke-width="1"') == 2
    assert "`P&L: ${formatEur(hourlyPnl)}`" in daily_report_js
    assert "`P&L: ${formatTooltipCents(toCents(computeHourlyPnl(hours[index])))}`" in daily_report_js


def test_daily_report_sample_price_variation_metrics_match_expected_day():
    payload = json.loads(DAILY_REPORT_SAMPLE_FILE.read_text(encoding="utf-8"))
    prices = [row["price_eur_per_kwh"] for row in payload["hours"] if row.get("price_eur_per_kwh") is not None]

    assert prices

    mean = sum(prices) / len(prices)
    variance = sum((value - mean) ** 2 for value in prices) / len(prices)
    stddev = variance ** 0.5
    cv_pct = (stddev / mean) * 100

    assert min(prices) == pytest.approx(0.1836)
    assert max(prices) == pytest.approx(0.3538)
    assert (max(prices) - min(prices)) == pytest.approx(0.1702)
    assert cv_pct == pytest.approx(16.344634637496515)
