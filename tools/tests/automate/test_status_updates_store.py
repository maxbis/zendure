#!/usr/bin/env python3
"""Focused tests for automate/status_updates_store.py."""

from __future__ import annotations

import json
import sqlite3
import sys
import uuid
from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo

import pytest

REPO_ROOT = Path(__file__).resolve().parents[3]


def _sus():
    automate_dir = REPO_ROOT / "automate"
    if str(automate_dir) not in sys.path:
        sys.path.insert(0, str(automate_dir))
    import status_updates_store  # type: ignore

    return status_updates_store


def _db_path(tmp_path: Path) -> Path:
    return tmp_path / f"su_{uuid.uuid4().hex}.db"


def test_ensure_db_creates_table_and_indexes(tmp_path):
    sus = _sus()
    path = _db_path(tmp_path)
    store = sus.StatusUpdatesStore(
        db_path=str(path),
        retention_days=7,
        log_warning=lambda _m: pytest.fail(_m),
    )
    store.ensure_db()
    with sqlite3.connect(path) as conn:
        tables = conn.execute(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='status_updates'"
        ).fetchall()
        assert tables == [("status_updates",)]
        indexes = conn.execute(
            "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='status_updates' ORDER BY name"
        ).fetchall()
        names = [r[0] for r in indexes]
        assert "idx_status_updates_timestamp" in names
        assert "idx_status_updates_type_timestamp" in names
        columns = conn.execute("PRAGMA table_info(status_updates)").fetchall()
        column_names = [row[1] for row in columns]
        assert "total_act_x100" in column_names
        assert "total_act_ret_x100" in column_names
        assert "rule" in column_names


def test_ensure_db_adds_missing_rule_column(tmp_path):
    sus = _sus()
    path = _db_path(tmp_path)
    with sqlite3.connect(path) as conn:
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
        conn.commit()
    store = sus.StatusUpdatesStore(
        db_path=str(path),
        retention_days=7,
        log_warning=lambda _m: pytest.fail(_m),
    )
    store.ensure_db()
    with sqlite3.connect(path) as conn:
        column_names = [row[1] for row in conn.execute("PRAGMA table_info(status_updates)").fetchall()]
    assert "rule" in column_names


def test_encode_schedule_rule_modes_and_fixed_watts():
    sus = _sus()
    assert sus.encode_schedule_rule("netzero+") == "NZ+"
    assert sus.encode_schedule_rule("netzero-") == "NZ-"
    assert sus.encode_schedule_rule("netzero") == "NZ0"
    assert sus.encode_schedule_rule(None) == "NZ0"
    assert sus.encode_schedule_rule(400) == "400"
    assert sus.encode_schedule_rule(-2200) == "-2200"
    assert sus.encode_schedule_rule(10000) == "10000"
    assert sus.encode_schedule_rule(-10000) is None
    assert sus.encode_schedule_rule("not-a-rule") is None


def test_insert_status_stores_rule(tmp_path):
    sus = _sus()
    path = _db_path(tmp_path)
    store = sus.StatusUpdatesStore(
        db_path=str(path), retention_days=7, log_warning=lambda *_a, **_k: None
    )
    assert store.insert_status(
        "change", None, 400, None, 50, None, None, 1000, rule="NZ+"
    ) is True
    assert store.insert_status(
        "start", None, None, None, None, None, None, 1001, rule=None
    ) is True
    with sqlite3.connect(path) as conn:
        rows = conn.execute(
            "SELECT type, rule FROM status_updates ORDER BY id ASC"
        ).fetchall()
    assert rows == [("change", "NZ+"), ("start", None)]


def test_fetch_status_updates_delta_includes_rule(tmp_path):
    sus = _sus()
    path = _db_path(tmp_path)
    store = sus.StatusUpdatesStore(
        db_path=str(path), retention_days=7, log_warning=lambda *_a, **_k: None
    )
    assert store.insert_status(
        "change", None, -500, None, None, None, None, 1000, rule="NZ-"
    ) is True

    payload = store.fetch_status_updates_delta(0, 10)

    assert payload["rows"][0]["rule"] == "NZ-"
    assert "total_act" in payload["rows"][0]


