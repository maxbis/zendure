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
import tempfile
import threading
import time
import uuid
from collections import deque
from datetime import datetime, timedelta
from pathlib import Path
from zoneinfo import ZoneInfo
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
AUTOMATE_DATA_DIR = REPO_ROOT / "automate" / "data"

TEST_DESCRIPTIONS = [
    ("test_base_schedule_resolution_reads_object_entries", "Checks raw schedule object entries resolve into standard slot values."),
    ("test_base_schedule_resolution_propagates_signed_power_bounds", "Checks raw schedule netzero entries expose min_power and max_power in resolved slots."),
    ("test_base_schedule_resolution_propagates_signed_power_bounds_for_netzero_minus", "Checks raw schedule netzero- entries expose min_power and max_power in resolved slots."),
    ("test_base_schedule_resolution_rejects_non_integer_bounds", "Checks raw schedule min_power/max_power reject non-integer values."),
    ("test_resolver_wildcard_and_specific_rule_precedence", "Checks wildcard base rule and specific-hour override precedence."),
    ("test_resolver_emits_signed_power_bounds_from_netzero_minus_rules", "Checks resolver includes min_power and max_power for netzero- rules."),
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


def _unique_status_db_path() -> Path:
    AUTOMATE_DATA_DIR.mkdir(parents=True, exist_ok=True)
    return AUTOMATE_DATA_DIR / f"test_status_updates_{uuid.uuid4().hex}.db"


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


def test_base_schedule_resolution_propagates_signed_power_bounds():
    today, _ = _today_and_tomorrow_ymd()

    php_code = (
        f'require "{(REPO_ROOT / "main" / "api" / "charge_schedule_functions.php").as_posix()}"; '
        '$schedule=['
        '"********0000"=>["value"=>0],'
        f'"{today}1500"=>["value"=>"netzero","min_power"=>-700,"max_power"=>-100]'
        '];'
        f'echo json_encode(resolveScheduleForDate($schedule, "{today}"));'
    )
    payload = _run_php_json(["php", "-r", php_code])
    by_time = {str(item.get("time")): item for item in payload}

    assert by_time["1500"]["value"] == "netzero"
    assert by_time["1500"]["min_power"] == -700
    assert by_time["1500"]["max_power"] == -100


def test_base_schedule_resolution_propagates_signed_power_bounds_for_netzero_minus():
    today, _ = _today_and_tomorrow_ymd()

    php_code = (
        f'require "{(REPO_ROOT / "main" / "api" / "charge_schedule_functions.php").as_posix()}"; '
        '$schedule=['
        '"********0000"=>["value"=>0],'
        f'"{today}1500"=>["value"=>"netzero-","min_power"=>-700,"max_power"=>-100]'
        '];'
        f'echo json_encode(resolveScheduleForDate($schedule, "{today}"));'
    )
    payload = _run_php_json(["php", "-r", php_code])
    by_time = {str(item.get("time")): item for item in payload}

    assert by_time["1500"]["value"] == "netzero-"
    assert by_time["1500"]["min_power"] == -700
    assert by_time["1500"]["max_power"] == -100


def test_base_schedule_resolution_rejects_non_integer_bounds():
    today, _ = _today_and_tomorrow_ymd()

    php_code = (
        f'require "{(REPO_ROOT / "main" / "api" / "charge_schedule_functions.php").as_posix()}"; '
        '$schedule=['
        f'"{today}1500"=>["value"=>"netzero","min_power"=>"100.5"]'
        '];'
        f'try {{ resolveScheduleForDate($schedule, "{today}"); echo json_encode(["ok" => true]); }} '
        'catch (Exception $e) { echo json_encode(["ok" => false, "error" => $e->getMessage()]); }'
    )
    payload = _run_php_json(["php", "-r", php_code])

    assert payload["ok"] is False
    assert "min_power" in payload["error"]


def test_resolver_emits_signed_power_bounds_from_rules(backup_and_restore_price_files):
    today, tomorrow = _today_and_tomorrow_ymd()
    _write_json(_price_file_path(today), _build_hourly_prices(0.10))
    _write_json(_price_file_path(tomorrow), _build_hourly_prices(0.20))

    rules = [
        {"name": "default", "key": "************", "value": 0, "enabled": True},
        {
            "name": "signed-bounds",
            "key": "********1500",
            "value": "netzero",
            "min_power": -300,
            "max_power": 300,
            "enabled": True,
        },
    ]
    _write_json(CONDITIONS_FILE, rules)

    payload = _run_php_json(["php", str(RESOLVER_FILE)])
    assert payload.get("success") is True
    today_group = next((g for g in payload.get("resolved", []) if g.get("date") == today), None)
    assert isinstance(today_group, dict)
    by_time = {str(item.get("time")): item for item in today_group.get("items", [])}

    assert by_time["1500"]["value"] == "netzero"
    assert by_time["1500"]["min_power"] == -300
    assert by_time["1500"]["max_power"] == 300


def test_resolver_emits_signed_power_bounds_from_netzero_minus_rules(backup_and_restore_price_files):
    today, tomorrow = _today_and_tomorrow_ymd()
    _write_json(_price_file_path(today), _build_hourly_prices(0.10))
    _write_json(_price_file_path(tomorrow), _build_hourly_prices(0.20))

    rules = [
        {"name": "default", "key": "************", "value": 0, "enabled": True},
        {
            "name": "signed-bounds-netzero-minus",
            "key": "********1500",
            "value": "netzero-",
            "min_power": -300,
            "max_power": 0,
            "enabled": True,
        },
    ]
    _write_json(CONDITIONS_FILE, rules)

    payload = _run_php_json(["php", str(RESOLVER_FILE)])
    assert payload.get("success") is True
    today_group = next((g for g in payload.get("resolved", []) if g.get("date") == today), None)
    assert isinstance(today_group, dict)
    by_time = {str(item.get("time")): item for item in today_group.get("items", [])}

    assert by_time["1500"]["value"] == "netzero-"
    assert by_time["1500"]["min_power"] == -300
    assert by_time["1500"]["max_power"] == 0


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


def test_resolver_emits_am_pm_max_price_hour_value_refs(backup_and_restore_price_files):
    today, tomorrow = _today_and_tomorrow_ymd()
    today_prices = _build_hourly_prices(0.10)
    tomorrow_prices = _build_hourly_prices(0.20)
    today_prices["10"] = 0.99
    today_prices["18"] = 1.23
    _write_json(_price_file_path(today), today_prices)
    _write_json(_price_file_path(tomorrow), tomorrow_prices)
    _write_json(CONDITIONS_FILE, [{"name": "default", "key": "************", "value": 0, "enabled": True}])

    payload = _run_php_json(["php", str(RESOLVER_FILE)])
    assert payload.get("success") is True
    today_group = next((g for g in payload.get("resolved", []) if g.get("date") == today), None)
    assert isinstance(today_group, dict)
    assert today_group.get("max_price_hour_am") == 10
    assert today_group.get("max_price_hour_pm") == 18


def test_resolver_hour_conditions_support_am_pm_max_price_value_refs(backup_and_restore_price_files):
    today, tomorrow = _today_and_tomorrow_ymd()
    today_prices = _build_hourly_prices(0.10)
    tomorrow_prices = _build_hourly_prices(0.20)
    today_prices["10"] = 0.99
    today_prices["18"] = 1.23
    _write_json(_price_file_path(today), today_prices)
    _write_json(_price_file_path(tomorrow), tomorrow_prices)
    _write_json(
        CONDITIONS_FILE,
        [
            {
                "name": "am-max-hour",
                "key": "************",
                "value": 111,
                "conditions": [{"field": "hour", "op": "==", "value_ref": "max_price_hour_am"}],
                "enabled": True,
            },
            {
                "name": "pm-max-hour",
                "key": "************",
                "value": 222,
                "conditions": [{"field": "hour", "op": "==", "value_ref": "max_price_hour_pm"}],
                "enabled": True,
            },
        ],
    )

    payload = _run_php_json(["php", str(RESOLVER_FILE)])
    assert payload.get("success") is True
    today_group = next((g for g in payload.get("resolved", []) if g.get("date") == today), None)
    assert isinstance(today_group, dict)
    by_time = {str(item.get("time")): item for item in today_group.get("items", [])}

    assert by_time["1000"]["value"] == 111
    assert by_time["1800"]["value"] == 222
    assert sorted(by_time.keys()) == ["1000", "1800"]


def test_resolver_am_pm_max_price_value_refs_use_first_tie_and_allow_null_half_day(backup_and_restore_price_files):
    today, tomorrow = _today_and_tomorrow_ymd()
    today_prices = {f"{h:02d}": ("bad" if h >= 12 else 0.10) for h in range(24)}
    today_prices["09"] = 0.80
    today_prices["10"] = 0.80
    _write_json(_price_file_path(today), today_prices)
    _write_json(_price_file_path(tomorrow), _build_hourly_prices(0.20))
    _write_json(CONDITIONS_FILE, [{"name": "default", "key": "************", "value": 0, "enabled": True}])

    payload = _run_php_json(["php", str(RESOLVER_FILE)])
    assert payload.get("success") is True
    today_group = next((g for g in payload.get("resolved", []) if g.get("date") == today), None)
    assert isinstance(today_group, dict)
    assert today_group.get("max_price_hour_am") == 9
    assert today_group.get("max_price_hour_pm") is None


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


def _import_automate_api_module():
    automate_dir = REPO_ROOT / "automate"
    if str(automate_dir) not in sys.path:
        sys.path.insert(0, str(automate_dir))
    import automate_api  # type: ignore
    return automate_api


def _import_status_updates_store_module():
    automate_dir = REPO_ROOT / "automate"
    if str(automate_dir) not in sys.path:
        sys.path.insert(0, str(automate_dir))
    import status_updates_store  # type: ignore
    return status_updates_store


def _sus():
    return _import_status_updates_store_module()


def _create_status_updates_db(db_path: Path, rows: list[tuple] | None = None) -> None:
    db_path.parent.mkdir(parents=True, exist_ok=True)
    with sqlite3.connect(str(db_path)) as conn:
        conn.executescript(
            """
            CREATE TABLE IF NOT EXISTS status_updates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                old_value TEXT,
                new_value TEXT,
                p1_total_power INTEGER,
                electric_level INTEGER,
                total_act_x100 INTEGER,
                total_act_ret_x100 INTEGER,
                timestamp INTEGER NOT NULL
            );
            """
        )
        if rows:
            normalized_rows = [
                row if len(row) == 8 else (*row[:-1], None, None, row[-1])
                for row in rows
            ]
            conn.executemany(
                "INSERT INTO status_updates (type, old_value, new_value, p1_total_power, electric_level, total_act_x100, total_act_ret_x100, timestamp) "
                "VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                normalized_rows,
            )
        conn.commit()


def _start_test_api_server(
    *,
    with_db: bool = True,
    db_rows: list[tuple] | None = None,
    status_updates_delta_token: str | None = None,
    compute_wh_result: dict | None = None,
    fetch_schedule_error: Exception | None = None,
    manual_schedule_refresh_callback=None,
):
    automate_api = _import_automate_api_module()
    automate_www = _import_automate_www_module()
    sus = _import_status_updates_store_module()
    cleanup_callbacks: list[callable] = []
    db_path = Path(__file__) if with_db else (REPO_ROOT / "__missing_status_updates__.db")
    if with_db:
        original_connect = sus.sqlite3.connect
        shared_uri = f"file:api-tests-{time.time_ns()}?mode=memory&cache=shared"
        keeper_conn = sqlite3.connect(shared_uri, uri=True, check_same_thread=False)
        keeper_conn.executescript(
            """
            CREATE TABLE IF NOT EXISTS status_updates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                old_value TEXT,
                new_value TEXT,
                p1_total_power INTEGER,
                electric_level INTEGER,
                total_act_x100 INTEGER,
                total_act_ret_x100 INTEGER,
                timestamp INTEGER NOT NULL
            );
            """
        )
        if db_rows:
            normalized_rows = [
                row if len(row) == 8 else (*row[:-1], None, None, row[-1])
                for row in db_rows
            ]
            keeper_conn.executemany(
                "INSERT INTO status_updates (type, old_value, new_value, p1_total_power, electric_level, total_act_x100, total_act_ret_x100, timestamp) "
                "VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                normalized_rows,
            )
            keeper_conn.commit()

        def _connect_proxy(_path, *args, **kwargs):
            return original_connect(shared_uri, uri=True, check_same_thread=False)

        sus.sqlite3.connect = _connect_proxy
        cleanup_callbacks.append(lambda: setattr(sus.sqlite3, "connect", original_connect))
        cleanup_callbacks.append(keeper_conn.close)

    api_state = automate_api.ApiState()
    api_state.last_p1 = automate_www.P1Readings(readings={"total_power": -42}, timestamp=int(time.time()))
    api_state.last_zendure = automate_www.ZendureReadings(
        readings={"properties": {"electricLevel": 77}},
        timestamp=int(time.time()),
    )
    api_state.last_status = automate_www.StatusChange(
        event_type="change",
        old_value=0,
        new_value=-42,
        timestamp=int(time.time()),
    )
    api_state.last_status_by_type = {
        "start": automate_www.AutomationStatusEntry("start", None, None, None, int(time.time()) - 120),
        "change": automate_www.AutomationStatusEntry("change", 0, -42, -42, int(time.time())),
    }

    events = {
        "refresh_p1": 0,
        "refresh_zendure": 0,
        "restart": 0,
        "refresh_schedule": 0,
        "manual_schedule_refresh": 0,
        "status_post_update": [],
        "pause_set": [],
        "compute_wh_calls": 0,
        "controller_logs": [],
    }
    pause_state = {"active": False}
    controller = SimpleNamespace(log_level="INFO", max_charge_power=1200, slow_charge_max_power=200)

    def refresh_p1():
        events["refresh_p1"] += 1
        api_state.last_p1 = automate_www.P1Readings(readings={"total_power": 321}, timestamp=int(time.time()))

    def refresh_zendure():
        events["refresh_zendure"] += 1
        api_state.last_zendure = automate_www.ZendureReadings(
            readings={"properties": {"electricLevel": 88}},
            timestamp=int(time.time()),
        )

    def request_restart():
        events["restart"] += 1

    def fetch_schedule():
        events["refresh_schedule"] += 1
        if fetch_schedule_error is not None:
            raise fetch_schedule_error

    def post_update(*args):
        events["status_post_update"].append(args)

    def set_pause(value: bool):
        pause_state["active"] = value
        events["pause_set"].append(value)

    def compute_wh_per_hour(_now: int, _days: int) -> dict:
        events["compute_wh_calls"] += 1
        return compute_wh_result if compute_wh_result is not None else {"2025-01-01": []}

    def controller_log(level, message, *args, **kwargs):
        events["controller_logs"].append((level, message))

    controller.log = controller_log

    if manual_schedule_refresh_callback is None:
        def manual_schedule_refresh_callback():
            events["manual_schedule_refresh"] += 1

    status_api_wrapper = None
    if with_db:
        status_store = sus.StatusUpdatesStore(
            db_path=str(db_path),
            retention_days=7,
            log_warning=lambda _m: None,
        )
        status_api_wrapper = SimpleNamespace(
            db_path=str(db_path),
            post_update=post_update,
            store=status_store,
            compute_wh_per_hour=compute_wh_per_hour,
        )

    server = automate_api.create_http_server(
        api_state=api_state,
        db_path=str(db_path),
        schedule_controller=SimpleNamespace(fetch_schedule=fetch_schedule),
        status_api=status_api_wrapper,
        refresh_p1_callback=refresh_p1,
        refresh_zendure_callback=refresh_zendure,
        restart_callback=request_restart,
        pause_getter=lambda: pause_state["active"],
        pause_setter=set_pause,
        controller=controller,
        status_updates_delta_token=status_updates_delta_token,
        manual_schedule_refresh_callback=manual_schedule_refresh_callback,
        port=0,
        log_level_priorities={"DEBUG": 10, "INFO": 20, "WARNING": 30, "ERROR": 40},
    )
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    base_url = f"http://127.0.0.1:{server.server_address[1]}"

    def cleanup():
        try:
            server.shutdown()
        finally:
            server.server_close()
            thread.join(timeout=2)
            for callback in reversed(cleanup_callbacks):
                callback()

    return SimpleNamespace(
        base_url=base_url,
        server=server,
        thread=thread,
        events=events,
        api_state=api_state,
        controller=controller,
        cleanup=cleanup,
    )


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


def _make_minimal_automate_controller(device_controller_module):
    controller = device_controller_module.AutomateController.__new__(device_controller_module.AutomateController)
    controller.test_mode = True
    controller.config_path = Path("/tmp/config.jsonc")
    controller.config = {}
    controller.previous_power = None
    controller.power_feed_min_threshold = 30
    controller.power_feed_min_delta = 0
    controller.power_feed_max_delta = 300
    controller.limit_state = 0
    controller.min_charge_level = 15
    controller.max_charge_level = 96
    controller.max_discharge_power = 800
    controller.max_charge_power = 1200
    controller.slow_charge_start_level = None
    controller.slow_charge_max_power = None
    controller.device_ip = "127.0.0.1"
    controller.device_sn = "TEST-SN"
    controller.accumulator = SimpleNamespace(last_zendure_data=None)
    controller.reversal_ramp_guard = device_controller_module.ReversalRampGuard(enabled=True    )
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
    controller._calculate_new_settings = device_controller_module.AutomateController._calculate_new_settings.__get__(
        controller, device_controller_module.AutomateController
    )
    controller._apply_dynamic_slow_charge_limit = device_controller_module.AutomateController._apply_dynamic_slow_charge_limit.__get__(
        controller, device_controller_module.AutomateController
    )
    controller._normalize_schedule_bound = device_controller_module.AutomateController._normalize_schedule_bound
    return controller


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
        set_power=lambda mode, p1_data=None, p1_source=None: device_controller.PowerResult(
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


def test_apply_dynamic_power_command_passes_http_p1_source():
    automate_www = _import_automate_www_module()
    device_controller = _import_device_controller_module()
    app = automate_www.AutomationApp()
    app.logger = SimpleNamespace(
        info=lambda *_args, **_kwargs: None,
        warning=lambda *_args, **_kwargs: None,
        error=lambda *_args, **_kwargs: None,
        debug=lambda *_args, **_kwargs: None,
    )

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

    app._accumulate_p1_data = lambda: {"total_power": -55}
    app._last_p1_read_source = "http"

    success, power, error = app._apply_dynamic_power_command(automate_www.POWER_MODE_NETZERO)

    assert success is True
    assert power == 123
    assert error is None
    assert captured["p1_source"] == "http"
    assert captured["p1_data"] == {"total_power": -55}


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


def test_command_handler_uses_dynamic_power_setter_for_netzero_minus_alias():
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
        dynamic_power_setter=lambda mode: (True, -321, None),
    )

    assert handler.handle("nzm") is True
    assert any(level == "info" and "Power set to netzero-" in msg for level, msg in logs)
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
            "min_power": -700,
            "max_power": -100,
        },
    ]

    value = device_controller.ScheduleController._find_current_schedule_value(controller, resolved, "1515")

    assert value == "netzero"
    assert controller.last_schedule_entry["min_power"] == -700
    assert controller.last_schedule_entry["max_power"] == -100


