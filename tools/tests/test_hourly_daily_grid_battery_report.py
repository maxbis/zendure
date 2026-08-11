#!/usr/bin/env python3
"""Tests for daily_report/tools/hourly_daily_grid_battery_report.py."""

from __future__ import annotations

import json
import os
import sqlite3
import sys
import shutil
import uuid
from types import SimpleNamespace
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
import update_hourly_report_inputs as hourly_inputs  # type: ignore
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


def test_normalize_monotonic_counter_samples_stitches_resets():
    samples = [
        report.NumericSample(ts=0, value=100.0),
        report.NumericSample(ts=1, value=110.0),
        report.NumericSample(ts=2, value=120.0),
        report.NumericSample(ts=3, value=0.0),
        report.NumericSample(ts=4, value=10.0),
        report.NumericSample(ts=5, value=20.0),
    ]

    normalized = report._normalize_monotonic_counter_samples(samples)

    assert [sample.value for sample in normalized] == pytest.approx([100.0, 110.0, 120.0, 120.0, 130.0, 140.0])


def test_normalize_monotonic_counter_samples_stitches_multiple_resets():
    samples = [
        report.NumericSample(ts=0, value=100.0),
        report.NumericSample(ts=1, value=110.0),
        report.NumericSample(ts=2, value=5.0),
        report.NumericSample(ts=3, value=15.0),
        report.NumericSample(ts=4, value=0.0),
        report.NumericSample(ts=5, value=10.0),
    ]

    normalized = report._normalize_monotonic_counter_samples(samples)

    assert [sample.value for sample in normalized] == pytest.approx([100.0, 110.0, 110.0, 120.0, 120.0, 130.0])


def test_battery_flow_pnl_splits_energy_and_uses_integer_millieuros():
    start = _ts(2025, 1, 1, 0, 0)
    middle = _ts(2025, 1, 1, 0, 30)
    end = _ts(2025, 1, 1, 1, 0)

    values = report.build_battery_flow_pnl_window(
        power_points=[(start, 1000.0), (middle, -1000.0), (end, 0.0)],
        grid_from_samples=[
            report.NumericSample(start, 0.0),
            report.NumericSample(middle, 30000.0),
            report.NumericSample(end, 30000.0),
        ],
        grid_to_samples=[
            report.NumericSample(start, 0.0),
            report.NumericSample(middle, 0.0),
            report.NumericSample(end, 20000.0),
        ],
        start_ts=start,
        end_ts=end,
        consumer_eur_per_kwh=0.30,
        spot_eur_per_kwh=0.10,
        estimated_home_load_wh=300.0,
    )

    assert values == {
        "battery_charge_grid_wh": 300,
        "battery_charge_surplus_wh": 200,
        "battery_discharge_home_wh": 300,
        "battery_discharge_export_wh": 200,
        "battery_charge_cost_milli_eur": 110,
        "battery_home_savings_milli_eur": 90,
        "battery_export_revenue_milli_eur": 20,
        "battery_flow_pnl_milli_eur": 0,
        "battery_pnl_status": "complete",
        "battery_pnl_method_version": 2,
    }


def test_battery_flow_pnl_negative_spot_rounds_half_away_from_zero():
    start = _ts(2025, 1, 1, 0, 0)
    end = start + 18  # 1000 W for 18 seconds = 5 Wh.
    values = report.build_battery_flow_pnl_window(
        power_points=[(start, 1000.0)],
        grid_from_samples=[report.NumericSample(start, 0.0), report.NumericSample(end, 0.0)],
        grid_to_samples=[report.NumericSample(start, 0.0), report.NumericSample(end, 0.0)],
        start_ts=start,
        end_ts=end,
        consumer_eur_per_kwh=0.30,
        spot_eur_per_kwh=-0.10,
    )

    assert values["battery_charge_surplus_wh"] == 5
    assert values["battery_charge_cost_milli_eur"] == -1
    assert values["battery_flow_pnl_milli_eur"] == 1


