#!/usr/bin/env python3
"""Tests for the SQL-backed /app hourly energy summary."""

from __future__ import annotations

import json
import subprocess
from pathlib import Path

import pytest


REPO_ROOT = Path(__file__).resolve().parents[2]
HELPER_FILE = REPO_ROOT / "main" / "includes" / "app_energy_history.php"
ENDPOINT_FILE = REPO_ROOT / "main" / "api" / "app_energy_history.php"
APP_INDEX_FILE = REPO_ROOT / "app" / "index.php"
ENERGY_OVERVIEW_TEMPLATE_FILE = REPO_ROOT / "app" / "partials" / "energy-money-overview.html"
ENERGY_HISTORY_JS_FILE = REPO_ROOT / "app" / "assets" / "js" / "energy-history.js"
APP_CSS_FILE = REPO_ROOT / "app" / "assets" / "css" / "app.css"


def _build_payload(rows: list[dict[str, object]]) -> dict[str, object]:
    php = (
        f'require {json.dumps(str(HELPER_FILE))};'
        f'$rows=json_decode({json.dumps(json.dumps(rows))},true);'
        'echo json_encode(appEnergyHistoryBuildPayload($rows,3));'
    )
    proc = subprocess.run(["php", "-r", php], capture_output=True, text=True, check=False)
    if proc.returncode != 0:
        raise RuntimeError(proc.stderr.strip())
    return json.loads(proc.stdout)


def _map_live_rows(
    report: dict[str, object], price_rows: list[dict[str, object]]
) -> list[dict[str, object]]:
    php = (
        f'require {json.dumps(str(HELPER_FILE))};'
        f'$report=json_decode({json.dumps(json.dumps(report))},true);'
        f'$prices=json_decode({json.dumps(json.dumps(price_rows))},true);'
        'echo json_encode(appEnergyHistoryMapLiveReportRows($report,$prices,"2026-08-01"));'
    )
    proc = subprocess.run(["php", "-r", php], capture_output=True, text=True, check=False)
    if proc.returncode != 0:
        raise RuntimeError(proc.stderr.strip())
    return json.loads(proc.stdout)


def _build_filtered_payload(rows: list[dict[str, object]], current_hour: int) -> dict[str, object]:
    php = (
        f'require {json.dumps(str(HELPER_FILE))};'
        f'$rows=json_decode({json.dumps(json.dumps(rows))},true);'
        f'$rows=appEnergyHistoryFilterFutureRows($rows,"2026-08-01",{current_hour});'
        'echo json_encode(appEnergyHistoryBuildPayload($rows,3));'
    )
    proc = subprocess.run(["php", "-r", php], capture_output=True, text=True, check=False)
    if proc.returncode != 0:
        raise RuntimeError(proc.stderr.strip())
    return json.loads(proc.stdout)


def _build_stored_value_payload(
    rows: list[dict[str, object]], today: str = "2026-08-01"
) -> dict[str, object]:
    php = (
        f'require {json.dumps(str(HELPER_FILE))};'
        f'$rows=json_decode({json.dumps(json.dumps(rows))},true);'
        '$battery=["capacityWh"=>5760,"roundTripEfficiency"=>0.85];'
        f'echo json_encode(appEnergyHistoryBuildPayload($rows,3,"live",false,$battery,{json.dumps(today)}));'
    )
    proc = subprocess.run(["php", "-r", php], capture_output=True, text=True, check=False)
    if proc.returncode != 0:
        raise RuntimeError(proc.stderr.strip())
    return json.loads(proc.stdout)


def _row(
    hour: int,
    *,
    charged_wh: float = 0.0,
    discharged_wh: float = 0.0,
    consumer: float | None = 0.3,
    spot: float | None = 0.1,
    grid_from_wh: float | None = 0.0,
    grid_to_wh: float | None = 0.0,
    battery_flow: dict[str, object] | None = None,
) -> dict[str, object]:
    return {
        "local_date": "2026-08-01",
        "local_hour": hour,
        "charged_wh": charged_wh,
        "discharged_wh": discharged_wh,
        "grid_from_wh": grid_from_wh,
        "grid_to_wh": grid_to_wh,
        "battery_pct_start": 50,
        "battery_pct_end": 55,
        "consumer_eur_per_kwh": consumer,
        "spot_eur_per_kwh": spot,
        **(battery_flow or {}),
    }


