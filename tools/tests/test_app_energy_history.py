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
    assert consumer["net"]["eur"] is None

    spot = day["priceTotals"]["spot"]
    assert spot["charged"]["eur"] is None
    assert spot["charged"]["missingHours"] == ["2026-08-01 01:00"]
    assert spot["discharged"]["eur"] == pytest.approx(0.20)
    assert spot["net"]["eur"] is None


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
    assert totals["consumer"]["net"]["eur"] == pytest.approx(0.30)
    assert totals["spot"]["net"]["eur"] == pytest.approx(0.10)


def test_app_wires_sql_endpoint_and_all_six_price_values() -> None:
    app_index = APP_INDEX_FILE.read_text(encoding="utf-8")
    energy_js = ENERGY_HISTORY_JS_FILE.read_text(encoding="utf-8")
    endpoint = ENDPOINT_FILE.read_text(encoding="utf-8")

    assert "../main/api/app_energy_history.php?days=3" in app_index
    for role in (
        "energy-charged-consumer",
        "energy-charged-spot",
        "energy-discharged-consumer",
        "energy-discharged-spot",
        "energy-net-consumer",
        "energy-net-spot",
    ):
        assert f'data-role="{role}"' in app_index
        assert role in energy_js

    assert "appEnergyHistoryFetchRows" in endpoint
    assert "hourly_report_inputs" in HELPER_FILE.read_text(encoding="utf-8")
    assert "source.complete !== true" in energy_js
    assert 'return "—"' in energy_js