def test_battery_flow_pnl_allocates_discharge_home_first():
    start = _ts(2025, 1, 1, 20, 0)
    end = start + 3600

    values = report.build_battery_flow_pnl_window(
        power_points=[(start, -999.0)],
        grid_from_samples=[],
        grid_to_samples=[],
        start_ts=start,
        end_ts=end,
        consumer_eur_per_kwh=0.422,
        spot_eur_per_kwh=0.2371,
        estimated_home_load_wh=101.0,
    )

    assert values["battery_discharge_home_wh"] == 101
    assert values["battery_discharge_export_wh"] == 898
    assert values["battery_home_savings_milli_eur"] == 43
    assert values["battery_export_revenue_milli_eur"] == 213
    assert values["battery_flow_pnl_milli_eur"] == 256
    assert values["battery_pnl_method_version"] == 2


def test_battery_flow_pnl_requires_home_load_only_during_discharge():
    start = _ts(2025, 1, 1, 0, 0)
    end = start + 3600

    values = report.build_battery_flow_pnl_window(
        power_points=[(start, -100.0)],
        grid_from_samples=[],
        grid_to_samples=[],
        start_ts=start,
        end_ts=end,
        consumer_eur_per_kwh=0.30,
        spot_eur_per_kwh=0.10,
    )

    assert values["battery_pnl_status"] == "missing_home_load"
    assert values["battery_discharge_home_wh"] is None
    assert values["battery_flow_pnl_milli_eur"] is None


def test_battery_flow_pnl_reports_missing_inputs_only_when_used():
    start = _ts(2025, 1, 1, 0, 0)
    end = start + 3600

    missing_counter = report.build_battery_flow_pnl_window(
        power_points=[(start, -100.0)],
        grid_from_samples=[],
        grid_to_samples=[],
        start_ts=start,
        end_ts=end,
        consumer_eur_per_kwh=0.30,
        spot_eur_per_kwh=0.10,
    )
    assert missing_counter["battery_pnl_status"] == "missing_home_load"

    idle = report.build_battery_flow_pnl_window(
        power_points=[(start, 0.0)],
        grid_from_samples=[],
        grid_to_samples=[],
        start_ts=start,
        end_ts=end,
        consumer_eur_per_kwh=None,
        spot_eur_per_kwh=None,
    )
    assert idle["battery_pnl_status"] == "complete"
    assert idle["battery_flow_pnl_milli_eur"] == 0


def test_battery_flow_pnl_rejects_material_existing_energy_mismatch():
    start = _ts(2025, 1, 1, 0, 0)
    end = start + 3600
    values = report.build_battery_flow_pnl_window(
        power_points=[(start, -100.0)],
        grid_from_samples=[report.NumericSample(start, 0.0), report.NumericSample(end, 0.0)],
        grid_to_samples=[report.NumericSample(start, 0.0), report.NumericSample(end, 0.0)],
        start_ts=start,
        end_ts=end,
        consumer_eur_per_kwh=0.30,
        spot_eur_per_kwh=0.10,
        expected_charged_wh=0.0,
        expected_discharged_wh=50.0,
    )

    assert values["battery_pnl_status"] == "energy_total_mismatch"
    assert values["battery_flow_pnl_milli_eur"] is None


def test_round_split_reconciles_to_rounded_total():
    primary, remainder = report._round_split(21.2, 10.6)

    assert primary == 11
    assert remainder == 10
    assert primary + remainder == 21


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


def test_hourly_report_inputs_match_daily_report_hourly_metrics():
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
    )
    aggregate_rows = hourly_inputs.build_hourly_input_rows(
        rows,
        target_day_start=day_start,
        analysis_end_ts=_ts(2025, 1, 2, 0, 0),
        tz=TZ,
        prices_by_hour={
            "00": {
                "consumer_eur_per_kwh": 0.2,
                "spot_eur_per_kwh": 0.07,
                "price_source": "entsoe_v6",
            },
            "01": {
                "consumer_eur_per_kwh": 0.3,
                "spot_eur_per_kwh": 0.15,
                "price_source": "entsoe_v6",
            },
        },
        computed_at=datetime(2025, 1, 2, 0, 0, 0, tzinfo=ZoneInfo("UTC")),
    )

    for hour in range(3):
        report_hour = report_data["hours"][hour]
        aggregate_hour = aggregate_rows[hour]
        assert aggregate_hour["charged_wh"] == pytest.approx(report_hour["charged_wh"])
        assert aggregate_hour["discharged_wh"] == pytest.approx(report_hour["discharged_wh"])
        assert aggregate_hour["battery_pct_start"] == pytest.approx(report_hour["battery_pct_start"])
        assert aggregate_hour["battery_pct_end"] == pytest.approx(report_hour["battery_pct_end"])
        assert aggregate_hour["battery_pct_delta"] == pytest.approx(report_hour["battery_pct_delta"])
        assert aggregate_hour["grid_from_wh"] == pytest.approx(report_hour["grid_from_wh"])
        assert aggregate_hour["grid_to_wh"] == pytest.approx(report_hour["grid_to_wh"])

    assert aggregate_rows[0]["source_min_id"] == 1
    assert aggregate_rows[0]["source_max_id"] == 3
    assert aggregate_rows[0]["source_rows"] == 2
    assert aggregate_rows[0]["consumer_eur_per_kwh"] == pytest.approx(0.2)
    assert aggregate_rows[0]["spot_eur_per_kwh"] == pytest.approx(0.07)
    assert aggregate_rows[0]["price_source"] == "entsoe_v6"
    assert aggregate_rows[2]["consumer_eur_per_kwh"] is None
    assert aggregate_rows[2]["spot_eur_per_kwh"] is None
    assert aggregate_rows[2]["price_source"] is None
    assert aggregate_rows[23]["source_rows"] == 1


