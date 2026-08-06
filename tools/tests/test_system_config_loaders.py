#!/usr/bin/env python
"""Cross-language tests for the read-only shared system configuration loaders."""

from __future__ import annotations

import copy
import json
import subprocess
import sys
from pathlib import Path
from typing import Optional

import pytest


REPO_ROOT = Path(__file__).resolve().parents[2]
SYSTEM_CONFIG_FILE = REPO_ROOT / "common" / "config" / "system.json"
SYSTEM_SCHEMA_FILE = REPO_ROOT / "common" / "config" / "system.schema.json"
PHP_LOADER_FILE = REPO_ROOT / "common" / "php" / "system_config.php"
PYTHON_LOADER_DIR = REPO_ROOT / "common" / "python"

sys.path.insert(0, str(PYTHON_LOADER_DIR))

from system_config import SystemConfigError, load_system_config  # noqa: E402


EXPECTED_CONFIG = {
    "schemaVersion": 1,
    "battery": {
        "capacityWh": 5760,
        "minChargePercent": 15,
        "maxChargePercent": 91,
        "efficiency": 0.9,
        "maxChargePowerW": 1200,
        "maxDischargePowerW": 1200,
    },
    "forecast": {
        "defaultHouseholdUsageWByHour": [
            100, 100, 100, 100, 100, 100, 100, 100,
            220, 220, 220, 220, 220, 220, 220, 220,
            220, 220, 220, 220, 220, 220, 220, 220,
        ],
    },
    "schedule": {
        "minPowerW": -1600,
        "maxPowerW": 1600,
        "powerStepW": 100,
    },
    "installation": {
        "name": "Amsterdam",
        "latitude": 52.3676,
        "longitude": 4.9041,
        "timezone": "Europe/Amsterdam",
    },
    "priceConversion": {
        "supplierMarkupEurPerKwh": 0.0219,
        "energyTaxEurPerKwh": 0.0898,
        "vatMultiplier": 1.21,
        "consumerPrecision": 4,
        "spotPrecision": 6,
    },
}


def _run_php_loader(path: Optional[Path] = None) -> subprocess.CompletedProcess[str]:
    load_expression = "loadSystemConfig()" if path is None else "loadSystemConfig($argv[1])"
    php_code = (
        f'require {json.dumps(str(PHP_LOADER_FILE))};'
        "try {"
        f"$config={load_expression};"
        "echo json_encode($config, JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);"
        "} catch (Throwable $error) {"
        "fwrite(STDERR, $error->getMessage());"
        "exit(1);"
        "}"
    )
    command = ["php", "-r", php_code]
    if path is not None:
        command.append(str(path))
    return subprocess.run(
        command,
        capture_output=True,
        text=True,
        check=False,
    )


def _write_config(path: Path, payload: object) -> Path:
    path.write_text(json.dumps(payload, indent=2), encoding="utf-8")
    return path


def _assert_both_reject(path: Path, expected_message: str) -> None:
    with pytest.raises(SystemConfigError, match=expected_message):
        load_system_config(path)

    php_result = _run_php_loader(path)
    assert php_result.returncode == 1
    assert expected_message.replace("\\", "") in php_result.stderr


def test_default_python_loader_returns_approved_configuration():
    assert load_system_config() == EXPECTED_CONFIG


def test_default_php_loader_returns_approved_configuration():
    php_result = _run_php_loader()

    assert php_result.returncode == 0, php_result.stderr
    assert json.loads(php_result.stdout) == EXPECTED_CONFIG


def test_php_and_python_loaders_return_identical_configuration():
    python_config = load_system_config(SYSTEM_CONFIG_FILE)
    php_result = _run_php_loader(SYSTEM_CONFIG_FILE)

    assert php_result.returncode == 0, php_result.stderr
    assert json.loads(php_result.stdout) == python_config == EXPECTED_CONFIG


def test_schema_contract_matches_loader_sections():
    schema = json.loads(SYSTEM_SCHEMA_FILE.read_text(encoding="utf-8"))

    assert schema["$schema"] == "https://json-schema.org/draft/2020-12/schema"
    assert schema["additionalProperties"] is False
    assert set(schema["required"]) == set(EXPECTED_CONFIG)
    for section in ("battery", "forecast", "schedule", "installation", "priceConversion"):
        section_schema = schema["properties"][section]
        assert section_schema["additionalProperties"] is False
        assert set(section_schema["required"]) == set(EXPECTED_CONFIG[section])


