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
ENERGY_HISTORY_JS_FILE = REPO_ROOT / "app" / "assets" / "js" / "energy-history.js"


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


def _row(
    hour: int,
    *,
    charged_wh: float = 0.0,
    discharged_wh: float = 0.0,
    consumer: float | None = 0.3,
    spot: float | None = 0.1,
) -> dict[str, object]:
    return {
        "local_date": "2026-08-01",
        "local_hour": hour,
        "charged_wh": charged_wh,
        "discharged_wh": discharged_wh,
        "battery_pct_start": 50,
        "battery_pct_end": 55,
        "consumer_eur_per_kwh": consumer,
        "spot_eur_per_kwh": spot,
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


def test_live_report_rows_use_live_energy_and_price_ticks() -> None:
    rows = _map_live_rows(
        {
            "hours": [
                {
                    "hour": "13",
                    "charged_wh": 725.5,
                    "discharged_wh": 110.25,
                    "battery_pct_start": 32,
                    "battery_pct_end": 40,
                    "price_eur_per_kwh": 9.99,
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
            "battery_pct_start": 32,
            "battery_pct_end": 40,
            "consumer_eur_per_kwh": 0.28,
            "spot_eur_per_kwh": 0.12,
        }
    ]


def test_app_wires_sql_endpoint_and_summary_price_tooltips() -> None:
    app_index = APP_INDEX_FILE.read_text(encoding="utf-8")
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

    assert 'label: "PnL"' in energy_js
    assert "discharged.eur - charged.eur" in energy_js
    assert '["Indicative", detail.indicative]' in energy_js
    assert "indicativeDischarge.eur - indicativeCharge.eur" in energy_js
    assert "indicative: money.indicative.pnl.eur" in energy_js
    assert "setEnergySummaryValue(elements.charged, totals.charged, true)" in energy_js
    assert "setEnergySummaryValue(elements.discharged, -totals.discharged, true)" in energy_js
    assert "formatEnergy(row.wh, true)" in energy_js
    assert "signed: true" in energy_js
    assert "signed: false" not in energy_js

    helper = HELPER_FILE.read_text(encoding="utf-8")
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


def test_mobile_summary_uses_modal_top_layer_instead_of_chart_event_timing() -> None:
    energy_js = ENERGY_HISTORY_JS_FILE.read_text(encoding="utf-8")

    assert 'document.createElement("dialog")' in energy_js
    assert "if (compactChartMedia.matches) summaryTooltip.showModal();" in energy_js
    assert "else summaryTooltip.show();" in energy_js
    assert 'tooltip.matches(":modal") && event.target === tooltip' in energy_js
    assert 'if (summaryTooltip.open) summaryTooltip.close();' in energy_js
    assert 'aria-label", "Close price totals"' in energy_js
    assert 'event.pointerType !== "mouse" || chartInteractionIsSuppressed()' in energy_js
    assert "if (chartInteractionIsSuppressed()) return;" in energy_js
    assert "CHART_TOUCH_SUPPRESSION_MS" not in energy_js