def test_hourly_report_inputs_derive_home_load_and_apply_home_first_pnl():
    day_start = datetime(2025, 1, 1, 0, 0, 0, tzinfo=TZ)
    rows = [
        _row(1, "change", _ts(2025, 1, 1, 0, 0), new_value="-100", total_act_x100=0, total_act_ret_x100=0),
        _row(2, "Rescan", _ts(2025, 1, 1, 1, 0), total_act_x100=0, total_act_ret_x100=0),
    ]

    aggregate_rows = hourly_inputs.build_hourly_input_rows(
        rows,
        target_day_start=day_start,
        analysis_end_ts=_ts(2025, 1, 1, 1, 0),
        tz=TZ,
        production_by_hour={"00": 0.0},
        prices_by_hour={
            "00": {
                "consumer_eur_per_kwh": 0.42,
                "spot_eur_per_kwh": 0.24,
                "price_source": "test",
            }
        },
    )

    assert aggregate_rows[0]["estimated_home_load_wh"] == 100
    assert aggregate_rows[0]["battery_discharge_home_wh"] == 100
    assert aggregate_rows[0]["battery_discharge_export_wh"] == 0
    assert aggregate_rows[0]["battery_home_savings_milli_eur"] == 42
    assert aggregate_rows[0]["battery_pnl_status"] == "complete"
    assert aggregate_rows[0]["battery_pnl_method_version"] == 2


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


def test_hourly_report_inputs_partial_day_future_hours_have_null_nullable_values():
    day_start = datetime(2025, 1, 1, 0, 0, 0, tzinfo=TZ)
    rows = [
        _row(1, "change", _ts(2025, 1, 1, 0, 0), new_value="600", electric_level=50, total_act_x100=100000, total_act_ret_x100=50000),
        _row(2, "Rescan", _ts(2025, 1, 1, 0, 30), electric_level=55, total_act_x100=100600, total_act_ret_x100=50020),
    ]

    aggregate_rows = hourly_inputs.build_hourly_input_rows(
        rows,
        target_day_start=day_start,
        analysis_end_ts=_ts(2025, 1, 1, 0, 30),
        tz=TZ,
    )

    assert aggregate_rows[0]["charged_wh"] == pytest.approx(300.0)
    assert aggregate_rows[0]["battery_pct_start"] == pytest.approx(50.0)
    assert aggregate_rows[0]["battery_pct_end"] == pytest.approx(55.0)
    assert aggregate_rows[1]["charged_wh"] == 0.0
    assert aggregate_rows[1]["discharged_wh"] == 0.0
    assert aggregate_rows[1]["battery_pct_start"] is None
    assert aggregate_rows[1]["battery_pct_end"] is None
    assert aggregate_rows[1]["grid_from_wh"] is None
    assert aggregate_rows[1]["grid_to_wh"] is None
    assert aggregate_rows[1]["source_rows"] == 0


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


def test_price_rows_to_hour_map_keeps_missing_hours_null():
    prices_by_hour, loaded = report._price_rows_to_hour_map([
        {"local_hour": 0, "consumer_eur_per_kwh": "0.25"},
        {"local_hour": "1", "consumer_eur_per_kwh": 0.30},
        {"local_hour": 25, "consumer_eur_per_kwh": 0.40},
        {"local_hour": 2, "consumer_eur_per_kwh": "bad"},
    ])

    assert loaded == 2
    assert prices_by_hour["00"] == pytest.approx(0.25)
    assert prices_by_hour["01"] == pytest.approx(0.30)
    assert prices_by_hour["02"] is None
    assert prices_by_hour["23"] is None


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