def test_controller_set_power_clamps_netzero_to_schedule_bounds():
    device_controller = _import_device_controller_module()
    controller = _make_minimal_automate_controller(device_controller)
    controller.calculate_netzero_power = lambda mode, p1_data, schedule_entry=None, zendure_data=None, p1_source=None: -50
    controller._last_dynamic_power_context = {"raw_power": -50, "guarded_power": -50, "guard_active": False}
    controller._apply_schedule_power_bounds = device_controller.AutomateController._apply_schedule_power_bounds.__get__(controller, device_controller.AutomateController)

    result = device_controller.AutomateController.set_power(
        controller,
        "netzero",
        p1_data={"total_power": 200},
        schedule_entry={"time": "1500", "key": "********1500", "min_power": -700, "max_power": -100},
    )

    assert result.success is True
    assert result.power == -100


def test_controller_set_power_clamps_netzero_plus_without_discharge():
    device_controller = _import_device_controller_module()
    controller = _make_minimal_automate_controller(device_controller)
    controller.calculate_netzero_power = lambda mode, p1_data, schedule_entry=None, zendure_data=None, p1_source=None: 50
    controller._last_dynamic_power_context = {"raw_power": 50, "guarded_power": 50, "guard_active": False}
    controller._apply_schedule_power_bounds = device_controller.AutomateController._apply_schedule_power_bounds.__get__(controller, device_controller.AutomateController)

    result = device_controller.AutomateController.set_power(
        controller,
        "netzero+",
        p1_data={"total_power": 200},
        schedule_entry={"time": "1500", "key": "********1500", "min_power": 100, "max_power": 700},
    )

    assert result.success is True
    assert result.power == 100


