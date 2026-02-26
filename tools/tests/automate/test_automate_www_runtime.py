#!/usr/bin/env python3
"""
Tests for automate_www runtime-condition behavior and schedule condition resolution.

Run with:
  pytest tools/tests/automate/test_automate_www_runtime.py -q
"""

from __future__ import annotations

import json
import shutil
import subprocess
import sys
from datetime import datetime, timedelta
from pathlib import Path
from types import SimpleNamespace

import pytest


REPO_ROOT = Path(__file__).resolve().parents[3]
MAIN_DATA_DIR = REPO_ROOT / "main" / "data"
SCHEDULE_FILE = MAIN_DATA_DIR / "charge_schedule.json"
CONDITIONS_FILE = MAIN_DATA_DIR / "charge_schedule_conditions.json"
SCHEDULE_BAK_FILE = MAIN_DATA_DIR / "charge_schedule_bak.json"
CONDITIONS_BAK_FILE = MAIN_DATA_DIR / "charge_schedule_conditions_bak.json"
RESOLVER_FILE = MAIN_DATA_DIR / "resolve_schedule_conditions.php"
DATA_API_FILE = MAIN_DATA_DIR / "api" / "data_api.php"
MAIN_CONFIG_FILE = REPO_ROOT / "main" / "config" / "config.json"

TEST_DESCRIPTIONS = [
    ("test_resolver_wildcard_and_specific_rule_precedence", "Checks wildcard base rule and specific-hour override precedence."),
    ("test_resolver_emits_runtime_condition_metadata", "Checks resolver includes runtime_conditions and fallback_value in output."),
    ("test_data_api_manual_override_wins_over_condition_if_include_conditions_enabled", "Checks manual concrete schedule entries win over condition merge when include_conditions=true."),
    ("test_runtime_condition_true_keeps_base_value", "Checks runtime condition true keeps base value."),
    ("test_runtime_condition_false_uses_fallback", "Checks runtime condition false uses fallback_value."),
    ("test_runtime_invalid_condition_is_skipped_and_does_not_break", "Checks invalid runtime condition is skipped and does not break execution."),
]


def _today_and_tomorrow_ymd() -> tuple[str, str]:
    now = datetime.now()
    today = now.strftime("%Y%m%d")
    tomorrow = (now + timedelta(days=1)).strftime("%Y%m%d")
    return today, tomorrow


def _price_file_path(yyyymmdd: str) -> Path:
    return MAIN_DATA_DIR / "price" / yyyymmdd[:6] / f"price{yyyymmdd}.json"


def _write_json(path: Path, payload: object) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2), encoding="utf-8")


def _build_hourly_prices(start_value: float = 0.10) -> dict[str, float]:
    out: dict[str, float] = {}
    value = start_value
    for h in range(24):
        out[f"{h:02d}"] = round(value, 5)
        value += 0.01
    return out


def _run_php_json(cmd: list[str]) -> dict:
    proc = subprocess.run(cmd, capture_output=True, text=True, check=False)
    if proc.returncode != 0:
        raise RuntimeError(f"PHP command failed ({proc.returncode}): {proc.stderr.strip()}")
    raw = proc.stdout.strip()
    if not raw:
        raise RuntimeError("PHP command returned empty output")
    return json.loads(raw)


@pytest.fixture(autouse=True)
def backup_and_restore_schedule_files():
    """Back up schedule files to *_bak.json and restore after each test."""
    shutil.copy2(SCHEDULE_FILE, SCHEDULE_BAK_FILE)
    shutil.copy2(CONDITIONS_FILE, CONDITIONS_BAK_FILE)
    try:
        yield
    finally:
        shutil.copy2(SCHEDULE_BAK_FILE, SCHEDULE_FILE)
        shutil.copy2(CONDITIONS_BAK_FILE, CONDITIONS_FILE)


@pytest.fixture
def backup_and_restore_price_files():
    """Backup/restore today's and tomorrow's price files when tests override them."""
    today, tomorrow = _today_and_tomorrow_ymd()
    paths = [_price_file_path(today), _price_file_path(tomorrow)]
    backups: dict[Path, str] = {}
    exists: dict[Path, bool] = {}

    for p in paths:
        exists[p] = p.exists()
        if p.exists():
            backups[p] = p.read_text(encoding="utf-8")

    try:
        yield
    finally:
        for p in paths:
            if exists[p]:
                p.parent.mkdir(parents=True, exist_ok=True)
                p.write_text(backups[p], encoding="utf-8")
            elif p.exists():
                p.unlink()


def test_resolver_wildcard_and_specific_rule_precedence(backup_and_restore_price_files):
    today, tomorrow = _today_and_tomorrow_ymd()
    _write_json(_price_file_path(today), _build_hourly_prices(0.10))
    _write_json(_price_file_path(tomorrow), _build_hourly_prices(0.20))

    rules = [
        {"name": "default", "key": "************", "value": 100, "enabled": True},
        {"name": "specific-1500", "key": "********1500", "value": 200, "enabled": True},
    ]
    _write_json(CONDITIONS_FILE, rules)

    payload = _run_php_json(["php", str(RESOLVER_FILE)])
    assert payload.get("success") is True
    today_group = next((g for g in payload.get("resolved", []) if g.get("date") == today), None)
    assert isinstance(today_group, dict)
    items = today_group.get("items", [])
    by_time = {str(item.get("time")): item for item in items}

    assert by_time["1400"]["value"] == 100
    assert by_time["1500"]["value"] == 200


