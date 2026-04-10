#!/usr/bin/env python3
"""Tests for daily_report/tools/hourly_daily_grid_battery_report.py."""

from __future__ import annotations

import json
import os
import sqlite3
import sys
import shutil
import uuid
from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo

import pytest


REPO_ROOT = Path(__file__).resolve().parents[2]
DAILY_REPORT_TOOLS_DIR = REPO_ROOT / "daily_report" / "tools"
LEGACY_TOOLS_DIR = REPO_ROOT / "tools"

for _tools_path in (DAILY_REPORT_TOOLS_DIR, LEGACY_TOOLS_DIR):
    if str(_tools_path) not in sys.path:
        sys.path.insert(0, str(_tools_path))

import hourly_daily_grid_battery_report as report  # type: ignore
import wh_per_hour_queries as whq  # type: ignore


TZ = ZoneInfo(report.DEFAULT_TIMEZONE)


def _ts(year: int, month: int, day: int, hour: int, minute: int = 0, second: int = 0) -> int:
    return int(datetime(year, month, day, hour, minute, second, tzinfo=TZ).timestamp())


def _row(
    row_id: int,
    event_type: str,
    ts: int,
    *,
    new_value: object | None = None,
    electric_level: int | None = None,
    total_act_x100: int | None = None,
    total_act_ret_x100: int | None = None,
) -> report.StatusRow:
    return report.StatusRow(
        id=row_id,
        event_type=event_type,
        old_value=None,
        new_value=new_value,
        p1_total_power=None,
        electric_level=electric_level,
        timestamp=ts,
        total_act_x100=total_act_x100,
        total_act_ret_x100=total_act_ret_x100,
    )


def _price_map(**overrides: float | None) -> dict[str, float | None]:
    prices = {f"{hour:02d}": None for hour in range(24)}
    prices.update(overrides)
    return prices


def _workspace_temp_dir() -> Path:
    path = REPO_ROOT / "automate" / "data" / f"test_price_root_{uuid.uuid4().hex}"
    path.mkdir(parents=True, exist_ok=True)
    return path


def test_interpolate_boundary_value_exact_linear_and_one_sided():
    samples = [
        report.NumericSample(ts=100, value=10.0),
        report.NumericSample(ts=200, value=30.0),
    ]

    assert report.interpolate_boundary_value(samples, 100) == pytest.approx(10.0)
    assert report.interpolate_boundary_value(samples, 150) == pytest.approx(20.0)
    assert report.interpolate_boundary_value(samples, 240, fallback_seconds=50) == pytest.approx(30.0)
    assert report.interpolate_boundary_value(samples, 260, fallback_seconds=50) is None


