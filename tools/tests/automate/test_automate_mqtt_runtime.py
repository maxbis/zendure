#!/usr/bin/env python3
"""
Tests for automate_mqtt runtime behavior.
"""

from __future__ import annotations

import sys
from collections import deque
from pathlib import Path
from types import SimpleNamespace


REPO_ROOT = Path(__file__).resolve().parents[3]


def _import_automate_mqtt_module():
    automate_dir = REPO_ROOT / "automate"
    if str(automate_dir) not in sys.path:
        sys.path.insert(0, str(automate_dir))
    import automate_mqtt  # type: ignore
    return automate_mqtt


def _import_device_controller_module():
    automate_dir = REPO_ROOT / "automate"
    if str(automate_dir) not in sys.path:
        sys.path.insert(0, str(automate_dir))
    import device_controller  # type: ignore
    return device_controller


def _noop_logger():
    return SimpleNamespace(
        info=lambda *_args, **_kwargs: None,
        warning=lambda *_args, **_kwargs: None,
        error=lambda *_args, **_kwargs: None,
        debug=lambda *_args, **_kwargs: None,
    )


def test_refresh_p1_for_api_prefers_mqtt_reading():
    automate_mqtt = _import_automate_mqtt_module()
    app = automate_mqtt.AutomationApp()
    app.logger = _noop_logger()
    app.controller = SimpleNamespace(config_path=Path("/tmp/config.jsonc"))
    app.mqtt_helper = SimpleNamespace(
        is_enabled=lambda: True,
        is_stale=lambda _seconds: False,
        get_latest_reading=lambda: {"total_power": -77, "source": "mqtt-test"},
        get_latest_total_act=lambda: None,
        get_latest_total_act_ret=lambda: None,
    )
    app._accumulate_p1_data = lambda: (_ for _ in ()).throw(
        AssertionError("HTTP fallback should not run when MQTT reading is fresh")
    )

    app._refresh_p1_for_api()

    assert app.api_state.last_p1 is not None
    assert app.api_state.last_p1.readings == {"total_power": -77, "source": "mqtt-test"}
    assert app.last_p1_total_power == -77
    assert app._last_p1_read_source == "mqtt"


def test_refresh_p1_for_api_falls_back_to_http_when_mqtt_unavailable():
    automate_mqtt = _import_automate_mqtt_module()
    app = automate_mqtt.AutomationApp()
    app.logger = _noop_logger()
    app.controller = SimpleNamespace(config_path=Path("/tmp/config.jsonc"))
    app.mqtt_helper = SimpleNamespace(
        is_enabled=lambda: False,
        get_latest_total_act=lambda: None,
        get_latest_total_act_ret=lambda: None,
    )
    app._accumulate_p1_data = lambda: {"total_power": 42, "source": "http-test"}

    app._refresh_p1_for_api()

    assert app.api_state.last_p1 is not None
    assert app.api_state.last_p1.readings == {"total_power": 42, "source": "http-test"}
    assert app.last_p1_total_power == 42


def test_apply_dynamic_power_command_prefers_mqtt_and_passes_mqtt_source():
    automate_mqtt = _import_automate_mqtt_module()
    device_controller = _import_device_controller_module()
    app = automate_mqtt.AutomationApp()
    app.logger = _noop_logger()

    captured = {}

    def _set_power(mode, p1_data=None, p1_source=None):
        captured["mode"] = mode
        captured["p1_data"] = p1_data
        captured["p1_source"] = p1_source
        return device_controller.PowerResult(success=True, power=123)

    app.controller = SimpleNamespace(
        config_path=Path("/tmp/config.jsonc"),
        set_power=_set_power,
    )
    app.mqtt_helper = SimpleNamespace(
        is_enabled=lambda: True,
        is_stale=lambda _seconds: False,
        get_latest_reading=lambda: {"total_power": -55},
        get_latest_total_act=lambda: None,
        get_latest_total_act_ret=lambda: None,
    )
    app._accumulate_p1_data = lambda: (_ for _ in ()).throw(
        AssertionError("HTTP fallback should not run when MQTT reading is fresh")
    )

    success, power, error = app._apply_dynamic_power_command(automate_mqtt.POWER_MODE_NETZERO)

    assert success is True
    assert power == 123
    assert error is None
    assert captured["mode"] == automate_mqtt.POWER_MODE_NETZERO
    assert captured["p1_data"] == {"total_power": -55}
    assert captured["p1_source"] == "mqtt"
    assert app.api_state.last_p1 is not None
    assert app.api_state.last_p1.readings == {"total_power": -55}