def test_insert_status_change_dedup_same_hour(tmp_path):
    sus = _sus()
    tz = ZoneInfo(sus.STATUS_TIMEZONE)
    path = _db_path(tmp_path)
    store = sus.StatusUpdatesStore(
        db_path=str(path), retention_days=7, log_warning=lambda *_a, **_k: None
    )
    base_ts = int(datetime(2025, 6, 1, 10, 5, 0, tzinfo=tz).timestamp())
    assert store.insert_status("change", None, 500, None, 50, None, None, base_ts) is True
    assert store.insert_status("change", None, 500, None, 50, None, None, base_ts + 60) is False
    with sqlite3.connect(path) as conn:
        n = conn.execute("SELECT COUNT(*) FROM status_updates").fetchone()[0]
    assert n == 1


def test_insert_status_non_change_always_inserts(tmp_path):
    sus = _sus()
    path = _db_path(tmp_path)
    store = sus.StatusUpdatesStore(
        db_path=str(path), retention_days=7, log_warning=lambda *_a, **_k: None
    )
    assert store.insert_status("start", None, None, None, None, None, None, 100) is True
    assert store.insert_status("start", None, None, None, None, None, None, 200) is True
    with sqlite3.connect(path) as conn:
        n = conn.execute("SELECT COUNT(*) FROM status_updates").fetchone()[0]
    assert n == 2


def test_cleanup_old_rows_respects_retention(tmp_path):
    sus = _sus()
    tz = ZoneInfo(sus.STATUS_TIMEZONE)
    path = _db_path(tmp_path)
    store = sus.StatusUpdatesStore(
        db_path=str(path), retention_days=1, log_warning=lambda *_a, **_k: None
    )
    now_ts = int(datetime(2025, 1, 10, 12, 0, 0, tzinfo=tz).timestamp())
    old_ts = now_ts - 3 * 86400
    fresh_ts = now_ts - 12 * 3600
    store.insert_status("start", None, None, None, None, None, None, old_ts)
    store.insert_status("start", None, None, None, None, None, None, fresh_ts)
    assert store.cleanup_old_rows(now_ts) is True
    with sqlite3.connect(path) as conn:
        rows = conn.execute("SELECT timestamp FROM status_updates ORDER BY timestamp ASC").fetchall()
    assert rows == [(fresh_ts,)]


def test_compute_wh_per_hour_segment(tmp_path):
    sus = _sus()
    path = _db_path(tmp_path)
    with sqlite3.connect(path) as conn:
        conn.executescript(
            """
            CREATE TABLE status_updates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                old_value TEXT,
                new_value TEXT,
                p1_total_power INTEGER,
                electric_level INTEGER,
                timestamp INTEGER NOT NULL
            );
            """
        )
        conn.execute(
            "INSERT INTO status_updates (type, new_value, electric_level, timestamp) VALUES (?, ?, ?, ?)",
            ("change", json.dumps(600), 77, 1735732800),
        )
        conn.execute(
            "INSERT INTO status_updates (type, new_value, electric_level, timestamp) VALUES (?, ?, ?, ?)",
            ("change", json.dumps(0), 78, 1735734600),
        )
        conn.commit()
    result = sus.compute_wh_per_hour(str(path), now=1735734600, days_back=0)
    end_dt = datetime.fromtimestamp(1735734600, tz=ZoneInfo(sus.WH_PER_HOUR_TIMEZONE))
    today = end_dt.strftime("%Y-%m-%d")
    hour_bucket = next(item for item in result[today] if item["hour"] == end_dt.strftime("%H"))
    assert hour_bucket["charged_wh"] == pytest.approx(300.0)


