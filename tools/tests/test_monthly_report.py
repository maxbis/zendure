#!/usr/bin/env python3
"""Tests for daily_report monthly aggregation."""

from __future__ import annotations

import calendar
import json
import subprocess
from contextlib import contextmanager
from datetime import datetime, timedelta
from pathlib import Path
from typing import Iterator
from zoneinfo import ZoneInfo

import pytest


REPO_ROOT = Path(__file__).resolve().parents[2]
MAIN_CONFIG_FILE = REPO_ROOT / "main" / "config" / "config.json"
MONTHLY_REPORT_API_FILE = REPO_ROOT / "daily_report" / "api" / "monthly_report_data.php"
MONTHLY_REPORT_PAGE_FILE = REPO_ROOT / "daily_report" / "monthly.php"
MONTHLY_REPORT_JS_FILE = REPO_ROOT / "daily_report" / "assets" / "js" / "monthly_report.js"
DAILY_REPORT_DATA_DIR = REPO_ROOT / "daily_report" / "data"
TZ_NL = ZoneInfo("Europe/Amsterdam")


def _run_php_json(args: list[str]) -> dict:
    proc = subprocess.run(args, capture_output=True, text=True, check=False)
    if proc.returncode != 0:
        raise RuntimeError(f"PHP command failed ({proc.returncode}): {proc.stderr.strip()}")
    raw = proc.stdout.strip()
    if not raw:
        raise RuntimeError("PHP command returned empty output")
    return json.loads(raw)


def _month_dates(month: str) -> list[str]:
    year, month_num = (int(part) for part in month.split("-"))
    day_count = calendar.monthrange(year, month_num)[1]
    return [f"{month}-{day:02d}" for day in range(1, day_count + 1)]


def _report_path(date: str) -> Path:
    yyyymm = date.replace("-", "")[:6]
    yyyymmdd = date.replace("-", "")
    return DAILY_REPORT_DATA_DIR / yyyymm / f"daily_report_{yyyymmdd}.json"


def _hour(
    hour: str,
    *,
    charged_wh: float = 0.0,
    discharged_wh: float = 0.0,
    battery_start: float | None = None,
    battery_end: float | None = None,
    grid_from_wh: float | None = 0.0,
    grid_to_wh: float | None = 0.0,
    price: float | None = 0.20,
    grid_from_cost: float | None = 0.0,
    grid_to_cost: float | None = 0.0,
    net_cost: float | None = 0.0,
    savings_eur: float | None = 0.0,
    charge_cost_eur: float | None = 0.0,
    is_partial_hour: bool = False,
) -> dict[str, object]:
    return {
        "hour": hour,
        "charged_wh": charged_wh,
        "discharged_wh": discharged_wh,
        "battery_pct_start": battery_start,
        "battery_pct_end": battery_end,
        "battery_pct_delta": None if battery_start is None or battery_end is None else round(battery_end - battery_start, 2),
        "grid_from_wh": grid_from_wh,
        "grid_to_wh": grid_to_wh,
        "price_eur_per_kwh": price,
        "grid_from_cost": grid_from_cost,
        "grid_to_cost": grid_to_cost,
        "net_cost": net_cost,
        "savings_eur": savings_eur,
        "charge_cost_eur": charge_cost_eur,
        "is_partial_hour": is_partial_hour,
    }


def _build_report(
    date: str,
    *,
    hours: list[dict[str, object]],
    totals: dict[str, object],
    price_file_found: bool = True,
    price_hours_available: int = 24,
    is_partial_day: bool = False,
) -> dict[str, object]:
    return {
        "date": date,
        "timezone": "Europe/Amsterdam",
        "day_start_ts": 0,
        "day_end_ts": 0,
        "analysis_end_ts": 0,
        "is_partial_day": is_partial_day,
        "price_file_found": price_file_found,
        "price_file_path": f"/fake/price{date.replace('-', '')}.json" if price_file_found else None,
        "price_hours_available": price_hours_available,
        "hours": hours,
        "totals": totals,
    }


def _zero_price_report(date: str) -> dict[str, object]:
    hours = [
        _hour(
            "00",
            charged_wh=0.0,
            discharged_wh=0.0,
            battery_start=None,
            battery_end=None,
            grid_from_wh=0.0,
            grid_to_wh=0.0,
            price=0.20,
            grid_from_cost=0.0,
            grid_to_cost=0.0,
            net_cost=0.0,
            savings_eur=0.0,
            charge_cost_eur=0.0,
        )
    ]
    totals = {
        "charged_wh": 0.0,
        "discharged_wh": 0.0,
        "battery_pct_delta_total": None,
        "grid_from_wh": 0.0,
        "grid_to_wh": 0.0,
        "grid_from_cost": 0.0,
        "grid_to_cost": 0.0,
        "net_cost": 0.0,
        "savings_eur": 0.0,
        "charge_cost_eur": 0.0,
    }
    return _build_report(date, hours=hours, totals=totals)


