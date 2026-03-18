#!/usr/bin/env python3
"""
Tests for automate_www runtime-condition behavior and schedule condition resolution.

Run with:
  pytest tools/tests/automate/test_automate_www_runtime.py -q
"""

from __future__ import annotations

import json
import sqlite3
import shutil
import subprocess
import sys
from collections import deque
from datetime import datetime, timedelta
from pathlib import Path
from types import SimpleNamespace

import pytest
import requests


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
    ("test_base_schedule_resolution_reads_object_entries", "Checks raw schedule object entries resolve into standard slot values."),
    ("test_base_schedule_resolution_propagates_min_max_bounds", "Checks raw schedule netzero entries expose min_value and max_value in resolved slots."),
    ("test_base_schedule_resolution_rejects_non_integer_bounds", "Checks raw schedule min_value/max_value reject non-integer values."),
    ("test_resolver_wildcard_and_specific_rule_precedence", "Checks wildcard base rule and specific-hour override precedence."),
    ("test_resolver_emits_runtime_condition_metadata", "Checks resolver includes runtime_conditions and fallback_value in output."),
    ("test_resolver_emits_sun_context_with_expected_rounding", "Checks sunrise/sunset fields exist and follow floor/ceil policy."),
    ("test_sun_offset_condition_matches_from_dynamic_offset_field", "Checks sunset_offset_hour conditions apply using numeric offsets."),
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


def _load_lat_lon_from_main_config() -> tuple[float, float]:
    if MAIN_CONFIG_FILE.exists():
        cfg = json.loads(MAIN_CONFIG_FILE.read_text(encoding="utf-8"))
    else:
        cfg = {}
    lat = cfg.get("latitude", 52.3676)
    lon = cfg.get("longitude", 4.9041)
    return float(lat), float(lon)


def _php_expected_sun_hours(yyyymmdd: str, lat: float, lon: float) -> dict:
    php_code = (
        '$tz=new DateTimeZone("Europe/Amsterdam");'
        f'$dt=DateTimeImmutable::createFromFormat("Ymd H:i:s","{yyyymmdd} 12:00:00",$tz);'
        f'$info=date_sun_info($dt->getTimestamp(),{lat},{lon});'
        '$sunrise=(new DateTimeImmutable("@".$info["sunrise"]))->setTimezone($tz);'
        '$sunset=(new DateTimeImmutable("@".$info["sunset"]))->setTimezone($tz);'
        '$sunriseFloat=((int)$sunrise->format("H"))+((int)$sunrise->format("i"))/60.0;'
        '$sunsetFloat=((int)$sunset->format("H"))+((int)$sunset->format("i"))/60.0;'
        '$sunriseHour=max(0,min(23,(int)floor($sunriseFloat)));'
        '$sunsetHour=max(0,min(23,(int)ceil($sunsetFloat)));'
        'echo json_encode(['
        '"sunrise_time"=>$sunrise->format("H:i"),'
        '"sunset_time"=>$sunset->format("H:i"),'
        '"sunrise_hour"=>$sunriseHour,'
        '"sunset_hour"=>$sunsetHour'
        ']);'
    )
    return _run_php_json(["php", "-r", php_code])


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


def test_base_schedule_resolution_reads_object_entries():
    today, _ = _today_and_tomorrow_ymd()

    php_code = (
        f'require "{(REPO_ROOT / "main" / "api" / "charge_schedule_functions.php").as_posix()}"; '
        '$schedule=['
        f'"********0000"=>["value"=>0],'
        f'"********1500"=>["value"=>"netzero"],'
        f'"{today}1600"=>["value"=>250]'
        '];'
        f'echo json_encode(resolveScheduleForDate($schedule, "{today}"));'
    )
    payload = _run_php_json(["php", "-r", php_code])
    by_time = {str(item.get("time")): item for item in payload}

    assert by_time["1400"]["value"] == 0
    assert by_time["1500"]["value"] == "netzero"
    assert by_time["1600"]["value"] == 250


def test_base_schedule_resolution_propagates_min_max_bounds():
    today, _ = _today_and_tomorrow_ymd()

    php_code = (
        f'require "{(REPO_ROOT / "main" / "api" / "charge_schedule_functions.php").as_posix()}"; '
        '$schedule=['
        '"********0000"=>["value"=>0],'
        f'"{today}1500"=>["value"=>"netzero","min_value"=>100,"max_value"=>700]'
        '];'
        f'echo json_encode(resolveScheduleForDate($schedule, "{today}"));'
    )
    payload = _run_php_json(["php", "-r", php_code])
    by_time = {str(item.get("time")): item for item in payload}

    assert by_time["1500"]["value"] == "netzero"
    assert by_time["1500"]["min_value"] == 100
    assert by_time["1500"]["max_value"] == 700


def test_base_schedule_resolution_rejects_non_integer_bounds():
    today, _ = _today_and_tomorrow_ymd()

    php_code = (
        f'require "{(REPO_ROOT / "main" / "api" / "charge_schedule_functions.php").as_posix()}"; '
        '$schedule=['
        f'"{today}1500"=>["value"=>"netzero","min_value"=>"100.5"]'
        '];'
        f'try {{ resolveScheduleForDate($schedule, "{today}"); echo json_encode(["ok" => true]); }} '
        'catch (Exception $e) { echo json_encode(["ok" => false, "error" => $e->getMessage()]); }'
    )
    payload = _run_php_json(["php", "-r", php_code])

    assert payload["ok"] is False
    assert "min_value" in payload["error"]


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


