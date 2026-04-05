#!/usr/bin/env python3
"""
Tests for compact MQTT status logging in automate_mqtt.
"""

from __future__ import annotations

import sqlite3
import sys
import uuid
from datetime import datetime
from pathlib import Path
from types import SimpleNamespace

import pytest


REPO_ROOT = Path(__file__).resolve().parents[3]
TEST_DATA_DIR = REPO_ROOT / "automate" / "data"


def _import_automate_mqtt_module():
    automate_dir = REPO_ROOT / "automate"
    if str(automate_dir) not in sys.path:
        sys.path.insert(0, str(automate_dir))
    import automate_mqtt  # type: ignore
    return automate_mqtt


def _build_app():
    automate_mqtt_module = _import_automate_mqtt_module()
    return automate_mqtt_module.AutomationApp()


def _unique_db_path() -> Path:
    TEST_DATA_DIR.mkdir(parents=True, exist_ok=True)
    return TEST_DATA_DIR / f"test_status_updates_{uuid.uuid4().hex}.db"


def test_format_mqtt_status_line_ok():
    app = _build_app()

    line = app._format_mqtt_status_line(
        {
            "connected": True,
            "stale": False,
            "age_seconds": 5.1,
            "total_power": 9,
            "message_count": 1775,
            "topic": "shellypro3em-841fe890decc/events/rpc",
        }
    )

    assert line == "MQTT: ok age=5.1s p=9W n=1775"
    assert "topic" not in line
    assert "connected=" not in line
    assert "stale=" not in line


def test_format_mqtt_status_line_stale():
    app = _build_app()

    line = app._format_mqtt_status_line(
        {
            "connected": True,
            "stale": True,
            "age_seconds": 75.2,
            "total_power": 9,
            "message_count": 1775,
        }
    )

    assert line == "MQTT: stale age=75.2s p=9W n=1775"


def test_format_mqtt_status_line_down_before_data():
    app = _build_app()

    line = app._format_mqtt_status_line(
        {
            "connected": False,
            "stale": True,
            "age_seconds": None,
            "total_power": None,
            "message_count": 0,
        }
    )

    assert line == "MQTT: down age=never p=? n=0"


def test_status_api_insert_does_not_run_retention_cleanup():
    automate_mqtt = _import_automate_mqtt_module()
    tz = automate_mqtt.ZoneInfo(automate_mqtt.STATUS_TIMEZONE)
    db_path = _unique_db_path()
    try:
        status_api = automate_mqtt.StatusApi(
            logger=SimpleNamespace(warning=lambda *_args, **_kwargs: None),
            db_path=str(db_path),
            retention_days=1,
        )
        old_ts = int(datetime(2025, 1, 1, 10, 0, 0, tzinfo=tz).timestamp())
        new_ts = old_ts + (2 * 24 * 60 * 60)

        status_api._insert_status("start", None, None, None, old_ts)
        status_api._insert_status("start", None, None, None, new_ts)

        with sqlite3.connect(db_path) as conn:
            rows = conn.execute(
                "SELECT timestamp FROM status_updates ORDER BY timestamp ASC"
            ).fetchall()
    finally:
        if db_path.exists():
            try:
                db_path.unlink()
            except PermissionError:
                pass

    assert rows == [(old_ts,), (new_ts,)]


def test_status_api_cleanup_old_rows_deletes_only_expired_rows():
    automate_mqtt = _import_automate_mqtt_module()
    tz = automate_mqtt.ZoneInfo(automate_mqtt.STATUS_TIMEZONE)
    db_path = _unique_db_path()
    try:
        status_api = automate_mqtt.StatusApi(
            logger=SimpleNamespace(warning=lambda *_args, **_kwargs: None),
            db_path=str(db_path),
            retention_days=1,
        )
        now_ts = int(datetime(2025, 1, 3, 12, 0, 0, tzinfo=tz).timestamp())
        old_ts = now_ts - (2 * 24 * 60 * 60)
        fresh_ts = now_ts - (12 * 60 * 60)

        status_api._insert_status("start", None, None, None, old_ts)
        status_api._insert_status("start", None, None, None, fresh_ts)

        assert status_api.cleanup_old_rows(now_ts) is True

        with sqlite3.connect(db_path) as conn:
            rows = conn.execute(
                "SELECT timestamp FROM status_updates ORDER BY timestamp ASC"
            ).fetchall()
    finally:
        if db_path.exists():
            try:
                db_path.unlink()
            except PermissionError:
                pass

    assert rows == [(fresh_ts,)]


def test_maybe_run_retention_cleanup_requires_loop_gate():
    automate_mqtt = _import_automate_mqtt_module()
    app = _build_app()
    cleanup_calls: list[int] = []
    app.status_api = SimpleNamespace(cleanup_old_rows=lambda now_ts: cleanup_calls.append(now_ts) or True)

    app.loop_counter = automate_mqtt.RETENTION_CLEANUP_LOOP_INTERVAL - 1
    app._maybe_run_retention_cleanup(now_ts=1000)

    assert cleanup_calls == []
    assert app.last_retention_cleanup_ts is None


def test_maybe_run_retention_cleanup_checks_elapsed_time():
    automate_mqtt = _import_automate_mqtt_module()
    app = _build_app()
    cleanup_calls: list[int] = []
    app.status_api = SimpleNamespace(cleanup_old_rows=lambda now_ts: cleanup_calls.append(now_ts) or True)
    app.loop_counter = automate_mqtt.RETENTION_CLEANUP_LOOP_INTERVAL

    app._maybe_run_retention_cleanup(now_ts=1000)
    app._maybe_run_retention_cleanup(
        now_ts=1000 + automate_mqtt.RETENTION_CLEANUP_INTERVAL_SECONDS - 1
    )
    app._maybe_run_retention_cleanup(
        now_ts=1000 + automate_mqtt.RETENTION_CLEANUP_INTERVAL_SECONDS
    )

    assert cleanup_calls == [
        1000,
        1000 + automate_mqtt.RETENTION_CLEANUP_INTERVAL_SECONDS,
    ]
    assert app.last_retention_cleanup_ts == 1000 + automate_mqtt.RETENTION_CLEANUP_INTERVAL_SECONDS


def test_maybe_run_retention_cleanup_does_not_update_timestamp_on_failure():
    automate_mqtt = _import_automate_mqtt_module()
    app = _build_app()
    app.status_api = SimpleNamespace(cleanup_old_rows=lambda _now_ts: False)
    app.loop_counter = automate_mqtt.RETENTION_CLEANUP_LOOP_INTERVAL

    app._maybe_run_retention_cleanup(now_ts=2000)

    assert app.last_retention_cleanup_ts is None