@pytest.mark.parametrize(
    ("mutate", "expected_message"),
    [
        (lambda value: value.pop("battery"), r"Invalid properties at \$ \(missing: battery\)\."),
        (lambda value: value.update({"unexpected": True}), r"Invalid properties at \$ \(unknown: unexpected\)\."),
        (lambda value: value["battery"].pop("capacityWh"), r"Invalid properties at \$\.battery \(missing: capacityWh\)\."),
        (lambda value: value["battery"].update({"unexpected": True}), r"Invalid properties at \$\.battery \(unknown: unexpected\)\."),
        (lambda value: value.update({"schemaVersion": 2}), r"\$\.schemaVersion must be at most 1\."),
        (lambda value: value["battery"].update({"capacityWh": 0}), r"\$\.battery\.capacityWh must be at least 1\."),
        (lambda value: value["battery"].update({"minChargePercent": True}), r"\$\.battery\.minChargePercent must be an integer\."),
        (lambda value: value["battery"].update({"minChargePercent": 91}), r"must be lower than"),
        (lambda value: value["battery"].update({"efficiency": 0}), r"efficiency must be greater than 0\."),
        (lambda value: value["battery"].update({"efficiency": 1.01}), r"efficiency must be at most 1\."),
        (lambda value: value["battery"].update({"maxChargePowerW": 0}), r"maxChargePowerW must be at least 1\."),
        (lambda value: value["forecast"].update({"defaultHouseholdUsageWByHour": [100] * 23}), r"must contain exactly 24 items\."),
        (lambda value: value["forecast"]["defaultHouseholdUsageWByHour"].__setitem__(4, -1), r"defaultHouseholdUsageWByHour\[4\] must be at least 0\."),
        (lambda value: value["schedule"].update({"minPowerW": 1}), r"minPowerW must be at most 0\."),
        (lambda value: value["schedule"].update({"maxPowerW": 0, "minPowerW": 0}), r"minPowerW must be lower than"),
        (lambda value: value["schedule"].update({"powerStepW": 0}), r"powerStepW must be at least 1\."),
        (lambda value: value["installation"].update({"latitude": 91}), r"\$\.installation\.latitude must be at most 90\."),
        (lambda value: value["installation"].update({"timezone": "Not/AZone"}), r"not a recognized IANA timezone"),
        (lambda value: value["priceConversion"].update({"supplierMarkupEurPerKwh": -0.01}), r"supplierMarkupEurPerKwh must be at least 0\."),
        (lambda value: value["priceConversion"].update({"vatMultiplier": 0}), r"vatMultiplier must be greater than 0\."),
        (lambda value: value["priceConversion"].update({"spotPrecision": 13}), r"spotPrecision must be at most 12\."),
    ],
)
def test_php_and_python_reject_invalid_contracts(tmp_path: Path, mutate, expected_message: str):
    payload = copy.deepcopy(EXPECTED_CONFIG)
    mutate(payload)
    path = _write_config(tmp_path / "system.json", payload)
    _assert_both_reject(path, expected_message)


def test_php_and_python_reject_invalid_json(tmp_path: Path):
    path = tmp_path / "system.json"
    path.write_text('{"schemaVersion":', encoding="utf-8")

    with pytest.raises(SystemConfigError, match="Invalid JSON"):
        load_system_config(path)
    php_result = _run_php_loader(path)
    assert php_result.returncode == 1
    assert "Invalid JSON" in php_result.stderr


def test_php_and_python_reject_non_object_root(tmp_path: Path):
    path = _write_config(tmp_path / "system.json", [])
    _assert_both_reject(path, r"Expected an object at \$\.")


def test_php_and_python_reject_missing_file(tmp_path: Path):
    path = tmp_path / "missing.json"

    with pytest.raises(SystemConfigError, match="file not found"):
        load_system_config(path)
    php_result = _run_php_loader(path)
    assert php_result.returncode == 1
    assert "file not found" in php_result.stderr
