#!/usr/bin/env python3
"""Integration tests for automation's shared system configuration boundary."""

from __future__ import annotations

import json
import sys
from pathlib import Path

import pytest


REPO_ROOT = Path(__file__).resolve().parents[3]
AUTOMATE_DIR = REPO_ROOT / "automate"
COMMON_CONFIG_PATH = REPO_ROOT / "common" / "config" / "system.json"


def _import_device_controller_module():
    if str(AUTOMATE_DIR) not in sys.path:
        sys.path.insert(0, str(AUTOMATE_DIR))
    import device_controller  # type: ignore

    return device_controller


def test_base_controller_uses_shared_battery_values_and_timezone(tmp_path):
    device_controller = _import_device_controller_module()
    local_config_path = tmp_path / "config.jsonc"
    local_config_path.write_text(
        json.dumps(
            {
                "TEST_MODE": True,
                "MIN_CHARGE_LEVEL": 1,
                "MAX_CHARGE_LEVEL": 99,
                "MAX_CHARGE_POWER": 2,
                "MAX_DISCHARGE_POWER": 3,
            }
        ),
        encoding="utf-8",
    )

    shared = json.loads(COMMON_CONFIG_PATH.read_text(encoding="utf-8"))
    controller = device_controller.BaseDeviceController(config_path=local_config_path)

    assert controller.min_charge_level == shared["battery"]["minChargePercent"]
    assert controller.max_charge_level == shared["battery"]["maxChargePercent"]
    assert controller.max_charge_power == shared["battery"]["maxChargePowerW"]
    assert controller.max_discharge_power == shared["battery"]["maxDischargePowerW"]
    assert controller.timezone.key == shared["installation"]["timezone"]
    assert controller.system_config_path == COMMON_CONFIG_PATH


def test_base_controller_fails_when_shared_config_loader_fails(tmp_path, monkeypatch):
    device_controller = _import_device_controller_module()
    local_config_path = tmp_path / "config.jsonc"
    local_config_path.write_text("{}", encoding="utf-8")

    def reject_shared_config():
        raise ValueError("invalid shared config")

    monkeypatch.setattr(device_controller, "load_system_config", reject_shared_config)

    with pytest.raises(ValueError, match="invalid shared config"):
        device_controller.BaseDeviceController(config_path=local_config_path)


def test_automation_local_config_has_no_migrated_system_keys():
    local_config_path = AUTOMATE_DIR / "config" / "config.jsonc"
    if not local_config_path.exists():
        pytest.skip("Deployment-local automation config is not present")

    if str(AUTOMATE_DIR) not in sys.path:
        sys.path.insert(0, str(AUTOMATE_DIR))
    from config_loader import load_config  # type: ignore

    local_config = load_config(local_config_path)
    migrated_keys = {
        "MIN_CHARGE_LEVEL",
        "MAX_CHARGE_LEVEL",
        "MAX_CHARGE_POWER",
        "MAX_DISCHARGE_POWER",
    }

    assert migrated_keys.isdisjoint(local_config)
