#!/usr/bin/env python3
"""Evaluate SWR-driven rule profiles and persist date-specific selections."""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import tempfile
import urllib.error
import urllib.request
from contextlib import contextmanager
from datetime import datetime
from pathlib import Path
from typing import Any, Iterator
from zoneinfo import ZoneInfo


ROOT = Path(__file__).resolve().parents[1]
DEFAULT_PROFILES = ROOT / "main/data/rule_profiles.json"
DEFAULT_STATE = ROOT / "main/data/rule_profile_auto_state.json"
DEFAULT_SYSTEM_CONFIG = ROOT / "common/config/system.json"
DEFAULT_SHORTWAVE_ENDPOINT = ROOT / "main/api/shortwave_radiation_api.php"


def load_object(path: Path, *, missing_ok: bool = False) -> dict[str, Any]:
    if missing_ok and not path.exists():
        return {}
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError as exc:
        raise RuntimeError(f"File not found: {path}") from exc
    except (OSError, json.JSONDecodeError) as exc:
        raise RuntimeError(f"Unable to read valid JSON from {path}: {exc}") from exc
    if not isinstance(value, dict):
        raise RuntimeError(f"JSON root must be an object: {path}")
    return value


def optional_bound(value: Any) -> float | None:
    if value is None or value == "":
        return None
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        return None
    number = float(value)
    return number if number >= 0 else None


def select_profile(profiles: list[dict[str, Any]], swr: float) -> tuple[str, str, dict[str, float | None]]:
    usable = [profile for profile in profiles if isinstance(profile, dict) and str(profile.get("id", "")).strip()]
    if not usable:
        raise RuntimeError("Automatic selection requires at least one profile.")

    for profile in usable:
        lower = optional_bound(profile.get("swr_min_wh_m2"))
        upper = optional_bound(profile.get("swr_max_wh_m2"))
        if lower is not None and upper is not None and lower >= upper:
            continue
        if (lower is None or swr >= lower) and (upper is None or swr < upper):
            return str(profile["id"]), "matched", {"minimum": lower, "maximum": upper}

    return str(usable[0]["id"]), "default_no_match", {"minimum": None, "maximum": None}


def normalize_forecast_days(payload: dict[str, Any]) -> dict[str, float]:
    result: dict[str, float] = {}
    days = payload.get("days")
    if not isinstance(days, list):
        return result
    for item in days:
        if not isinstance(item, dict):
            continue
        date_text = str(item.get("date", ""))
        value = item.get("value")
        try:
            datetime.strptime(date_text, "%Y-%m-%d")
            number = float(value)
        except (TypeError, ValueError):
            continue
        if number >= 0:
            result[date_text] = number
    return result


def fetch_shortwave(url: str, timeout: int) -> dict[str, Any]:
    request = urllib.request.Request(url, headers={"Accept": "application/json", "User-Agent": "zendure-auto-profile/1.0"})
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            raw = response.read().decode("utf-8")
    except (OSError, urllib.error.URLError) as exc:
        raise RuntimeError(f"SWR endpoint request failed: {exc}") from exc
    try:
        payload = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise RuntimeError(f"SWR endpoint returned invalid JSON: {exc}") from exc
    if not isinstance(payload, dict) or not payload.get("success"):
        message = payload.get("error", "unknown error") if isinstance(payload, dict) else "invalid response"
        raise RuntimeError(f"SWR endpoint failed: {message}")
    return payload


