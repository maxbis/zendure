#!/usr/bin/env python3
"""Tests for daily_report/api/pnl_data.php."""

from __future__ import annotations

import json
import subprocess
from contextlib import contextmanager
from pathlib import Path

import pytest


REPO_ROOT = Path(__file__).resolve().parents[2]
MAIN_CONFIG_FILE = REPO_ROOT / "main" / "config" / "config.json"
PNL_API_FILE = REPO_ROOT / "daily_report" / "api" / "pnl_data.php"
DAILY_REPORT_DATA_DIR = REPO_ROOT / "daily_report" / "data"


def _run_php_json(args: list[str]) -> dict:
    proc = subprocess.run(args, capture_output=True, text=True, check=False)
    if proc.returncode != 0:
        raise RuntimeError(f"PHP command failed ({proc.returncode}): {proc.stderr.strip()}")
    raw = proc.stdout.strip()
    if not raw:
        raise RuntimeError("PHP command returned empty output")
    return json.loads(raw)


def _write_json(path: Path, payload: object) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2), encoding="utf-8")


@contextmanager
def _temporary_reports(reports: dict[str, dict[str, object]]) -> Path:
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
        yield DAILY_REPORT_DATA_DIR
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


def _report_path(date: str) -> Path:
    yyyymm = date.replace("-", "")[:6]
    yyyymmdd = date.replace("-", "")
    return DAILY_REPORT_DATA_DIR / yyyymm / f"daily_report_{yyyymmdd}.json"


def _hour(
    hour: str,
    *,
    charged_wh: float = 0.0,
    discharged_wh: float = 0.0,
    grid_from_wh: float | None = 0.0,
    grid_to_wh: float | None = 0.0,
    price: float | None = 0.20,
    grid_from_cost: float | None = 0.0,
    grid_to_cost: float | None = 0.0,
    net_cost: float | None = 0.0,
    savings_eur: float | None = 0.0,
    charge_cost_eur: float | None = 0.0,
) -> dict[str, object]:
    return {
        "hour": hour,
        "charged_wh": charged_wh,
        "discharged_wh": discharged_wh,
        "battery_pct_start": None,
        "battery_pct_end": None,
        "battery_pct_delta": None,
        "grid_from_wh": grid_from_wh,
        "grid_to_wh": grid_to_wh,
        "price_eur_per_kwh": price,
        "grid_from_cost": grid_from_cost,
        "grid_to_cost": grid_to_cost,
        "net_cost": net_cost,
        "savings_eur": savings_eur,
        "charge_cost_eur": charge_cost_eur,
        "is_partial_hour": False,
    }


def _build_report(
    date: str,
    *,
    hours: list[dict[str, object]],
    totals: dict[str, object],
    price_file_found: bool = True,
    price_hours_available: int = 24,
) -> dict[str, object]:
    return {
        "date": date,
        "timezone": "Europe/Amsterdam",
        "day_start_ts": 0,
        "day_end_ts": 0,
        "analysis_end_ts": 0,
        "is_partial_day": False,
        "price_file_found": price_file_found,
        "price_file_path": f"/fake/price{date.replace('-', '')}.json" if price_file_found else None,
        "price_hours_available": price_hours_available,
        "hours": hours,
        "totals": totals,
    }


def _current_price_config() -> dict[str, float | int]:
    config = json.loads(MAIN_CONFIG_FILE.read_text(encoding="utf-8"))
    return config.get("priceConversion", {
        "supplierMarkupEurPerKwh": 0.0219,
        "energyTaxEurPerKwh": 0.0898,
        "vatMultiplier": 1.21,
        "consumerPrecision": 4,
        "spotPrecision": 6,
    })


def _consumer_to_spot(value: float) -> float:
    config = _current_price_config()
    return round(
        (value / float(config["vatMultiplier"]))
        - float(config["supplierMarkupEurPerKwh"])
        - float(config["energyTaxEurPerKwh"]),
        int(config["spotPrecision"]),
    )


def _invoke_pnl_api(data_dir: Path, *, date: str | None, method: str = "GET", n: str | None = None) -> dict:
    statements = [f'putenv("DAILY_REPORT_DATA_DIR={data_dir.as_posix()}");', f'$_SERVER["REQUEST_METHOD"] = "{method}";']
    if date is not None:
        statements.append(f'$_GET["date"] = "{date}";')
    if n is not None:
        statements.append(f'$_GET["n"] = "{n}";')
    statements.append(f'require "{PNL_API_FILE.as_posix()}";')
    return _run_php_json(["php", "-r", "".join(statements)])