def test_controller_set_power_applies_bounds_before_reversal_for_negative_only_slot():
    device_controller = _import_device_controller_module()
    controller = _make_minimal_automate_controller(device_controller)
    logs = []
    controller.log = lambda level, message, *args, **kwargs: logs.append((level, str(message)))
    controller.previous_power = -200
    controller.calculate_netzero_power = lambda mode, p1_data, schedule_entry=None, zendure_data=None, p1_source=None: 327
    controller._last_dynamic_power_context = {"raw_power": 327}
    controller._apply_schedule_power_bounds = device_controller.AutomateController._apply_schedule_power_bounds.__get__(controller, device_controller.AutomateController)

    result = device_controller.AutomateController.set_power(
        controller,
        "netzero",
        p1_data={"total_power": -527},
        schedule_entry={"time": "0900", "key": "202603290800", "min_power": -1200, "max_power": -400},
    )

    assert result.success is True
    assert result.power == -400
    assert not any("reversal detected after bounds" in msg for _, msg in logs)
    assert any("raw_target=327, bounded_target=-400, final_target=-400" in msg for _, msg in logs)


def test_controller_set_power_reversal_guard_uses_bounded_target_sign():
    device_controller = _import_device_controller_module()
    controller = _make_minimal_automate_controller(device_controller)
    logs = []
    controller.log = lambda level, message, *args, **kwargs: logs.append((level, str(message)))
    controller.previous_power = -200
    controller.calculate_netzero_power = lambda mode, p1_data, schedule_entry=None, zendure_data=None, p1_source=None: 50
    controller._last_dynamic_power_context = {"raw_power": 50}
    controller._apply_schedule_power_bounds = device_controller.AutomateController._apply_schedule_power_bounds.__get__(controller, device_controller.AutomateController)

    result = device_controller.AutomateController.set_power(
        controller,
        "netzero",
        p1_data={"total_power": -50},
        schedule_entry={"time": "1500", "key": "********1500", "min_power": 100, "max_power": 700},
    )

    assert result.success is True
    assert result.power == -100
    assert any("reversal detected after bounds" in msg for _, msg in logs)
    assert any("bounded_target=100, final_target=-100" in msg for _, msg in logs)


def test_calculate_netzero_power_allows_charge_in_bidirectional_netzero():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    controller.log = lambda *args, **kwargs: None
    controller.config = {}
    controller.accumulator = SimpleNamespace(last_zendure_data=None)
    controller.previous_power = 0
    controller.reversal_ramp_guard = device_controller.ReversalRampGuard(enabled=False)
    controller._calculate_new_settings = lambda p1_power, current_input, current_output, electric_level: (250, 0)

    result = device_controller.AutomateController.calculate_netzero_power(
        controller,
        mode="netzero",
        p1_data={"total_power": -250},
        zendure_data={"properties": {"inputLimit": 0, "outputLimit": 0, "electricLevel": 50}},
    )

    assert result == 250


def test_calculate_netzero_minus_power_never_charges():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    controller.log = lambda *args, **kwargs: None
    controller.config = {}
    controller.accumulator = SimpleNamespace(last_zendure_data=None)
    controller.previous_power = 0
    controller.reversal_ramp_guard = device_controller.ReversalRampGuard(enabled=False)
    controller._calculate_new_settings = lambda p1_power, current_input, current_output, electric_level: (250, 0)

    result = device_controller.AutomateController.calculate_netzero_power(
        controller,
        mode="netzero-",
        p1_data={"total_power": -250},
        zendure_data={"properties": {"inputLimit": 0, "outputLimit": 0, "electricLevel": 50}},
    )

    assert result == 0


def test_calculate_netzero_plus_power_never_discharges():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    controller.log = lambda *args, **kwargs: None
    controller.accumulator = SimpleNamespace(last_zendure_data=None)
    controller.previous_power = 0
    controller.reversal_ramp_guard = device_controller.ReversalRampGuard(enabled=False)
    controller._calculate_new_settings = lambda p1_power, current_input, current_output, electric_level: (0, 250)

    result = device_controller.AutomateController.calculate_netzero_power(
        controller,
        mode="netzero+",
        p1_data={"total_power": 250},
        zendure_data={"properties": {"inputLimit": 0, "outputLimit": 0, "electricLevel": 50}},
    )

    assert result == 0


def test_calculate_netzero_minus_power_returns_discharge_when_requested():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    controller.log = lambda *args, **kwargs: None
    controller.config = {}
    controller.accumulator = SimpleNamespace(last_zendure_data=None)
    controller.previous_power = 0
    controller.reversal_ramp_guard = device_controller.ReversalRampGuard(enabled=False)
    controller._calculate_new_settings = lambda p1_power, current_input, current_output, electric_level: (0, 250)

    result = device_controller.AutomateController.calculate_netzero_power(
        controller,
        mode="netzero-",
        p1_data={"total_power": 250},
        zendure_data={"properties": {"inputLimit": 0, "outputLimit": 0, "electricLevel": 50}},
    )

    assert result == -250


@pytest.mark.parametrize(
    ("target_w", "p1_power", "expected_adjusted"),
    [
        (-10, -250, -240),
        (20, 250, 230),
    ],
)
def test_calculate_netzero_power_applies_configured_target_offset(target_w, p1_power, expected_adjusted):
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    controller.log = lambda *args, **kwargs: None
    controller.config = {"NETZERO_TARGET_W": target_w}
    controller.accumulator = SimpleNamespace(last_zendure_data=None)
    controller.previous_power = 0
    controller.reversal_ramp_guard = device_controller.ReversalRampGuard(enabled=False)

    captured = {}

    def fake_calculate_new_settings(p1_power, current_input, current_output, electric_level):
        captured["p1_power"] = p1_power
        return (0, 0)

    controller._calculate_new_settings = fake_calculate_new_settings

    device_controller.AutomateController.calculate_netzero_power(
        controller,
        mode="netzero",
        p1_data={"total_power": p1_power},
        zendure_data={"properties": {"inputLimit": 0, "outputLimit": 0, "electricLevel": 50}},
    )

    assert captured["p1_power"] == expected_adjusted
    assert controller._last_dynamic_power_context["p1_power"] == p1_power
    assert controller._last_dynamic_power_context["netzero_target_w"] == target_w
    assert controller._last_dynamic_power_context["adjusted_p1_power"] == expected_adjusted


def test_calculate_netzero_minus_power_applies_configured_target_offset():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    controller.log = lambda *args, **kwargs: None
    controller.config = {"NETZERO_TARGET_W": -25}
    controller.accumulator = SimpleNamespace(last_zendure_data=None)
    controller.previous_power = 0
    controller.reversal_ramp_guard = device_controller.ReversalRampGuard(enabled=False)

    captured = {}

    def fake_calculate_new_settings(p1_power, current_input, current_output, electric_level):
        captured["p1_power"] = p1_power
        return (0, 0)

    controller._calculate_new_settings = fake_calculate_new_settings

    device_controller.AutomateController.calculate_netzero_power(
        controller,
        mode="netzero-",
        p1_data={"total_power": 250},
        zendure_data={"properties": {"inputLimit": 0, "outputLimit": 0, "electricLevel": 50}},
    )

    assert captured["p1_power"] == 275
    assert controller._last_dynamic_power_context["netzero_target_w"] == -25


def test_controller_set_power_applies_max_delta_to_fixed_values():
    device_controller = _import_device_controller_module()
    controller = _make_minimal_automate_controller(device_controller)
    controller.previous_power = 0

    result = device_controller.AutomateController.set_power(controller, 800)

    assert result.success is True
    assert result.power == 300


def test_controller_send_power_feed_limits_discharge_to_configured_max():
    device_controller = _import_device_controller_module()
    controller = _make_minimal_automate_controller(device_controller)
    controller.max_discharge_power = 1200

    success, error, actual_power = device_controller.AutomateController._send_power_feed(controller, -1500)

    assert success is True
    assert error is None
    assert actual_power == -1200
    assert controller.previous_power == -1200


def test_controller_send_power_feed_limits_charge_to_configured_max():
    device_controller = _import_device_controller_module()
    controller = _make_minimal_automate_controller(device_controller)
    controller.max_charge_power = 1200

    success, error, actual_power = device_controller.AutomateController._send_power_feed(controller, 1500)

    assert success is True
    assert error is None
    assert actual_power == 1200
    assert controller.previous_power == 1200


def test_dynamic_slow_charge_limit_caps_dynamic_charge_near_full():
    device_controller = _import_device_controller_module()
    controller = _make_minimal_automate_controller(device_controller)
    controller.slow_charge_start_level = 80
    controller.slow_charge_max_power = 200

    new_input, new_output = device_controller.AutomateController._calculate_new_settings(
        controller,
        p1_power=-650,
        current_input=0,
        current_output=0,
        electric_level=80,
    )

    assert new_input == 200
    assert new_output == 0


def test_dynamic_slow_charge_limit_does_not_apply_below_threshold():
    device_controller = _import_device_controller_module()
    controller = _make_minimal_automate_controller(device_controller)
    controller.slow_charge_start_level = 80
    controller.slow_charge_max_power = 200

    new_input, new_output = device_controller.AutomateController._calculate_new_settings(
        controller,
        p1_power=-650,
        current_input=0,
        current_output=0,
        electric_level=79,
    )

    assert new_input == 650
    assert new_output == 0


def test_dynamic_slow_charge_limit_does_not_override_max_charge_level_stop():
    device_controller = _import_device_controller_module()
    controller = _make_minimal_automate_controller(device_controller)
    controller.slow_charge_start_level = 80
    controller.slow_charge_max_power = 200
    controller.max_charge_level = 80

    new_input, new_output = device_controller.AutomateController._calculate_new_settings(
        controller,
        p1_power=-650,
        current_input=0,
        current_output=0,
        electric_level=80,
    )

    assert new_input == 0
    assert new_output == 0