def _battery_flow(
    *,
    charge_grid: int,
    charge_solar: int,
    discharge_home: int,
    discharge_export: int,
    charge_cost: int,
    home_savings: int,
    export_revenue: int,
) -> dict[str, object]:
    return {
        "battery_charge_grid_wh": charge_grid,
        "battery_charge_surplus_wh": charge_solar,
        "battery_discharge_home_wh": discharge_home,
        "battery_discharge_export_wh": discharge_export,
        "battery_charge_cost_milli_eur": charge_cost,
        "battery_home_savings_milli_eur": home_savings,
        "battery_export_revenue_milli_eur": export_revenue,
        "battery_flow_pnl_milli_eur": home_savings + export_revenue - charge_cost,
        "battery_pnl_status": "complete",
        "battery_pnl_method_version": 2,
    }


def test_hourly_payload_preserves_both_directions_and_weights_each_hour() -> None:
    payload = _build_payload(
        [
            _row(0, charged_wh=1000, discharged_wh=500, consumer=0.30, spot=0.10),
            _row(1, charged_wh=500, consumer=0.20, spot=None),
            _row(2, discharged_wh=1000, consumer=None, spot=0.15),
        ]
    )

    first_hour = payload["whPerHour"][0]
    assert first_hour["wh"] == pytest.approx(500)
    assert first_hour["chargedWh"] == pytest.approx(1000)
    assert first_hour["dischargedWh"] == pytest.approx(500)

    day = payload["whPerDay"]["2026-08-01"]
    assert day["pos"] == pytest.approx(1500)
    assert day["neg"] == pytest.approx(-1500)

    consumer = day["priceTotals"]["consumer"]
    assert consumer["charged"]["eur"] == pytest.approx(0.40)
    assert consumer["charged"]["complete"] is True
    assert consumer["discharged"]["eur"] is None
    assert consumer["discharged"]["missingHours"] == ["2026-08-01 02:00"]
    assert consumer["pnl"]["eur"] is None

    spot = day["priceTotals"]["spot"]
    assert spot["charged"]["eur"] is None
    assert spot["charged"]["missingHours"] == ["2026-08-01 01:00"]
    assert spot["discharged"]["eur"] == pytest.approx(0.20)
    assert spot["pnl"]["eur"] is None


def test_missing_price_on_zero_flow_hour_does_not_invalidate_totals() -> None:
    payload = _build_payload(
        [
            _row(0, charged_wh=1000, consumer=0.30, spot=0.10),
            _row(1, consumer=None, spot=None),
        ]
    )
    totals = payload["whPerDay"]["2026-08-01"]["priceTotals"]

    assert totals["consumer"]["charged"]["eur"] == pytest.approx(0.30)
    assert totals["consumer"]["discharged"]["eur"] == pytest.approx(0)
    assert totals["consumer"]["pnl"]["eur"] == pytest.approx(-0.30)
    assert totals["spot"]["pnl"]["eur"] == pytest.approx(-0.10)


def test_price_pnl_is_discharged_minus_charged() -> None:
    payload = _build_payload(
        [
            _row(0, charged_wh=1000, consumer=0.58, spot=0.03),
            _row(1, discharged_wh=1000, consumer=0.50, spot=0.22),
        ]
    )
    totals = payload["whPerDay"]["2026-08-01"]["priceTotals"]

    assert totals["consumer"]["charged"]["eur"] == pytest.approx(0.58)
    assert totals["consumer"]["discharged"]["eur"] == pytest.approx(0.50)
    assert totals["consumer"]["pnl"]["eur"] == pytest.approx(-0.08)
    assert totals["spot"]["charged"]["eur"] == pytest.approx(0.03)
    assert totals["spot"]["discharged"]["eur"] == pytest.approx(0.22)
    assert totals["spot"]["pnl"]["eur"] == pytest.approx(0.19)


def test_grid_cost_uses_each_hours_consumer_import_and_spot_export_prices() -> None:
    payload = _build_payload(
        [
            _row(0, grid_from_wh=1000, grid_to_wh=500, consumer=0.30, spot=0.10),
            _row(1, grid_from_wh=500, grid_to_wh=1000, consumer=0.20, spot=-0.05),
        ]
    )
    grid = payload["whPerDay"]["2026-08-01"]["gridPriceTotals"]
    assert grid["import"]["eur"] == pytest.approx(0.40)
    assert grid["export"]["eur"] == pytest.approx(0)
    assert grid["import"]["complete"] is True
    assert grid["export"]["complete"] is True