def test_pnl_api_returns_single_day_when_n_is_omitted():
    date = "2026-05-04"
    report = _build_report(
        date,
        hours=[
            _hour(
                "00",
                charged_wh=1000.0,
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
            "grid_from_wh": 500.0,
            "grid_to_wh": 300.0,
            "grid_from_cost": 0.15,
            "grid_to_cost": -0.07,
            "net_cost": 0.08,
            "savings_eur": 0.10,
            "charge_cost_eur": 0.30,
        },
    )

    with _temporary_reports({date: report}) as data_dir:
        payload = _invoke_pnl_api(data_dir, date=date)

    spot_030 = _consumer_to_spot(0.30)
    spot_020 = _consumer_to_spot(0.20)
    spot_net = (0.5 * 0.30) - ((0.1 * spot_030) + (0.2 * spot_020))
    spot_charge = 1.0 * spot_030

    assert payload["success"] is True
    assert payload["requestedDate"] == date
    assert payload["requestedDayCount"] == 1
    assert payload["startDate"] == date
    assert payload["endDate"] == date
    assert len(payload["days"]) == 1

    day = payload["days"][0]
    assert day["date"] == date
    assert day["source"] == "saved"
    assert day["savedAt"]
    assert day["price_file_found"] is True
    assert day["price_hours_available"] == 24
    assert day["net_cost"] == pytest.approx(0.08)
    assert day["savings_eur"] == pytest.approx(0.10)
    assert day["charge_cost_eur"] == pytest.approx(0.30)
    assert day["spot_net_cost_eur"] == pytest.approx(round(spot_net, 4))
    assert day["spot_charge_cost_eur"] == pytest.approx(round(spot_charge, 4))
    assert day["pnl_eur"] == pytest.approx(-0.28)
    assert day["spot_pnl_eur"] == pytest.approx(round((spot_charge - 0.10 + spot_net) * -1, 4))


def test_pnl_api_returns_requested_range_oldest_to_newest():
    day1 = "2026-05-04"
    day2 = "2026-05-05"
    reports = {
        day1: _build_report(
            day1,
            hours=[_hour("00", charged_wh=500.0, price=0.24, charge_cost_eur=0.12)],
            totals={
                "charged_wh": 500.0,
                "discharged_wh": 0.0,
                "grid_from_wh": 0.0,
                "grid_to_wh": 0.0,
                "grid_from_cost": 0.0,
                "grid_to_cost": 0.0,
                "net_cost": 0.0,
                "savings_eur": 0.0,
                "charge_cost_eur": 0.12,
            },
        ),
        day2: _build_report(
            day2,
            hours=[_hour("00", discharged_wh=400.0, price=0.25, savings_eur=0.10)],
            totals={
                "charged_wh": 0.0,
                "discharged_wh": 400.0,
                "grid_from_wh": 0.0,
                "grid_to_wh": 0.0,
                "grid_from_cost": 0.0,
                "grid_to_cost": 0.0,
                "net_cost": 0.0,
                "savings_eur": 0.10,
                "charge_cost_eur": 0.0,
            },
        ),
    }

    with _temporary_reports(reports) as data_dir:
        payload = _invoke_pnl_api(data_dir, date=day2, n="2")

    assert payload["success"] is True
    assert payload["requestedDate"] == day2
    assert payload["requestedDayCount"] == 2
    assert payload["startDate"] == day1
    assert payload["endDate"] == day2
    assert [day["date"] for day in payload["days"]] == [day1, day2]
    assert payload["days"][0]["pnl_eur"] == pytest.approx(-0.12)
    assert payload["days"][1]["pnl_eur"] == pytest.approx(0.10)


def test_pnl_api_missing_price_data_keeps_cost_and_pnl_fields_null():
    date = "2026-05-04"
    report = _build_report(
        date,
        hours=[_hour("00", price=None, grid_from_cost=None, grid_to_cost=None, net_cost=None, savings_eur=None, charge_cost_eur=None)],
        totals={
            "charged_wh": 0.0,
            "discharged_wh": 0.0,
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

    with _temporary_reports({date: report}) as data_dir:
        payload = _invoke_pnl_api(data_dir, date=date)

    day = payload["days"][0]
    assert day["price_file_found"] is False
    assert day["price_hours_available"] == 0
    assert day["net_cost"] is None
    assert day["spot_net_cost_eur"] is None
    assert day["savings_eur"] is None
    assert day["charge_cost_eur"] is None
    assert day["spot_charge_cost_eur"] is None
    assert day["pnl_eur"] is None
    assert day["spot_pnl_eur"] is None


@pytest.mark.parametrize("n_value", ["0", "-1", "", "abc"])
def test_pnl_api_rejects_invalid_n_values(n_value: str):
    with _temporary_reports({}) as data_dir:
        payload = _invoke_pnl_api(data_dir, date="2026-05-04", n=n_value)

    assert payload["success"] is False
    assert "Invalid n" in payload["error"]


def test_pnl_api_rejects_invalid_date():
    with _temporary_reports({}) as data_dir:
        payload = _invoke_pnl_api(data_dir, date="2026/05/04")

    assert payload["success"] is False
    assert "Invalid date" in payload["error"]


def test_pnl_api_rejects_missing_date():
    with _temporary_reports({}) as data_dir:
        payload = _invoke_pnl_api(data_dir, date=None)

    assert payload["success"] is False
    assert "Missing date" in payload["error"]


def test_pnl_api_rejects_non_get_methods():
    with _temporary_reports({}) as data_dir:
        payload = _invoke_pnl_api(data_dir, date="2026-05-04", method="POST")

    assert payload["success"] is False
    assert "Method not allowed" in payload["error"]
