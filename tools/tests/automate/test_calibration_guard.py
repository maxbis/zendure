#!/usr/bin/env python3
"""Tests for Zendure automatic-calibration state tracking."""

from __future__ import annotations

import sys
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[3]
AUTOMATE_DIR = REPO_ROOT / "automate"
if str(AUTOMATE_DIR) not in sys.path:
    sys.path.insert(0, str(AUTOMATE_DIR))

from calibration_guard import CalibrationGuard, read_calibration_active  # noqa: E402


def _snapshot(status):
    return {"properties": {"socStatus": status}}


def test_read_calibration_active_accepts_only_explicit_zero_or_one():
    assert read_calibration_active(_snapshot(1)) is True
    assert read_calibration_active(_snapshot("1")) is True
    assert read_calibration_active(_snapshot(0)) is False
    assert read_calibration_active(_snapshot("0")) is False
    assert read_calibration_active(_snapshot(2)) is None
    assert read_calibration_active(_snapshot(True)) is None
    assert read_calibration_active({"properties": {}}) is None
    assert read_calibration_active(None) is None


def test_guard_reports_enter_and_exit_edges_once():
    guard = CalibrationGuard()

    entered = guard.update(_snapshot(1))
    steady = guard.update(_snapshot(1))
    exited = guard.update(_snapshot(0))

    assert entered.active is True
    assert entered.entered is True
    assert entered.exited is False
    assert steady.active is True
    assert steady.entered is False
    assert steady.exited is False
    assert exited.active is False
    assert exited.entered is False
    assert exited.exited is True


def test_guard_stays_suppressed_when_status_temporarily_disappears():
    guard = CalibrationGuard()
    guard.update(_snapshot(1))

    missing = guard.update(None)

    assert missing.active is True
    assert missing.status_known is False
    assert missing.entered is False
    assert missing.exited is False
