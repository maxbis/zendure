#!/usr/bin/env python3
"""
Tests for MQTT power meter subscriber filtering behavior.
"""

from __future__ import annotations

import json
import sys
import threading
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[3]


def _import_mqtt_subscriber_module():
    automate_dir = REPO_ROOT / "automate"
    if str(automate_dir) not in sys.path:
        sys.path.insert(0, str(automate_dir))
    import power_meter_mqtt_subscriber  # type: ignore
    return power_meter_mqtt_subscriber


def _build_subscriber():
    mqtt_subscriber_module = _import_mqtt_subscriber_module()
    subscriber = mqtt_subscriber_module.MqttPowerMeterSubscriber.__new__(
        mqtt_subscriber_module.MqttPowerMeterSubscriber
    )
    subscriber.total_power_path = "params.em:0.total_act_power"
    subscriber.change_threshold_watts = 10
    subscriber._lock = threading.Lock()
    subscriber._client = None
    subscriber._connected = False
    subscriber._running = False
    subscriber._power_change_event = False
    subscriber._wake_event = threading.Event()
    subscriber._last_raw_payload = None
    subscriber._last_payload = None
    subscriber._last_total_power = None
    subscriber._last_message_timestamp = None
    subscriber._message_count = 0
    subscriber._last_delta_watts = None
    subscriber._last_triggered_change = False
    return subscriber


def _message(payload: dict) -> object:
    return type(
        "Message",
        (),
        {"payload": json.dumps(payload).encode("utf-8")},
    )()


def test_on_message_accepts_non_null_total_power():
    subscriber = _build_subscriber()
    payload = {
        "method": "NotifyStatus",
        "params": {"em:0": {"total_act_power": 12.7}},
    }

    subscriber._on_message(None, None, _message(payload))

    assert subscriber._message_count == 1
    assert subscriber._last_total_power == 13
    assert subscriber._power_change_event is True


def test_on_message_ignores_missing_total_power_path():
    subscriber = _build_subscriber()
    payload = {
        "method": "NotifyEvent",
        "params": {"emdata:0": {"total_act": 123}},
    }

    subscriber._on_message(None, None, _message(payload))

    assert subscriber._message_count == 0
    assert subscriber._last_total_power is None
    assert subscriber._power_change_event is False


def test_on_message_ignores_null_total_power():
    subscriber = _build_subscriber()
    payload = {
        "method": "NotifyStatus",
        "params": {"em:0": {"total_act_power": None}},
    }

    subscriber._on_message(None, None, _message(payload))

    assert subscriber._message_count == 0
    assert subscriber._last_total_power is None
    assert subscriber._power_change_event is False