def test_resolver_emits_runtime_condition_metadata(backup_and_restore_price_files):
    today, tomorrow = _today_and_tomorrow_ymd()
    _write_json(_price_file_path(today), _build_hourly_prices(0.10))
    _write_json(_price_file_path(tomorrow), _build_hourly_prices(0.20))

    rules = [
        {"name": "default", "key": "************", "value": 0, "enabled": True},
        {
            "name": "runtime-1500",
            "key": "********1500",
            "value": "netzero",
            "fallback_value": 0,
            "conditions": [{"field": "electricity_level", "op": ">=", "value": 60}],
            "enabled": True,
        },
    ]
    _write_json(CONDITIONS_FILE, rules)

    payload = _run_php_json(["php", str(RESOLVER_FILE)])
    assert payload.get("success") is True
    today_group = next((g for g in payload.get("resolved", []) if g.get("date") == today), None)
    assert isinstance(today_group, dict)
    by_time = {str(item.get("time")): item for item in today_group.get("items", [])}
    slot = by_time["1500"]
    assert slot["value"] == "netzero"
    assert slot["fallback_value"] == 0
    assert isinstance(slot["runtime_conditions"], list)
    assert slot["runtime_conditions"][0]["field"] == "electricity_level"


def test_data_api_manual_override_wins_over_condition_if_include_conditions_enabled(backup_and_restore_price_files):
    if not MAIN_CONFIG_FILE.exists():
        pytest.skip("main/config/config.json not found")

    cfg = json.loads(MAIN_CONFIG_FILE.read_text(encoding="utf-8"))
    include_conditions = bool(cfg.get("include_conditions"))
    if not include_conditions:
        pytest.skip("include_conditions is false; manual-vs-condition merge path not active")

    today, tomorrow = _today_and_tomorrow_ymd()
    _write_json(_price_file_path(today), _build_hourly_prices(0.10))
    _write_json(_price_file_path(tomorrow), _build_hourly_prices(0.20))

    manual_key = f"{today}1500"
    _write_json(SCHEDULE_FILE, {manual_key: 999, f"{today}0000": 0})

    _write_json(
        CONDITIONS_FILE,
        [
            {
                "name": "runtime-1500",
                "key": "********1500",
                "value": "netzero",
                "fallback_value": 0,
                "conditions": [{"field": "electricity_level", "op": ">=", "value": 60}],
                "enabled": True,
            }
        ],
    )

    php_code = (
        '$_SERVER["REQUEST_METHOD"]="GET"; '
        '$_GET["type"]="schedule"; '
        '$_GET["resolved"]="1"; '
        f'$_GET["date"]="{today}"; '
        f'include "{DATA_API_FILE}";'
    )
    payload = _run_php_json(["php", "-r", php_code])
    assert payload.get("success") is True
    slot = next((item for item in payload.get("resolved", []) if str(item.get("time")) == "1500"), None)
    assert isinstance(slot, dict)
    assert slot.get("value") == 999
    assert slot.get("source") != "condition"


def _import_automate_www_module():
    automate_dir = REPO_ROOT / "automate"
    if str(automate_dir) not in sys.path:
        sys.path.insert(0, str(automate_dir))
    import automate_www  # type: ignore
    return automate_www


def _build_app_with_slot(slot: dict, electric_level: int):
    automate_www = _import_automate_www_module()
    app = automate_www.AutomationApp()

    logs: list[tuple[str, str]] = []
    app.logger = SimpleNamespace(
        info=lambda msg: logs.append(("info", str(msg))),
        warning=lambda msg: logs.append(("warning", str(msg))),
        error=lambda msg: logs.append(("error", str(msg))),
    )
    app.schedule_controller = SimpleNamespace(last_schedule_entry=slot)
    app.controller = SimpleNamespace(config_path=Path("/tmp/config.jsonc"))

    fake_reader = SimpleNamespace(last_zendure_data={"properties": {"electricLevel": electric_level}})
    automate_www.get_reader = lambda _config_path: fake_reader
    return app, logs


def test_runtime_condition_true_keeps_base_value():
    slot = {
        "time": "2000",
        "value": "netzero",
        "runtime_conditions": [{"field": "electricity_level", "op": ">=", "value": 60}],
        "fallback_value": 0,
    }
    app, _logs = _build_app_with_slot(slot, electric_level=88)
    assert app._apply_runtime_conditions("netzero") == "netzero"


def test_runtime_condition_false_uses_fallback():
    slot = {
        "time": "2000",
        "value": "netzero",
        "runtime_conditions": [{"field": "electricity_level", "op": ">=", "value": 90}],
        "fallback_value": 0,
    }
    app, _logs = _build_app_with_slot(slot, electric_level=88)
    assert app._apply_runtime_conditions("netzero") == 0


def test_runtime_invalid_condition_is_skipped_and_does_not_break():
    slot = {
        "time": "2000",
        "value": "netzero",
        "runtime_conditions": [{"field": "unknown_field", "op": ">=", "value": 90}],
        "fallback_value": 0,
    }
    app, logs = _build_app_with_slot(slot, electric_level=88)
    assert app._apply_runtime_conditions("netzero") == "netzero"
    assert any(level == "warning" and "Unsupported runtime field" in msg for level, msg in logs)


if __name__ == "__main__":
    print("Running automate runtime tests")
    print("Planned tests:")
    for test_name, description in TEST_DESCRIPTIONS:
        print(f"- {test_name}: {description}")
    print("")
    exit_code = pytest.main([str(Path(__file__).name), "-q"])
    print("")
    print("Test descriptions:")
    for _, description in TEST_DESCRIPTIONS:
        print(f"- {description}")
    raise SystemExit(exit_code)
