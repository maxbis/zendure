#!/usr/bin/env python3
"""
Tests for AutomateController battery-limit evaluation logging.
"""

from __future__ import annotations

import sys
import time
from pathlib import Path
from types import SimpleNamespace

import pytest


REPO_ROOT = Path(__file__).resolve().parents[3]


def _import_device_controller_module():
    automate_dir = REPO_ROOT / "automate"
    if str(automate_dir) not in sys.path:
        sys.path.insert(0, str(automate_dir))
    import device_controller  # type: ignore
    return device_controller


def _make_controller(device_controller_module):
    controller = device_controller_module.AutomateController.__new__(device_controller_module.AutomateController)
    controller.config_path = Path("/tmp/config.jsonc")
    controller.min_charge_level = 15
    controller.configured_max_charge_level = 91
    controller.max_charge_level = 91
    controller._full_charge_override_lock = device_controller_module.threading.Lock()
    controller._full_charge_override_active = False
    controller._full_charge_override_target = None
    controller._full_charge_override_armed_at = None
    controller._full_charge_override_expires_at = None
    controller._full_charge_override_last_reset_reason = None
    controller.limit_state = 0
    controller.accumulator = SimpleNamespace(last_zendure_data=None)
    return controller


def _stub_reader():
    return SimpleNamespace(
        config={"deviceIp": "192.0.2.5"},
        CONFIG_KEY_DEVICE_IP="deviceIp",
        API_ENDPOINT_PROPERTIES_REPORT="/properties/report",
        read_zendure=lambda update_json=True: None,
    )


@pytest.mark.parametrize(
    ("battery_level", "expected_limit_state", "expected_state", "expected_charge_allowed", "expected_discharge_allowed"),
    [
        (10, -1, "MIN", "yes", "no"),
        (50, 0, "OK", "yes", "yes"),
        (91, 1, "MAX", "no", "yes"),
        (100, 1, "MAX", "no", "yes"),
    ],
)
def test_check_battery_limits_logs_every_evaluation(
    battery_level,
    expected_limit_state,
    expected_state,
    expected_charge_allowed,
    expected_discharge_allowed,
    monkeypatch,
):
    device_controller = _import_device_controller_module()
    controller = _make_controller(device_controller)
    logs = []
    controller.log = lambda level, message, *args, **kwargs: logs.append(
        (level, str(message), kwargs.get("message_key"))
    )
    monkeypatch.setattr(device_controller, "get_reader", lambda _config_path=None: _stub_reader())

    device_controller.AutomateController.check_battery_limits(
        controller,
        zendure_data={"properties": {"electricLevel": battery_level}},
    )

    assert controller.limit_state == expected_limit_state
    assert len(logs) == 1
    level, message, message_key = logs[0]
    assert level == "info"
    assert message_key is None
    assert (
        message
        == f"Battery limit check: level={battery_level}% min=15% max=91% "
        f"state={expected_state} charge_allowed={expected_charge_allowed} "
        f"discharge_allowed={expected_discharge_allowed}"
    )


def test_check_battery_limits_failed_read_keeps_existing_warning(monkeypatch):
    device_controller = _import_device_controller_module()
    controller = _make_controller(device_controller)
    logs = []
    controller.log = lambda level, message, *args, **kwargs: logs.append(
        (level, str(message), kwargs.get("message_key"))
    )
    fake_reader = _stub_reader()
    monkeypatch.setattr(device_controller, "get_reader", lambda _config_path=None: fake_reader)

    device_controller.AutomateController.check_battery_limits(controller)

    assert controller.limit_state == 0
    assert logs == [
        (
            "warning",
            "Failed to read Zendure device data from http://192.0.2.5/properties/report during battery limit check, assuming OK",
            "battery_limit_read_failed",
        )
    ]


