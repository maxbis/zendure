#!/usr/bin/env python3
"""Regression checks for the mobile energy-graph P&L column wiring."""

from __future__ import annotations

from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
CHARGE_SCHEDULE_MOBILE_FILE = REPO_ROOT / "main" / "charge_schedule_mobile.php"
ENERGY_GRAPH_JS_FILE = REPO_ROOT / "main" / "assets" / "js" / "energy_graph_refresh.js"
ENERGY_GRAPH_CSS_FILE = REPO_ROOT / "main" / "assets" / "css" / "charge_schedule_mobile.css"


def test_mobile_energy_graph_daily_totals_use_pnl_api_column():
    page_php = CHARGE_SCHEDULE_MOBILE_FILE.read_text(encoding="utf-8")
    energy_graph_js = ENERGY_GRAPH_JS_FILE.read_text(encoding="utf-8")
    energy_graph_css = ENERGY_GRAPH_CSS_FILE.read_text(encoding="utf-8")

    assert "const DAILY_TOTALS_PNL_API_URL =" in page_php
    assert "../daily_report/api/pnl_data.php" in page_php

    assert "var DAILY_PNL_API_URL = typeof DAILY_TOTALS_PNL_API_URL !== 'undefined'" in energy_graph_js
    assert "var DAILY_TOTALS_PNL_DAY_COUNT = 4;" in energy_graph_js
    assert "url.searchParams.set('n', String(DAILY_TOTALS_PNL_DAY_COUNT));" in energy_graph_js
    assert "await ensureDailyPnlForDates(getVisibleDailyTotalDates(latestWhPerDay));" in energy_graph_js
    assert '<th title="P&L (EUR, main price)">P&amp;L</th>' in energy_graph_js
    assert "pnlByDate && pnlByDate[date] ? pnlByDate[date].pnl_eur : null" in energy_graph_js
    assert "return prefix + numericValue.toFixed(2);" in energy_graph_js
    assert "if (!Number.isFinite(numericValue)) return '--';" in energy_graph_js
    assert "if (!Number.isFinite(numericValue) || numericValue === 0) return '';" in energy_graph_js
    assert "spot_pnl_eur" not in energy_graph_js
    assert "'<table><thead><tr><th>Time</th><th>W</th><th>Battery</th>' +" in energy_graph_js

    assert ".energy-graph-mobile-daily-table .col-pnl" in energy_graph_css