def test_resolver_emits_sun_context_with_expected_rounding(backup_and_restore_price_files):
    today, tomorrow = _today_and_tomorrow_ymd()
    _write_json(_price_file_path(today), _build_hourly_prices(0.10))
    _write_json(_price_file_path(tomorrow), _build_hourly_prices(0.20))
    _write_json(CONDITIONS_FILE, [{"name": "default", "key": "************", "value": 0, "enabled": True}])

    payload = _run_php_json(["php", str(RESOLVER_FILE)])
    assert payload.get("success") is True
    today_group = next((g for g in payload.get("resolved", []) if g.get("date") == today), None)
    assert isinstance(today_group, dict)

    lat, lon = _load_lat_lon_from_main_config()
    expected = _php_expected_sun_hours(today, lat, lon)

    assert today_group.get("sunrise_time") == expected["sunrise_time"]
    assert today_group.get("sunset_time") == expected["sunset_time"]
    assert today_group.get("sunrise_hour") == expected["sunrise_hour"]
    assert today_group.get("sunset_hour") == expected["sunset_hour"]


def test_sun_offset_condition_matches_from_dynamic_offset_field(backup_and_restore_price_files):
    today, tomorrow = _today_and_tomorrow_ymd()
    _write_json(_price_file_path(today), _build_hourly_prices(0.10))
    _write_json(_price_file_path(tomorrow), _build_hourly_prices(0.20))
    _write_json(
        CONDITIONS_FILE,
        [
            {
                "name": "sun-window",
                "key": "************",
                "value": "netzero",
                "conditions": [
                    {"field": "sunset_offset_hour", "op": ">=", "value": -2},
                    {"field": "sunset_offset_hour", "op": "<=", "value": 0},
                ],
                "enabled": True,
            }
        ],
    )

    payload = _run_php_json(["php", str(RESOLVER_FILE)])
    assert payload.get("success") is True
    today_group = next((g for g in payload.get("resolved", []) if g.get("date") == today), None)
    assert isinstance(today_group, dict)
    sunset_hour = int(today_group["sunset_hour"])
    start_h = max(0, sunset_hour - 2)
    end_h = sunset_hour
    items = today_group.get("items", [])
    slot_hours = sorted(int(str(item.get("time"))[:2]) for item in items)
    expected_hours = list(range(start_h, end_h + 1))
    assert slot_hours == expected_hours


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
    _write_json(SCHEDULE_FILE, {manual_key: {"value": 999}, f"{today}0000": {"value": 0}})

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


def _import_power_meter_module():
    automate_dir = REPO_ROOT / "automate"
    if str(automate_dir) not in sys.path:
        sys.path.insert(0, str(automate_dir))
    import power_metere_p1_hw  # type: ignore
    return power_metere_p1_hw


def _import_shelly_power_meter_module():
    automate_dir = REPO_ROOT / "automate"
    if str(automate_dir) not in sys.path:
        sys.path.insert(0, str(automate_dir))
    import power_metere_shelly  # type: ignore
    return power_metere_shelly


def _import_power_meter_loader_module():
    automate_dir = REPO_ROOT / "automate"
    if str(automate_dir) not in sys.path:
        sys.path.insert(0, str(automate_dir))
    import power_metere_loader  # type: ignore
    return power_metere_loader


def _import_device_controller_module():
    automate_dir = REPO_ROOT / "automate"
    if str(automate_dir) not in sys.path:
        sys.path.insert(0, str(automate_dir))
    import device_controller  # type: ignore
    return device_controller


def _build_app_with_slot(slot: dict, electric_level: int):
    automate_www = _import_automate_www_module()
    app = automate_www.AutomationApp()

    logs: list[tuple[str, str]] = []
    app.logger = SimpleNamespace(
        info=lambda msg, *_args, **_kwargs: logs.append(("info", str(msg))),
        warning=lambda msg, *_args, **_kwargs: logs.append(("warning", str(msg))),
        error=lambda msg, *_args, **_kwargs: logs.append(("error", str(msg))),
        debug=lambda msg, *_args, **_kwargs: logs.append(("debug", str(msg))),
    )
    app.schedule_controller = SimpleNamespace(last_schedule_entry=slot)
    app.controller = SimpleNamespace(config_path=Path("/tmp/config.jsonc"))

    fake_reader = SimpleNamespace(last_zendure_data={"properties": {"electricLevel": electric_level}})
    automate_www.get_reader = lambda _config_path: fake_reader
    return app, logs


def test_p1_power_meter_reader_reads_flat_total_power(tmp_path, monkeypatch):
    power_meter_reader = _import_power_meter_module()
    config_path = tmp_path / "config.jsonc"
    config_path.write_text(
        json.dumps(
            {
                "powerMeter": {
                    "type": "p1_hw",
                    "p1_hw": {
                        "ip": "127.0.0.1:1616",
                        "endpoint": "/api/v1/data",
                        "totalPowerPath": "active_power_w",
                    },
                }
            }
        ),
        encoding="utf-8",
    )

    class _Response:
        def raise_for_status(self):
            return None

        def json(self):
            return {"active_power_w": -321, "deviceId": "meter-1"}

    monkeypatch.setattr(power_meter_reader.requests, "get", lambda url, timeout: _Response())
    reader = power_meter_reader.build_power_meter_reader(config_path=config_path)
    result = reader.read()

    assert result["total_power"] == -321
    assert result["deviceId"] == "meter-1"


def test_p1_power_meter_reader_reads_nested_total_power(tmp_path, monkeypatch):
    power_meter_reader = _import_power_meter_module()
    config_path = tmp_path / "config.jsonc"
    config_path.write_text(
        json.dumps(
            {
                "powerMeter": {
                    "type": "p1_hw",
                    "p1_hw": {
                        "ip": "127.0.0.1:1616",
                        "endpoint": "/api/v1/data",
                        "totalPowerPath": "data.metrics.grid_w",
                    },
                }
            }
        ),
        encoding="utf-8",
    )

    class _Response:
        def raise_for_status(self):
            return None

        def json(self):
            return {"data": {"metrics": {"grid_w": 456}}}

    monkeypatch.setattr(power_meter_reader.requests, "get", lambda url, timeout: _Response())
    reader = power_meter_reader.build_power_meter_reader(config_path=config_path)
    result = reader.read()

    assert result["total_power"] == 456