def test_store_compute_wh_per_hour_segment(tmp_path):
    sus = _sus()
    path = _db_path(tmp_path)
    store = sus.StatusUpdatesStore(
        db_path=str(path), retention_days=7, log_warning=lambda *_a, **_k: None
    )
    with sqlite3.connect(path) as conn:
        conn.executescript(
            """
            CREATE TABLE status_updates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                old_value TEXT,
                new_value TEXT,
                p1_total_power INTEGER,
                electric_level INTEGER,
                timestamp INTEGER NOT NULL
            );
            """
        )
        conn.execute(
            "INSERT INTO status_updates (type, new_value, electric_level, timestamp) VALUES (?, ?, ?, ?)",
            ("change", json.dumps(600), 77, 1735732800),
        )
        conn.execute(
            "INSERT INTO status_updates (type, new_value, electric_level, timestamp) VALUES (?, ?, ?, ?)",
            ("change", json.dumps(0), 78, 1735734600),
        )
        conn.commit()
    result = store.compute_wh_per_hour(now=1735734600, days_back=0)
    end_dt = datetime.fromtimestamp(1735734600, tz=ZoneInfo(sus.WH_PER_HOUR_TIMEZONE))
    today = end_dt.strftime("%Y-%m-%d")
    hour_bucket = next(item for item in result[today] if item["hour"] == end_dt.strftime("%H"))
    assert hour_bucket["charged_wh"] == pytest.approx(300.0)


def test_store_compute_wh_per_hour_missing_db_returns_empty_shape(tmp_path):
    sus = _sus()
    missing_path = tmp_path / "missing_status_updates.db"
    store = sus.StatusUpdatesStore(
        db_path=str(missing_path), retention_days=7, log_warning=lambda *_a, **_k: None
    )
    result = store.compute_wh_per_hour(now=1735734600, days_back=0)
    assert list(result.keys()) == ["2025-01-01"]
    assert len(result["2025-01-01"]) == 24
    assert all(bucket["charged_wh"] == 0.0 for bucket in result["2025-01-01"])
    assert all(bucket["discharged_wh"] == 0.0 for bucket in result["2025-01-01"])


def test_fetch_status_updates_delta_order_and_limit(tmp_path):
    sus = _sus()
    path = _db_path(tmp_path)
    store = sus.StatusUpdatesStore(
        db_path=str(path), retention_days=7, log_warning=lambda *_a, **_k: None
    )
    for i in range(5):
        assert store.insert_status("change", None, i, None, None, None, None, 1000 + i) is True

    p0 = store.fetch_status_updates_delta(0, 2)
    assert p0["has_more"] is True
    assert len(p0["rows"]) == 2
    assert p0["rows"][0]["id"] == 1
    assert p0["rows"][1]["id"] == 2
    assert p0["max_id_returned"] == 2

    p1 = store.fetch_status_updates_delta(2, 10)
    assert p1["has_more"] is False
    assert [r["id"] for r in p1["rows"]] == [3, 4, 5]

    empty = store.fetch_status_updates_delta(99, 10)
    assert empty["rows"] == []
    assert empty["has_more"] is False
    assert empty["max_id_returned"] == 99


def test_insert_status_stores_scaled_energy_counters(tmp_path):
    sus = _sus()
    path = _db_path(tmp_path)
    store = sus.StatusUpdatesStore(
        db_path=str(path), retention_days=7, log_warning=lambda *_a, **_k: None
    )
    assert store.insert_status("start", None, None, None, None, 5258788, 4573494, 100) is True
    with sqlite3.connect(path) as conn:
        row = conn.execute(
            "SELECT total_act_x100, total_act_ret_x100 FROM status_updates"
        ).fetchone()
    assert row == (5258788, 4573494)


def test_fetch_status_updates_delta_returns_decimal_energy_counters(tmp_path):
    sus = _sus()
    path = _db_path(tmp_path)
    store = sus.StatusUpdatesStore(
        db_path=str(path), retention_days=7, log_warning=lambda *_a, **_k: None
    )
    assert store.insert_status("start", None, None, None, None, 5258788, 4573494, 100) is True

    payload = store.fetch_status_updates_delta(0, 10)

    assert payload["rows"][0]["total_act"] == 52587.88
    assert payload["rows"][0]["total_act_ret"] == 45734.94


def test_fetch_status_updates_delta_preserves_null_energy_counters(tmp_path):
    sus = _sus()
    path = _db_path(tmp_path)
    store = sus.StatusUpdatesStore(
        db_path=str(path), retention_days=7, log_warning=lambda *_a, **_k: None
    )
    assert store.insert_status("start", None, None, None, None, None, None, 100) is True

    payload = store.fetch_status_updates_delta(0, 10)

    assert payload["rows"][0]["total_act"] is None
    assert payload["rows"][0]["total_act_ret"] is None