def test_fixed_power_command_bypasses_dynamic_slow_charge_limit():
    device_controller = _import_device_controller_module()
    controller = _make_minimal_automate_controller(device_controller)
    controller.slow_charge_start_level = 80
    controller.slow_charge_max_power = 200

    result = device_controller.AutomateController.set_power(controller, 500)

    assert result.success is True
    assert result.power == 500


def test_controller_set_power_reaches_target_in_300w_steps():
    device_controller = _import_device_controller_module()
    controller = _make_minimal_automate_controller(device_controller)
    controller.previous_power = 0

    step_one = device_controller.AutomateController.set_power(controller, 1000)
    step_two = device_controller.AutomateController.set_power(controller, 1000)
    step_three = device_controller.AutomateController.set_power(controller, 1000)
    step_four = device_controller.AutomateController.set_power(controller, 1000)

    assert step_one.success is True
    assert step_two.success is True
    assert step_three.success is True
    assert step_four.success is True
    assert [step_one.power, step_two.power, step_three.power, step_four.power] == [300, 600, 900, 1000]
    assert controller.previous_power == 1000


def test_controller_set_power_netzero_from_zero_with_p1_400_sends_expected_zendure_command(monkeypatch):
    device_controller = _import_device_controller_module()
    controller = _make_minimal_automate_controller(device_controller)
    controller.test_mode = False
    controller.previous_power = 0
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

    result = device_controller.AutomateController.set_power(
        controller,
        "netzero",
        p1_data={"total_power": 400},
        zendure_data={"properties": {"inputLimit": 0, "outputLimit": 0, "electricLevel": 50}},
    )

    print("Zendure API request:", json.dumps(sent_requests[0], indent=2, sort_keys=True))
    print("Applied power:", result.power)

    assert result.success is True
    assert result.power == -300
    assert sent_requests == [
        {
            "url": "http://127.0.0.1/properties/write",
            "json": {
                "sn": "TEST-SN",
                "properties": {
                    "acMode": 2,
                    "outputLimit": 300,
                    "inputLimit": 0,
                    "smartMode": 1,
                },
            },
            "timeout": device_controller.BaseDeviceController.REQUEST_TIMEOUT,
            "headers": {"Content-Type": "application/json"},
        }
    ]


def test_controller_set_power_applies_max_delta_to_netzero_values():
    device_controller = _import_device_controller_module()
    controller = _make_minimal_automate_controller(device_controller)
    controller.previous_power = -179
    controller.calculate_netzero_power = lambda mode, p1_data, schedule_entry=None, zendure_data=None, p1_source=None: -1000
    controller._last_dynamic_power_context = {"raw_power": -1000, "guarded_power": -1000, "guard_active": False}
    controller._apply_schedule_power_bounds = device_controller.AutomateController._apply_schedule_power_bounds.__get__(controller, device_controller.AutomateController)

    result = device_controller.AutomateController.set_power(
        controller,
        "netzero",
        p1_data={"total_power": 200},
        schedule_entry={"time": "1500", "key": "********1500"},
    )

    assert result.success is True
    assert result.power == -479


def test_controller_set_power_skips_max_delta_without_previous_power():
    device_controller = _import_device_controller_module()
    controller = _make_minimal_automate_controller(device_controller)

    result = device_controller.AutomateController.set_power(controller, -650)

    assert result.success is True
    assert result.power == -650


def test_controller_test_mode_updates_previous_power_for_simulated_send():
    device_controller = _import_device_controller_module()
    controller = _make_minimal_automate_controller(device_controller)

    result = device_controller.AutomateController.set_power(controller, -147)

    assert result.success is True
    assert result.power == -147
    assert controller.previous_power == -147


def test_controller_test_mode_uses_simulated_previous_power_for_next_max_delta_step():
    device_controller = _import_device_controller_module()
    controller = _make_minimal_automate_controller(device_controller)
    controller.calculate_netzero_power = lambda mode, p1_data, schedule_entry=None, zendure_data=None, p1_source=None: -800
    controller._last_dynamic_power_context = {"raw_power": -800, "guarded_power": -800, "guard_active": False}
    controller._apply_schedule_power_bounds = device_controller.AutomateController._apply_schedule_power_bounds.__get__(controller, device_controller.AutomateController)

    first = device_controller.AutomateController.set_power(controller, -147)
    second = device_controller.AutomateController.set_power(
        controller,
        "netzero",
        p1_data={"total_power": 200},
        schedule_entry={"time": "1500", "key": "********1500"},
    )

    assert first.success is True
    assert second.success is True
    assert second.power == -447
    assert controller.previous_power == -447


def test_controller_test_mode_skips_network_calls():
    device_controller = _import_device_controller_module()
    controller = _make_minimal_automate_controller(device_controller)

    def _fail_post(*_args, **_kwargs):
        raise AssertionError("requests.post should not be called in test mode")

    original_post = requests.post
    requests.post = _fail_post
    try:
        result = device_controller.AutomateController.set_power(controller, 200)
    finally:
        requests.post = original_post

    assert result.success is True
    assert result.power == 200
    assert controller.previous_power == 200


def test_controller_test_mode_skips_duplicate_simulated_send_after_state_update():
    device_controller = _import_device_controller_module()
    controller = _make_minimal_automate_controller(device_controller)
    logs = []
    controller.log = lambda level, message, *args, **kwargs: logs.append((level, str(message)))

    first = device_controller.AutomateController.set_power(controller, 150)
    second = device_controller.AutomateController.set_power(controller, 150)

    assert first.success is True
    assert second.success is True
    assert controller.previous_power == 150
    assert any("Power value unchanged (150 W), skipping device update" in msg for _, msg in logs)


def test_controller_test_mode_resends_duplicate_when_live_snapshot_disagrees():
    device_controller = _import_device_controller_module()
    controller = _make_minimal_automate_controller(device_controller)
    controller.previous_power = -800
    logs = []
    controller.log = lambda level, message, *args, **kwargs: logs.append((level, str(message), kwargs.get("message_key")))

    result = device_controller.AutomateController.set_power(
        controller,
        -800,
        zendure_data={"properties": {"inputLimit": 240, "outputLimit": 0, "electricLevel": 100}},
    )

    assert result.success is True
    assert result.power == -800
    assert controller.previous_power == -800
    assert any(key == "stale_device_state_detected" for _, _, key in logs)
    assert not any("Power value unchanged (-800 W), skipping device update" in msg for _, msg, _ in logs)


def test_schedule_power_bounds_clamp_to_signed_discharge_range():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    logs = []
    controller.log = lambda level, message, *args, **kwargs: logs.append((level, str(message)))

    bounded = device_controller.AutomateController._apply_schedule_power_bounds(
        controller,
        -300,
        mode="netzero",
        schedule_entry={"time": "1800", "key": "********1800", "min_power": -200, "max_power": -100},
        runtime_context={"raw_power": -300},
    )

    assert bounded == -200
    assert any("Signed power bounds clamped netzero" in msg for _, msg in logs)


def test_schedule_power_bounds_raise_into_signed_discharge_range():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    controller.log = lambda *args, **kwargs: None

    bounded = device_controller.AutomateController._apply_schedule_power_bounds(
        controller,
        -50,
        mode="netzero",
        schedule_entry={"time": "1800", "key": "********1800", "min_power": -700, "max_power": -100},
        runtime_context={"raw_power": -50},
    )

    assert bounded == -100


def test_schedule_power_bounds_clamp_positive_result_into_signed_range():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    controller.log = lambda *args, **kwargs: None

    bounded = device_controller.AutomateController._apply_schedule_power_bounds(
        controller,
        300,
        mode="netzero",
        schedule_entry={"time": "1800", "key": "********1800", "min_power": -200, "max_power": 200},
        runtime_context={"raw_power": 300},
    )

    assert bounded == 200


def test_schedule_power_bounds_raise_into_signed_charge_range():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    controller.log = lambda *args, **kwargs: None

    bounded = device_controller.AutomateController._apply_schedule_power_bounds(
        controller,
        50,
        mode="netzero+",
        schedule_entry={"time": "1800", "key": "********1800", "min_power": 100, "max_power": 700},
        runtime_context={"raw_power": 50},
    )

    assert bounded == 100


def test_schedule_power_bounds_allow_cross_zero_negative_value():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    controller.log = lambda *args, **kwargs: None

    bounded = device_controller.AutomateController._apply_schedule_power_bounds(
        controller,
        -50,
        mode="netzero",
        schedule_entry={"time": "1800", "key": "********1800", "min_power": -300, "max_power": 300},
        runtime_context={"raw_power": -50},
    )

    assert bounded == -50


def test_schedule_power_bounds_allow_cross_zero_positive_value():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    controller.log = lambda *args, **kwargs: None

    bounded = device_controller.AutomateController._apply_schedule_power_bounds(
        controller,
        250,
        mode="netzero+",
        schedule_entry={"time": "1800", "key": "********1800", "min_power": -300, "max_power": 300},
        runtime_context={"raw_power": 250},
    )

    assert bounded == 250


def test_schedule_power_bounds_apply_only_min_power_when_present():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    controller.log = lambda *args, **kwargs: None

    bounded = device_controller.AutomateController._apply_schedule_power_bounds(
        controller,
        50,
        mode="netzero+",
        schedule_entry={"time": "1800", "key": "********1800", "min_power": 100},
        runtime_context={"raw_power": 50},
    )

    assert bounded == 100


def test_schedule_power_bounds_apply_only_max_power_when_present():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    controller.log = lambda *args, **kwargs: None

    bounded = device_controller.AutomateController._apply_schedule_power_bounds(
        controller,
        900,
        mode="netzero+",
        schedule_entry={"time": "1800", "key": "********1800", "max_power": 400},
        runtime_context={"raw_power": 900},
    )

    assert bounded == 400


def test_schedule_power_bounds_ignore_invalid_bounds_with_debug_log():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    logs = []
    controller.log = lambda level, message, *args, **kwargs: logs.append((level, str(message)))

    bounded = device_controller.AutomateController._apply_schedule_power_bounds(
        controller,
        -120,
        mode="netzero",
        schedule_entry={"time": "1800", "key": "********1800", "min_power": "bad"},
        runtime_context={"raw_power": -120},
    )

    assert bounded == -120
    assert any(level == "debug" and "Ignoring invalid min_power" in msg for level, msg in logs)


