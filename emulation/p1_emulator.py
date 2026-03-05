#!/usr/bin/env python3
"""
P1 meter emulator.

Serves an HTTP endpoint compatible with the configured P1 reader and returns
values from a local JSON scenario file. The scenario advances by one step per
poll and stays on the last step when finished.
"""

from __future__ import annotations

import argparse
import json
import os
import time
from dataclasses import dataclass
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from threading import Lock
from typing import Any, Dict, List, Optional
from urllib.parse import urlparse


DEFAULT_ENDPOINT = "/api/v1/data"
STATUS_ENDPOINT = "/api/emulation/status"
RESET_ENDPOINT = "/api/emulation/reset"
TEST_ENDPOINT = "/api/test"


class ScenarioError(ValueError):
    """Raised when scenario JSON is invalid."""


@dataclass
class Scenario:
    steps: List[Dict[str, Any]]
    defaults: Dict[str, Any]

    @staticmethod
    def from_dict(raw: Dict[str, Any]) -> "Scenario":
        if not isinstance(raw, dict):
            raise ScenarioError("Scenario root must be an object")

        steps = raw.get("steps")
        if not isinstance(steps, list) or not steps:
            raise ScenarioError("Scenario must contain a non-empty 'steps' array")

        normalized_steps: List[Dict[str, Any]] = []
        for idx, step in enumerate(steps):
            if not isinstance(step, dict):
                raise ScenarioError(f"Step {idx} is not an object")

            normalized = dict(step)
            if "active_power_w" not in normalized:
                if "total_power" in normalized:
                    normalized["active_power_w"] = normalized["total_power"]
                else:
                    raise ScenarioError(
                        f"Step {idx} missing 'active_power_w' (or 'total_power' alias)"
                    )

            try:
                normalized["active_power_w"] = int(normalized["active_power_w"])
            except (TypeError, ValueError):
                raise ScenarioError(f"Step {idx} has non-integer active_power_w")

            normalized_steps.append(normalized)

        defaults = raw.get("defaults", {})
        if not isinstance(defaults, dict):
            raise ScenarioError("'defaults' must be an object if provided")

        return Scenario(steps=normalized_steps, defaults=dict(defaults))


class StepEmulator:
    """Thread-safe state holder for scenario playback and hot reload."""

    def __init__(self, scenario_path: Path):
        self.scenario_path = scenario_path
        self._lock = Lock()
        self._scenario: Optional[Scenario] = None
        self._mtime: Optional[float] = None
        self._idx = 0
        self._reload_error: Optional[str] = None
        self._load(force=True)

    def _load(self, force: bool = False) -> None:
        mtime = os.path.getmtime(self.scenario_path)
        if not force and self._mtime is not None and mtime == self._mtime:
            return

        with open(self.scenario_path, "r", encoding="utf-8") as fh:
            raw = json.load(fh)
        scenario = Scenario.from_dict(raw)

        self._scenario = scenario
        self._mtime = mtime
        self._idx = 0
        self._reload_error = None

    def _try_reload(self) -> None:
        try:
            self._load(force=False)
        except (OSError, json.JSONDecodeError, ScenarioError) as exc:
            self._reload_error = str(exc)

    def next_step_payload(self) -> Dict[str, Any]:
        with self._lock:
            self._try_reload()
            if self._scenario is None:
                raise ScenarioError("No scenario loaded")

            steps = self._scenario.steps
            idx = min(self._idx, len(steps) - 1)
            step = steps[idx]
            payload = dict(self._scenario.defaults)
            payload.update(step)
            payload["timestamp"] = int(time.time())
            payload["emulation_step_index"] = idx
            payload["emulation_step_total"] = len(steps)
            payload["emulation_finished"] = idx >= len(steps) - 1

            if self._idx < len(steps) - 1:
                self._idx += 1
            return payload

    def reset(self) -> Dict[str, Any]:
        with self._lock:
            self._idx = 0
            self._try_reload()
            total = len(self._scenario.steps) if self._scenario else 0
            return {
                "ok": self._scenario is not None,
                "scenario_path": str(self.scenario_path),
                "current_step_index": 0,
                "step_count": total,
                "finished": bool(total == 0),
                "reload_error": self._reload_error,
            }

    def status(self) -> Dict[str, Any]:
        with self._lock:
            self._try_reload()
            total = len(self._scenario.steps) if self._scenario else 0
            idx = min(self._idx, max(total - 1, 0)) if total else 0
            return {
                "ok": self._scenario is not None,
                "scenario_path": str(self.scenario_path),
                "current_step_index": idx,
                "step_count": total,
                "finished": bool(total and idx >= total - 1),
                "reload_error": self._reload_error,
            }