def test_build_daily_report_stitches_grid_import_counter_reset():
    day_start = datetime(2025, 1, 1, 0, 0, 0, tzinfo=TZ)
    rows = [
        _row(1, "change", _ts(2025, 1, 1, 0, 0), new_value="0", electric_level=50, total_act_x100=10000, total_act_ret_x100=5000),
        _row(2, "Rescan", _ts(2025, 1, 1, 0, 20), electric_level=50, total_act_x100=11000, total_act_ret_x100=5000),
        _row(3, "Rescan", _ts(2025, 1, 1, 0, 40), electric_level=50, total_act_x100=12000, total_act_ret_x100=5000),
        _row(4, "Rescan", _ts(2025, 1, 1, 0, 45), electric_level=50, total_act_x100=0, total_act_ret_x100=5000),
        _row(5, "Rescan", _ts(2025, 1, 1, 0, 50), electric_level=50, total_act_x100=1000, total_act_ret_x100=5000),
        _row(6, "Rescan", _ts(2025, 1, 1, 1, 0), electric_level=50, total_act_x100=2000, total_act_ret_x100=5000),
    ]

    report_data = report.build_daily_report(
        rows,
        target_day_start=day_start,
        analysis_end_ts=_ts(2025, 1, 1, 1, 0),
        tz=TZ,
        prices_by_hour=_price_map(**{"00": 0.25}),
        price_file_path=Path("D:/fake/price20250101.json"),
        price_file_found=True,
    )

    hour00 = report_data["hours"][0]

    assert hour00["grid_from_wh"] == pytest.approx(40.0)
    assert hour00["grid_from_cost"] == pytest.approx(0.01)
    assert hour00["grid_to_wh"] == pytest.approx(0.0)
    assert hour00["net_cost"] == pytest.approx(0.01)
    assert report_data["totals"]["grid_from_wh"] == pytest.approx(40.0)
    assert report_data["totals"]["net_cost"] == pytest.approx(0.01)


def test_build_daily_report_stitches_grid_export_counter_reset():
    day_start = datetime(2025, 1, 1, 0, 0, 0, tzinfo=TZ)
    rows = [
        _row(1, "change", _ts(2025, 1, 1, 0, 0), new_value="0", electric_level=50, total_act_x100=10000, total_act_ret_x100=5000),
        _row(2, "Rescan", _ts(2025, 1, 1, 0, 20), electric_level=50, total_act_x100=10000, total_act_ret_x100=6000),
        _row(3, "Rescan", _ts(2025, 1, 1, 0, 40), electric_level=50, total_act_x100=10000, total_act_ret_x100=7000),
        _row(4, "Rescan", _ts(2025, 1, 1, 0, 45), electric_level=50, total_act_x100=10000, total_act_ret_x100=0),
        _row(5, "Rescan", _ts(2025, 1, 1, 0, 50), electric_level=50, total_act_x100=10000, total_act_ret_x100=500),
        _row(6, "Rescan", _ts(2025, 1, 1, 1, 0), electric_level=50, total_act_x100=10000, total_act_ret_x100=1000),
    ]

    report_data = report.build_daily_report(
        rows,
        target_day_start=day_start,
        analysis_end_ts=_ts(2025, 1, 1, 1, 0),
        tz=TZ,
        prices_by_hour=_price_map(**{"00": 0.25}),
        price_file_path=Path("D:/fake/price20250101.json"),
        price_file_found=True,
    )

    hour00 = report_data["hours"][0]

    assert hour00["grid_from_wh"] == pytest.approx(0.0)
    assert hour00["grid_to_wh"] == pytest.approx(30.0)
    assert hour00["grid_to_cost"] == pytest.approx(-0.0075)
    assert hour00["net_cost"] == pytest.approx(-0.0075)
    assert report_data["totals"]["grid_to_wh"] == pytest.approx(30.0)
    assert report_data["totals"]["net_cost"] == pytest.approx(-0.0075)


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