def _write_json(path: Path, payload: object) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2), encoding="utf-8")


@contextmanager
def _temporary_reports(reports: dict[str, dict[str, object]]) -> Iterator[None]:
    backups: dict[Path, str] = {}
    existed: dict[Path, bool] = {}
    touched_paths = [_report_path(date) for date in reports]

    try:
        for date, payload in reports.items():
            path = _report_path(date)
            existed[path] = path.exists()
            if path.exists():
                backups[path] = path.read_text(encoding="utf-8")
            _write_json(path, payload)
        yield
    finally:
        for path in touched_paths:
            if existed.get(path):
                path.write_text(backups[path], encoding="utf-8")
            elif path.exists():
                path.unlink()
            try:
                path.parent.rmdir()
            except OSError:
                pass


def _current_price_config() -> dict[str, float | int]:
    config = json.loads(MAIN_CONFIG_FILE.read_text(encoding="utf-8"))
    return config["priceConversion"]


def _consumer_to_spot(value: float) -> float:
    config = _current_price_config()
    return round(
        (value / float(config["vatMultiplier"]))
        - float(config["supplierMarkupEurPerKwh"])
        - float(config["energyTaxEurPerKwh"]),
        int(config["spotPrecision"]),
    )


def test_monthly_api_aggregates_saved_daily_reports_and_keeps_partial_cost_totals():
    month = "1999-02"
    dates = _month_dates(month)
    day1, day2, day3 = dates[:3]

    reports = {date: _zero_price_report(date) for date in dates}

    reports[day1] = _build_report(
        day1,
        hours=[
            _hour(
                "00",
                charged_wh=1000.0,
                battery_start=60.0,
                battery_end=65.0,
                grid_from_wh=500.0,
                grid_to_wh=100.0,
                price=0.30,
                grid_from_cost=0.15,
                grid_to_cost=-0.03,
                net_cost=0.12,
                savings_eur=0.0,
                charge_cost_eur=0.30,
            ),
            _hour(
                "01",
                discharged_wh=500.0,
                battery_start=65.0,
                battery_end=55.0,
                grid_from_wh=0.0,
                grid_to_wh=200.0,
                price=0.20,
                grid_from_cost=0.0,
                grid_to_cost=-0.04,
                net_cost=-0.04,
                savings_eur=0.10,
                charge_cost_eur=0.0,
            ),
        ],
        totals={
            "charged_wh": 1000.0,
            "discharged_wh": 500.0,
            "battery_pct_delta_total": -5.0,
            "grid_from_wh": 500.0,
            "grid_to_wh": 300.0,
            "grid_from_cost": 0.15,
            "grid_to_cost": -0.07,
            "net_cost": 0.08,
            "savings_eur": 0.10,
            "charge_cost_eur": 0.30,
        },
    )

    reports[day2] = _build_report(
        day2,
        hours=[
            _hour(
                "00",
                discharged_wh=1000.0,
                battery_start=55.0,
                battery_end=45.0,
                grid_from_wh=200.0,
                grid_to_wh=0.0,
                price=0.25,
                grid_from_cost=0.05,
                grid_to_cost=0.0,
                net_cost=0.05,
                savings_eur=0.25,
                charge_cost_eur=0.0,
            ),
            _hour(
                "01",
                charged_wh=250.0,
                battery_start=45.0,
                battery_end=40.0,
                grid_from_wh=0.0,
                grid_to_wh=50.0,
                price=0.35,
                grid_from_cost=0.0,
                grid_to_cost=-0.0175,
                net_cost=-0.0175,
                savings_eur=0.0,
                charge_cost_eur=0.0875,
            ),
        ],
        totals={
            "charged_wh": 250.0,
            "discharged_wh": 1000.0,
            "battery_pct_delta_total": -15.0,
            "grid_from_wh": 200.0,
            "grid_to_wh": 50.0,
            "grid_from_cost": 0.05,
            "grid_to_cost": -0.0175,
            "net_cost": 0.0325,
            "savings_eur": 0.25,
            "charge_cost_eur": 0.0875,
        },
    )

    reports[day3] = _build_report(
        day3,
        hours=[
            _hour(
                "00",
                battery_start=40.0,
                battery_end=39.0,
                grid_from_wh=0.0,
                grid_to_wh=0.0,
                price=None,
                grid_from_cost=None,
                grid_to_cost=None,
                net_cost=None,
                savings_eur=None,
                charge_cost_eur=None,
            )
        ],
        totals={
            "charged_wh": 0.0,
            "discharged_wh": 0.0,
            "battery_pct_delta_total": -1.0,
            "grid_from_wh": 0.0,
            "grid_to_wh": 0.0,
            "grid_from_cost": None,
            "grid_to_cost": None,
            "net_cost": None,
            "savings_eur": None,
            "charge_cost_eur": None,
        },
        price_file_found=False,
        price_hours_available=0,
    )

    spot_030 = _consumer_to_spot(0.30)
    spot_020 = _consumer_to_spot(0.20)
    spot_035 = _consumer_to_spot(0.35)
    day1_spot_net = (0.5 * 0.30) - ((0.1 * spot_030) + (0.2 * spot_020))
    day2_spot_net = (0.2 * 0.25) - (0.05 * spot_035)
    total_spot_net = round(day1_spot_net + day2_spot_net, 4)
    total_spot_charge = round((1.0 * spot_030) + (0.25 * spot_035), 4)

    with _temporary_reports(reports):
        php_code = (
            '$_SERVER["REQUEST_METHOD"] = "GET";'
            f'$_GET["month"] = "{month}";'
            f'require "{MONTHLY_REPORT_API_FILE.as_posix()}";'
        )
        payload = _run_php_json(["php", "-r", php_code])

    report = payload["report"]
    totals = report["totals"]

    assert payload["success"] is True
    assert payload["requestedMonth"] == month
    assert report["includedDayCount"] == 28
    assert report["savedDayCount"] == 28
    assert report["generatedDayCount"] == 0
    assert report["missingPriceDayCount"] == 1
    assert report["costCoverageDayCount"] == 27
    assert report["isPartialMonth"] is False
    assert report["battery"]["start_pct"] == pytest.approx(60.0)
    assert report["battery"]["end_pct"] == pytest.approx(39.0)
    assert report["battery"]["min_pct"] == pytest.approx(39.0)
    assert report["battery"]["max_pct"] == pytest.approx(65.0)
    assert report["battery"]["range_pct"] == pytest.approx(26.0)

    assert totals["charged_wh"] == pytest.approx(1250.0)
    assert totals["discharged_wh"] == pytest.approx(1500.0)
    assert totals["grid_from_wh"] == pytest.approx(700.0)
    assert totals["grid_to_wh"] == pytest.approx(350.0)
    assert totals["grid_from_cost"] == pytest.approx(0.2)
    assert totals["grid_to_cost"] == pytest.approx(-0.0875)
    assert totals["net_cost"] == pytest.approx(0.1125)
    assert totals["savings_eur"] == pytest.approx(0.35)
    assert totals["charge_cost_eur"] == pytest.approx(0.3875)
    assert totals["spot_net_cost_eur"] == pytest.approx(total_spot_net)
    assert totals["spot_charge_cost_eur"] == pytest.approx(total_spot_charge)
    assert totals["pnl_eur"] == pytest.approx(-0.15)
    assert totals["spot_pnl_eur"] == pytest.approx(round((total_spot_charge - 0.35 + total_spot_net) * -1, 4))

    assert report["days"][0]["spot_net_cost_eur"] == pytest.approx(round(day1_spot_net, 4))
    assert report["days"][1]["spot_net_cost_eur"] == pytest.approx(round(day2_spot_net, 4))
    assert report["days"][2]["spot_net_cost_eur"] is None
    assert report["days"][2]["price_file_found"] is False


