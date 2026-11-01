#!/usr/bin/env python3
"""Tests for durable, fail-closed battery command ownership."""

from __future__ import annotations

import json
import sys
import threading
from pathlib import Path
from types import SimpleNamespace


REPO_ROOT = Path(__file__).resolve().parents[3]
AUTOMATE_DIR = REPO_ROOT / "automate"
if str(AUTOMATE_DIR) not in sys.path:
    sys.path.insert(0, str(AUTOMATE_DIR))

from control_mode import (  # noqa: E402
    CONTROL_MODE_ACTIVE,
    CONTROL_MODE_EXTERNAL,
    CONTROL_MODE_EXTERNAL_PENDING,
    CONTROL_MODE_PAUSED,
    ControlModeStore,
)
from device_controller import PowerResult  # noqa: E402
import automate_www  # noqa: E402


class RecordingLogger:
    def __init__(self):
        self.messages: list[tuple[str, str]] = []

    def __getattr__(self, level):
        return lambda message: self.messages.append((level, message))


def test_missing_file_is_created_in_external_fail_closed_mode(tmp_path):
    path = tmp_path / "control_mode.json"
    store = ControlModeStore(path, RecordingLogger())

    assert path.exists()
    assert store.effective_mode == CONTROL_MODE_EXTERNAL
    status = store.status()
    assert status["controlStateValid"] is True
    assert status["controlStateRecovered"] is True
    assert status["controlStateRecoveryReason"] == "missing_file"
    assert status["controlStateFailClosed"] is True
    assert status["controlWritesEnabled"] is False
    assert json.loads(path.read_text(encoding="utf-8"))["mode"] == CONTROL_MODE_EXTERNAL


def test_invalid_file_is_quarantined_and_replaced_with_external(tmp_path):
    path = tmp_path / "control_mode.json"
    path.write_text("not-json", encoding="utf-8")

    store = ControlModeStore(path, RecordingLogger())

    assert store.effective_mode == CONTROL_MODE_EXTERNAL
    assert store.status()["controlStateRecoveryReason"] == "invalid_file"
    assert json.loads(path.read_text(encoding="utf-8"))["mode"] == CONTROL_MODE_EXTERNAL
    quarantined = list(tmp_path.glob("control_mode.invalid-*.json"))
    assert len(quarantined) == 1
    assert quarantined[0].read_text(encoding="utf-8") == "not-json"


def test_valid_transition_persists_across_restart(tmp_path):
    path = tmp_path / "control_mode.json"
    store = ControlModeStore(path, RecordingLogger())
    store.transition(
        CONTROL_MODE_PAUSED,
        changed_by="test",
        reason="operator paused automation",
    )
    store.transition(
        CONTROL_MODE_ACTIVE,
        changed_by="test",
        reason="operator resumed automation",
    )

    reloaded = ControlModeStore(path, RecordingLogger())
    assert reloaded.stored_mode == CONTROL_MODE_ACTIVE
    assert reloaded.status()["changedBy"] == "test"
    assert reloaded.status()["controlStateRecovered"] is False


def test_write_policy_blocks_all_external_commands_except_pending_zero_handoff(tmp_path):
    store = ControlModeStore(tmp_path / "control_mode.json", RecordingLogger())
    assert store.write_allowed("normal", 100) is False

    store.transition(
        CONTROL_MODE_EXTERNAL_PENDING,
        changed_by="test",
        reason="handoff",
        owner="external-controller",
    )
    assert store.effective_mode == CONTROL_MODE_EXTERNAL
    assert store.write_allowed("external_handoff_zero", 0) is True
    assert store.write_allowed("external_handoff_zero", 1) is False
    assert store.write_allowed("standby", True) is False

    store.transition(CONTROL_MODE_PAUSED, changed_by="test", reason="paused")
    assert store.write_allowed("hold_zero", 0) is True
    assert store.write_allowed("standby", True) is True
    assert store.write_allowed("normal", 100) is False


def test_app_handoff_moves_external_to_paused_before_active(tmp_path):
    store = ControlModeStore(tmp_path / "control_mode.json", RecordingLogger())
    store.transition(CONTROL_MODE_ACTIVE, changed_by="test", reason="start active")
    writes: list[tuple[int, str]] = []
    lock = threading.RLock()

    def set_power(value, *, write_purpose="normal", **_kwargs):
        assert store.write_allowed(write_purpose, value)
        writes.append((value, write_purpose))
        return PowerResult(success=True, power=value)

    app = automate_www.AutomationApp()
    app.control_mode_store = store
    app.controller = SimpleNamespace(
        control_write_transaction=lambda: lock,
        set_power=set_power,
    )
    app.status_api = SimpleNamespace(post_update=lambda *_args, **_kwargs: None)
    app._read_zendure_snapshot = lambda: {
        "properties": {"inputLimit": 0, "outputLimit": 0}
    }

    external = app._set_control_mode(CONTROL_MODE_EXTERNAL)
    assert external["controlMode"] == CONTROL_MODE_EXTERNAL
    assert writes == [(0, "external_handoff_zero")]

    paused = app._set_control_mode(CONTROL_MODE_PAUSED)
    assert paused["controlMode"] == CONTROL_MODE_PAUSED
    assert app.pause_override_active is True

    active = app._set_control_mode(CONTROL_MODE_ACTIVE)
    assert active["controlMode"] == CONTROL_MODE_ACTIVE
    assert app.pause_override_active is False


def test_failed_external_handoff_remains_fail_closed_pending(tmp_path):
    store = ControlModeStore(tmp_path / "control_mode.json", RecordingLogger())
    store.transition(CONTROL_MODE_ACTIVE, changed_by="test", reason="start active")
    lock = threading.RLock()
    app = automate_www.AutomationApp()
    app.control_mode_store = store
    app.controller = SimpleNamespace(
        control_write_transaction=lambda: lock,
        set_power=lambda *_args, **_kwargs: PowerResult(
            success=False,
            power=100,
            error="simulated failure",
        ),
    )

    try:
        app._set_control_mode(CONTROL_MODE_EXTERNAL)
        raise AssertionError("expected handoff failure")
    except RuntimeError as exc:
        assert "simulated failure" in str(exc)

    assert store.stored_mode == CONTROL_MODE_EXTERNAL_PENDING
    assert store.effective_mode == CONTROL_MODE_EXTERNAL
    assert store.status()["controlStateFailClosed"] is True