def fetch_shortwave_from_php(endpoint: Path, timeout: int) -> dict[str, Any]:
    try:
        completed = subprocess.run(
            ["php", str(endpoint)],
            check=False,
            capture_output=True,
            text=True,
            timeout=timeout,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise RuntimeError(f"SWR endpoint process failed: {exc}") from exc
    try:
        payload = json.loads(completed.stdout)
    except json.JSONDecodeError as exc:
        detail = completed.stderr.strip() or completed.stdout.strip() or str(exc)
        raise RuntimeError(f"SWR endpoint returned invalid JSON: {detail}") from exc
    if completed.returncode != 0 or not isinstance(payload, dict) or not payload.get("success"):
        message = payload.get("error", completed.stderr.strip() or "unknown error") if isinstance(payload, dict) else "invalid response"
        raise RuntimeError(f"SWR endpoint failed: {message}")
    return payload


@contextmanager
def state_lock(path: Path) -> Iterator[None]:
    import fcntl

    lock_path = path.with_suffix(path.suffix + ".lock")
    lock_path.parent.mkdir(parents=True, exist_ok=True)
    with lock_path.open("a+", encoding="utf-8") as handle:
        fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
        yield


def write_atomic(path: Path, value: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temp_name = tempfile.mkstemp(prefix=path.name + ".", suffix=".tmp", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(value, handle, indent=2, ensure_ascii=False)
            handle.write("\n")
        os.replace(temp_name, path)
    except BaseException:
        try:
            os.unlink(temp_name)
        except FileNotFoundError:
            pass
        raise


def evaluate(
    profiles_config: dict[str, Any],
    old_state: dict[str, Any],
    payload: dict[str, Any] | None,
    now: datetime,
    include_today: bool,
    fetch_error: str | None,
) -> dict[str, Any]:
    profiles = profiles_config.get("profiles")
    if not isinstance(profiles, list) or not profiles:
        raise RuntimeError("No rule profiles are configured.")

    today = now.date().isoformat()
    old_days = old_state.get("days") if isinstance(old_state.get("days"), dict) else {}
    forecast_days = normalize_forecast_days(payload or {})
    candidate_dates = set(forecast_days)
    candidate_dates.update(date for date in old_days if isinstance(date, str) and date >= today)
    if not include_today:
        candidate_dates.discard(today)

    manual_profile = str(profiles_config.get("active_profile_id", "")).strip()
    profile_ids = {str(profile.get("id", "")) for profile in profiles if isinstance(profile, dict)}
    if not manual_profile:
        manual_profile = str(profiles[0].get("id", ""))
    retained_profile_ids = profile_ids | {manual_profile}

    evaluated_at = now.isoformat()
    cached_at = payload.get("cachedAt") if isinstance(payload, dict) else old_state.get("forecast_cached_at")
    cache_status = str((payload or {}).get("cacheStatus", "fresh" if payload else "unavailable"))
    days: dict[str, Any] = {}
    prior_effective = manual_profile

    for date_text in sorted(candidate_dates):
        if date_text < today:
            continue
        previous = old_days.get(date_text) if isinstance(old_days.get(date_text), dict) else {}
        swr = forecast_days.get(date_text)
        used_stored_swr = False
        if swr is None and isinstance(previous.get("swr_wh_m2"), (int, float)):
            swr = float(previous["swr_wh_m2"])
            used_stored_swr = True

        if swr is not None:
            profile_id, reason, matched_range = select_profile(profiles, swr)
            if used_stored_swr or cache_status == "stale":
                reason = "stale_forecast_matched" if reason == "matched" else "stale_forecast_default_no_match"
            record = {
                "swr_wh_m2": int(round(swr)),
                "profile_id": profile_id,
                "reason": reason,
                "matched_range": matched_range,
                "forecast_cached_at": previous.get("forecast_cached_at") if used_stored_swr else cached_at,
                "evaluated_at": evaluated_at,
            }
        elif previous.get("profile_id") in retained_profile_ids:
            record = dict(previous)
            record.update({"reason": "retained_selection", "evaluated_at": evaluated_at})
        else:
            record = {
                "swr_wh_m2": None,
                "profile_id": prior_effective,
                "reason": "carried_forward",
                "matched_range": None,
                "forecast_cached_at": None,
                "evaluated_at": evaluated_at,
            }
        days[date_text] = record
        prior_effective = str(record["profile_id"])

    return {
        "version": 1,
        "last_evaluation_at": evaluated_at,
        "forecast_status": cache_status,
        "forecast_cached_at": cached_at,
        "refresh_error": fetch_error or (payload or {}).get("refreshError"),
        "days": days,
    }


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--profiles", type=Path, default=DEFAULT_PROFILES)
    parser.add_argument("--state", type=Path, default=DEFAULT_STATE)
    parser.add_argument("--system-config", type=Path, default=DEFAULT_SYSTEM_CONFIG)
    parser.add_argument("--shortwave-url", help="Optional HTTP URL instead of invoking the local PHP endpoint.")
    parser.add_argument("--shortwave-endpoint", type=Path, default=DEFAULT_SHORTWAVE_ENDPOINT)
    parser.add_argument("--payload-file", type=Path, help="Use a forecast fixture instead of the HTTP endpoint.")
    parser.add_argument("--include-today", action="store_true", help="Also reevaluate the current local date.")
    parser.add_argument("--timeout", type=int, default=25)
    parser.add_argument("--now", help="Testing override as an ISO-8601 timestamp.")
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv or sys.argv[1:])
    try:
        profiles = load_object(args.profiles)
        system_config = load_object(args.system_config)
        timezone_name = str(system_config.get("installation", {}).get("timezone", "Europe/Amsterdam"))
        timezone = ZoneInfo(timezone_name)
        now = datetime.fromisoformat(args.now).astimezone(timezone) if args.now else datetime.now(timezone)
        old_state = load_object(args.state, missing_ok=True)

        payload: dict[str, Any] | None = None
        fetch_error: str | None = None
        try:
            if args.payload_file:
                payload = load_object(args.payload_file)
            elif args.shortwave_url:
                payload = fetch_shortwave(args.shortwave_url, args.timeout)
            else:
                payload = fetch_shortwave_from_php(args.shortwave_endpoint, args.timeout)
        except RuntimeError as exc:
            fetch_error = str(exc)

        with state_lock(args.state):
            old_state = load_object(args.state, missing_ok=True)
            state = evaluate(profiles, old_state, payload, now, args.include_today, fetch_error)
            write_atomic(args.state, state)

        summary = {
            "success": True,
            "selection_mode": profiles.get("selection_mode", "manual"),
            "forecast_status": state["forecast_status"],
            "refresh_error": state["refresh_error"],
            "evaluated_at": state["last_evaluation_at"],
            "days": state["days"],
        }
        print(json.dumps(summary, ensure_ascii=False))
        return 0
    except Exception as exc:  # CLI boundary
        print(json.dumps({"success": False, "error": str(exc)}, ensure_ascii=False), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