def test_monthly_api_current_month_stops_at_today_and_regenerates_only_today(tmp_path: Path):
    now = datetime.now(TZ_NL)
    current_month = now.strftime("%Y-%m")
    today = now.strftime("%Y-%m-%d")
    tomorrow = (now + timedelta(days=1)).strftime("%Y-%m-%d")
    dates = _month_dates(current_month)
    included_dates = [date for date in dates if date <= today]

    reports = {date: _zero_price_report(date) for date in included_dates}
    if tomorrow.startswith(current_month):
        reports[tomorrow] = _zero_price_report(tomorrow)

    fake_python = tmp_path / "fake-python.sh"
    fake_python.write_text("#!/bin/sh\nexit 0\n", encoding="utf-8")
    fake_python.chmod(0o755)

    with _temporary_reports(reports):
        php_code = (
            f'putenv("PYTHON_BIN={fake_python.as_posix()}");'
            '$_SERVER["REQUEST_METHOD"] = "GET";'
            f'$_GET["month"] = "{current_month}";'
            f'require "{MONTHLY_REPORT_API_FILE.as_posix()}";'
        )
        payload = _run_php_json(["php", "-r", php_code])

    report = payload["report"]
    assert report["month"] == current_month
    assert report["includedDayCount"] == now.day
    assert report["generatedDayCount"] == 1
    assert report["savedDayCount"] == max(now.day - 1, 0)
    assert report["lastIncludedDate"] == today
    assert report["isPartialMonth"] is True
    assert report["days"][-1]["date"] == today
    assert all(day["date"] <= today for day in report["days"])
    assert tomorrow not in {day["date"] for day in report["days"]}