def test_p1_power_meter_reader_returns_none_on_request_failure(tmp_path, monkeypatch):
    power_meter_reader = _import_power_meter_module()
    config_path = tmp_path / "config.jsonc"
    config_path.write_text(
        json.dumps(
            {
                "powerMeter": {
                    "type": "p1_hw",
                    "p1_hw": {
                        "ip": "127.0.0.1:1616",
                        "endpoint": "/api/v1/data",
                    },
                }
            }
        ),
        encoding="utf-8",
    )

    def _raise(_url, timeout):
        raise requests.exceptions.RequestException("boom")

    monkeypatch.setattr(power_meter_reader.requests, "get", _raise)
    reader = power_meter_reader.build_power_meter_reader(config_path=config_path)
    assert reader.read() is None


def test_shelly_power_meter_reader_reads_total_power(tmp_path, monkeypatch):
    power_meter_reader = _import_shelly_power_meter_module()
    config_path = tmp_path / "config.jsonc"
    config_path.write_text(
        json.dumps(
            {
                "powerMeter": {
                    "type": "shelly",
                    "shelly": {
                        "ip": "127.0.0.1:1616",
                        "endpoint": "/rpc/EM.GetStatus?id=0",
                        "totalPowerPath": "total_act_power",
                    },
                }
            }
        ),
        encoding="utf-8",
    )

    class _Response:
        def raise_for_status(self):
            return None

        def json(self):
            return {"total_act_power": 789, "a_current": 1.2}

    monkeypatch.setattr(power_meter_reader.requests, "get", lambda url, timeout: _Response())
    reader = power_meter_reader.build_power_meter_reader(config_path=config_path)
    result = reader.read()

    assert result["total_power"] == 789
    assert result["a_current"] == 1.2


def test_shelly_power_meter_reader_rounds_total_power_to_int(tmp_path, monkeypatch):
    power_meter_reader = _import_shelly_power_meter_module()
    config_path = tmp_path / "config.jsonc"
    config_path.write_text(
        json.dumps(
            {
                "powerMeter": {
                    "type": "shelly",
                    "shelly": {
                        "ip": "127.0.0.1:1616",
                        "endpoint": "/rpc/EM.GetStatus?id=0",
                        "totalPowerPath": "total_act_power",
                    },
                }
            }
        ),
        encoding="utf-8",
    )

    class _Response:
        def raise_for_status(self):
            return None

        def json(self):
            return {"total_act_power": 789.456}

    monkeypatch.setattr(power_meter_reader.requests, "get", lambda url, timeout: _Response())
    reader = power_meter_reader.build_power_meter_reader(config_path=config_path)
    result = reader.read()

    assert result["total_power"] == 789


def test_build_power_meter_reader_selects_p1(tmp_path):
    power_meter_reader = _import_power_meter_module()
    config_path = tmp_path / "config.jsonc"
    config_path.write_text(
        json.dumps(
            {
                "powerMeter": {
                    "type": "p1_hw",
                    "p1_hw": {
                        "ip": "127.0.0.1:1616",
                    },
                }
            }
        ),
        encoding="utf-8",
    )

    reader = power_meter_reader.build_power_meter_reader(config_path=config_path)
    assert isinstance(reader, power_meter_reader.P1PowerMeterReader)


def test_build_power_meter_reader_requires_power_meter_block(tmp_path):
    power_meter_reader = _import_power_meter_module()
    config_path = tmp_path / "config.jsonc"
    config_path.write_text(json.dumps({}), encoding="utf-8")

    with pytest.raises(ValueError, match="powerMeter configuration is required"):
        power_meter_reader.build_power_meter_reader(config_path=config_path)


def test_build_power_meter_reader_requires_type(tmp_path):
    power_meter_reader = _import_power_meter_module()
    config_path = tmp_path / "config.jsonc"
    config_path.write_text(json.dumps({"powerMeter": {}}), encoding="utf-8")

    with pytest.raises(ValueError, match="powerMeter.type is required"):
        power_meter_reader.build_power_meter_reader(config_path=config_path)


def test_build_power_meter_reader_rejects_unsupported_type(tmp_path):
    power_meter_reader = _import_power_meter_module()
    config_path = tmp_path / "config.jsonc"
    config_path.write_text(
        json.dumps({"powerMeter": {"type": "modbus"}}),
        encoding="utf-8",
    )

    with pytest.raises(ValueError, match="Unsupported powerMeter.type 'modbus'"):
        power_meter_reader.build_power_meter_reader(config_path=config_path)


def test_build_power_meter_reader_requires_p1_ip(tmp_path):
    power_meter_reader = _import_power_meter_module()
    config_path = tmp_path / "config.jsonc"
    config_path.write_text(
        json.dumps({"powerMeter": {"type": "p1_hw", "p1_hw": {}}}),
        encoding="utf-8",
    )

    with pytest.raises(ValueError, match="powerMeter.p1_hw.ip is required"):
        power_meter_reader.build_power_meter_reader(config_path=config_path)


def test_power_metere_loader_resolves_p1(tmp_path):
    power_meter_loader = _import_power_meter_loader_module()
    config_path = tmp_path / "config.jsonc"
    config_path.write_text(
        json.dumps({"powerMeter": {"type": "p1_hw", "p1_hw": {"ip": "127.0.0.1"}}}),
        encoding="utf-8",
    )

    reader = power_meter_loader.get_power_meter_reader(config_path=config_path)
    assert reader.__class__.__name__ == "P1PowerMeterReader"


