#!/usr/bin/env python3
"""
Tests for AutomateController battery-limit evaluation logging.
"""

from __future__ import annotations

import sys
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
    controller.max_charge_level = 91
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
