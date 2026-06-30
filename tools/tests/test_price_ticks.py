#!/usr/bin/env python3
"""Tests for the price_ticks helper and CLI wiring."""

from __future__ import annotations

import json
import subprocess
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
PRICE_TICKS_COMMON = REPO_ROOT / "main" / "prices" / "price_ticks_common.php"
GET_PRICES_V6 = REPO_ROOT / "main" / "prices" / "get_prices_v6.php"
UPDATE_PRICE_TICKS = REPO_ROOT / "main" / "prices" / "update_price_ticks.php"
BACKFILL_PRICE_TICKS = REPO_ROOT / "main" / "prices" / "backfill_price_ticks.php"


def _run_php_json(code: str) -> dict:
    proc = subprocess.run(["php", "-r", code], capture_output=True, text=True, check=False)
    if proc.returncode != 0:
        raise RuntimeError(proc.stderr.strip())
    return json.loads(proc.stdout.strip())


def test_get_prices_v6_can_be_included_without_emitting_response():
    payload = _run_php_json(
        f'require "{GET_PRICES_V6.as_posix()}";'
        'echo json_encode(["has_fetcher" => function_exists("fetchEntsoeHourPricesForDate")]);'
    )

    assert payload == {"has_fetcher": True}


def test_price_ticks_schema_definitions_are_idempotent_and_keyed():
    source = PRICE_TICKS_COMMON.read_text(encoding="utf-8")

    assert "CREATE TABLE IF NOT EXISTS price_ticks" in source
    assert "CREATE TABLE IF NOT EXISTS price_fetch_log" in source
    assert "UNIQUE KEY uq_price_ticks_local_hour (local_date, local_hour)" in source
    assert "ON DUPLICATE KEY UPDATE" in source


def test_price_ticks_json_import_helper_reads_valid_hours(tmp_path: Path):
    price_root = tmp_path / "price"
    price_dir = price_root / "202605"
    price_dir.mkdir(parents=True)
    (price_dir / "price20260512.json").write_text(
        json.dumps({"00": 0.2, "01": "0.3", "02": "bad"}),
        encoding="utf-8",
    )

    payload = _run_php_json(
        f'require "{PRICE_TICKS_COMMON.as_posix()}";'
        f'$prices = priceTicksLoadJsonPriceFile("2026-05-12", "{price_root.as_posix()}");'
        'echo json_encode(["prices" => $prices, "missing" => priceTicksMissingHours($prices)]);'
    )

    assert payload["prices"]["00"] == 0.2
    assert payload["prices"]["01"] == 0.3
    assert "02" in payload["missing"]
    assert "23" in payload["missing"]


def test_price_tick_cli_scripts_wire_expected_behaviour():
    update_source = UPDATE_PRICE_TICKS.read_text(encoding="utf-8")
    backfill_source = BACKFILL_PRICE_TICKS.read_text(encoding="utf-8")
    common_source = PRICE_TICKS_COMMON.read_text(encoding="utf-8")

    assert "priceTicksFillDate" in update_source
    assert "modify('-1 day')" in update_source
    assert "modify('+1 day')" in update_source
    assert "get_prices_v6.php" in update_source

    assert "priceTicksFillDate" in backfill_source
    assert "--start-date" in backfill_source
    assert "--end-date" in backfill_source
    assert "--dry-run" in backfill_source
    assert "defaults to yesterday" in backfill_source
    assert "modify('-1 day')" in backfill_source

    assert "priceTicksLoadJsonPriceFile" in common_source
    assert "priceTicksFetchEntsoe" in common_source
    assert "priceTicksFetchEnergyzero" in common_source
    assert "fetchEntsoeHourPricesForDate" in common_source
    assert "fetchEnergyzeroHourPricesForDate" in common_source
    assert "get_prices_v7" in common_source