def test_power_metere_loader_resolves_shelly(tmp_path):
    power_meter_loader = _import_power_meter_loader_module()
    config_path = tmp_path / "config.jsonc"
    config_path.write_text(
        json.dumps({"powerMeter": {"type": "shelly", "shelly": {"ip": "127.0.0.1"}}}),
        encoding="utf-8",
    )

    reader = power_meter_loader.get_power_meter_reader(config_path=config_path)
    assert reader.__class__.__name__ == "ShellyPowerMeterReader"


def test_power_metere_loader_rejects_invalid_identifier(tmp_path):
    power_meter_loader = _import_power_meter_loader_module()
    config_path = tmp_path / "config.jsonc"
    config_path.write_text(
        json.dumps({"powerMeter": {"type": "Shelly-1"}}),
        encoding="utf-8",
    )

    with pytest.raises(ValueError, match="Invalid powerMeter.type 'Shelly-1'"):
        power_meter_loader.get_power_meter_reader(config_path=config_path)


def test_power_metere_loader_rejects_unknown_module(tmp_path):
    power_meter_loader = _import_power_meter_loader_module()
    config_path = tmp_path / "config.jsonc"
    config_path.write_text(
        json.dumps({"powerMeter": {"type": "modbus"}}),
        encoding="utf-8",
    )

    with pytest.raises(ValueError, match="Unsupported powerMeter.type 'modbus'"):
        power_meter_loader.get_power_meter_reader(config_path=config_path)


def test_refresh_p1_for_api_uses_power_meter_reader():
    automate_www = _import_automate_www_module()
    app = automate_www.AutomationApp()
    app.logger = SimpleNamespace(
        info=lambda *_args, **_kwargs: None,
        warning=lambda *_args, **_kwargs: None,
        error=lambda *_args, **_kwargs: None,
        debug=lambda *_args, **_kwargs: None,
    )
    app.controller = SimpleNamespace(config_path=Path("/tmp/config.jsonc"))

    fake_reader = SimpleNamespace(read=lambda: {"total_power": -42, "source": "test-meter"})
    automate_www.get_power_meter_reader = lambda _config_path: fake_reader

    app._refresh_p1_for_api()

    assert app.api_state.last_p1 is not None
    assert app.api_state.last_p1.readings == {"total_power": -42, "source": "test-meter"}
    assert app.last_p1_total_power == -42


def test_apply_dynamic_power_command_reads_meter_once_and_passes_same_data():
    automate_www = _import_automate_www_module()
    device_controller = _import_device_controller_module()
    app = automate_www.AutomationApp()
    app.logger = SimpleNamespace(
        info=lambda *_args, **_kwargs: None,
        warning=lambda *_args, **_kwargs: None,
        error=lambda *_args, **_kwargs: None,
        debug=lambda *_args, **_kwargs: None,
    )
    app.controller = SimpleNamespace(
        config_path=Path("/tmp/config.jsonc"),
        set_power=lambda mode, p1_data=None: device_controller.PowerResult(
            success=True,
            power=123 if p1_data == {"total_power": -55} and mode == automate_www.POWER_MODE_NETZERO else 0,
        ),
    )

    app._accumulate_p1_data = lambda: {"total_power": -55}

    success, power, error = app._apply_dynamic_power_command(automate_www.POWER_MODE_NETZERO)

    assert success is True
    assert power == 123
    assert error is None
    assert app.api_state.last_p1 is not None
    assert app.api_state.last_p1.readings == {"total_power": -55}


def test_command_handler_uses_dynamic_power_setter_for_netzero():
    automate_www = _import_automate_www_module()
    calls = []
    logs = []
    handler = automate_www.CommandHandler(
        controller=SimpleNamespace(set_power=lambda value: (_ for _ in ()).throw(AssertionError("should not call controller.set_power for dynamic mode"))),
        schedule_controller=SimpleNamespace(),
        status_api=SimpleNamespace(post_update=lambda *args, **kwargs: calls.append(("status", args, kwargs))),
        logger=SimpleNamespace(
            info=lambda msg, *_args, **_kwargs: logs.append(("info", str(msg))),
            warning=lambda msg, *_args, **_kwargs: logs.append(("warning", str(msg))),
            error=lambda msg, *_args, **_kwargs: logs.append(("error", str(msg))),
            debug=lambda msg, *_args, **_kwargs: logs.append(("debug", str(msg))),
        ),
        dynamic_power_setter=lambda mode: (True, 321, None),
    )

    assert handler.handle("netzero") is True
    assert any(level == "info" and "Power set to netzero" in msg for level, msg in logs)
    assert calls


def test_controller_set_power_requires_p1_data_for_dynamic_modes():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    controller.test_mode = True

    result = device_controller.AutomateController.set_power(controller, "netzero", p1_data=None)

    assert result.success is False
    assert "must be supplied by the caller" in str(result.error)


def test_controller_calculate_netzero_power_requires_p1_data():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    controller.config_path = Path("/tmp/config.jsonc")

    with pytest.raises(ValueError, match="must be supplied by the caller"):
        device_controller.AutomateController.calculate_netzero_power(controller, mode="netzero", p1_data=None)


def test_controller_find_current_schedule_value_keeps_min_max_metadata():
    device_controller = _import_device_controller_module()
    controller = device_controller.ScheduleController.__new__(device_controller.ScheduleController)
    controller.last_schedule_entry = None
    controller.log = lambda *args, **kwargs: None

    resolved = [
        {"time": "0000", "value": 0, "key": "********0000"},
        {
            "time": "1500",
            "value": "netzero",
            "key": "********1500",
            "min_value": 100,
            "max_value": 700,
        },
    ]

    value = device_controller.ScheduleController._find_current_schedule_value(controller, resolved, "1515")

    assert value == "netzero"
    assert controller.last_schedule_entry["min_value"] == 100
    assert controller.last_schedule_entry["max_value"] == 700