def test_build_daily_report_full_day_metrics_and_totals():
    day_start = datetime(2025, 1, 1, 0, 0, 0, tzinfo=TZ)
    rows = [
        _row(1, "change", _ts(2025, 1, 1, 0, 0), new_value="600", electric_level=50, total_act_x100=100000, total_act_ret_x100=50000),
        _row(2, "Rescan", _ts(2025, 1, 1, 1, 30), electric_level=62, total_act_x100=101500, total_act_ret_x100=50300),
        _row(3, "change", _ts(2025, 1, 1, 0, 30), new_value="0"),
        _row(4, "change", _ts(2025, 1, 1, 1, 15), new_value="-300"),
        _row(5, "change", _ts(2025, 1, 1, 2, 0), new_value="0", electric_level=58, total_act_x100=102100, total_act_ret_x100=50600),
        _row(6, "Rescan", _ts(2025, 1, 2, 0, 0), electric_level=58, total_act_x100=102100, total_act_ret_x100=50600),
    ]

    report_data = report.build_daily_report(
        rows,
        target_day_start=day_start,
        analysis_end_ts=_ts(2025, 1, 2, 0, 0),
        tz=TZ,
        prices_by_hour=_price_map(**{"00": 0.2, "01": 0.3, "02": 0.4}),
        price_file_path=Path("D:/fake/price20250101.json"),
        price_file_found=True,
    )

    hour00 = report_data["hours"][0]
    hour01 = report_data["hours"][1]
    hour02 = report_data["hours"][2]

    assert hour00["charged_wh"] == pytest.approx(300.0)
    assert hour00["discharged_wh"] == pytest.approx(0.0)
    assert hour00["battery_pct_start"] == pytest.approx(50.0)
    assert hour00["battery_pct_end"] == pytest.approx(58.0)
    assert hour00["battery_pct_delta"] == pytest.approx(8.0)
    assert hour00["grid_from_wh"] == pytest.approx(10.0)
    assert hour00["grid_to_wh"] == pytest.approx(2.0)
    assert hour00["price_eur_per_kwh"] == pytest.approx(0.2)
    assert hour00["grid_from_cost"] == pytest.approx(0.002)
    assert hour00["grid_to_cost"] == pytest.approx(-0.0004)
    assert hour00["net_cost"] == pytest.approx(0.0016)
    assert hour00["savings_eur"] == pytest.approx(0.0)
    assert hour00["charge_cost_eur"] == pytest.approx(0.06)

    assert hour01["charged_wh"] == pytest.approx(0.0)
    assert hour01["discharged_wh"] == pytest.approx(225.0)
    assert hour01["battery_pct_start"] == pytest.approx(58.0)
    assert hour01["battery_pct_end"] == pytest.approx(58.0)
    assert hour01["battery_pct_delta"] == pytest.approx(0.0)
    assert hour01["grid_from_wh"] == pytest.approx(11.0)
    assert hour01["grid_to_wh"] == pytest.approx(4.0)
    assert hour01["price_eur_per_kwh"] == pytest.approx(0.3)
    assert hour01["grid_from_cost"] == pytest.approx(0.0033)
    assert hour01["grid_to_cost"] == pytest.approx(-0.0012)
    assert hour01["net_cost"] == pytest.approx(0.0021)
    assert hour01["savings_eur"] == pytest.approx(0.0675)
    assert hour01["charge_cost_eur"] == pytest.approx(0.0)

    assert hour02["charged_wh"] == pytest.approx(0.0)
    assert hour02["discharged_wh"] == pytest.approx(0.0)
    assert hour02["battery_pct_start"] == pytest.approx(58.0)
    assert hour02["price_eur_per_kwh"] == pytest.approx(0.4)
    assert hour02["grid_from_cost"] == pytest.approx(0.0)
    assert hour02["grid_to_cost"] == pytest.approx(0.0)
    assert hour02["net_cost"] == pytest.approx(0.0)
    assert hour02["savings_eur"] == pytest.approx(0.0)
    assert hour02["charge_cost_eur"] == pytest.approx(0.0)

    assert report_data["price_file_found"] is True
    assert report_data["price_hours_available"] == 3
    assert report_data["totals"]["charged_wh"] == pytest.approx(300.0)
    assert report_data["totals"]["discharged_wh"] == pytest.approx(225.0)
    assert report_data["totals"]["battery_pct_delta_total"] == pytest.approx(8.0)
    assert report_data["totals"]["grid_from_wh"] == pytest.approx(21.0)
    assert report_data["totals"]["grid_to_wh"] == pytest.approx(6.0)
    assert report_data["totals"]["grid_from_cost"] == pytest.approx(0.0053)
    assert report_data["totals"]["grid_to_cost"] == pytest.approx(-0.0016)
    assert report_data["totals"]["net_cost"] == pytest.approx(0.0037)
    assert report_data["totals"]["savings_eur"] == pytest.approx(0.0675)
    assert report_data["totals"]["charge_cost_eur"] == pytest.approx(0.06)


def test_build_daily_report_partial_day_future_hours_are_empty():
    day_start = datetime(2025, 1, 1, 0, 0, 0, tzinfo=TZ)
    rows = [
        _row(1, "change", _ts(2025, 1, 1, 0, 0), new_value="600", electric_level=50, total_act_x100=100000, total_act_ret_x100=50000),
        _row(2, "Rescan", _ts(2025, 1, 1, 0, 30), electric_level=55, total_act_x100=100600, total_act_ret_x100=50020),
    ]

    report_data = report.build_daily_report(
        rows,
        target_day_start=day_start,
        analysis_end_ts=_ts(2025, 1, 1, 0, 30),
        tz=TZ,
        prices_by_hour=_price_map(**{"00": 0.2, "01": 0.4}),
        price_file_path=Path("D:/fake/price20250101.json"),
        price_file_found=True,
    )

    hour00 = report_data["hours"][0]
    hour01 = report_data["hours"][1]

    assert report_data["is_partial_day"] is True
    assert hour00["charged_wh"] == pytest.approx(300.0)
    assert hour00["battery_pct_start"] == pytest.approx(50.0)
    assert hour00["battery_pct_end"] == pytest.approx(55.0)
    assert hour00["price_eur_per_kwh"] == pytest.approx(0.2)
    assert hour00["grid_from_cost"] == pytest.approx(0.0012)
    assert hour00["grid_to_cost"] == pytest.approx(0.0)
    assert hour00["net_cost"] == pytest.approx(0.0012)
    assert hour00["savings_eur"] == pytest.approx(0.0)
    assert hour00["charge_cost_eur"] == pytest.approx(0.06)
    assert hour00["is_partial_hour"] is True

    assert hour01["charged_wh"] == 0.0
    assert hour01["discharged_wh"] == 0.0
    assert hour01["battery_pct_start"] is None
    assert hour01["battery_pct_end"] is None
    assert hour01["grid_from_wh"] is None
    assert hour01["grid_to_wh"] is None
    assert hour01["price_eur_per_kwh"] == pytest.approx(0.4)
    assert hour01["grid_from_cost"] is None
    assert hour01["grid_to_cost"] is None
    assert hour01["net_cost"] is None
    assert hour01["savings_eur"] is None
    assert hour01["charge_cost_eur"] is None

    assert report_data["totals"]["charged_wh"] == pytest.approx(300.0)
    assert report_data["totals"]["battery_pct_delta_total"] == pytest.approx(5.0)
    assert report_data["totals"]["grid_from_cost"] == pytest.approx(0.0012)
    assert report_data["totals"]["grid_to_cost"] == pytest.approx(0.0)
    assert report_data["totals"]["net_cost"] == pytest.approx(0.0012)
    assert report_data["totals"]["savings_eur"] == pytest.approx(0.0)
    assert report_data["totals"]["charge_cost_eur"] == pytest.approx(0.06)