def test_grid_cost_is_unavailable_when_meter_or_required_price_is_missing() -> None:
    payload = _build_payload(
        [
            _row(0, grid_from_wh=None),
            _row(1, grid_to_wh=100, spot=None),
            _row(2, grid_from_wh=0, grid_to_wh=0, consumer=None, spot=None),
        ]
    )
    grid = payload["whPerDay"]["2026-08-01"]["gridPriceTotals"]
    assert grid["import"]["eur"] is None
    assert grid["import"]["missingHours"] == ["2026-08-01 00:00"]
    assert grid["export"]["eur"] is None
    assert grid["export"]["missingHours"] == ["2026-08-01 01:00"]


def test_battery_flow_totals_use_four_way_attribution_and_report_pnl() -> None:
    payload = _build_payload(
        [
            _row(0, consumer=0.30, spot=0.10, battery_flow=_battery_flow(
                charge_grid=1000, charge_solar=500, discharge_home=400,
                discharge_export=100, charge_cost=350, home_savings=120,
                export_revenue=10,
            )),
            _row(1, consumer=0.20, spot=0.15, battery_flow=_battery_flow(
                charge_grid=0, charge_solar=1000, discharge_home=500,
                discharge_export=200, charge_cost=150, home_savings=100,
                export_revenue=30,
            )),
        ]
    )
    flow = payload["whPerDay"]["2026-08-01"]["batteryFlowTotals"]
    assert flow["complete"] is True
    assert flow["chargeGridWh"] == 1000
    assert flow["chargeSurplusWh"] == 1500
    assert flow["dischargeHomeWh"] == 900
    assert flow["dischargeExportWh"] == 300
    assert flow["chargeGridMilliEur"] == 300
    assert flow["chargeSurplusMilliEur"] == 200
    assert flow["chargeCostMilliEur"] == 500
    assert flow["homeSavingsMilliEur"] == 220
    assert flow["exportRevenueMilliEur"] == 40
    assert flow["pnlMilliEur"] == -240


def test_incomplete_battery_hour_keeps_covered_values_and_marks_day_partial() -> None:
    complete = _battery_flow(
        charge_grid=1000, charge_solar=0, discharge_home=500,
        discharge_export=0, charge_cost=300, home_savings=150,
        export_revenue=0,
    )
    incomplete = _battery_flow(
        charge_grid=0, charge_solar=0, discharge_home=0,
        discharge_export=0, charge_cost=0, home_savings=0,
        export_revenue=0,
    )
    incomplete["battery_pnl_status"] = "missing_home_load"
    payload = _build_payload([_row(0, battery_flow=complete), _row(1, battery_flow=incomplete)])
    flow = payload["whPerDay"]["2026-08-01"]["batteryFlowTotals"]
    assert flow["complete"] is False
    assert flow["partial"] is True
    assert flow["elapsedHours"] == 2
    assert flow["valuedHours"] == 1
    assert flow["missingHours"] == ["2026-08-01 01:00"]
    assert flow["reasons"] == ["missing_home_load"]
    assert flow["chargeGridWh"] == 1000
    assert flow["pnlMilliEur"] == -150


def test_battery_pnl_stays_unavailable_when_no_hour_can_be_valued() -> None:
    incomplete = _battery_flow(
        charge_grid=0, charge_solar=0, discharge_home=0,
        discharge_export=0, charge_cost=0, home_savings=0,
        export_revenue=0,
    )
    incomplete["battery_pnl_status"] = "missing_home_load"
    flow = _build_payload([_row(0, battery_flow=incomplete)])["whPerDay"]["2026-08-01"]["batteryFlowTotals"]
    assert flow["complete"] is False
    assert flow["partial"] is False
    assert flow["valuedHours"] == 0
    assert flow["chargeGridWh"] is None
    assert flow["pnlMilliEur"] is None


