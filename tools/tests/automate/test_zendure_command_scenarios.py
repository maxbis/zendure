#!/usr/bin/env python3
"""
Scenario-style tests for inspecting the command sent to the Zendure API.

Edit SCENARIOS below and run:

    pytest test_zendure_command_scenarios.py -v -s

Each scenario prints:
- the schedule command under test
- the P1 reading used
- the resulting applied power
- the exact request payload sent to /properties/write
"""

from __future__ import annotations

import json
import sys
from pathlib import Path
from types import SimpleNamespace

import pytest


REPO_ROOT = Path(__file__).resolve().parents[3]


# Edit this list to add more scenarios.
# command:
# - int: fixed schedule command, for example 400
# - "netzero"
# - "netzero+"
#
# p1_total_power:
# - required for dynamic modes
# - ignored for fixed integer commands
SCENARIOS = [
    {
        "name": "netzero_from_zero_p1_400",
        "command": "netzero",
        "p1_total_power": -600,
        "previous_power": -100,
    },
    {
        "name": "netzero_from_zero_p1_400",
        "command": "netzero",
        "p1_total_power": -450,
        "previous_power": -50,
    },
    {
        "name": "netzero_from_zero_p1_400",
        "command": "netzero",
        "p1_total_power": -500,
        "previous_power": 0,
    },
    {
        "name": "slot_1900_forces_export_from_charge_request",
        "command": "netzero",
        "p1_total_power": -23,
        "previous_power": 231,
        "config": {
            "NETZERO_TARGET_W": -20,
        },
        "schedule_entry": {
            "time": "1900",
            "key": "202604081900",
            "min_power": -1200,
            "max_power": -800,
        },
        "zendure_data": {
            "properties": {
                "inputLimit": 228,
                "outputLimit": 0,
                "electricLevel": 95,
            }
        },
    }
]


def _import_device_controller_module():
    automate_dir = REPO_ROOT / "automate"
    if str(automate_dir) not in sys.path:
        sys.path.insert(0, str(automate_dir))
    import device_controller  # type: ignore
    return device_controller


def _make_scenario_controller(device_controller_module):
    controller = device_controller_module.AutomateController.__new__(device_controller_module.AutomateController)
    controller.test_mode = False
    controller.config_path = Path("/tmp/config.jsonc")
    controller.config = {
        "NETZERO_BI_DIRECTIONAL": True,
        "NETZERO_TARGET_W": 0,
    }
    controller.previous_power = None
    controller.power_feed_min_threshold = 30
    controller.power_feed_min_delta = 0
    controller.power_feed_max_delta = 300
    controller.limit_state = 0
    controller.min_charge_level = 15
    controller.max_charge_level = 96
    controller.slow_charge_start_level = None
    controller.slow_charge_max_power = None
    controller.max_discharge_power = 1200
    controller.max_charge_power = 1200
    controller.device_ip = "127.0.0.1"
    controller.device_sn = "TEST-SN"
    controller.accumulator = SimpleNamespace(last_zendure_data=None)
    controller.reversal_ramp_guard = device_controller_module.ReversalRampGuard(enabled=True)
    controller._last_dynamic_power_context = {}
    controller.log = lambda *args, **kwargs: None
    controller._build_device_properties = device_controller_module.AutomateController._build_device_properties.__get__(
        controller, device_controller_module.AutomateController
    )
    controller._send_power_feed = device_controller_module.AutomateController._send_power_feed.__get__(
        controller, device_controller_module.AutomateController
    )
    controller._apply_power_feed_max_delta = device_controller_module.AutomateController._apply_power_feed_max_delta.__get__(
        controller, device_controller_module.AutomateController
    )
    controller._resolve_power_target = device_controller_module.AutomateController._resolve_power_target.__get__(
        controller, device_controller_module.AutomateController
    )
    controller._get_dynamic_power_context = device_controller_module.AutomateController._get_dynamic_power_context.__get__(
        controller, device_controller_module.AutomateController
    )
    controller._normalize_schedule_bound = device_controller_module.AutomateController._normalize_schedule_bound
    controller._apply_schedule_power_bounds = device_controller_module.AutomateController._apply_schedule_power_bounds.__get__(
        controller, device_controller_module.AutomateController
    )
    controller.calculate_netzero_power = device_controller_module.AutomateController.calculate_netzero_power.__get__(
        controller, device_controller_module.AutomateController
    )
    return controller


