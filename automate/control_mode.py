"""Durable, fail-closed ownership state for battery command control."""

from __future__ import annotations

import json
import os
import threading
import time
from pathlib import Path
from typing import Any, Optional


CONTROL_MODE_ACTIVE = "active"
CONTROL_MODE_PAUSED = "paused"
CONTROL_MODE_EXTERNAL_PENDING = "external_pending"
CONTROL_MODE_EXTERNAL = "external"
VALID_STORED_MODES = {
    CONTROL_MODE_ACTIVE,
    CONTROL_MODE_PAUSED,
    CONTROL_MODE_EXTERNAL_PENDING,
    CONTROL_MODE_EXTERNAL,
}
SCHEMA_VERSION = 1


class ControlModeStore:
    """Read and atomically persist the controller ownership mode as JSON."""

    def __init__(self, path: Path | str, logger: Optional[Any] = None):
        self.path = Path(path)
        self.logger = logger
        self._lock = threading.RLock()
        self._state: dict[str, Any] = {}
        self._valid = False
        self._recovered = False
        self._recovery_reason: Optional[str] = None
        self._error: Optional[str] = None
        self._load_or_recover()
        self.log_startup_state()

    def _log(self, level: str, message: str) -> None:
        target = getattr(self.logger, level, None) if self.logger is not None else None
        if callable(target):
            target(message)
        else:
            print(f"[{level.upper()}] {message}")

    @staticmethod
    def _validate(payload: Any) -> dict[str, Any]:
        if not isinstance(payload, dict):
            raise ValueError("root value must be an object")
        if payload.get("schemaVersion") != SCHEMA_VERSION:
            raise ValueError("schemaVersion is missing or unsupported")
        mode = payload.get("mode")
        if mode not in VALID_STORED_MODES:
            raise ValueError("mode is missing or unsupported")
        owner = payload.get("owner")
        if owner is not None and not isinstance(owner, str):
            raise ValueError("owner must be a string or null")
        changed_at = payload.get("changedAt")
        if isinstance(changed_at, bool) or not isinstance(changed_at, int):
            raise ValueError("changedAt must be an integer timestamp")
        changed_by = payload.get("changedBy")
        reason = payload.get("reason")
        if not isinstance(changed_by, str) or not changed_by.strip():
            raise ValueError("changedBy must be a non-empty string")
        if not isinstance(reason, str) or not reason.strip():
            raise ValueError("reason must be a non-empty string")
        return {
            "schemaVersion": SCHEMA_VERSION,
            "mode": mode,
            "owner": owner.strip() if isinstance(owner, str) and owner.strip() else None,
            "changedAt": changed_at,
            "changedBy": changed_by.strip(),
            "reason": reason.strip(),
        }

    @staticmethod
    def _external_recovery_state(reason: str) -> dict[str, Any]:
        return {
            "schemaVersion": SCHEMA_VERSION,
            "mode": CONTROL_MODE_EXTERNAL,
            "owner": None,
            "changedAt": int(time.time()),
            "changedBy": "startup-fail-closed",
            "reason": reason,
        }

    def _atomic_write(self, payload: dict[str, Any]) -> None:
        validated = self._validate(payload)
        self.path.parent.mkdir(parents=True, exist_ok=True)
        temporary = self.path.with_name(self.path.name + ".tmp")
        serialized = json.dumps(validated, indent=2, sort_keys=True) + "\n"
        try:
            with open(temporary, "w", encoding="utf-8") as handle:
                handle.write(serialized)
                handle.flush()
                os.fsync(handle.fileno())
            os.chmod(temporary, 0o640)
            os.replace(temporary, self.path)
            try:
                directory_fd = os.open(self.path.parent, os.O_RDONLY)
                try:
                    os.fsync(directory_fd)
                finally:
                    os.close(directory_fd)
            except OSError:
                pass
        finally:
            try:
                temporary.unlink(missing_ok=True)
            except OSError:
                pass

    def _quarantine_invalid_file(self) -> Optional[Path]:
        suffix = time.strftime("%Y%m%d-%H%M%S", time.localtime())
        candidate = self.path.with_name(f"{self.path.stem}.invalid-{suffix}{self.path.suffix}")
        counter = 1
        while candidate.exists():
            candidate = self.path.with_name(
                f"{self.path.stem}.invalid-{suffix}-{counter}{self.path.suffix}"
            )
            counter += 1
        try:
            os.replace(self.path, candidate)
            return candidate
        except OSError as exc:
            self._error = f"invalid state could not be preserved: {exc}"
            self._log("error", f"[CONTROL STATE ERROR] {self._error}")
            return None

    def _load_or_recover(self) -> None:
        with self._lock:
            recovery_reason: Optional[str] = None
            recovery_kind: Optional[str] = None
            try:
                raw = self.path.read_text(encoding="utf-8")
                self._state = self._validate(json.loads(raw))
                self._valid = True
                return
            except FileNotFoundError:
                recovery_reason = "Control-state file was missing"
                recovery_kind = "missing_file"
            except (OSError, json.JSONDecodeError, ValueError) as exc:
                recovery_reason = f"Control-state file was invalid: {exc}"
                recovery_kind = "invalid_file"
                if self.path.exists() and self._quarantine_invalid_file() is None:
                    self._state = self._external_recovery_state(recovery_reason)
                    self._valid = False
                    self._recovered = True
                    self._recovery_reason = "invalid_file"
                    return

            self._state = self._external_recovery_state(recovery_reason)
            self._recovered = True
            self._recovery_reason = recovery_kind
            try:
                self._atomic_write(self._state)
                self._valid = True
                self._log("warning", f"[CONTROL STATE] {recovery_reason}; created EXTERNAL fail-closed state")
            except (OSError, ValueError) as exc:
                self._valid = False
                self._error = f"Unable to persist replacement state: {exc}"
                self._log("error", f"[CONTROL STATE ERROR] {self._error}")

    @property
    def stored_mode(self) -> str:
        with self._lock:
            return str(self._state.get("mode", CONTROL_MODE_EXTERNAL))

    @property
    def effective_mode(self) -> str:
        return (
            CONTROL_MODE_EXTERNAL
            if self.stored_mode == CONTROL_MODE_EXTERNAL_PENDING
            else self.stored_mode
        )

    def transition(
        self,
        mode: str,
        *,
        changed_by: str,
        reason: str,
        owner: Optional[str] = None,
    ) -> dict[str, Any]:
        if mode not in VALID_STORED_MODES:
            raise ValueError(f"Unsupported control mode: {mode}")
        payload = {
            "schemaVersion": SCHEMA_VERSION,
            "mode": mode,
            "owner": owner,
            "changedAt": int(time.time()),
            "changedBy": changed_by,
            "reason": reason,
        }
        with self._lock:
            previous = self.stored_mode
            self._atomic_write(payload)
            self._state = self._validate(payload)
            self._valid = True
            self._recovered = False
            self._recovery_reason = None
            self._error = None
        self._log("info", f"[CONTROL MODE] {previous.upper()} -> {mode.upper()}")
        return self.status()

    def write_allowed(self, purpose: str, requested_value: Any = None) -> bool:
        mode = self.stored_mode
        if mode == CONTROL_MODE_ACTIVE:
            return True
        if mode == CONTROL_MODE_PAUSED:
            return purpose in {"hold_zero", "standby"}
        if mode == CONTROL_MODE_EXTERNAL_PENDING:
            return purpose == "external_handoff_zero" and requested_value == 0
        return False

    def status(self) -> dict[str, Any]:
        with self._lock:
            stored_mode = self.stored_mode
            effective_mode = self.effective_mode
            result = {
                "controlMode": effective_mode,
                "storedControlMode": stored_mode,
                "controlOwner": self._state.get("owner"),
                "controlWritesEnabled": effective_mode != CONTROL_MODE_EXTERNAL,
                "controlStateValid": self._valid,
                "controlStateRecovered": self._recovered,
                "controlStateRecoveryReason": self._recovery_reason,
                "controlStateFailClosed": (
                    not self._valid
                    or self._recovered
                    or stored_mode == CONTROL_MODE_EXTERNAL_PENDING
                ),
                "pauseActive": effective_mode == CONTROL_MODE_PAUSED,
                "externalControlActive": effective_mode == CONTROL_MODE_EXTERNAL,
                "changedAt": self._state.get("changedAt"),
                "changedBy": self._state.get("changedBy"),
                "reason": self._state.get("reason"),
            }
            if self._error:
                result["controlStateError"] = self._error
            return result

    def log_startup_state(self) -> None:
        status = self.status()
        self._log("info", f"[CONTROL MODE] {status['controlMode'].upper()}")
        self._log("info", f"[CONTROL OWNER] {status['controlOwner'] or 'none'}")
        self._log(
            "info",
            f"[BATTERY WRITES] {'ENABLED' if status['controlWritesEnabled'] else 'DISABLED'}",
        )
        self._log("info", f"[STATE SOURCE] {self.path}")
        if status["controlStateFailClosed"]:
            self._log("warning", "[CONTROL STATE] Running fail-closed in EXTERNAL mode")