def test_monthly_api_rejects_future_month():
    now = datetime.now(TZ_NL)
    next_year = now.year + (1 if now.month == 12 else 0)
    next_month = 1 if now.month == 12 else now.month + 1
    future_month = f"{next_year:04d}-{next_month:02d}"

    php_code = (
        '$_SERVER["REQUEST_METHOD"] = "GET";'
        f'$_GET["month"] = "{future_month}";'
        f'require "{MONTHLY_REPORT_API_FILE.as_posix()}";'
    )
    payload = _run_php_json(["php", "-r", php_code])

    assert payload["success"] is False
    assert "Future months are not supported" in payload["error"]


def test_monthly_report_page_and_js_wiring():
    page_php = MONTHLY_REPORT_PAGE_FILE.read_text(encoding="utf-8")
    monthly_report_js = MONTHLY_REPORT_JS_FILE.read_text(encoding="utf-8")

    assert "<title>Monthly Report</title>" in page_php
    assert 'type="month"' in page_php
    assert "apiUrl: 'api/monthly_report_data.php'" in page_php
    assert 'data-role="net-cost-spot-total"' in page_php
    assert 'data-role="charge-cost-spot-total"' in page_php
    assert 'data-role="pnl-spot-total"' in page_php
    assert 'data-role="monthly-table-body"' in page_php
    assert "<th>Date</th>" in page_php
    assert "Daily table" in page_php
    assert 'assets/css/monthly_report.css' in page_php
    assert 'assets/js/monthly_report.js' in page_php
    assert "function shiftMonth" in monthly_report_js
    assert "api/report_data.php" not in monthly_report_js
    assert "const apiUrl = boot.apiUrl || 'api/monthly_report_data.php';" in monthly_report_js
    assert "function formatKwhFromWh(value)" in monthly_report_js
    assert "function formatEurCents(value)" in monthly_report_js
    assert "setText('charged-total', formatKwhFromWh(Number(totals.charged_wh)));" in monthly_report_js
    assert "setText('discharged-total', formatKwhFromWh(Number(totals.discharged_wh)));" in monthly_report_js
    assert "setText('grid-from-total', formatKwhFromWh(Number(totals.grid_from_wh)));" in monthly_report_js
    assert "setText('grid-to-total', formatKwhFromWh(Number(totals.grid_to_wh)));" in monthly_report_js
    assert "setText('net-cost-total', formatEurCents(netCost));" in monthly_report_js
    assert "setText('savings-total', formatEurCents(savings));" in monthly_report_js
    assert "setText('charge-cost-total', formatEurCents(chargeCost));" in monthly_report_js
    assert "setText('pnl-total', formatEurCents(pnl));" in monthly_report_js
    assert "setText('net-cost-spot-total', Number.isFinite(spotNetCost) ? `Spot ${formatEurCents(spotNetCost)}` : '--');" in monthly_report_js
    assert "setText('charge-cost-spot-total', Number.isFinite(spotChargeCost) ? `Spot ${formatEurCents(spotChargeCost)}` : '--');" in monthly_report_js
    assert "setText('pnl-spot-total', Number.isFinite(spotPnl) ? `Spot ${formatEurCents(spotPnl)}` : '--');" in monthly_report_js
    assert "el.textContent = `Net import ${formatEurCents(value)}`;" in monthly_report_js
    assert "<td class=\"${netCostClass}\">${escapeHtml(formatEur(netCost))}</td>" in monthly_report_js
    assert "<td>${escapeHtml(formatWh(Number(row.charged_wh)))}</td>" in monthly_report_js
    assert "<td>${escapeHtml(formatWh(Number(row.grid_from_wh)))}</td>" in monthly_report_js
    assert "spot_net_cost_eur" in monthly_report_js
    assert "spot_pnl_eur" in monthly_report_js
    assert "payload.report && payload.report.days" in monthly_report_js
