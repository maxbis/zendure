#!/usr/bin/env python3
"""
Tests for compact MQTT status logging in automate_mqtt.
"""

from __future__ import annotations

import sys
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[3]


def _import_automate_mqtt_module():
    automate_dir = REPO_ROOT / "automate"
    if str(automate_dir) not in sys.path:
        sys.path.insert(0, str(automate_dir))
    import automate_mqtt  # type: ignore
    return automate_mqtt


def _build_app():
    automate_mqtt_module = _import_automate_mqtt_module()
    return automate_mqtt_module.AutomationApp()


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