class P1EmulatorHandler(BaseHTTPRequestHandler):
    emulator: StepEmulator = None  # type: ignore[assignment]
    p1_endpoint: str = DEFAULT_ENDPOINT

    def _send_json(self, data: Dict[str, Any], status: int = 200) -> None:
        body = json.dumps(data).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(body)
        self.close_connection = True

    def _handle_get(self) -> bool:
        path = urlparse(self.path).path
        if path == self.p1_endpoint:
            try:
                payload = self.emulator.next_step_payload()
                self._send_json(payload)
            except ScenarioError as exc:
                self._send_json({"ok": False, "error": str(exc)}, status=500)
            return True

        if path == STATUS_ENDPOINT:
            self._send_json(self.emulator.status())
            return True

        if path == TEST_ENDPOINT:
            self._send_json(
                {
                    "ok": True,
                    "name": "p1-emulator",
                    "p1Endpoint": self.p1_endpoint,
                    "statusEndpoint": STATUS_ENDPOINT,
                    "resetEndpoint": RESET_ENDPOINT,
                }
            )
            return True
        return False

    def _handle_post(self) -> bool:
        path = urlparse(self.path).path
        if path == RESET_ENDPOINT:
            self._send_json(self.emulator.reset())
            return True
        return False

    def do_GET(self) -> None:  # noqa: N802
        if self._handle_get():
            return
        self._send_json({"ok": False, "error": "Not found"}, status=404)

    def do_POST(self) -> None:  # noqa: N802
        if self._handle_post():
            return
        self._send_json({"ok": False, "error": "Not found"}, status=404)

    def log_message(self, fmt: str, *args: Any) -> None:
        print(
            f"[{time.strftime('%Y-%m-%d %H:%M:%S')}] "
            f"{self.client_address[0]} {self.command} {self.path} - {fmt % args}"
        )


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run a local P1 meter emulator")
    parser.add_argument(
        "--file",
        default=str(Path(__file__).with_name("p1_steps.json")),
        help="Path to scenario JSON file",
    )
    parser.add_argument("--host", default="127.0.0.1", help="Bind host")
    parser.add_argument("--port", type=int, default=1616, help="Bind port")
    parser.add_argument(
        "--endpoint",
        default=DEFAULT_ENDPOINT,
        help="P1 endpoint path to serve (default: /api/v1/data)",
    )
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    scenario_path = Path(args.file).expanduser().resolve()
    if not scenario_path.exists():
        raise SystemExit(f"Scenario file not found: {scenario_path}")

    emulator = StepEmulator(scenario_path=scenario_path)
    P1EmulatorHandler.emulator = emulator
    P1EmulatorHandler.p1_endpoint = str(args.endpoint).strip() or DEFAULT_ENDPOINT

    server = ThreadingHTTPServer((args.host, args.port), P1EmulatorHandler)
    print(f"P1 emulator listening on http://{args.host}:{args.port}{P1EmulatorHandler.p1_endpoint}")
    print(f"Scenario: {scenario_path}")
    print(f"Status:   http://{args.host}:{args.port}{STATUS_ENDPOINT}")
    print(f"Reset:    POST http://{args.host}:{args.port}{RESET_ENDPOINT}")

    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()


if __name__ == "__main__":
    main()