def test_unclassified_discharge_uses_lower_hourly_price_and_covers_charge_cost() -> None:
    classified = _battery_flow(
        charge_grid=1000, charge_solar=0, discharge_home=500,
        discharge_export=0, charge_cost=300, home_savings=150,
        export_revenue=0,
    )
    unclassified = {
        "battery_charge_grid_wh": 200,
        "battery_charge_surplus_wh": 300,
        "battery_pnl_status": "missing_home_load",
        "battery_pnl_method_version": 2,
    }
    payload = _build_payload([
        _row(0, charged_wh=1000, discharged_wh=500, battery_flow=classified),
        _row(1, charged_wh=500, discharged_wh=1000, consumer=0.40, spot=0.10,
             battery_flow=unclassified),
        _row(2, discharged_wh=1000, consumer=0.20, spot=0.30,
             battery_flow={"battery_pnl_status": "missing_home_load"}),
    ])
    flow = payload["whPerDay"]["2026-08-01"]["batteryFlowTotals"]
    estimate = flow["conservative"]
    assert flow["valuedHours"] == 1
    assert flow["pnlMilliEur"] == -150  # Classified-hours total remains separate.
    assert estimate["unclassifiedWh"] == 2000
    assert estimate["unclassifiedValueMilliEur"] == 300  # 1000 Wh at 0.10 and 0.20.
    assert estimate["chargeGridWh"] == 1200
    assert estimate["chargeSurplusWh"] == 300
    assert estimate["chargeCostMilliEur"] == 410
    assert estimate["dischargeValueMilliEur"] == 450
    assert estimate["pnlMilliEur"] == 40
    assert estimate["complete"] is True


def test_conservative_discharge_can_be_negative_and_missing_charge_blocks_pnl() -> None:
    flow = _build_payload([
        _row(0, charged_wh=100, discharged_wh=1000, consumer=0.30, spot=-0.05,
             battery_flow={"battery_pnl_status": "missing_home_load"}),
    ])["whPerDay"]["2026-08-01"]["batteryFlowTotals"]
    estimate = flow["conservative"]
    assert estimate["unclassifiedValueMilliEur"] == -50
    assert estimate["dischargeValueMilliEur"] == -50
    assert estimate["chargeComplete"] is False
    assert estimate["pnlMilliEur"] is None


def test_conservative_value_is_available_without_any_classified_hours() -> None:
    flow = _build_payload([
        _row(0, discharged_wh=750, consumer=0.40, spot=0.15,
             battery_flow={"battery_pnl_status": "missing_home_load"}),
    ])["whPerDay"]["2026-08-01"]["batteryFlowTotals"]
    assert flow["partial"] is False
    assert flow["dischargeHomeWh"] is None
    assert flow["conservative"]["unclassifiedWh"] == 750
    assert flow["conservative"]["dischargeValueMilliEur"] == 113
    assert flow["conservative"]["chargeCostMilliEur"] == 0
    assert flow["conservative"]["pnlMilliEur"] == 113


def test_today_stored_value_uses_only_midnight_to_latest_level_and_elapsed_prices() -> None:
    rows = [
        {**_row(0, consumer=0.20), "battery_pct_start": 50, "battery_pct_end": 60},
        {**_row(1, consumer=0.40), "battery_pct_start": 60, "battery_pct_end": 70},
    ]
    stored = _build_stored_value_payload(rows)["whPerDay"]["2026-08-01"]["storedEnergyValue"]
    expected_deliverable_wh = 5760 * 0.20 * 0.85**0.5
    assert stored["complete"] is True
    assert stored["openingPct"] == 50
    assert stored["closingPct"] == 70
    assert stored["storedDeltaWh"] == pytest.approx(1152)
    assert stored["deliverableDeltaWh"] == pytest.approx(expected_deliverable_wh, abs=0.001)
    assert stored["averageConsumerEurPerKwh"] == pytest.approx(0.30)
    assert stored["valueEur"] == pytest.approx(expected_deliverable_wh / 1000 * 0.30, abs=0.000001)


def test_stored_value_can_be_negative_and_does_not_count_opening_stock_as_gain() -> None:
    rows = [
        {**_row(0), "battery_pct_start": 80, "battery_pct_end": 75},
        {**_row(1), "battery_pct_start": 75, "battery_pct_end": 70},
    ]
    stored = _build_stored_value_payload(rows)["whPerDay"]["2026-08-01"]["storedEnergyValue"]
    assert stored["complete"] is True
    assert stored["storedDeltaWh"] == pytest.approx(-576)
    assert stored["valueEur"] == pytest.approx(-576 * 0.85**0.5 / 1000 * 0.30, abs=0.000001)