def test_schedule_power_bounds_ignore_min_greater_than_max_with_debug_log():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    logs = []
    controller.log = lambda level, message, *args, **kwargs: logs.append((level, str(message)))

    bounded = device_controller.AutomateController._apply_schedule_power_bounds(
        controller,
        -120,
        mode="netzero",
        schedule_entry={"time": "1800", "key": "********1800", "min_power": 500, "max_power": 200},
        runtime_context={"raw_power": -120},
    )

    assert bounded == -120
    assert any("min_power 500 is greater than max_power 200" in msg for _, msg in logs)


def test_schedule_power_bounds_always_apply_before_reversal():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    logs = []
    controller.log = lambda level, message, *args, **kwargs: logs.append((level, str(message)))

    bounded = device_controller.AutomateController._apply_schedule_power_bounds(
        controller,
        150,
        mode="netzero",
        schedule_entry={"time": "1800", "key": "********1800", "min_power": -100, "max_power": 100},
        runtime_context={"raw_power": -100},
    )

    assert bounded == 100
    assert not any("ReversalRampGuard deferred signed bound handling" in msg for _, msg in logs)


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
        schedule_entry={"time": "1500", "key": "202603181400", "min_power": 100, "max_power": 400},
        runtime_context={"raw_power": 896},
    )

    assert bounded == 400
    assert ("info", "Applied schedule bounds for netzero+ slot 1500 (202603181400): raw=896, bounded=400, min=100, max=400", "schedule_bounds_applied") in logs


def test_controller_set_power_logs_when_device_limits_override_bounded_result():
    device_controller = _import_device_controller_module()
    controller = device_controller.AutomateController.__new__(device_controller.AutomateController)
    logs = []
    controller.test_mode = False
    controller.previous_power = None
    controller.power_feed_max_delta = 300
    controller.calculate_netzero_power = lambda mode, p1_data, schedule_entry=None, zendure_data=None, p1_source=None: -50
    controller._last_dynamic_power_context = {"raw_power": -50, "guarded_power": -50, "guard_active": False}
    controller.reversal_ramp_guard = device_controller.ReversalRampGuard(enabled=True)
    controller._apply_schedule_power_bounds = device_controller.AutomateController._apply_schedule_power_bounds.__get__(controller, device_controller.AutomateController)
    controller._apply_power_feed_max_delta = device_controller.AutomateController._apply_power_feed_max_delta.__get__(controller, device_controller.AutomateController)
    controller._resolve_power_target = device_controller.AutomateController._resolve_power_target.__get__(controller, device_controller.AutomateController)
    controller._normalize_schedule_bound = device_controller.AutomateController._normalize_schedule_bound
    controller._get_dynamic_power_context = device_controller.AutomateController._get_dynamic_power_context.__get__(controller, device_controller.AutomateController)
    controller._send_power_feed = lambda value, zendure_data=None: (True, None, 0)
    controller.log = lambda level, message, *args, **kwargs: logs.append((level, str(message)))

    result = device_controller.AutomateController.set_power(
        controller,
        "netzero",
        p1_data={"total_power": 200},
        schedule_entry={"time": "1800", "key": "********1800", "min_power": -700, "max_power": -100},
    )

    assert result.success is True
    assert result.power == 0
    assert any("Battery/device limits overrode bounded result" in msg for _, msg in logs)


def test_compute_wh_per_hour_uses_status_updates_sqlite(tmp_path):
    sus = _sus()
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

    result = sus.compute_wh_per_hour(str(db_path), now=1735734600, days_back=0)
    end_dt = datetime.fromtimestamp(1735734600, tz=ZoneInfo(sus.WH_PER_HOUR_TIMEZONE))
    today = end_dt.strftime("%Y-%m-%d")
    hour_bucket = next(item for item in result[today] if item["hour"] == end_dt.strftime("%H"))

    assert hour_bucket["charged_wh"] == pytest.approx(300.0)
    assert hour_bucket["discharged_wh"] == pytest.approx(0.0)
    assert hour_bucket["electric_level"] == 78


def test_compute_wh_per_hour_keeps_seed_point_before_visible_window(tmp_path):
    sus = _sus()
    tz = ZoneInfo(sus.WH_PER_HOUR_TIMEZONE)
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

    result = sus.compute_wh_per_hour(
        str(db_path),
        now=int(datetime(2025, 1, 1, 12, 0, 0, tzinfo=tz).timestamp()),
        days_back=0,
    )
    hour_bucket = result["2025-01-01"][0]

    assert hour_bucket["charged_wh"] == pytest.approx(300.0)
    assert hour_bucket["discharged_wh"] == pytest.approx(0.0)
    assert hour_bucket["electric_level"] == 71


def test_status_api_skips_redundant_change_rows_in_same_hour(tmp_path):
    sus = _sus()
    tz = ZoneInfo(sus.STATUS_TIMEZONE)
    db_path = tmp_path / "status_updates.db"
    status_api = sus.StatusApi(
        logger=SimpleNamespace(warning=lambda *_args, **_kwargs: None),
        db_path=str(db_path),
        get_electric_level=lambda: 70,
    )
    base_ts = int(datetime(2025, 1, 1, 10, 5, 0, tzinfo=tz).timestamp())

    status_api.store.insert_status("change", None, 600, None, 70, None, None, base_ts)
    status_api.store.insert_status("change", None, 600, None, 70, None, None, base_ts + 300)

    with sqlite3.connect(db_path) as conn:
        rows = conn.execute(
            "SELECT type, new_value, electric_level, timestamp FROM status_updates ORDER BY timestamp ASC"
        ).fetchall()

    assert rows == [("change", "600", 70, base_ts)]


def test_status_api_keeps_first_change_row_in_new_hour(tmp_path):
    sus = _sus()
    tz = ZoneInfo(sus.STATUS_TIMEZONE)
    db_path = tmp_path / "status_updates.db"
    status_api = sus.StatusApi(
        logger=SimpleNamespace(warning=lambda *_args, **_kwargs: None),
        db_path=str(db_path),
        get_electric_level=lambda: 70,
    )
    base_ts = int(datetime(2025, 1, 1, 10, 55, 0, tzinfo=tz).timestamp())

    status_api.store.insert_status("change", None, 600, None, 70, None, None, base_ts)
    status_api.store.insert_status("change", None, 600, None, 70, None, None, base_ts + 600)
    status_api.store.insert_status("change", None, 600, None, 70, None, None, base_ts + 900)

    with sqlite3.connect(db_path) as conn:
        rows = conn.execute(
            "SELECT type, new_value, electric_level, timestamp FROM status_updates ORDER BY timestamp ASC"
        ).fetchall()

    assert rows == [
        ("change", "600", 70, base_ts),
        ("change", "600", 70, base_ts + 600),
    ]


def test_status_api_keeps_change_when_electric_level_changes(tmp_path):
    sus = _sus()
    tz = ZoneInfo(sus.STATUS_TIMEZONE)
    db_path = tmp_path / "status_updates.db"
    status_api = sus.StatusApi(
        logger=SimpleNamespace(warning=lambda *_args, **_kwargs: None),
        db_path=str(db_path),
        get_electric_level=lambda: 70,
    )
    base_ts = int(datetime(2025, 1, 1, 10, 5, 0, tzinfo=tz).timestamp())

    status_api.store.insert_status("change", None, 600, None, 70, None, None, base_ts)
    status_api.store.insert_status("change", None, 600, None, 71, None, None, base_ts + 300)

    with sqlite3.connect(db_path) as conn:
        rows = conn.execute(
            "SELECT type, new_value, electric_level, timestamp FROM status_updates ORDER BY timestamp ASC"
        ).fetchall()

    assert rows == [
        ("change", "600", 70, base_ts),
        ("change", "600", 71, base_ts + 300),
    ]


def test_status_api_keeps_change_when_power_changes(tmp_path):
    sus = _sus()
    tz = ZoneInfo(sus.STATUS_TIMEZONE)
    db_path = tmp_path / "status_updates.db"
    status_api = sus.StatusApi(
        logger=SimpleNamespace(warning=lambda *_args, **_kwargs: None),
        db_path=str(db_path),
        get_electric_level=lambda: 70,
    )
    base_ts = int(datetime(2025, 1, 1, 10, 5, 0, tzinfo=tz).timestamp())

    status_api.store.insert_status("change", None, 600, None, 70, None, None, base_ts)
    status_api.store.insert_status("change", None, 400, None, 70, None, None, base_ts + 300)

    with sqlite3.connect(db_path) as conn:
        rows = conn.execute(
            "SELECT type, new_value, electric_level, timestamp FROM status_updates ORDER BY timestamp ASC"
        ).fetchall()

    assert rows == [
        ("change", "600", 70, base_ts),
        ("change", "400", 70, base_ts + 300),
    ]


def test_status_api_keeps_non_change_events_unchanged(tmp_path):
    sus = _sus()
    tz = ZoneInfo(sus.STATUS_TIMEZONE)
    db_path = tmp_path / "status_updates.db"
    status_api = sus.StatusApi(
        logger=SimpleNamespace(warning=lambda *_args, **_kwargs: None),
        db_path=str(db_path),
        get_electric_level=lambda: 70,
    )
    ts = int(datetime(2025, 1, 1, 10, 5, 0, tzinfo=tz).timestamp())

    status_api.store.insert_status("start", None, None, None, 70, None, None, ts)
    status_api.store.insert_status("start", None, None, None, 70, None, None, ts + 60)

    with sqlite3.connect(db_path) as conn:
        rows = conn.execute(
            "SELECT type, new_value, electric_level, timestamp FROM status_updates ORDER BY timestamp ASC"
        ).fetchall()

    assert rows == [
        ("start", None, 70, ts),
        ("start", None, 70, ts + 60),
    ]


def test_status_api_initializes_change_insert_state_from_existing_db(tmp_path):
    sus = _sus()
    tz = ZoneInfo(sus.STATUS_TIMEZONE)
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

    status_api = sus.StatusApi(
        logger=SimpleNamespace(warning=lambda *_args, **_kwargs: None),
        db_path=str(db_path),
        get_electric_level=lambda: 70,
    )
    status_api.store.ensure_db()
    status_api.store.insert_status("change", None, 600, None, 70, None, None, first_ts + 300)
    status_api.store.insert_status("change", None, 600, None, 70, None, None, first_ts + 3600)

    with sqlite3.connect(db_path) as conn:
        rows = conn.execute(
            "SELECT type, new_value, electric_level, timestamp FROM status_updates ORDER BY timestamp ASC"
        ).fetchall()

    assert rows == [
        ("change", "600", 70, first_ts),
        ("change", "600", 70, first_ts + 3600),
    ]