class _FakeCursor:
    def __init__(self, fetch_rows=None):
        self.executed: list[object] = []
        self.fetch_rows = fetch_rows or []

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc, tb):
        return False

    def execute(self, sql, params=None):
        self.executed.append((sql, params))

    def executemany(self, sql, rows):
        self.executed.append((sql, list(rows)))

    def fetchall(self):
        return self.fetch_rows


class _FakeConnection:
    def __init__(self, fetch_rows=None):
        self.cursor_obj = _FakeCursor(fetch_rows)

    def cursor(self):
        return self.cursor_obj


def test_hourly_report_inputs_ddl_contains_expected_unique_key_and_log_table():
    connection = _FakeConnection()

    hourly_inputs.ensure_tables(connection, "hourly_report_inputs", "hourly_report_inputs_update_log")
    sql = "\n".join(statement for statement, _params in connection.cursor_obj.executed)

    assert "CREATE TABLE IF NOT EXISTS `hourly_report_inputs`" in sql
    assert "UNIQUE KEY uq_hourly_report_inputs_hour (local_date, local_hour)" in sql
    assert "CREATE TABLE IF NOT EXISTS `hourly_report_inputs_update_log`" in sql
    assert "ADD COLUMN IF NOT EXISTS consumer_eur_per_kwh" in sql
    assert "ADD COLUMN IF NOT EXISTS spot_eur_per_kwh" in sql
    assert "ADD COLUMN IF NOT EXISTS price_source" in sql
    assert "ADD COLUMN IF NOT EXISTS estimated_home_load_wh INT UNSIGNED NULL" in sql
    assert "ADD COLUMN IF NOT EXISTS battery_charge_grid_wh INT UNSIGNED NULL" in sql
    assert "ADD COLUMN IF NOT EXISTS battery_flow_pnl_milli_eur INT NULL" in sql
    assert "ADD COLUMN IF NOT EXISTS battery_pnl_status VARCHAR(32) NULL" in sql


def test_hourly_report_inputs_upsert_is_idempotent_by_date_hour():
    connection = _FakeConnection()
    rows = [
        {
            "local_date": "2025-01-01",
            "local_hour": 0,
            "hour_start_ts": 1,
            "hour_end_ts": 2,
            "charged_wh": 1.0,
            "discharged_wh": 0.0,
            "battery_pct_start": 50.0,
            "battery_pct_end": 51.0,
            "battery_pct_delta": 1.0,
            "grid_from_wh": 2.0,
            "grid_to_wh": 0.0,
            "estimated_home_load_wh": 1,
            "consumer_eur_per_kwh": 0.25,
            "spot_eur_per_kwh": 0.11,
            "price_source": "entsoe_v6",
            "battery_charge_grid_wh": 1,
            "battery_charge_surplus_wh": 0,
            "battery_discharge_home_wh": 0,
            "battery_discharge_export_wh": 0,
            "battery_charge_cost_milli_eur": 0,
            "battery_home_savings_milli_eur": 0,
            "battery_export_revenue_milli_eur": 0,
            "battery_flow_pnl_milli_eur": 0,
            "battery_pnl_status": "complete",
            "battery_pnl_method_version": 2,
            "source_min_id": 10,
            "source_max_id": 11,
            "source_rows": 2,
            "computed_at": "2025-01-02 00:00:00",
        }
    ]

    upserted = hourly_inputs.upsert_hourly_rows(connection, "hourly_report_inputs", rows)
    sql, bound_rows = connection.cursor_obj.executed[0]

    assert upserted == 1
    assert "ON DUPLICATE KEY UPDATE" in sql
    assert "local_date, local_hour" in sql
    assert "consumer_eur_per_kwh = VALUES(consumer_eur_per_kwh)" in sql
    assert "spot_eur_per_kwh = VALUES(spot_eur_per_kwh)" in sql
    assert "price_source = VALUES(price_source)" in sql
    assert "battery_flow_pnl_milli_eur = VALUES(battery_flow_pnl_milli_eur)" in sql
    assert bound_rows == rows