@pytest.mark.parametrize("change", ["missing_open", "missing_close", "missing_price", "missing_hour"])
def test_stored_value_is_unavailable_without_complete_boundaries_and_prices(change: str) -> None:
    rows = [
        {**_row(0), "battery_pct_start": 50, "battery_pct_end": 55},
        {**_row(1), "battery_pct_start": 55, "battery_pct_end": 60},
    ]
    if change == "missing_open":
        rows[0]["battery_pct_start"] = None
    elif change == "missing_close":
        rows[1]["battery_pct_end"] = None
    elif change == "missing_price":
        rows[1]["consumer_eur_per_kwh"] = None
    else:
        rows.pop(0)
    stored = _build_stored_value_payload(rows)["whPerDay"]["2026-08-01"]["storedEnergyValue"]
    assert stored["complete"] is False
    assert stored["valueEur"] is None


def test_historical_stored_value_requires_end_of_day_reading() -> None:
    rows = [
        {**_row(hour), "battery_pct_start": 50 + hour, "battery_pct_end": 51 + hour}
        for hour in range(24)
    ]
    past = _build_stored_value_payload(rows, today="2026-08-02")["whPerDay"]["2026-08-01"]["storedEnergyValue"]
    assert past["complete"] is True
    assert past["closingPct"] == 74
    missing_last = _build_stored_value_payload(rows[:-1], today="2026-08-02")["whPerDay"]["2026-08-01"]["storedEnergyValue"]
    assert missing_last["complete"] is False


def test_today_grid_cost_ignores_future_placeholder_but_not_elapsed_missing_data() -> None:
    rows = [
        _row(21, grid_from_wh=1000, grid_to_wh=100, consumer=0.30, spot=0.10),
        _row(22, grid_from_wh=500, grid_to_wh=0, consumer=0.20),
        _row(23, grid_from_wh=None, grid_to_wh=None),
    ]
    through_22 = _build_filtered_payload(rows, 22)
    grid = through_22["whPerDay"]["2026-08-01"]["gridPriceTotals"]
    assert [hour["hourLabel"] for hour in through_22["whPerHour"]] == [
        "2026-08-01 21:00", "2026-08-01 22:00"
    ]
    assert grid["import"]["eur"] == pytest.approx(0.40)
    assert grid["export"]["eur"] == pytest.approx(0.01)
    assert grid["import"]["complete"] is True
    assert grid["export"]["complete"] is True

    through_23 = _build_filtered_payload(rows, 23)
    assert through_23["whPerDay"]["2026-08-01"]["gridPriceTotals"]["import"]["eur"] is None


def test_live_report_rows_use_live_energy_and_price_ticks() -> None:
    rows = _map_live_rows(
        {
            "hours": [
                {
                    "hour": "13",
                    "charged_wh": 725.5,
                    "discharged_wh": 110.25,
                    "grid_from_wh": 330.5,
                    "grid_to_wh": 20.75,
                    "battery_pct_start": 32,
                    "battery_pct_end": 40,
                    "price_eur_per_kwh": 9.99,
                    **_battery_flow(
                        charge_grid=500, charge_solar=225, discharge_home=100,
                        discharge_export=10, charge_cost=167, home_savings=28,
                        export_revenue=1,
                    ),
                }
            ]
        },
        [
            {
                "local_hour": 13,
                "consumer_eur_per_kwh": 0.28,
                "spot_eur_per_kwh": 0.12,
            }
        ],
    )

    assert rows == [
        {
            "local_date": "2026-08-01",
            "local_hour": 13,
            "charged_wh": 725.5,
            "discharged_wh": 110.25,
            "grid_from_wh": 330.5,
            "grid_to_wh": 20.75,
            "battery_pct_start": 32,
            "battery_pct_end": 40,
            "consumer_eur_per_kwh": 0.28,
            "spot_eur_per_kwh": 0.12,
            **_battery_flow(
                charge_grid=500, charge_solar=225, discharge_home=100,
                discharge_export=10, charge_cost=167, home_savings=28,
                export_revenue=1,
            ),
        }
    ]