def test_controller_set_power_clamps_netzero_to_schedule_bounds():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    controller.test_mode = True
    controller.calculate_netzero_power = lambda mode, p1_data, schedule_entry=None: -50
    controller._last_dynamic_power_context = {"raw_power": -50, "guarded_power": -50, "guard_active": False}
    controller._apply_schedule_power_bounds = device_controller.AutomateController._apply_schedule_power_bounds.__get__(controller, device_controller.AutomateController)
    controller._normalize_schedule_bound = device_controller.AutomateController._normalize_schedule_bound
    controller.log = lambda *args, **kwargs: None

    result = device_controller.AutomateController.set_power(
        controller,
        "netzero",
        p1_data={"total_power": 200},
        schedule_entry={"time": "1500", "key": "********1500", "min_value": 100},
    )

    assert result.success is True
    assert result.power == -100


def test_controller_set_power_clamps_netzero_plus_without_discharge():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    controller.test_mode = True
    controller.calculate_netzero_power = lambda mode, p1_data, schedule_entry=None: 50
    controller._last_dynamic_power_context = {"raw_power": 50, "guarded_power": 50, "guard_active": False}
    controller._apply_schedule_power_bounds = device_controller.AutomateController._apply_schedule_power_bounds.__get__(controller, device_controller.AutomateController)
    controller._normalize_schedule_bound = device_controller.AutomateController._normalize_schedule_bound
    controller.log = lambda *args, **kwargs: None

    result = device_controller.AutomateController.set_power(
        controller,
        "netzero+",
        p1_data={"total_power": 200},
        schedule_entry={"time": "1500", "key": "********1500", "min_value": 100},
    )

    assert result.success is True
    assert result.power == 100


def test_schedule_power_bounds_cap_netzero_magnitude_and_keep_direction():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    logs = []
    controller.log = lambda level, message, *args, **kwargs: logs.append((level, str(message)))

    bounded = device_controller.AutomateController._apply_schedule_power_bounds(
        controller,
        -300,
        mode="netzero",
        schedule_entry={"time": "1800", "key": "********1800", "max_value": 200},
        runtime_context={"raw_power": -300, "guarded_power": -300, "guard_active": False},
    )

    assert bounded == -200
    assert any("max_value capped netzero" in msg for _, msg in logs)


def test_schedule_power_bounds_cap_netzero_charge_direction_when_positive():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    controller.log = lambda *args, **kwargs: None

    bounded = device_controller.AutomateController._apply_schedule_power_bounds(
        controller,
        300,
        mode="netzero",
        schedule_entry={"time": "1800", "key": "********1800", "max_value": 200},
        runtime_context={"raw_power": 300, "guarded_power": 300, "guard_active": False},
    )

    assert bounded == 200


def test_schedule_power_bounds_ignore_invalid_bounds_with_debug_log():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    logs = []
    controller.log = lambda level, message, *args, **kwargs: logs.append((level, str(message)))

    bounded = device_controller.AutomateController._apply_schedule_power_bounds(
        controller,
        -120,
        mode="netzero",
        schedule_entry={"time": "1800", "key": "********1800", "min_value": "bad"},
        runtime_context={"raw_power": -120, "guarded_power": -120, "guard_active": False},
    )

    assert bounded == -120
    assert any(level == "debug" and "Ignoring invalid min_value" in msg for level, msg in logs)


def test_schedule_power_bounds_ignore_min_greater_than_max_with_debug_log():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    logs = []
    controller.log = lambda level, message, *args, **kwargs: logs.append((level, str(message)))

    bounded = device_controller.AutomateController._apply_schedule_power_bounds(
        controller,
        -120,
        mode="netzero",
        schedule_entry={"time": "1800", "key": "********1800", "min_value": 500, "max_value": 200},
        runtime_context={"raw_power": -120, "guarded_power": -120, "guard_active": False},
    )

    assert bounded == -120
    assert any("min_value 500 is greater than max_value 200" in msg for _, msg in logs)


def test_schedule_power_bounds_defer_to_reversal_guard():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    logs = []
    controller.log = lambda level, message, *args, **kwargs: logs.append((level, str(message)))

    bounded = device_controller.AutomateController._apply_schedule_power_bounds(
        controller,
        150,
        mode="netzero",
        schedule_entry={"time": "1800", "key": "********1800", "min_value": 100},
        runtime_context={"raw_power": -100, "guarded_power": 150, "guard_active": True},
    )

    assert bounded == 150
    assert any("ReversalRampGuard deferred min/max handling" in msg for _, msg in logs)


def test_base_logger_debug_never_dedups_even_with_message_key(capsys):
    device_controller = _import_device_controller_module()
    controller = device_controller.BaseDeviceController.__new__(device_controller.BaseDeviceController)
    controller.log_level = "DEBUG"
    controller._recent_log_messages = deque(maxlen=device_controller.BaseDeviceController._RECENT_LOG_WINDOW)

    device_controller.BaseDeviceController.log(controller, "debug", "debug trace", message_key="debug_trace")
    device_controller.BaseDeviceController.log(controller, "debug", "debug trace", message_key="debug_trace")

    lines = [line for line in capsys.readouterr().out.splitlines() if "debug trace" in line]
    assert len(lines) == 2


def test_base_logger_only_dedups_keyed_non_debug_logs(capsys):
    device_controller = _import_device_controller_module()
    controller = device_controller.BaseDeviceController.__new__(device_controller.BaseDeviceController)
    controller.log_level = "DEBUG"
    controller._recent_log_messages = deque(maxlen=device_controller.BaseDeviceController._RECENT_LOG_WINDOW)

    device_controller.BaseDeviceController.log(controller, "info", "same event", message_key="same_event")
    device_controller.BaseDeviceController.log(controller, "info", "same event", message_key="same_event")
    device_controller.BaseDeviceController.log(controller, "info", "same event without key")
    device_controller.BaseDeviceController.log(controller, "info", "same event without key")

    lines = [line for line in capsys.readouterr().out.splitlines() if "same event" in line]
    assert len(lines) == 3
    assert sum("same event without key" in line for line in lines) == 2