def test_status_api_insert_does_not_run_retention_cleanup():
    sus = _sus()
    tz = ZoneInfo(sus.STATUS_TIMEZONE)
    db_path = _unique_status_db_path()
    try:
        status_api = sus.StatusApi(
            logger=SimpleNamespace(warning=lambda *_args, **_kwargs: None),
            db_path=str(db_path),
            retention_days=1,
        )
        old_ts = int(datetime(2025, 1, 1, 10, 0, 0, tzinfo=tz).timestamp())
        new_ts = old_ts + (2 * 24 * 60 * 60)

        status_api.store.insert_status("start", None, None, None, None, None, None, old_ts)
        status_api.store.insert_status("start", None, None, None, None, None, None, new_ts)

        with sqlite3.connect(db_path) as conn:
            rows = conn.execute(
                "SELECT timestamp FROM status_updates ORDER BY timestamp ASC"
            ).fetchall()
    finally:
        if db_path.exists():
            try:
                db_path.unlink()
            except PermissionError:
                pass

    assert rows == [(old_ts,), (new_ts,)]


def test_status_api_cleanup_old_rows_deletes_only_expired_rows():
    sus = _sus()
    tz = ZoneInfo(sus.STATUS_TIMEZONE)
    db_path = _unique_status_db_path()
    try:
        status_api = sus.StatusApi(
            logger=SimpleNamespace(warning=lambda *_args, **_kwargs: None),
            db_path=str(db_path),
            retention_days=1,
        )
        now_ts = int(datetime(2025, 1, 3, 12, 0, 0, tzinfo=tz).timestamp())
        old_ts = now_ts - (2 * 24 * 60 * 60)
        fresh_ts = now_ts - (12 * 60 * 60)

        status_api.store.insert_status("start", None, None, None, None, None, None, old_ts)
        status_api.store.insert_status("start", None, None, None, None, None, None, fresh_ts)

        assert status_api.cleanup_old_rows(now_ts) is True

        with sqlite3.connect(db_path) as conn:
            rows = conn.execute(
                "SELECT timestamp FROM status_updates ORDER BY timestamp ASC"
            ).fetchall()
    finally:
        if db_path.exists():
            try:
                db_path.unlink()
            except PermissionError:
                pass

    assert rows == [(fresh_ts,)]


def test_automation_app_retention_cleanup_requires_loop_gate():
    automate_www = _import_automate_www_module()
    app = automate_www.AutomationApp()
    cleanup_calls: list[int] = []
    app.status_api = SimpleNamespace(cleanup_old_rows=lambda now_ts: cleanup_calls.append(now_ts) or True)

    app.loop_counter = automate_www.RETENTION_CLEANUP_LOOP_INTERVAL - 1
    app._maybe_run_retention_cleanup(now_ts=1000)

    assert cleanup_calls == []
    assert app.last_retention_cleanup_ts is None


def test_automation_app_retention_cleanup_checks_elapsed_time():
    automate_www = _import_automate_www_module()
    app = automate_www.AutomationApp()
    cleanup_calls: list[int] = []
    app.status_api = SimpleNamespace(cleanup_old_rows=lambda now_ts: cleanup_calls.append(now_ts) or True)
    app.loop_counter = automate_www.RETENTION_CLEANUP_LOOP_INTERVAL

    app._maybe_run_retention_cleanup(now_ts=1000)
    app._maybe_run_retention_cleanup(
        now_ts=1000 + automate_www.RETENTION_CLEANUP_INTERVAL_SECONDS - 1
    )
    app._maybe_run_retention_cleanup(
        now_ts=1000 + automate_www.RETENTION_CLEANUP_INTERVAL_SECONDS
    )

    assert cleanup_calls == [
        1000,
        1000 + automate_www.RETENTION_CLEANUP_INTERVAL_SECONDS,
    ]
    assert app.last_retention_cleanup_ts == 1000 + automate_www.RETENTION_CLEANUP_INTERVAL_SECONDS


def test_automation_app_retention_cleanup_failure_does_not_update_timestamp():
    automate_www = _import_automate_www_module()
    app = automate_www.AutomationApp()
    app.status_api = SimpleNamespace(cleanup_old_rows=lambda _now_ts: False)
    app.loop_counter = automate_www.RETENTION_CLEANUP_LOOP_INTERVAL

    app._maybe_run_retention_cleanup(now_ts=2000)

    assert app.last_retention_cleanup_ts is None


def test_load_change_points_preserves_electric_level_transitions(tmp_path):
    sus = _sus()
    tz = ZoneInfo(sus.WH_PER_HOUR_TIMEZONE)
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

    points = sus._load_change_points(str(db_path), window_start_ts=base_ts, tz=tz)

    assert points == [
        (base_ts, 600.0),
        (base_ts + 600, 600.0),
        (base_ts + 1200, 0.0),
    ]


def test_load_change_points_preserves_hour_anchor_rows(tmp_path):
    sus = _sus()
    tz = ZoneInfo(sus.WH_PER_HOUR_TIMEZONE)
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

    points = sus._load_change_points(str(db_path), window_start_ts=base_ts, tz=tz)

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


def test_load_loop_config_reads_fast_loop_interval():
    automate_www = _import_automate_www_module()
    app = automate_www.AutomationApp()
    app.controller = SimpleNamespace(
        config={
            "LOOP_INTERVAL_SECONDS": 20,
            "FAST_LOOP_INTERVAL_SECONDS": 2,
            "powerMeter": {
                "type": "shelly",
                "shelly": {"loopIntervalSeconds": 6},
            },
            "API_REFRESH_INTERVAL_SECONDS": 60,
        }
    )

    app._load_loop_config()

    assert app.loop_interval_seconds == 6
    assert app.fast_loop_interval_seconds == 2


def test_apply_power_settings_enables_and_clears_fast_loop():
    automate_www = _import_automate_www_module()
    app = automate_www.AutomationApp()
    app.old_value = -200
    app.value = -200
    app.fast_loop_active = False
    app.schedule_controller = SimpleNamespace(last_schedule_entry=None)
    app.logger = SimpleNamespace(
        info=lambda *_args, **_kwargs: None,
        warning=lambda *_args, **_kwargs: None,
        error=lambda *_args, **_kwargs: None,
        debug=lambda *_args, **_kwargs: None,
    )
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
    app.controller = SimpleNamespace(
        set_power=lambda *_args, **_kwargs: responses.popleft()
    )

    app._apply_power_settings(automate_www.POWER_MODE_NETZERO, {"total_power": 500})
    assert app.fast_loop_active is True
    assert app.value == -600

    app.old_value = app.value
    app._apply_power_settings(automate_www.POWER_MODE_NETZERO, {"total_power": 450})
    assert app.fast_loop_active is False
    assert app.value == -600


def test_apply_power_settings_passes_http_p1_source():
    automate_www = _import_automate_www_module()
    device_controller = _import_device_controller_module()
    app = automate_www.AutomationApp()
    app.old_value = -200
    app.value = -200
    app.fast_loop_active = False
    app._last_p1_read_source = "http"
    app.schedule_controller = SimpleNamespace(last_schedule_entry=None)
    app.logger = SimpleNamespace(
        info=lambda *_args, **_kwargs: None,
        warning=lambda *_args, **_kwargs: None,
        error=lambda *_args, **_kwargs: None,
        debug=lambda *_args, **_kwargs: None,
    )
    app.status_api = SimpleNamespace(post_update=lambda *args, **kwargs: None)

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

    app._apply_power_settings(automate_www.POWER_MODE_NETZERO, {"total_power": 320})

    assert captured["p1_source"] == "http"
    assert captured["p1_data"] == {"total_power": 320}


def test_sleep_interrupted_uses_fast_loop_interval(monkeypatch):
    automate_www = _import_automate_www_module()
    app = automate_www.AutomationApp()
    app.loop_interval_seconds = 6
    app.fast_loop_interval_seconds = 2
    app.fast_loop_active = True
    app.shutdown_requested = False
    app.steps = []
    app.logger = SimpleNamespace(
        info=lambda *_args, **_kwargs: None,
        warning=lambda *_args, **_kwargs: None,
        error=lambda *_args, **_kwargs: None,
        debug=lambda *_args, **_kwargs: None,
    )
    app._handle_user_input = lambda: True

    sleep_calls: list[float] = []

    monkeypatch.setattr(automate_www.time, "sleep", lambda seconds: sleep_calls.append(seconds))
    monkeypatch.setattr(
        automate_www.time,
        "localtime",
        lambda: SimpleNamespace(tm_sec=1),
    )

    app._sleep_interrupted()

    assert sleep_calls == [1, 1]


def test_create_http_server_wires_shared_state_and_callbacks():
    automate_api = _import_automate_api_module()
    sus = _import_status_updates_store_module()

    api_state = automate_api.ApiState()
    schedule_controller = SimpleNamespace()
    status_api = SimpleNamespace(
        store=sus.StatusUpdatesStore(
            db_path=":memory:",
            retention_days=7,
            log_warning=lambda _m: None,
        ),
    )
    controller = SimpleNamespace(log_level="INFO")
    manual_refresh_calls: list[str] = []

    def _noop():
        return None

    server = automate_api.create_http_server(
        api_state=api_state,
        db_path=":memory:",
        schedule_controller=schedule_controller,
        status_api=status_api,
        refresh_p1_callback=_noop,
        refresh_zendure_callback=_noop,
        restart_callback=_noop,
        pause_getter=lambda: False,
        pause_setter=lambda _value: None,
        controller=controller,
        status_updates_delta_token="token123",
        manual_schedule_refresh_callback=lambda: manual_refresh_calls.append("refresh"),
        port=0,
        log_level_priorities={"DEBUG": 10, "INFO": 20, "WARNING": 30, "ERROR": 40},
    )
    try:
        assert server.api_state is api_state
        assert server.schedule_controller is schedule_controller
        assert server.status_api is status_api
        assert server.controller is controller
        assert server.status_updates_delta_token == "token123"
        assert callable(server.manual_schedule_refresh_callback)
    finally:
        server.server_close()