def test_charged_and_discharged_match_existing_wh_query_logic():
    db_path = REPO_ROOT / "automate" / "data" / f"test_status_updates_{uuid.uuid4().hex}.db"
    try:
        with sqlite3.connect(db_path) as conn:
            conn.executescript(
                """
                CREATE TABLE status_updates (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    type TEXT NOT NULL,
                    old_value TEXT,
                    new_value TEXT,
                    p1_total_power INTEGER,
                    electric_level INTEGER,
                    total_act_x100 INTEGER,
                    total_act_ret_x100 INTEGER,
                    timestamp INTEGER NOT NULL
                );
                """
            )
            conn.execute(
                "INSERT INTO status_updates (type, new_value, electric_level, timestamp) VALUES (?, ?, ?, ?)",
                ("change", json.dumps(600), 50, _ts(2025, 1, 1, 0, 0)),
            )
            conn.execute(
                "INSERT INTO status_updates (type, new_value, electric_level, timestamp) VALUES (?, ?, ?, ?)",
                ("change", json.dumps(0), 55, _ts(2025, 1, 1, 0, 30)),
            )
            conn.execute(
                "INSERT INTO status_updates (type, new_value, electric_level, timestamp) VALUES (?, ?, ?, ?)",
                ("change", json.dumps(-300), 56, _ts(2025, 1, 1, 1, 15)),
            )
            conn.execute(
                "INSERT INTO status_updates (type, new_value, electric_level, timestamp) VALUES (?, ?, ?, ?)",
                ("change", json.dumps(0), 58, _ts(2025, 1, 1, 2, 0)),
            )
            conn.commit()

        rows = [
            _row(1, "change", _ts(2025, 1, 1, 0, 0), new_value="600", electric_level=50),
            _row(2, "change", _ts(2025, 1, 1, 0, 30), new_value="0", electric_level=55),
            _row(3, "change", _ts(2025, 1, 1, 1, 15), new_value="-300", electric_level=56),
            _row(4, "change", _ts(2025, 1, 1, 2, 0), new_value="0", electric_level=58),
        ]
        day_start = datetime(2025, 1, 1, 0, 0, 0, tzinfo=TZ)
        report_data = report.build_daily_report(
            rows,
            target_day_start=day_start,
            analysis_end_ts=_ts(2025, 1, 2, 0, 0),
            tz=TZ,
        )
        existing = whq.compute_wh_per_hour(str(db_path), now=_ts(2025, 1, 2, 0, 0), days_back=1)
        day_key = "2025-01-01"

        assert report_data["hours"][0]["charged_wh"] == pytest.approx(existing[day_key][0]["charged_wh"])
        assert report_data["hours"][1]["discharged_wh"] == pytest.approx(existing[day_key][1]["discharged_wh"])
    finally:
        if db_path.exists():
            try:
                os.remove(db_path)
            except PermissionError:
                pass