def test_app_wires_sql_endpoint_and_summary_price_tooltips() -> None:
    app_index = APP_INDEX_FILE.read_text(encoding="utf-8")
    overview_html = ENERGY_OVERVIEW_TEMPLATE_FILE.read_text(encoding="utf-8")
    energy_js = ENERGY_HISTORY_JS_FILE.read_text(encoding="utf-8")
    endpoint = ENDPOINT_FILE.read_text(encoding="utf-8")

    assert "../main/api/app_energy_history.php?days=3" in app_index
    for role in (
        "energy-charged-summary",
        "energy-discharged-summary",
        "energy-pnl-summary",
        "energy-total-pnl",
    ):
        assert f'data-role="{role}"' in app_index
        assert role in energy_js

    assert 'label: "Net flow"' in energy_js
    assert "readfile(__DIR__ . '/partials/energy-money-overview.html')" in app_index
    assert 'data-role="energy-money-overview-template"' in overview_html
    assert "content.append(elements.moneyOverviewTemplate.content.cloneNode(true))" in energy_js
    assert 'trigger === elements.pnlSummary) return;' in energy_js
    assert 'detail.label === "Net flow" ? buildMoneyOverview(detail)' in energy_js
    assert "batteryBenefit: milliToEur(batteryFlow.pnlMilliEur)" in energy_js
    assert "setEnergySummaryValue(elements.charged, totals.charged, true)" in energy_js
    assert "setEnergySummaryValue(elements.discharged, -totals.discharged, true)" in energy_js
    assert "formatEnergy(row.wh, true)" in energy_js
    assert "signed: true" in energy_js
    assert "signed: false" not in energy_js

    helper = HELPER_FILE.read_text(encoding="utf-8")
    assert "battery_charge_grid_wh" in helper
    assert "battery_pnl_method_version" in helper
    assert "'pnl' =>" in helper
    assert "dailyReportGenerateLive($today)" in endpoint
    assert "appEnergyHistoryFetchPriceRows($pdo, $today)" in endpoint
    assert "appEnergyHistoryMapLiveReportRows" in endpoint
    assert "hourly_report_inputs_fallback" in endpoint
    assert "hourly_report_inputs" in helper
    assert "FROM price_ticks" in helper
    assert "'todaySource' => $todaySource" in helper
    assert "source.complete !== true" in energy_js
    assert 'return "—"' in energy_js


def test_zero_discharge_rows_are_hidden_and_unclassified_is_a_peer() -> None:
    app_index = ENERGY_OVERVIEW_TEMPLATE_FILE.read_text(encoding="utf-8")
    energy_js = ENERGY_HISTORY_JS_FILE.read_text(encoding="utf-8")
    app_css = APP_CSS_FILE.read_text(encoding="utf-8")

    confirmed_export = app_index.index('data-role="energy-battery-discharge-export-value"')
    unclassified = app_index.index('data-role="energy-battery-unclassified-row"')
    discharge_total = app_index.index('data-role="energy-battery-discharge-value"')
    assert confirmed_export < unclassified < discharge_total
    assert 'class="app-energy-history__unclassified-tag">Unclassified</span>' in app_index
    assert 'data-role="energy-battery-unclassified-note">Conservative · lower hourly price' in app_index
    assert '<dt data-role="energy-battery-discharge-label">Total discharge value</dt>' in app_index
    assert '<dt data-role="energy-battery-pnl-label">Estimated battery economic contribution</dt>' in app_index
    assert '.app-energy-history__money-card h3 {' in app_css
    assert 'color: var(--gsd-accent);\n    font-size: 0.9rem;' in app_css
    assert 'data-role="energy-battery-stored-value"' in app_index
    assert 'data-role="energy-battery-flow-pnl"' in app_index
    assert '<dt><strong>Battery P&amp;L</strong><small>Discharge value − charging cost</small></dt><dd data-role="energy-battery-flow-pnl">' in app_index
    assert '.app-energy-history__money-flow:not(.app-energy-history__money-flow--stored) {' in app_css
    assert 'margin-inline: 14px;' in app_css
    assert '.app-energy-history__money-flow:not(.app-energy-history__money-flow--stored) dt' not in app_css
    assert '.app-energy-history__money-card--flows .app-energy-history__money-result {' in app_css
    assert '.app-energy-history__money-card--contribution' in app_css
    assert 'grid-template-columns: minmax(0, 1fr);' in app_css
    assert 'data-role="energy-battery-discharge-home-row"' in app_index
    assert 'data-role="energy-battery-discharge-export-row"' in app_index
    assert 'data-role="energy-battery-no-discharge" hidden' in app_index
    assert 'No battery discharge recorded' in app_index
    assert 'detail.batteryDischargeHomeWh === 0' in energy_js
    assert 'detail.batteryDischargeExportWh === 0' in energy_js
    assert 'detail.batteryFlow?.valuedHours === 0' in energy_js
    assert 'detail.dischargedWh === 0' in energy_js
    assert 'noDischarge || !useConservative' in energy_js
    assert '(detail.batteryDischargeExportWh ?? 0)' not in energy_js
    assert 'status.textContent = useConservative ? "" : batteryFlowStatusMessage' in energy_js
    assert 'badge.hidden = useConservative || !detail.batteryFlow?.partial' in energy_js
    assert "Conservative battery P&L" not in energy_js
    assert "Conservative discharge value" not in energy_js
    assert '.app-energy-history__money-card dl > div[hidden]' in app_css
    assert 'margin-left: 12px;' not in app_css[app_css.index('.app-energy-history__money-card dl > .app-energy-history__money-flow--unclassified'):][:200]