def test_base_logger_dedup_is_separated_by_level(capsys):
    device_controller = _import_device_controller_module()
    controller = device_controller.BaseDeviceController.__new__(device_controller.BaseDeviceController)
    controller.log_level = "DEBUG"
    controller._recent_log_messages = deque(maxlen=device_controller.BaseDeviceController._RECENT_LOG_WINDOW)

    device_controller.BaseDeviceController.log(controller, "info", "same key", message_key="shared_key")
    device_controller.BaseDeviceController.log(controller, "warning", "same key", message_key="shared_key")

    lines = [line for line in capsys.readouterr().out.splitlines() if "same key" in line]
    assert len(lines) == 2


def test_base_logger_keyed_log_can_emit_again_after_window_rollover(capsys):
    device_controller = _import_device_controller_module()
    controller = device_controller.BaseDeviceController.__new__(device_controller.BaseDeviceController)
    controller.log_level = "DEBUG"
    controller._recent_log_messages = deque(maxlen=device_controller.BaseDeviceController._RECENT_LOG_WINDOW)

    device_controller.BaseDeviceController.log(controller, "info", "target", message_key="target")
    for index in range(device_controller.BaseDeviceController._RECENT_LOG_WINDOW):
        device_controller.BaseDeviceController.log(
            controller,
            "info",
            f"other-{index}",
            message_key=f"other_{index}",
        )
    device_controller.BaseDeviceController.log(controller, "info", "target", message_key="target")

    lines = [line for line in capsys.readouterr().out.splitlines() if "target" in line]
    assert len(lines) == 2


def test_schedule_power_bounds_log_uses_message_key():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    logs = []
    controller.log = lambda level, message, *args, **kwargs: logs.append((level, str(message), kwargs.get("message_key")))

    bounded = device_controller.AutomateController._apply_schedule_power_bounds(
        controller,
        900,
        mode="netzero+",
        schedule_entry={"time": "1500", "key": "202603181400", "max_value": 400},
        runtime_context={"raw_power": 896, "guarded_power": 896, "guard_active": False},
    )

    assert bounded == 400
    assert ("info", "Applied schedule bounds for netzero+ slot 1500 (202603181400): raw=896, guarded=900, bounded=400, min=None, max=400", "schedule_bounds_applied") in logs


def test_controller_set_power_logs_when_device_limits_override_bounded_result():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    logs = []
    controller.test_mode = False
    controller.calculate_netzero_power = lambda mode, p1_data, schedule_entry=None: -50
    controller._last_dynamic_power_context = {"raw_power": -50, "guarded_power": -50, "guard_active": False}
    controller._apply_schedule_power_bounds = device_controller.AutomateController._apply_schedule_power_bounds.__get__(controller, device_controller.AutomateController)
    controller._normalize_schedule_bound = device_controller.AutomateController._normalize_schedule_bound
    controller._get_dynamic_power_context = device_controller.AutomateController._get_dynamic_power_context.__get__(controller, device_controller.AutomateController)
    controller._send_power_feed = lambda value: (True, None, 0)
    controller.log = lambda level, message, *args, **kwargs: logs.append((level, str(message)))

    result = device_controller.AutomateController.set_power(
        controller,
        "netzero",
        p1_data={"total_power": 200},
        schedule_entry={"time": "1800", "key": "********1800", "min_value": 100},
    )

    assert result.success is True
    assert result.power == 0
    assert any("Battery/device limits overrode bounded result" in msg for _, msg in logs)


def test_compute_wh_per_hour_uses_status_updates_sqlite(tmp_path):
    automate_www = _import_automate_www_module()
    db_path = tmp_path / "status_updates.db"
    with sqlite3.connect(db_path) as conn:
        conn.executescript(
            """
            CREATE TABLE status_updates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                old_value TEXT,
                new_value TEXT,
                p1_total_power INTEGER,
                electric_level INTEGER,
                timestamp INTEGER NOT NULL
            );
            """
        )
        # One 30-minute charge segment at 600W -> 300Wh.
        conn.execute(
            "INSERT INTO status_updates (type, new_value, electric_level, timestamp) VALUES (?, ?, ?, ?)",
            ("change", json.dumps(600), 77, 1735732800),
        )
        conn.execute(
            "INSERT INTO status_updates (type, new_value, electric_level, timestamp) VALUES (?, ?, ?, ?)",
            ("change", json.dumps(0), 78, 1735734600),
        )
        conn.commit()

    result = automate_www.compute_wh_per_hour(str(db_path), now=1735734600, days_back=0)
    end_dt = datetime.fromtimestamp(1735734600, tz=automate_www.ZoneInfo(automate_www.WH_PER_HOUR_TIMEZONE))
    today = end_dt.strftime("%Y-%m-%d")
    hour_bucket = next(item for item in result[today] if item["hour"] == end_dt.strftime("%H"))

    assert hour_bucket["charged_wh"] == pytest.approx(300.0)
    assert hour_bucket["discharged_wh"] == pytest.approx(0.0)
    assert hour_bucket["electric_level"] == 78


def test_compute_wh_per_hour_keeps_seed_point_before_visible_window(tmp_path):
    automate_www = _import_automate_www_module()
    tz = automate_www.ZoneInfo(automate_www.WH_PER_HOUR_TIMEZONE)
    db_path = tmp_path / "status_updates.db"
    with sqlite3.connect(db_path) as conn:
        conn.executescript(
            """
            CREATE TABLE status_updates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                old_value TEXT,
                new_value TEXT,
                p1_total_power INTEGER,
                electric_level INTEGER,
                timestamp INTEGER NOT NULL
            );
            """
        )
        window_start = int(datetime(2025, 1, 1, 0, 0, 0, tzinfo=tz).timestamp())
        conn.execute(
            "INSERT INTO status_updates (type, new_value, electric_level, timestamp) VALUES (?, ?, ?, ?)",
            ("change", json.dumps(600), 70, window_start - 300),
        )
        conn.execute(
            "INSERT INTO status_updates (type, new_value, electric_level, timestamp) VALUES (?, ?, ?, ?)",
            ("change", json.dumps(0), 71, window_start + 1800),
        )
        conn.commit()

    result = automate_www.compute_wh_per_hour(
        str(db_path),
        now=int(datetime(2025, 1, 1, 12, 0, 0, tzinfo=tz).timestamp()),
        days_back=0,
    )
    hour_bucket = result["2025-01-01"][0]

    assert hour_bucket["charged_wh"] == pytest.approx(300.0)
    assert hour_bucket["discharged_wh"] == pytest.approx(0.0)
    assert hour_bucket["electric_level"] == 71