def test_load_price_map_for_day_reads_existing_file_and_invalid_hours():
    target_day_start = datetime(2025, 1, 1, 0, 0, 0, tzinfo=TZ)
    price_root = _workspace_temp_dir() / "price"
    try:
        price_dir = price_root / "202501"
        price_dir.mkdir(parents=True)
        price_path = price_dir / "price20250101.json"
        price_path.write_text(json.dumps({"00": 0.25, "01": "0.30", "02": "bad"}), encoding="utf-8")

        prices_by_hour, resolved_path, found = report.load_price_map_for_day(
            target_day_start,
            price_root=price_root,
        )

        assert found is True
        assert resolved_path == price_path
        assert prices_by_hour is not None
        assert prices_by_hour["00"] == pytest.approx(0.25)
        assert prices_by_hour["01"] == pytest.approx(0.30)
        assert prices_by_hour["02"] is None
        assert prices_by_hour["03"] is None
    finally:
        shutil.rmtree(price_root.parent, ignore_errors=True)


def test_load_price_map_for_day_missing_file_returns_unavailable():
    target_day_start = datetime(2025, 1, 1, 0, 0, 0, tzinfo=TZ)
    price_root = _workspace_temp_dir() / "price"
    try:
        prices_by_hour, resolved_path, found = report.load_price_map_for_day(
            target_day_start,
            price_root=price_root,
        )

        assert found is False
        assert prices_by_hour is None
        assert resolved_path == (price_root / "202501" / "price20250101.json")
    finally:
        shutil.rmtree(price_root.parent, ignore_errors=True)


def test_build_daily_report_missing_price_file_keeps_costs_null():
    day_start = datetime(2025, 1, 1, 0, 0, 0, tzinfo=TZ)
    rows = [
        _row(1, "change", _ts(2025, 1, 1, 0, 0), new_value="600", electric_level=50, total_act_x100=100000, total_act_ret_x100=50000),
        _row(2, "Rescan", _ts(2025, 1, 1, 0, 30), electric_level=55, total_act_x100=100600, total_act_ret_x100=50020),
    ]

    report_data = report.build_daily_report(
        rows,
        target_day_start=day_start,
        analysis_end_ts=_ts(2025, 1, 1, 0, 30),
        tz=TZ,
        prices_by_hour=None,
        price_file_path=Path("D:/fake/missing-price20250101.json"),
        price_file_found=False,
    )

    assert report_data["price_file_found"] is False
    assert report_data["price_hours_available"] == 0
    assert report_data["hours"][0]["price_eur_per_kwh"] is None
    assert report_data["hours"][0]["grid_from_cost"] is None
    assert report_data["hours"][0]["grid_to_cost"] is None
    assert report_data["hours"][0]["net_cost"] is None
    assert report_data["hours"][0]["savings_eur"] is None
    assert report_data["hours"][0]["charge_cost_eur"] is None
    assert report_data["totals"]["grid_from_cost"] is None
    assert report_data["totals"]["grid_to_cost"] is None
    assert report_data["totals"]["net_cost"] is None
    assert report_data["totals"]["savings_eur"] is None
    assert report_data["totals"]["charge_cost_eur"] is None


def test_build_daily_report_falls_back_to_observed_electric_level_within_hour():
    day_start = datetime(2025, 1, 1, 0, 0, 0, tzinfo=TZ)
    rows = [
        _row(1, "change", _ts(2025, 1, 1, 0, 5), new_value="0", electric_level=47),
        _row(2, "Rescan", _ts(2025, 1, 1, 0, 55), electric_level=49),
    ]

    report_data = report.build_daily_report(
        rows,
        target_day_start=day_start,
        analysis_end_ts=_ts(2025, 1, 1, 0, 55),
        tz=TZ,
        prices_by_hour=None,
        price_file_found=False,
    )

    hour00 = report_data["hours"][0]
    assert hour00["battery_pct_start"] == pytest.approx(47.0)
    assert hour00["battery_pct_end"] == pytest.approx(49.0)
    assert hour00["battery_pct_delta"] == pytest.approx(2.0)
    assert report_data["totals"]["battery_pct_delta_total"] == pytest.approx(2.0)


def test_build_saved_report_path_and_save_report_json():
    target_day_start = datetime(2025, 1, 2, 0, 0, 0, tzinfo=TZ)
    data_root = REPO_ROOT / "automate" / "data" / f"test_daily_report_data_{uuid.uuid4().hex}"
    try:
        path = report.build_saved_report_path(target_day_start, data_root=data_root)
        assert path == data_root / "202501" / "daily_report_20250102.json"

        payload = {"date": "2025-01-02", "hours": [], "totals": {"net_cost": 1.23}}
        saved = report.save_report_json(payload, path)
        assert saved == path
        assert path.exists()
        loaded = json.loads(path.read_text(encoding="utf-8"))
        assert loaded == payload
    finally:
        shutil.rmtree(data_root, ignore_errors=True)