def test_api_discovery_and_status_endpoints():
    runtime = _start_test_api_server()
    try:
        test_response = requests.get(f"{runtime.base_url}/api/test", timeout=2)
        assert test_response.status_code == 200
        test_payload = test_response.json()
        assert test_payload["path"] == "/api/test"
        assert any(item["path"] == "/api/p1" for item in test_payload["endpoints"])

        p1_response = requests.get(f"{runtime.base_url}/api/p1?max_age=0", timeout=2)
        assert p1_response.status_code == 200
        assert p1_response.json()["readings"]["total_power"] == 321
        assert runtime.events["refresh_p1"] == 1

        zendure_response = requests.get(f"{runtime.base_url}/api/zendure?max_age=0", timeout=2)
        assert zendure_response.status_code == 200
        assert zendure_response.json()["readings"]["properties"]["electricLevel"] == 88
        assert runtime.events["refresh_zendure"] == 1

        status_response = requests.get(f"{runtime.base_url}/api/status", timeout=2)
        assert status_response.status_code == 200
        assert status_response.json()["eventType"] == "change"

        all_response = requests.get(f"{runtime.base_url}/api/all", timeout=2)
        assert all_response.status_code == 200
        all_payload = all_response.json()
        assert all_payload["p1"]["readings"]["total_power"] == 321
        assert all_payload["zendure"]["readings"]["properties"]["electricLevel"] == 88
        assert all_payload["status"]["eventType"] == "change"

        automation_status_response = requests.get(f"{runtime.base_url}/api/automation_status", timeout=2)
        assert automation_status_response.status_code == 200
        automation_payload = automation_status_response.json()
        assert automation_payload["success"] is True
        assert automation_payload["entryCount"] == 2
        assert automation_payload["lastChanges"][0]["type"] == "change"
    finally:
        runtime.cleanup()


def test_api_control_endpoints_and_unknown_path():
    runtime = _start_test_api_server()
    try:
        refresh_response = requests.get(f"{runtime.base_url}/api/refresh", timeout=2)
        assert refresh_response.status_code == 200
        assert refresh_response.json()["ok"] is True
        assert runtime.events["refresh_schedule"] == 1
        assert runtime.events["manual_schedule_refresh"] == 1
        assert runtime.events["status_post_update"][0][0] == "Rescan"

        restart_response = requests.post(f"{runtime.base_url}/api/restart", timeout=2)
        assert restart_response.status_code == 200
        assert restart_response.json()["ok"] is True
        assert runtime.events["restart"] == 1

        pause_get_response = requests.get(f"{runtime.base_url}/api/pause", timeout=2)
        assert pause_get_response.status_code == 200
        assert pause_get_response.json()["pauseActive"] is False

        pause_on_response = requests.post(f"{runtime.base_url}/api/pause?state=on", timeout=2)
        assert pause_on_response.status_code == 200
        assert pause_on_response.json()["pauseActive"] is True

        pause_off_response = requests.post(f"{runtime.base_url}/api/pause?state=off", timeout=2)
        assert pause_off_response.status_code == 200
        assert pause_off_response.json()["pauseActive"] is False
        assert runtime.events["pause_set"] == [True, False]

        loglevel_get_response = requests.get(f"{runtime.base_url}/api/loglevel", timeout=2)
        assert loglevel_get_response.status_code == 200
        assert loglevel_get_response.json()["level"] == "INFO"

        loglevel_post_response = requests.post(f"{runtime.base_url}/api/loglevel?level=debug", timeout=2)
        assert loglevel_post_response.status_code == 200
        assert loglevel_post_response.json()["level"] == "DEBUG"
        assert runtime.controller.log_level == "DEBUG"

        invalid_loglevel_response = requests.post(f"{runtime.base_url}/api/loglevel?level=wat", timeout=2)
        assert invalid_loglevel_response.status_code == 400

        missing_response = requests.get(f"{runtime.base_url}/api/does-not-exist", timeout=2)
        assert missing_response.status_code == 404
    finally:
        runtime.cleanup()


def test_api_refresh_does_not_arm_manual_refresh_when_fetch_fails():
    runtime = _start_test_api_server(fetch_schedule_error=RuntimeError("boom"))
    try:
        refresh_response = requests.get(f"{runtime.base_url}/api/refresh", timeout=2)
        assert refresh_response.status_code == 500
        assert refresh_response.json()["ok"] is False
        assert runtime.events["refresh_schedule"] == 1
        assert runtime.events["manual_schedule_refresh"] == 0
        assert runtime.events["status_post_update"] == []
    finally:
        runtime.cleanup()


def test_api_wh_per_hour_endpoint_and_cache():
    runtime = _start_test_api_server(compute_wh_result={"2025-01-01": [{"hour": "00", "charged_wh": 1.0, "discharged_wh": 0.0, "electric_level": 77}]})
    try:
        response_one = requests.get(f"{runtime.base_url}/api/wh_per_hour?days=0", timeout=2)
        assert response_one.status_code == 200
        assert response_one.json()["2025-01-01"][0]["charged_wh"] == 1.0
        assert runtime.events["compute_wh_calls"] == 1

        response_two = requests.get(f"{runtime.base_url}/api/wh_per_hour?days=0", timeout=2)
        assert response_two.status_code == 200
        assert runtime.events["compute_wh_calls"] == 1
    finally:
        runtime.cleanup()


def test_api_wh_per_hour_returns_error_when_db_missing():
    runtime = _start_test_api_server(with_db=False)
    try:
        response = requests.get(f"{runtime.base_url}/api/wh_per_hour?days=0", timeout=2)
        assert response.status_code == 200
        assert response.json()["error"] == "Status updates database not available"
    finally:
        runtime.cleanup()


def test_api_status_updates_delta_endpoint_behaviors():
    db_rows = [
        ("change", "0", "-42", -42, 77, 5258788, 4573494, 1000),
        ("change", "-42", "-84", -84, 76, 5258792, 4573499, 1010),
    ]
    runtime = _start_test_api_server(db_rows=db_rows, status_updates_delta_token="secret")
    try:
        unauthorized_response = requests.get(
            f"{runtime.base_url}/api/status_updates_delta?after_id=0",
            timeout=2,
        )
        assert unauthorized_response.status_code == 401

        missing_after_id_response = requests.get(
            f"{runtime.base_url}/api/status_updates_delta?token=secret",
            timeout=2,
        )
        assert missing_after_id_response.status_code == 400

        success_response = requests.get(
            f"{runtime.base_url}/api/status_updates_delta?after_id=0&token=secret",
            timeout=2,
        )
        assert success_response.status_code == 200
        payload = success_response.json()
        assert len(payload["rows"]) == 2
        assert payload["rows"][0]["type"] == "change"
        assert payload["rows"][0]["total_act"] == 52587.88
        assert payload["rows"][0]["total_act_ret"] == 45734.94
        assert payload["max_id_returned"] == payload["rows"][-1]["id"]
        assert payload["has_more"] is False
    finally:
        runtime.cleanup()


def test_api_state_dependent_endpoint_returns_503_when_api_state_missing():
    runtime = _start_test_api_server()
    try:
        runtime.server.api_state = None
        response = requests.get(f"{runtime.base_url}/api/status", timeout=2)
        assert response.status_code == 503
        assert response.json()["error"] == "API state not initialized"
    finally:
        runtime.cleanup()


def test_api_slow_charge_max_power_get_and_post():
    runtime = _start_test_api_server()
    try:
        get_response = requests.get(f"{runtime.base_url}/api/slow_charge_max_power", timeout=2)
        assert get_response.status_code == 200
        assert get_response.json()["slowChargeMaxPower"] == 200
        assert get_response.json()["maxChargePower"] == 1200

        post_response = requests.post(f"{runtime.base_url}/api/slow_charge_max_power?value=300", timeout=2)
        assert post_response.status_code == 200
        assert post_response.json()["slowChargeMaxPower"] == 300
        assert runtime.controller.slow_charge_max_power == 300
        assert runtime.events["controller_logs"][-1][0] == "info"
        assert "SLOW_CHARGE_MAX_POWER" in runtime.events["controller_logs"][-1][1]
        assert "200 -> 300" in runtime.events["controller_logs"][-1][1]
    finally:
        runtime.cleanup()


def test_api_slow_charge_max_power_validation_and_missing_controller():
    runtime = _start_test_api_server()
    try:
        negative_response = requests.post(f"{runtime.base_url}/api/slow_charge_max_power?value=-1", timeout=2)
        assert negative_response.status_code == 400

        too_high_response = requests.post(f"{runtime.base_url}/api/slow_charge_max_power?value=1201", timeout=2)
        assert too_high_response.status_code == 400

        missing_value_response = requests.post(f"{runtime.base_url}/api/slow_charge_max_power", timeout=2)
        assert missing_value_response.status_code == 400

        invalid_value_response = requests.post(f"{runtime.base_url}/api/slow_charge_max_power?value=abc", timeout=2)
        assert invalid_value_response.status_code == 400

        runtime.server.controller = None
        missing_controller_get = requests.get(f"{runtime.base_url}/api/slow_charge_max_power", timeout=2)
        assert missing_controller_get.status_code == 503

        missing_controller_post = requests.post(f"{runtime.base_url}/api/slow_charge_max_power?value=300", timeout=2)
        assert missing_controller_post.status_code == 503
    finally:
        runtime.cleanup()