@pytest.mark.parametrize("scenario", SCENARIOS, ids=[item["name"] for item in SCENARIOS])
def test_print_zendure_api_command_for_scenario(monkeypatch, scenario):
    device_controller = _import_device_controller_module()
    controller = _make_scenario_controller(device_controller)
    controller.config.update(scenario.get("config", {}))
    controller.previous_power = scenario.get("previous_power")
    sent_requests = []

    class _Response:
        def raise_for_status(self):
            return None

        def json(self):
            return {"success": True}

    def _fake_post(url, json, timeout, headers):
        sent_requests.append(
            {
                "url": url,
                "json": json,
                "timeout": timeout,
                "headers": headers,
            }
        )
        return _Response()

    monkeypatch.setattr(device_controller.requests, "post", _fake_post)

    command = scenario["command"]
    p1_total_power = scenario.get("p1_total_power")
    p1_data = None if p1_total_power is None else {"total_power": p1_total_power}
    zendure_data = scenario.get(
        "zendure_data",
        {"properties": {"inputLimit": 0, "outputLimit": 0, "electricLevel": 50}},
    )
    schedule_entry = scenario.get("schedule_entry")

    result = device_controller.AutomateController.set_power(
        controller,
        command,
        p1_data=p1_data,
        schedule_entry=schedule_entry,
        zendure_data=zendure_data,
    )

    print("")
    print(f"Scenario: {scenario['name']}")
    print(f"Schedule command: {command}")
    print(f"P1 reading: {p1_total_power}")
    print(f"Applied power: {result.power}")
    if sent_requests:
        print("Zendure API request:")
        print(json.dumps(sent_requests[0], indent=2, sort_keys=True))
    else:
        print("Zendure API request: <no request sent>")

    # assert result.success is True
    # assert len(sent_requests) == 1


def test_netzero_schedule_bounds_force_export_from_charge_request():
    device_controller = _import_device_controller_module()
    controller = _make_scenario_controller(device_controller)
    controller.config.update({"NETZERO_TARGET_W": -20})
    controller.previous_power = 231

    target_power = device_controller.AutomateController._resolve_power_target(
        controller,
        "netzero",
        p1_data={"total_power": -23},
        schedule_entry={
            "time": "1900",
            "key": "202604081900",
            "min_power": -1200,
            "max_power": -800,
        },
        zendure_data={
            "properties": {
                "inputLimit": 228,
                "outputLimit": 0,
                "electricLevel": 95,
            }
        },
        p1_source="mqtt",
    )

    runtime_context = controller._get_dynamic_power_context()

    assert runtime_context["adjusted_p1_power"] == -3
    assert runtime_context["current_input"] == 228
    assert runtime_context["raw_power"] == 231
    assert runtime_context["bounded_power"] == -800
    assert runtime_context["final_power"] == 115
    assert target_power == 115


def test_set_power_resends_when_snapshot_contradicts_previous_power(monkeypatch):
    device_controller = _import_device_controller_module()
    controller = _make_scenario_controller(device_controller)
    controller.config.update({"NETZERO_TARGET_W": -20})
    controller.previous_power = -800
    controller.power_feed_max_delta = 400
    sent_requests = []
    logs = []
    controller.log = lambda level, message, *args, **kwargs: logs.append((level, str(message), kwargs.get("message_key")))

    class _Response:
        def raise_for_status(self):
            return None

        def json(self):
            return {"success": True}

    def _fake_post(url, json, timeout, headers):
        sent_requests.append(
            {
                "url": url,
                "json": json,
                "timeout": timeout,
                "headers": headers,
            }
        )
        return _Response()

    monkeypatch.setattr(device_controller.requests, "post", _fake_post)

    result = device_controller.AutomateController.set_power(
        controller,
        "netzero",
        p1_data={"total_power": 980},
        schedule_entry={
            "time": "1900",
            "key": "202604081900",
            "min_power": -1200,
            "max_power": -800,
        },
        zendure_data={
            "properties": {
                "inputLimit": 240,
                "outputLimit": 0,
                "electricLevel": 100,
            }
        },
        p1_source="mqtt",
    )

    assert result.success is True
    assert len(sent_requests) == 1
    assert sent_requests[0]["json"]["properties"]["acMode"] == 2
    assert sent_requests[0]["json"]["properties"]["outputLimit"] == 800
    assert any(key == "stale_device_state_detected" for _, _, key in logs)