def test_apply_dynamic_power_command_falls_back_to_http_and_passes_http_source():
    automate_mqtt = _import_automate_mqtt_module()
    device_controller = _import_device_controller_module()
    app = automate_mqtt.AutomationApp()
    app.logger = _noop_logger()

    captured = {}

    def _set_power(mode, p1_data=None, p1_source=None):
        captured["mode"] = mode
        captured["p1_data"] = p1_data
        captured["p1_source"] = p1_source
        return device_controller.PowerResult(success=True, power=321)

    app.controller = SimpleNamespace(
        config_path=Path("/tmp/config.jsonc"),
        set_power=_set_power,
    )
    app.mqtt_helper = SimpleNamespace(
        is_enabled=lambda: True,
        is_stale=lambda _seconds: True,
        get_latest_total_act=lambda: None,
        get_latest_total_act_ret=lambda: None,
    )

    def _http_read():
        app._last_p1_read_source = "http"
        return {"total_power": 80}

    app._accumulate_p1_data = _http_read

    success, power, error = app._apply_dynamic_power_command(automate_mqtt.POWER_MODE_NETZERO)

    assert success is True
    assert power == 321
    assert error is None
    assert captured["mode"] == automate_mqtt.POWER_MODE_NETZERO
    assert captured["p1_data"] == {"total_power": 80}
    assert captured["p1_source"] == "http"


def test_apply_power_settings_passes_mqtt_p1_source():
    automate_mqtt = _import_automate_mqtt_module()
    device_controller = _import_device_controller_module()
    app = automate_mqtt.AutomationApp()
    app.old_value = -200
    app.value = -200
    app.fast_loop_active = False
    app._last_p1_read_source = "mqtt"
    app.schedule_controller = SimpleNamespace(last_schedule_entry=None)
    app.logger = _noop_logger()
    posted_updates: list[tuple] = []
    app.status_api = SimpleNamespace(post_update=lambda *args, **kwargs: posted_updates.append((args, kwargs)))
    app.last_total_act = None
    app.last_total_act_ret = None
    app.mqtt_helper = None

    captured = {}

    def _set_power(desired_power, p1_data=None, schedule_entry=None, zendure_data=None, p1_source=None):
        captured["desired_power"] = desired_power
        captured["p1_data"] = p1_data
        captured["schedule_entry"] = schedule_entry
        captured["zendure_data"] = zendure_data
        captured["p1_source"] = p1_source
        return device_controller.PowerResult(
            success=True,
            power=-300,
            max_delta_limited=False,
            reversal_ramp_active=False,
        )

    app.controller = SimpleNamespace(set_power=_set_power)

    app._apply_power_settings(automate_mqtt.POWER_MODE_NETZERO, {"total_power": 320})

    assert captured["p1_source"] == "mqtt"
    assert captured["p1_data"] == {"total_power": 320}


def test_apply_power_settings_enables_and_clears_fast_loop():
    automate_mqtt = _import_automate_mqtt_module()
    app = automate_mqtt.AutomationApp()
    app.old_value = -200
    app.value = -200
    app.fast_loop_active = False
    app.schedule_controller = SimpleNamespace(last_schedule_entry=None)
    app.logger = _noop_logger()
    app.last_total_act = None
    app.last_total_act_ret = None
    app.mqtt_helper = None
    posted_updates: list[tuple] = []
    app.status_api = SimpleNamespace(post_update=lambda *args, **kwargs: posted_updates.append((args, kwargs)))

    responses = deque(
        [
            SimpleNamespace(
                success=True,
                power=-600,
                error=None,
                max_delta_limited=True,
                reversal_ramp_active=False,
            ),
            SimpleNamespace(
                success=True,
                power=-600,
                error=None,
                max_delta_limited=False,
                reversal_ramp_active=False,
            ),
        ]
    )
    app.controller = SimpleNamespace(set_power=lambda *_args, **_kwargs: responses.popleft())

    app._apply_power_settings(automate_mqtt.POWER_MODE_NETZERO, {"total_power": 500})
    assert app.fast_loop_active is True
    assert app.value == -600

    app.old_value = app.value
    app._apply_power_settings(automate_mqtt.POWER_MODE_NETZERO, {"total_power": 450})
    assert app.fast_loop_active is False
    assert app.value == -600