def test_status_api_skips_redundant_change_rows_in_same_hour(tmp_path):
    automate_www = _import_automate_www_module()
    tz = automate_www.ZoneInfo(automate_www.STATUS_TIMEZONE)
    db_path = tmp_path / "status_updates.db"
    status_api = automate_www.StatusApi(
        logger=SimpleNamespace(warning=lambda *_args, **_kwargs: None),
        db_path=str(db_path),
        get_electric_level=lambda: 70,
    )
    base_ts = int(datetime(2025, 1, 1, 10, 5, 0, tzinfo=tz).timestamp())

    status_api._insert_status("change", None, 600, None, base_ts)
    status_api._insert_status("change", None, 600, None, base_ts + 300)

    with sqlite3.connect(db_path) as conn:
        rows = conn.execute(
            "SELECT type, new_value, electric_level, timestamp FROM status_updates ORDER BY timestamp ASC"
        ).fetchall()

    assert rows == [("change", "600", 70, base_ts)]


def test_status_api_keeps_first_change_row_in_new_hour(tmp_path):
    automate_www = _import_automate_www_module()
    tz = automate_www.ZoneInfo(automate_www.STATUS_TIMEZONE)
    db_path = tmp_path / "status_updates.db"
    status_api = automate_www.StatusApi(
        logger=SimpleNamespace(warning=lambda *_args, **_kwargs: None),
        db_path=str(db_path),
        get_electric_level=lambda: 70,
    )
    base_ts = int(datetime(2025, 1, 1, 10, 55, 0, tzinfo=tz).timestamp())

    status_api._insert_status("change", None, 600, None, base_ts)
    status_api._insert_status("change", None, 600, None, base_ts + 600)
    status_api._insert_status("change", None, 600, None, base_ts + 900)

    with sqlite3.connect(db_path) as conn:
        rows = conn.execute(
            "SELECT type, new_value, electric_level, timestamp FROM status_updates ORDER BY timestamp ASC"
        ).fetchall()

    assert rows == [
        ("change", "600", 70, base_ts),
        ("change", "600", 70, base_ts + 600),
    ]


def test_status_api_keeps_change_when_electric_level_changes(tmp_path):
    automate_www = _import_automate_www_module()
    tz = automate_www.ZoneInfo(automate_www.STATUS_TIMEZONE)
    db_path = tmp_path / "status_updates.db"
    levels = iter([70, 71])
    status_api = automate_www.StatusApi(
        logger=SimpleNamespace(warning=lambda *_args, **_kwargs: None),
        db_path=str(db_path),
        get_electric_level=lambda: next(levels),
    )
    base_ts = int(datetime(2025, 1, 1, 10, 5, 0, tzinfo=tz).timestamp())

    status_api._insert_status("change", None, 600, None, base_ts)
    status_api._insert_status("change", None, 600, None, base_ts + 300)

    with sqlite3.connect(db_path) as conn:
        rows = conn.execute(
            "SELECT type, new_value, electric_level, timestamp FROM status_updates ORDER BY timestamp ASC"
        ).fetchall()

    assert rows == [
        ("change", "600", 70, base_ts),
        ("change", "600", 71, base_ts + 300),
    ]


def test_status_api_keeps_change_when_power_changes(tmp_path):
    automate_www = _import_automate_www_module()
    tz = automate_www.ZoneInfo(automate_www.STATUS_TIMEZONE)
    db_path = tmp_path / "status_updates.db"
    status_api = automate_www.StatusApi(
        logger=SimpleNamespace(warning=lambda *_args, **_kwargs: None),
        db_path=str(db_path),
        get_electric_level=lambda: 70,
    )
    base_ts = int(datetime(2025, 1, 1, 10, 5, 0, tzinfo=tz).timestamp())

    status_api._insert_status("change", None, 600, None, base_ts)
    status_api._insert_status("change", None, 400, None, base_ts + 300)

    with sqlite3.connect(db_path) as conn:
        rows = conn.execute(
            "SELECT type, new_value, electric_level, timestamp FROM status_updates ORDER BY timestamp ASC"
        ).fetchall()

    assert rows == [
        ("change", "600", 70, base_ts),
        ("change", "400", 70, base_ts + 300),
    ]


def test_status_api_keeps_non_change_events_unchanged(tmp_path):
    automate_www = _import_automate_www_module()
    tz = automate_www.ZoneInfo(automate_www.STATUS_TIMEZONE)
    db_path = tmp_path / "status_updates.db"
    status_api = automate_www.StatusApi(
        logger=SimpleNamespace(warning=lambda *_args, **_kwargs: None),
        db_path=str(db_path),
        get_electric_level=lambda: 70,
    )
    ts = int(datetime(2025, 1, 1, 10, 5, 0, tzinfo=tz).timestamp())

    status_api._insert_status("start", None, None, None, ts)
    status_api._insert_status("start", None, None, None, ts + 60)

    with sqlite3.connect(db_path) as conn:
        rows = conn.execute(
            "SELECT type, new_value, electric_level, timestamp FROM status_updates ORDER BY timestamp ASC"
        ).fetchall()

    assert rows == [
        ("start", None, 70, ts),
        ("start", None, 70, ts + 60),
    ]