def test_energy_cost_dialog_groups_flows_and_pnl_in_one_card() -> None:
    app_index = ENERGY_OVERVIEW_TEMPLATE_FILE.read_text(encoding="utf-8")
    energy_js = ENERGY_HISTORY_JS_FILE.read_text(encoding="utf-8")
    card_classes = (
        'app-energy-history__money-card--grid',
        'app-energy-history__money-card--flows',
        'app-energy-history__money-card--contribution',
    )
    positions = [app_index.index(name) for name in card_classes]
    assert positions == sorted(positions)
    flows = app_index[positions[1]:app_index.index('</section>', positions[1])]
    assert 'data-role="energy-battery-charge-cost"' in flows
    assert 'data-role="energy-battery-discharge-value"' in flows
    assert 'data-role="energy-battery-flow-pnl"' in flows
    assert 'app-energy-history__money-card--flow-pnl' not in app_index
    contribution = app_index[positions[-1]:app_index.index('</section>', positions[-1])]
    assert 'data-role="energy-battery-stored-value"' in contribution
    assert 'data-role="energy-battery-benefit"' in contribution
    assert 'benefit.closest(".app-energy-history__money-card").dataset.benefitSign' in energy_js


def test_mobile_summary_uses_modal_top_layer_instead_of_chart_event_timing() -> None:
    app_index = APP_INDEX_FILE.read_text(encoding="utf-8")
    energy_js = ENERGY_HISTORY_JS_FILE.read_text(encoding="utf-8")
    app_css = APP_CSS_FILE.read_text(encoding="utf-8")

    assert '<meta name="apple-mobile-web-app-status-bar-style" content="black">' in app_index
    assert 'document.createElement("dialog")' in energy_js
    assert "if (compactChartMedia.matches) {" in energy_js
    assert "summaryTooltip.showModal();" in energy_js
    assert "summaryTooltip.show();" in energy_js
    assert 'tooltip.matches(":modal") && event.target === tooltip' in energy_js
    assert 'if (summaryTooltip.open) summaryTooltip.close();' in energy_js
    assert '"Close energy costs" : "Close price totals"' in energy_js
    assert 'document.documentElement.classList.add("app-energy-summary-modal-open")' in energy_js
    assert 'document.documentElement.classList.remove("app-energy-summary-modal-open")' in energy_js
    assert 'if (summaryTooltip.matches(":modal") || eventInsideSummaryTooltip(event.target)) return;' in energy_js
    assert 'dialog#app-energy-summary-tooltip.app-schedule-tooltip.is-overview' in app_css
    assert 'height: 100dvh;' in app_css
    assert 'padding-top: calc(20px + env(safe-area-inset-top, 0px));' in app_css
    assert 'flex: 1 1 auto;' in app_css
    assert '-webkit-overflow-scrolling: touch;' in app_css
    assert 'event.pointerType !== "mouse" || chartInteractionIsSuppressed()' in energy_js
    assert "if (chartInteractionIsSuppressed()) return;" in energy_js
    assert "CHART_TOUCH_SUPPRESSION_MS" not in energy_js
