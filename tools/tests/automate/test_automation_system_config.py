from __future__ import annotations

import json
import sys
from pathlib import Path

import pytest


REPO_ROOT = Path(__file__).resolve().parents[3]
COMMON_PYTHON_DIR = REPO_ROOT / "common" / "python"
sys.path.insert(0, str(COMMON_PYTHON_DIR))

from system_config import (  # noqa: E402
    SystemConfigError,
    load_automation_system_config,
    load_system_config,
)


def _write_config(path: Path, payload: dict) -> Path:
    path.write_text(json.dumps(payload), encoding="utf-8")
    return path


def _automation_config() -> dict:
    return {
        "schemaVersion": 1,
        "battery": {
            "minChargePercent": 15,
            "maxChargePercent": 91,
            "maxChargePowerW": 1200,
            "maxDischargePowerW": 2000,
        },
        "installation": {"timezone": "Europe/Amsterdam"},
    }


def test_automation_loader_accepts_only_the_settings_it_consumes(tmp_path: Path):
    path = _write_config(tmp_path / "system.json", _automation_config())

    assert load_automation_system_config(path) == _automation_config()


def test_automation_loader_ignores_qool_only_and_future_settings(tmp_path: Path):
    payload = _automation_config()
    payload["battery"]["dynamicReserve"] = {"enabled": True}
    payload["battery"]["futureQoolSetting"] = 123
    payload["forecast"] = {"qoolOnly": True}
    path = _write_config(tmp_path / "system.json", payload)

    assert load_automation_system_config(path) == _automation_config()


@pytest.mark.parametrize(
    ("section", "key"),
    [
        ("battery", "minChargePercent"),
        ("battery", "maxChargePercent"),
        ("battery", "maxChargePowerW"),
        ("battery", "maxDischargePowerW"),
        ("installation", "timezone"),
    ],
)
def test_automation_loader_rejects_missing_consumed_settings(
    tmp_path: Path, section: str, key: str
):
    payload = _automation_config()
    payload[section].pop(key)
    path = _write_config(tmp_path / "system.json", payload)

    with pytest.raises(SystemConfigError, match=f"missing: {key}"):
        load_automation_system_config(path)


def test_automation_loader_rejects_invalid_timezone(tmp_path: Path):
    payload = _automation_config()
    payload["installation"]["timezone"] = "Not/A_Timezone"
    path = _write_config(tmp_path / "system.json", payload)

    with pytest.raises(SystemConfigError, match="recognized IANA timezone"):
        load_automation_system_config(path)


def test_full_qool_loader_remains_strict(tmp_path: Path):
    payload = json.loads(
        (REPO_ROOT / "common" / "config" / "system.json").read_text(encoding="utf-8")
    )
    payload["battery"].pop("dynamicReserve")
    path = _write_config(tmp_path / "system.json", payload)

    with pytest.raises(SystemConfigError, match="dynamicReserve"):
        load_system_config(path)