def test_status_api_initializes_change_insert_state_from_existing_db(tmp_path):
    automate_www = _import_automate_www_module()
    tz = automate_www.ZoneInfo(automate_www.STATUS_TIMEZONE)
    db_path = tmp_path / "status_updates.db"
    first_ts = int(datetime(2025, 1, 1, 10, 5, 0, tzinfo=tz).timestamp())
    with sqlite3.connect(db_path) as conn:
        conn.executescript(
            """
            CREATE TABLE status_updates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                old_value TEXT,
                new_value TEXT,
                p1_total_power INTEGER,
                electric_level INTEGER,
                timestamp INTEGER NOT NULL
            );
            """
        )
        conn.execute(
            "INSERT INTO status_updates (type, new_value, electric_level, timestamp) VALUES (?, ?, ?, ?)",
            ("change", json.dumps(600), 70, first_ts),
        )
        conn.commit()

    status_api = automate_www.StatusApi(
        logger=SimpleNamespace(warning=lambda *_args, **_kwargs: None),
        db_path=str(db_path),
        get_electric_level=lambda: 70,
    )
    status_api._ensure_db()
    status_api._insert_status("change", None, 600, None, first_ts + 300)
    status_api._insert_status("change", None, 600, None, first_ts + 3600)

    with sqlite3.connect(db_path) as conn:
        rows = conn.execute(
            "SELECT type, new_value, electric_level, timestamp FROM status_updates ORDER BY timestamp ASC"
        ).fetchall()

    assert rows == [
        ("change", "600", 70, first_ts),
        ("change", "600", 70, first_ts + 3600),
    ]


def test_load_change_points_preserves_electric_level_transitions(tmp_path):
    automate_www = _import_automate_www_module()
    tz = automate_www.ZoneInfo(automate_www.WH_PER_HOUR_TIMEZONE)
    db_path = tmp_path / "status_updates.db"
    with sqlite3.connect(db_path) as conn:
        conn.executescript(
            """
            CREATE TABLE status_updates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                old_value TEXT,
                new_value TEXT,
                p1_total_power INTEGER,
                electric_level INTEGER,
                timestamp INTEGER NOT NULL
            );
            """
        )
        base_ts = int(datetime(2025, 1, 1, 10, 0, 0, tzinfo=tz).timestamp())
        conn.executemany(
            "INSERT INTO status_updates (type, new_value, electric_level, timestamp) VALUES (?, ?, ?, ?)",
            [
                ("change", json.dumps(600), 70, base_ts),
                ("change", json.dumps(600), 70, base_ts + 300),
                ("change", json.dumps(600), 71, base_ts + 600),
                ("change", json.dumps(600), 71, base_ts + 900),
                ("change", json.dumps(0), 71, base_ts + 1200),
            ],
        )
        conn.commit()

    points = automate_www._load_change_points(str(db_path), window_start_ts=base_ts, tz=tz)

    assert points == [
        (base_ts, 600.0),
        (base_ts + 600, 600.0),
        (base_ts + 1200, 0.0),
    ]


def test_load_change_points_preserves_hour_anchor_rows(tmp_path):
    automate_www = _import_automate_www_module()
    tz = automate_www.ZoneInfo(automate_www.WH_PER_HOUR_TIMEZONE)
    db_path = tmp_path / "status_updates.db"
    with sqlite3.connect(db_path) as conn:
        conn.executescript(
            """
            CREATE TABLE status_updates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                old_value TEXT,
                new_value TEXT,
                p1_total_power INTEGER,
                electric_level INTEGER,
                timestamp INTEGER NOT NULL
            );
            """
        )
        base_ts = int(datetime(2025, 1, 1, 10, 20, 0, tzinfo=tz).timestamp())
        conn.executemany(
            "INSERT INTO status_updates (type, new_value, electric_level, timestamp) VALUES (?, ?, ?, ?)",
            [
                ("change", json.dumps(600), 70, base_ts),
                ("change", json.dumps(600), 70, base_ts + 1800),
                ("change", json.dumps(600), 70, base_ts + 2700),
                ("change", json.dumps(0), 70, base_ts + 3000),
            ],
        )
        conn.commit()

    points = automate_www._load_change_points(str(db_path), window_start_ts=base_ts, tz=tz)

    assert points == [
        (base_ts, 600.0),
        (base_ts + 2700, 600.0),
        (base_ts + 3000, 0.0),
    ]


def test_load_loop_config_prefers_selected_power_meter_interval():
    automate_www = _import_automate_www_module()
    app = automate_www.AutomationApp()
    app.controller = SimpleNamespace(
        config={
            "LOOP_INTERVAL_SECONDS": 20,
            "powerMeter": {
                "type": "shelly",
                "p1_hw": {"loopIntervalSeconds": 20},
                "shelly": {"loopIntervalSeconds": 5},
            },
            "POWER_FEED_MAX_DELTA": 300,
            "API_REFRESH_INTERVAL_SECONDS": 60,
            "ZERO_COUNT_THRESHOLD_STANDBY": 3,
        }
    )

    app._load_loop_config()

    assert app.loop_interval_seconds == 5


def test_load_loop_config_falls_back_to_global_interval():
    automate_www = _import_automate_www_module()
    app = automate_www.AutomationApp()
    app.controller = SimpleNamespace(
        config={
            "LOOP_INTERVAL_SECONDS": 25,
                "powerMeter": {
                "type": "p1_hw",
                "p1_hw": {},
            },
            "POWER_FEED_MAX_DELTA": 300,
            "API_REFRESH_INTERVAL_SECONDS": 60,
            "ZERO_COUNT_THRESHOLD_STANDBY": 3,
        }
    )

    app._load_loop_config()

    assert app.loop_interval_seconds == 25


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