def test_check_battery_limits_missing_battery_level_keeps_existing_warning(monkeypatch):
    device_controller = _import_device_controller_module()
    controller = _make_controller(device_controller)
    logs = []
    controller.log = lambda level, message, *args, **kwargs: logs.append(
        (level, str(message), kwargs.get("message_key"))
    )
    monkeypatch.setattr(device_controller, "get_reader", lambda _config_path=None: _stub_reader())

    device_controller.AutomateController.check_battery_limits(
        controller,
        zendure_data={"properties": {}},
    )

    assert controller.limit_state == 0
    assert logs == [
        (
            "warning",
            "Battery level not found in Zendure data, assuming OK",
            "battery_level_missing",
        )
    ]


def test_full_charge_once_arms_idempotently_and_manual_cancel_restores_configured_limit():
    device_controller = _import_device_controller_module()
    controller = _make_controller(device_controller)
    logs = []
    controller.log = lambda level, message, *args, **kwargs: logs.append((level, str(message)))

    armed = controller.arm_full_charge_once(now=1000)
    armed_again = controller.arm_full_charge_once(now=2000)

    assert armed["active"] is True
    assert armed["configured_max_charge_level"] == 91
    assert armed["effective_max_charge_level"] == 100
    assert armed["armed_at"] == 1000
    assert armed["expires_at"] == 1000 + (24 * 60 * 60)
    assert armed_again["armed_at"] == 1000
    assert len(logs) == 1

    cancelled = controller.cancel_full_charge_once("manual")

    assert cancelled["active"] is False
    assert cancelled["effective_max_charge_level"] == 91
    assert cancelled["last_reset_reason"] == "manual"
    assert controller.max_charge_level == controller.configured_max_charge_level == 91
    assert "(manual): 100% -> 91%" in logs[-1][1]


def test_full_charge_once_expires_after_24_hours():
    device_controller = _import_device_controller_module()
    controller = _make_controller(device_controller)
    logs = []
    controller.log = lambda level, message, *args, **kwargs: logs.append((level, str(message)))
    controller.arm_full_charge_once(now=1000)

    before_expiry = controller.get_full_charge_override_status(now=1000 + (24 * 60 * 60) - 1)
    expired = controller.get_full_charge_override_status(now=1000 + (24 * 60 * 60))

    assert before_expiry["active"] is True
    assert expired["active"] is False
    assert expired["effective_max_charge_level"] == 91
    assert expired["last_reset_reason"] == "expired"
    assert "(expired): 100% -> 91%" in logs[-1][1]


def test_full_charge_once_allows_95_percent_then_resets_at_100_in_same_limit_check(monkeypatch):
    device_controller = _import_device_controller_module()
    controller = _make_controller(device_controller)
    logs = []
    controller.log = lambda level, message, *args, **kwargs: logs.append((level, str(message)))
    monkeypatch.setattr(device_controller, "get_reader", lambda _config_path=None: _stub_reader())
    now = int(time.time())
    controller.arm_full_charge_once(now=now)

    controller.check_battery_limits(zendure_data={"properties": {"electricLevel": 95}})
    assert controller.limit_state == 0
    assert controller.max_charge_level == 100

    controller.check_battery_limits(zendure_data={"properties": {"electricLevel": 100}})

    status = controller.get_full_charge_override_status(now=now + 1)
    assert status["active"] is False
    assert status["last_reset_reason"] == "target_reached"
    assert controller.max_charge_level == 91
    assert controller.limit_state == 1
    assert any("(target_reached): 100% -> 91%" in message for _level, message in logs)
    assert logs[-1][1] == (
        "Battery limit check: level=100% min=15% max=91% "
        "state=MAX charge_allowed=no discharge_allowed=yes"
    )


def test_full_charge_once_is_not_needed_when_configured_maximum_is_100():
    device_controller = _import_device_controller_module()
    controller = _make_controller(device_controller)
    controller.configured_max_charge_level = 100
    controller.max_charge_level = 100
    controller.log = lambda *_args, **_kwargs: None

    status = controller.arm_full_charge_once(now=1000)

    assert status["active"] is False
    assert status["effective_max_charge_level"] == 100
    assert status["last_reset_reason"] == "not_needed"