def test_api_charge_level_get_and_post_normalizes_values():
    runtime = _start_test_api_server()
    try:
        runtime.controller.min_charge_level = 15
        runtime.controller.max_charge_level = 93

        get_min_response = requests.get(f"{runtime.base_url}/api/min_charge_level", timeout=2)
        assert get_min_response.status_code == 200
        assert get_min_response.json()["minChargeLevel"] == 15
        assert get_min_response.json()["maxChargeLevel"] == 93

        get_max_response = requests.get(f"{runtime.base_url}/api/max_charge_level", timeout=2)
        assert get_max_response.status_code == 200
        assert get_max_response.json()["minChargeLevel"] == 15
        assert get_max_response.json()["maxChargeLevel"] == 93

        post_min_response = requests.post(f"{runtime.base_url}/api/min_charge_level?value=93", timeout=2)
        assert post_min_response.status_code == 200
        assert post_min_response.json()["minChargeLevel"] == 93
        assert post_min_response.json()["maxChargeLevel"] == 93
        assert runtime.controller.min_charge_level == 93
        assert runtime.controller.max_charge_level == 93

        post_max_response = requests.post(f"{runtime.base_url}/api/max_charge_level?value=20", timeout=2)
        assert post_max_response.status_code == 200
        assert post_max_response.json()["minChargeLevel"] == 20
        assert post_max_response.json()["maxChargeLevel"] == 20
        assert runtime.controller.min_charge_level == 20
        assert runtime.controller.max_charge_level == 20
        assert runtime.events["controller_logs"][-1][0] == "info"
        assert "MIN_CHARGE_LEVEL 93 -> 20%" in runtime.events["controller_logs"][-1][1]
        assert "MAX_CHARGE_LEVEL 93 -> 20%" in runtime.events["controller_logs"][-1][1]

        clamp_min_response = requests.post(f"{runtime.base_url}/api/min_charge_level?value=150", timeout=2)
        assert clamp_min_response.status_code == 200
        assert clamp_min_response.json()["minChargeLevel"] == 100
        assert clamp_min_response.json()["maxChargeLevel"] == 100
        assert runtime.controller.min_charge_level == 100
        assert runtime.controller.max_charge_level == 100

        clamp_max_response = requests.post(f"{runtime.base_url}/api/max_charge_level?value=-10", timeout=2)
        assert clamp_max_response.status_code == 200
        assert clamp_max_response.json()["minChargeLevel"] == 0
        assert clamp_max_response.json()["maxChargeLevel"] == 0
        assert runtime.controller.min_charge_level == 0
        assert runtime.controller.max_charge_level == 0
    finally:
        runtime.cleanup()


def test_api_charge_level_validation_and_missing_controller():
    runtime = _start_test_api_server()
    try:
        missing_min_value = requests.post(f"{runtime.base_url}/api/min_charge_level", timeout=2)
        assert missing_min_value.status_code == 400

        invalid_min_value = requests.post(f"{runtime.base_url}/api/min_charge_level?value=abc", timeout=2)
        assert invalid_min_value.status_code == 400

        missing_max_value = requests.post(f"{runtime.base_url}/api/max_charge_level", timeout=2)
        assert missing_max_value.status_code == 400

        invalid_max_value = requests.post(f"{runtime.base_url}/api/max_charge_level?value=abc", timeout=2)
        assert invalid_max_value.status_code == 400

        runtime.server.controller = None
        missing_controller_get_min = requests.get(f"{runtime.base_url}/api/min_charge_level", timeout=2)
        assert missing_controller_get_min.status_code == 503

        missing_controller_post_min = requests.post(f"{runtime.base_url}/api/min_charge_level?value=25", timeout=2)
        assert missing_controller_post_min.status_code == 503

        missing_controller_get_max = requests.get(f"{runtime.base_url}/api/max_charge_level", timeout=2)
        assert missing_controller_get_max.status_code == 503

        missing_controller_post_max = requests.post(f"{runtime.base_url}/api/max_charge_level?value=80", timeout=2)
        assert missing_controller_post_max.status_code == 503
    finally:
        runtime.cleanup()


def test_standby_check_sends_standby_only_once_per_zero_period(monkeypatch):
    automate_www = _import_automate_www_module()
    app = automate_www.AutomationApp()
    app.value = 0
    app.logger = SimpleNamespace(
        info=lambda *_args, **_kwargs: None,
        warning=lambda *_args, **_kwargs: None,
        error=lambda *_args, **_kwargs: None,
        debug=lambda *_args, **_kwargs: None,
    )

    standby_calls: list[str] = []
    app.controller = SimpleNamespace(
        set_standby_mode=lambda: standby_calls.append("standby") or SimpleNamespace(success=True)
    )

    current_time = 1000.0
    monkeypatch.setattr(automate_www.time, "time", lambda: current_time)

    app._handle_standby_check()
    assert app.zero_power_since == 1000.0
    assert app.standby_sent is False
    assert standby_calls == []

    current_time = 1000.0 + automate_www.STANDBY_DELAY_SECONDS - 1
    app._handle_standby_check()
    assert standby_calls == []
    assert app.standby_sent is False

    current_time = 1000.0 + automate_www.STANDBY_DELAY_SECONDS
    app._handle_standby_check()
    assert standby_calls == ["standby"]
    assert app.standby_sent is True
    assert app.zero_power_since is None

    current_time = 1000.0 + (2 * automate_www.STANDBY_DELAY_SECONDS)
    app._handle_standby_check()
    assert standby_calls == ["standby"]
    assert app.standby_sent is True


def test_standby_check_allows_new_standby_after_nonzero_period(monkeypatch):
    automate_www = _import_automate_www_module()
    app = automate_www.AutomationApp()
    app.logger = SimpleNamespace(
        info=lambda *_args, **_kwargs: None,
        warning=lambda *_args, **_kwargs: None,
        error=lambda *_args, **_kwargs: None,
        debug=lambda *_args, **_kwargs: None,
    )

    standby_calls: list[str] = []
    app.controller = SimpleNamespace(
        set_standby_mode=lambda: standby_calls.append("standby") or SimpleNamespace(success=True)
    )

    current_time = 2000.0
    monkeypatch.setattr(automate_www.time, "time", lambda: current_time)

    app.value = 0
    app._handle_standby_check()
    current_time = 2000.0 + automate_www.STANDBY_DELAY_SECONDS
    app._handle_standby_check()
    assert standby_calls == ["standby"]
    assert app.standby_sent is True

    app.value = 150
    app._handle_standby_check()
    assert app.zero_power_since is None
    assert app.standby_sent is False

    app.value = 0
    current_time = 3000.0
    app._handle_standby_check()
    current_time = 3000.0 + automate_www.STANDBY_DELAY_SECONDS
    app._handle_standby_check()
    assert standby_calls == ["standby", "standby"]
    assert app.standby_sent is True


def test_standby_check_retries_after_failed_standby(monkeypatch):
    automate_www = _import_automate_www_module()
    app = automate_www.AutomationApp()
    app.value = 0
    app.logger = SimpleNamespace(
        info=lambda *_args, **_kwargs: None,
        warning=lambda *_args, **_kwargs: None,
        error=lambda *_args, **_kwargs: None,
        debug=lambda *_args, **_kwargs: None,
    )

    results = deque([False, True])
    standby_calls: list[str] = []
    app.controller = SimpleNamespace(
        set_standby_mode=lambda: standby_calls.append("standby") or SimpleNamespace(success=results.popleft())
    )

    current_time = 4000.0
    monkeypatch.setattr(automate_www.time, "time", lambda: current_time)

    app._handle_standby_check()
    current_time = 4000.0 + automate_www.STANDBY_DELAY_SECONDS
    app._handle_standby_check()
    assert standby_calls == ["standby"]
    assert app.standby_sent is False
    assert app.zero_power_since == 4000.0

    current_time = 4000.0 + automate_www.STANDBY_DELAY_SECONDS + 1
    app._handle_standby_check()
    assert standby_calls == ["standby", "standby"]
    assert app.standby_sent is True
    assert app.zero_power_since is None


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


def test_runtime_condition_false_accepts_netzero_minus_fallback():
    slot = {
        "time": "2000",
        "value": "netzero",
        "runtime_conditions": [{"field": "electricity_level", "op": ">=", "value": 90}],
        "fallback_value": "netzero-",
    }
    app, _logs = _build_app_with_slot(slot, electric_level=88)
    assert app._apply_runtime_conditions("netzero") == "netzero-"


def test_runtime_condition_false_logs_slot_level_and_bounds():
    slot = {
        "time": "2000",
        "value": "netzero",
        "runtime_conditions": [{"field": "electricity_level", "op": ">=", "value": 90}],
        "fallback_value": 0,
        "min_power": -300,
        "max_power": 100,
    }
    app, logs = _build_app_with_slot(slot, electric_level=88)

    assert app._apply_runtime_conditions("netzero") == 0
    assert any(
        level == "info"
        and msg == "Runtime: slot=2000 fallback lvl=88 min=-300 max=100 base=netzero -> fb=0"
        for level, msg in logs
    )


def test_runtime_condition_false_logs_only_present_bound():
    slot = {
        "time": "2000",
        "value": "netzero",
        "runtime_conditions": [{"field": "electricity_level", "op": ">=", "value": 90}],
        "fallback_value": 0,
        "min_power": -300,
    }
    app, logs = _build_app_with_slot(slot, electric_level=88)

    assert app._apply_runtime_conditions("netzero") == 0
    assert any(
        level == "info"
        and msg == "Runtime: slot=2000 fallback lvl=88 min=-300 base=netzero -> fb=0"
        for level, msg in logs
    )


def test_runtime_condition_false_logs_without_bounds():
    slot = {
        "time": "2000",
        "value": "netzero",
        "runtime_conditions": [{"field": "electricity_level", "op": ">=", "value": 90}],
        "fallback_value": 0,
    }
    app, logs = _build_app_with_slot(slot, electric_level=88)

    assert app._apply_runtime_conditions("netzero") == 0
    assert any(
        level == "info"
        and msg == "Runtime: slot=2000 fallback lvl=88 base=netzero -> fb=0"
        for level, msg in logs
    )


def test_runtime_condition_false_logs_once_per_decision_signature():
    slot = {
        "time": "2000",
        "value": "netzero",
        "runtime_conditions": [{"field": "electricity_level", "op": ">=", "value": 90}],
        "fallback_value": 0,
        "min_power": -300,
        "max_power": 100,
    }
    app, logs = _build_app_with_slot(slot, electric_level=88)

    assert app._apply_runtime_conditions("netzero") == 0
    assert app._apply_runtime_conditions("netzero") == 0
    info_logs = [msg for level, msg in logs if level == "info"]
    assert info_logs == [
        "Runtime: slot=2000 fallback lvl=88 min=-300 max=100 base=netzero -> fb=0"
    ]


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


def test_check_battery_limits_blocks_netzero_minus_at_min_charge_level():
    automate_www = _import_automate_www_module()
    app = automate_www.AutomationApp()
    warnings = []
    app.logger = SimpleNamespace(
        info=lambda *_args, **_kwargs: None,
        warning=lambda msg, *_args, **_kwargs: warnings.append(str(msg)),
        error=lambda *_args, **_kwargs: None,
        debug=lambda *_args, **_kwargs: None,
    )
    app.controller = SimpleNamespace(limit_state=-1, check_battery_limits=lambda: None)

    assert app._check_battery_limits(automate_www.POWER_MODE_NETZERO_MINUS) == 0
    assert any("preventing discharge" in msg for msg in warnings)


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
