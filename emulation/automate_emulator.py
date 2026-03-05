#!/usr/bin/env python3
"""
Automate emulator client for P1 emulation testing.

It waits until the P1 emulator is ready, then repeatedly polls the emulated
P1 endpoint, computes the battery command for netzero/netzero+ behavior, and
prints a report line per step.
"""

from __future__ import annotations

import argparse
import json
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from typing import Any, Dict, Optional


MODE_NETZERO = "netzero"
MODE_NETZERO_PLUS = "netzero+"
VALID_MODES = {MODE_NETZERO, MODE_NETZERO_PLUS}


@dataclass
class Decision:
    mode: str
    command_w: int
    battery_mode: str  # charge | discharge | idle


def _json_request(url: str, method: str = "GET", timeout: float = 5.0) -> Dict[str, Any]:
    req = urllib.request.Request(url, method=method)
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        raw = resp.read().decode("utf-8")
    data = json.loads(raw)
    if not isinstance(data, dict):
        raise ValueError(f"Expected JSON object from {url}, got {type(data).__name__}")
    return data


def wait_until_ready(base_url: str, ready_timeout: float, retry_interval: float) -> None:
    test_url = urllib.parse.urljoin(base_url + "/", "api/test")
    status_url = urllib.parse.urljoin(base_url + "/", "api/emulation/status")
    deadline = time.time() + max(0.0, ready_timeout)
    last_error = "unknown"

    while True:
        try:
            test = _json_request(test_url, timeout=3.0)
            status = _json_request(status_url, timeout=3.0)
            if bool(test.get("ok", True)) and bool(status.get("ok")) and not status.get("reload_error"):
                return
            last_error = f"status not ready: {status}"
        except (urllib.error.URLError, TimeoutError, json.JSONDecodeError, ValueError) as exc:
            last_error = str(exc)

        if time.time() >= deadline:
            raise TimeoutError(
                f"P1 emulator not ready within {ready_timeout:.1f}s. Last error: {last_error}"
            )
        time.sleep(max(0.05, retry_interval))


def decide_command(
    active_power_w: int,
    mode: str,
    min_abs_power: int,
    max_charge: int,
    max_discharge: int,
) -> Decision:
    if mode not in VALID_MODES:
        raise ValueError(f"Invalid mode: {mode}")

    min_abs_power = max(0, int(min_abs_power))
    max_charge = max(0, int(max_charge))
    max_discharge = max(0, int(max_discharge))

    # netzero: discharge only on import
    if mode == MODE_NETZERO:
        if active_power_w > min_abs_power:
            cmd = -min(active_power_w, max_discharge)
            return Decision(mode=mode, command_w=cmd, battery_mode="discharge")
        return Decision(mode=mode, command_w=0, battery_mode="idle")

    # netzero+: charge only on export
    if active_power_w < -min_abs_power:
        cmd = min(abs(active_power_w), max_charge)
        return Decision(mode=mode, command_w=cmd, battery_mode="charge")
    return Decision(mode=mode, command_w=0, battery_mode="idle")


def resolve_mode(step_data: Dict[str, Any], fallback_mode: str) -> str:
    mode_raw = step_data.get("expected_mode")
    if isinstance(mode_raw, str):
        mode = mode_raw.strip().lower()
        if mode in VALID_MODES:
            return mode
    return fallback_mode


def compare_expected(step_data: Dict[str, Any], decision: Decision) -> str:
    checks = []

    exp_mode = step_data.get("expected_mode")
    if isinstance(exp_mode, str):
        checks.append(str(exp_mode).strip().lower() == decision.mode)

    exp_batt_mode = step_data.get("expected_battery_mode")
    if isinstance(exp_batt_mode, str):
        checks.append(str(exp_batt_mode).strip().lower() == decision.battery_mode)

    exp_power = step_data.get("expected_battery_power_w")
    if isinstance(exp_power, (int, float)):
        checks.append(int(exp_power) == decision.command_w)

    if not checks:
        return "N/A"
    return "MATCH" if all(checks) else "MISMATCH"