def test_hourly_report_inputs_pnl_only_update_does_not_touch_legacy_fields():
    connection = _FakeConnection()
    rows = [{
        "local_date": "2025-01-01",
        "local_hour": 0,
        "estimated_home_load_wh": 100,
        "battery_charge_grid_wh": 100,
        "battery_charge_surplus_wh": 0,
        "battery_discharge_home_wh": 0,
        "battery_discharge_export_wh": 0,
        "battery_charge_cost_milli_eur": 30,
        "battery_home_savings_milli_eur": 0,
        "battery_export_revenue_milli_eur": 0,
        "battery_flow_pnl_milli_eur": -30,
        "battery_pnl_status": "complete",
        "battery_pnl_method_version": 2,
    }]

    updated = hourly_inputs.update_pnl_rows(connection, "hourly_report_inputs", rows)
    sql, bound_rows = connection.cursor_obj.executed[0]

    assert updated == 1
    assert "WHERE local_date = %(local_date)s AND local_hour = %(local_hour)s" in sql
    assert "charged_wh =" not in sql
    assert "grid_from_wh =" not in sql
    assert "estimated_home_load_wh = %(estimated_home_load_wh)s" in sql
    assert bound_rows == rows


def test_hourly_report_inputs_loads_prices_from_price_ticks():
    connection = _FakeConnection([
        {
            "local_hour": 0,
            "consumer_eur_per_kwh": "0.250000",
            "spot_eur_per_kwh": "0.110000",
            "source": "entsoe_v6",
        },
        {
            "local_hour": 25,
            "consumer_eur_per_kwh": "0.990000",
            "spot_eur_per_kwh": "0.990000",
            "source": "bad",
        },
    ])

    prices = hourly_inputs.load_price_ticks_for_day(
        connection,
        "price_ticks",
        datetime(2025, 1, 1, 0, 0, 0, tzinfo=TZ),
    )

    assert prices == {
        "00": {
            "consumer_eur_per_kwh": pytest.approx(0.25),
            "spot_eur_per_kwh": pytest.approx(0.11),
            "price_source": "entsoe_v6",
        }
    }
    sql, params = connection.cursor_obj.executed[0]
    assert "FROM `price_ticks`" in sql
    assert params == ("2025-01-01",)


def test_hourly_report_inputs_loads_configured_enphase_production():
    connection = _FakeConnection([
        {"local_hour": 0, "energy_wh": "12.500"},
        {"local_hour": 20, "energy_wh": "380.000"},
        {"local_hour": 24, "energy_wh": "999.000"},
    ])

    production = hourly_inputs.load_production_for_day(
        connection,
        database="enphase_history",
        table="production_hourly",
        system_id=5053376,
        source="production_micro",
        target_day_start=datetime(2025, 1, 1, 0, 0, 0, tzinfo=TZ),
    )

    assert production == {"00": 12.5, "20": 380.0}
    sql, params = connection.cursor_obj.executed[0]
    assert "FROM `enphase_history`.`production_hourly`" in sql
    assert params == ("2025-01-01", 5053376, "production_micro")


def test_hourly_report_inputs_loads_existing_energy_targets_for_pnl_only():
    connection = _FakeConnection([
        {"local_hour": 0, "charged_wh": "123.500", "discharged_wh": "45.250"},
        {"local_hour": 25, "charged_wh": "1.000", "discharged_wh": "1.000"},
    ])

    targets = hourly_inputs.load_existing_energy_targets(
        connection,
        "hourly_report_inputs",
        datetime(2025, 1, 1, 0, 0, 0, tzinfo=TZ),
    )

    assert targets == {
        "00": {
            "charged_wh": 123.5,
            "discharged_wh": 45.25,
            "grid_from_wh": None,
            "grid_to_wh": None,
        }
    }
    sql, params = connection.cursor_obj.executed[0]
    assert "SELECT local_hour, charged_wh, discharged_wh, grid_from_wh, grid_to_wh" in sql
    assert params == ("2025-01-01",)


def test_hourly_report_inputs_default_target_days_are_yesterday_and_today():
    args = SimpleNamespace(date=None, start_date=None, end_date=None, days_back=None)
    days = hourly_inputs.resolve_target_days(args, TZ)

    assert len(days) == 2
    assert (days[1] - days[0]).days == 1


def test_hourly_report_inputs_rejects_ambiguous_target_arguments():
    args = SimpleNamespace(date="2025-01-01", start_date="2025-01-01", end_date=None, days_back=None)

    with pytest.raises(ValueError, match="cannot be combined"):
        hourly_inputs.resolve_target_days(args, TZ)


def test_existing_daily_report_still_fetches_status_updates_directly():
    source = (DAILY_REPORT_TOOLS_DIR / "hourly_daily_grid_battery_report.py").read_text(encoding="utf-8")

    assert "fetch_status_rows(" in source
    assert "hourly_report_inputs" not in source
