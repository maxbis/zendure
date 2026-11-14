#!/usr/bin/env python3
"""Runtime integration checks for automatic-calibration command suppression."""

from __future__ import annotations

import sys
from pathlib import Path
from types import SimpleNamespace


REPO_ROOT = Path(__file__).resolve().parents[3]
AUTOMATE_DIR = REPO_ROOT / "automate"
if str(AUTOMATE_DIR) not in sys.path:
    sys.path.insert(0, str(AUTOMATE_DIR))

import automate_mqtt  # noqa: E402
import automate_www  # noqa: E402


def _logger():
    return SimpleNamespace(
        info=lambda *_args, **_kwargs: None,
        warning=lambda *_args, **_kwargs: None,
        error=lambda *_args, **_kwargs: None,
        debug=lambda *_args, **_kwargs: None,
    )


def _configure_pipeline(app, snapshot_ref, apply_calls, refresh_calls, standby_calls):
    app.logger = _logger()
    app.pause_override_active = False
    app.control_mode_store = SimpleNamespace(effective_mode="active")
    app.controller = SimpleNamespace(check_battery_limits=lambda zendure_data=None: None)
    app.schedule_controller = SimpleNamespace()
    app._update_p1_state = lambda _p1_data: None
    app._refresh_schedule_if_needed = lambda: refresh_calls.append(True)
    app._calculate_desired_power = lambda: -1800
    app._read_zendure_snapshot = lambda: snapshot_ref[0]
    app._apply_runtime_conditions = lambda desired: desired
    app._check_battery_limits = lambda desired, prechecked=False: desired
    app._update_zendure_state = lambda: None
    app._apply_power_settings = (
        lambda desired, p1_data, zendure_data=None: apply_calls.append(desired)
    )
    app._handle_standby_check = lambda: standby_calls.append(True)


def test_mqtt_runtime_suppresses_writes_and_reconciles_after_calibration():
    app = automate_mqtt.AutomationApp()
    snapshots = [{"properties": {"socStatus": 1}}]
    apply_calls = []
    refresh_calls = []
    standby_calls = []
    _configure_pipeline(app, snapshots, apply_calls, refresh_calls, standby_calls)

    assert app._run_full_control_pipeline({"total_power": 100}) is True
    assert apply_calls == []
    assert standby_calls == []
    assert app.calibration_guard.active is True

    snapshots[0] = {"properties": {"socStatus": 0}}
    assert app._run_full_control_pipeline({"total_power": 100}) is True
    assert apply_calls == [-1800]
    assert standby_calls == [True]
    assert len(refresh_calls) == 3
    assert app.calibration_guard.active is False


def test_www_runtime_suppresses_writes_and_reconciles_after_calibration():
    app = automate_www.AutomationApp()
    snapshots = [{"properties": {"socStatus": 1}}]
    apply_calls = []
    refresh_calls = []
    standby_calls = []
    _configure_pipeline(app, snapshots, apply_calls, refresh_calls, standby_calls)
    app._sleep_interrupted = lambda: None
    app._accumulate_p1_data = lambda: {"total_power": 100}

    assert app._run_cycle() is True
    assert apply_calls == []
    assert standby_calls == []
    assert app.calibration_guard.active is True

    snapshots[0] = {"properties": {"socStatus": 0}}
    assert app._run_cycle() is True
    assert apply_calls == [-1800]
    assert standby_calls == [True]
    assert len(refresh_calls) == 3
    assert app.calibration_guard.active is False