def test_run_cycle_uses_mqtt_triggered_reading_for_control():
    automate_mqtt = _import_automate_mqtt_module()
    app = automate_mqtt.AutomationApp()
    app.logger = _noop_logger()
    app.shutdown_requested = False
    app.mqtt_helper = SimpleNamespace(
        is_enabled=lambda: True,
        consume_power_change_event=lambda: True,
        is_stale=lambda _seconds: False,
    )
    app._sleep_interrupted = lambda: None
    app._log_mqtt_diagnostics_if_needed = lambda: None
    app._should_run_periodic_control = lambda: False
    app._get_mqtt_p1_data = lambda: {"total_power": -111}
    app._accumulate_p1_data = lambda: (_ for _ in ()).throw(
        AssertionError("HTTP fallback should not run for fresh MQTT-triggered control")
    )
    captured = {}
    def _run_pipeline(p1_data):
        captured["p1_data"] = p1_data
        return True

    app._run_full_control_pipeline = _run_pipeline

    result = app._run_cycle()

    assert result is True
    assert captured["p1_data"] == {"total_power": -111}


def test_run_cycle_falls_back_to_http_when_mqtt_is_stale():
    automate_mqtt = _import_automate_mqtt_module()
    app = automate_mqtt.AutomationApp()
    app.logger = _noop_logger()
    app.shutdown_requested = False
    app.mqtt_helper = SimpleNamespace(
        is_enabled=lambda: True,
        consume_power_change_event=lambda: False,
        is_stale=lambda _seconds: True,
    )
    app._sleep_interrupted = lambda: None
    app._log_mqtt_diagnostics_if_needed = lambda: None
    app._should_run_periodic_control = lambda: False
    app._get_mqtt_p1_data = lambda: (_ for _ in ()).throw(
        AssertionError("Fresh MQTT read should not be requested when subscriber is stale")
    )
    captured = {}

    def _http_read():
        app._last_p1_read_source = "http"
        return {"total_power": 222}

    app._accumulate_p1_data = _http_read
    def _run_pipeline(p1_data):
        captured["p1_data"] = p1_data
        return True

    app._run_full_control_pipeline = _run_pipeline

    result = app._run_cycle()

    assert result is True
    assert captured["p1_data"] == {"total_power": 222}


def test_run_cycle_uses_periodic_control_with_fresh_mqtt():
    automate_mqtt = _import_automate_mqtt_module()
    app = automate_mqtt.AutomationApp()
    app.logger = _noop_logger()
    app.shutdown_requested = False
    app.mqtt_helper = SimpleNamespace(
        is_enabled=lambda: True,
        consume_power_change_event=lambda: False,
        is_stale=lambda _seconds: False,
    )
    app._sleep_interrupted = lambda: None
    app._log_mqtt_diagnostics_if_needed = lambda: None
    app._should_run_periodic_control = lambda: True
    app._get_mqtt_p1_data = lambda: {"total_power": -333}
    app._accumulate_p1_data = lambda: (_ for _ in ()).throw(
        AssertionError("HTTP fallback should not run when fresh MQTT data is available for periodic control")
    )
    captured = {}
    def _run_pipeline(p1_data):
        captured["p1_data"] = p1_data
        return True

    app._run_full_control_pipeline = _run_pipeline

    result = app._run_cycle()

    assert result is True
    assert captured["p1_data"] == {"total_power": -333}


def test_run_cycle_skips_control_when_mqtt_is_fresh_without_trigger_or_periodic_due():
    automate_mqtt = _import_automate_mqtt_module()
    app = automate_mqtt.AutomationApp()
    app.logger = _noop_logger()
    app.shutdown_requested = False
    app.mqtt_helper = SimpleNamespace(
        is_enabled=lambda: True,
        consume_power_change_event=lambda: False,
        is_stale=lambda _seconds: False,
        get_status_snapshot=lambda _seconds: {
            "last_delta_watts": 5,
            "last_triggered_change": False,
            "change_threshold_watts": 25,
            "total_power": 140,
        },
    )
    app._sleep_interrupted = lambda: None
    app._log_mqtt_diagnostics_if_needed = lambda: None
    app._should_run_periodic_control = lambda: False
    app._get_mqtt_p1_data = lambda: (_ for _ in ()).throw(
        AssertionError("No control run should request MQTT reading in idle case")
    )
    app._accumulate_p1_data = lambda: (_ for _ in ()).throw(
        AssertionError("No control run should fall back to HTTP in idle case")
    )
    app._run_full_control_pipeline = lambda _p1_data: (_ for _ in ()).throw(
        AssertionError("Control pipeline should not run in idle case")
    )

    result = app._run_cycle()

    assert result is True