def fmt_step_line(step_data: Dict[str, Any], decision: Decision, verdict: str) -> str:
    ts = step_data.get("timestamp")
    idx = step_data.get("emulation_step_index")
    total = step_data.get("emulation_step_total")
    p1 = step_data.get("active_power_w")
    phase = step_data.get("phase", "-")
    note = step_data.get("note", "")
    note_part = f" note={note}" if note else ""
    return (
        f"t={ts} step={idx}/{total} phase={phase} p1={p1}W mode={decision.mode} "
        f"command={decision.command_w}W batt={decision.battery_mode} verdict={verdict}{note_part}"
    )


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Run automate-style control decisions against the P1 emulator"
    )
    parser.add_argument("--p1-base-url", default="http://127.0.0.1:1616", help="P1 emulator base URL")
    parser.add_argument("--p1-endpoint", default="/api/v1/data", help="P1 endpoint path")
    parser.add_argument("--mode", default=MODE_NETZERO, choices=sorted(VALID_MODES), help="Fallback control mode")
    parser.add_argument("--ready-timeout", type=float, default=30.0, help="Seconds to wait for emulator readiness")
    parser.add_argument("--retry-interval", type=float, default=0.5, help="Readiness retry interval in seconds")
    parser.add_argument("--poll-interval", type=float, default=0.0, help="Delay between polls in seconds")
    parser.add_argument("--max-steps", type=int, default=0, help="Optional max number of reads (0 = no limit)")
    parser.add_argument("--min-abs-power", type=int, default=30, help="Deadband threshold in watts")
    parser.add_argument("--max-charge", type=int, default=1200, help="Max charge command in watts")
    parser.add_argument("--max-discharge", type=int, default=800, help="Max discharge command magnitude in watts")
    parser.add_argument("--reset-on-start", action="store_true", help="Reset emulator step index before reading")
    parser.add_argument("--json-output", action="store_true", help="Output per-step report as JSON lines")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    base_url = args.p1_base_url.rstrip("/")
    p1_url = urllib.parse.urljoin(base_url + "/", args.p1_endpoint.lstrip("/"))
    status_url = urllib.parse.urljoin(base_url + "/", "api/emulation/status")
    reset_url = urllib.parse.urljoin(base_url + "/", "api/emulation/reset")

    print(f"Waiting for P1 emulator at {base_url} ...")
    wait_until_ready(base_url, ready_timeout=args.ready_timeout, retry_interval=args.retry_interval)
    print("P1 emulator ready.")

    if args.reset_on_start:
        _json_request(reset_url, method="POST", timeout=3.0)
        print("Emulator reset to step 0.")

    reads = 0
    matches = 0
    mismatches = 0
    na = 0

    while True:
        step_data = _json_request(p1_url, timeout=5.0)
        reads += 1

        try:
            p1_power = int(step_data.get("active_power_w"))
        except (TypeError, ValueError):
            raise ValueError(f"P1 reading missing/invalid active_power_w: {step_data}")

        mode = resolve_mode(step_data, args.mode)
        decision = decide_command(
            active_power_w=p1_power,
            mode=mode,
            min_abs_power=args.min_abs_power,
            max_charge=args.max_charge,
            max_discharge=args.max_discharge,
        )
        verdict = compare_expected(step_data, decision)

        if verdict == "MATCH":
            matches += 1
        elif verdict == "MISMATCH":
            mismatches += 1
        else:
            na += 1

        if args.json_output:
            out = {
                "timestamp": step_data.get("timestamp"),
                "step_index": step_data.get("emulation_step_index"),
                "step_total": step_data.get("emulation_step_total"),
                "phase": step_data.get("phase"),
                "p1_active_power_w": p1_power,
                "mode": decision.mode,
                "battery_mode": decision.battery_mode,
                "command_w": decision.command_w,
                "verdict": verdict,
                "expected_mode": step_data.get("expected_mode"),
                "expected_battery_mode": step_data.get("expected_battery_mode"),
                "expected_battery_power_w": step_data.get("expected_battery_power_w"),
                "finished": bool(step_data.get("emulation_finished")),
            }
            print(json.dumps(out))
        else:
            print(fmt_step_line(step_data, decision, verdict))

        if bool(step_data.get("emulation_finished")):
            break
        if args.max_steps > 0 and reads >= args.max_steps:
            break
        if args.poll_interval > 0:
            time.sleep(args.poll_interval)

    try:
        status = _json_request(status_url, timeout=3.0)
    except Exception:
        status = {}

    print(
        "Summary: "
        f"reads={reads} match={matches} mismatch={mismatches} na={na} "
        f"final_step={status.get('current_step_index')} finished={status.get('finished')}"
    )
    return 0 if mismatches == 0 else 2


if __name__ == "__main__":
    raise SystemExit(main())
