#!/usr/bin/env python3
"""
Minute-aligned power monitor (test script).

Reads HomeWizard P1 meter data from config key `p1Meter_2` and prints:
  timestamp  delta_import_kwh  delta_export_kwh  active_power_w

Baseline (start) import/export totals are persisted to:
  data/power_monitor_state.json

On startup, the last baseline is reused if it is less than 1 hour old.
At every full hour (N:00:00) the baseline is refreshed.
"""

from __future__ import annotations

import json
import time
from dataclasses import dataclass
from datetime import datetime, timedelta
from pathlib import Path
from typing import Any, Optional

import requests


REPO_ROOT = Path(__file__).resolve().parents[1]
CONFIG_PATH_PRIMARY = REPO_ROOT / "config" / "config.json"
CONFIG_PATH_FALLBACK = Path(__file__).resolve().parent / "config" / "config.json"

STATE_PATH = REPO_ROOT / "data" / "power_monitor_state.json"

# Output formatting
TS_FMT = "%Y-%m-%d %H:%M:%S"
DELTA_KWH_WIDTH = 14  # includes sign, decimal point, and digits
DELTA_KWH_PREC = 6
POWER_W_WIDTH = 8


@dataclass(frozen=True)
class Baseline:
    ts: datetime
    import_kwh: float
    export_kwh: float


def _load_config() -> dict[str, Any]:
    if CONFIG_PATH_PRIMARY.exists():
        path = CONFIG_PATH_PRIMARY
    elif CONFIG_PATH_FALLBACK.exists():
        path = CONFIG_PATH_FALLBACK
    else:
        raise FileNotFoundError(
            "config.json not found. Looked in:\n"
            f"  1) {CONFIG_PATH_PRIMARY}\n"
            f"  2) {CONFIG_PATH_FALLBACK}"
        )

    with path.open("r", encoding="utf-8") as f:
        return json.load(f)


def _load_baseline(now: datetime) -> Optional[Baseline]:
    try:
        with STATE_PATH.open("r", encoding="utf-8") as f:
            data = json.load(f)
        ts = datetime.fromisoformat(str(data.get("timestamp")))
        import_kwh = float(data.get("import_kwh"))
        export_kwh = float(data.get("export_kwh"))
        baseline = Baseline(ts=ts, import_kwh=import_kwh, export_kwh=export_kwh)
    except FileNotFoundError:
        return None
    except Exception:
        # Corrupt or incompatible file; ignore and recreate on first good reading.
        return None

    if now - baseline.ts < timedelta(hours=1):
        return baseline
    return None


def _save_baseline(baseline: Baseline) -> None:
    STATE_PATH.parent.mkdir(parents=True, exist_ok=True)
    tmp_path = STATE_PATH.with_suffix(".tmp")
    payload = {
        "timestamp": baseline.ts.replace(microsecond=0).isoformat(),
        "import_kwh": baseline.import_kwh,
        "export_kwh": baseline.export_kwh,
    }
    with tmp_path.open("w", encoding="utf-8") as f:
        json.dump(payload, f, indent=2)
    tmp_path.replace(STATE_PATH)


def _sleep_until_next_minute() -> datetime:
    """
    Sleep until the next whole-minute boundary and return the target timestamp.

    We return the *target* timestamp (second==0) and use it for printing, so the
    output always shows neat :00 seconds even if the OS wakes a tiny bit early/late.
    """
    now = datetime.now()
    target = now.replace(second=0, microsecond=0) + timedelta(minutes=1)
    sleep_s = (target - now).total_seconds()
    if sleep_s > 0:
        time.sleep(sleep_s)
    # Guard against waking slightly early (common on Windows with coarse timers).
    while datetime.now() < target:
        time.sleep(0.001)
    return target


def _read_p1(p1_cfg: dict[str, Any]) -> dict[str, float]:
    ip = p1_cfg.get("ip")
    endpoint = p1_cfg.get("endpoint")
    if not ip or not endpoint:
        raise ValueError("p1Meter_2 config must contain 'ip' and 'endpoint'")

    url = f"http://{ip}{endpoint}"
    r = requests.get(url, timeout=5)
    r.raise_for_status()
    data = r.json()

    # Required fields
    import_kwh = float(data["total_power_import_kwh"])
    export_kwh = float(data["total_power_export_kwh"])
    active_power_w = float(data["active_power_w"])
    return {
        "import_kwh": import_kwh,
        "export_kwh": export_kwh,
        "active_power_w": active_power_w,
    }


def _fmt_delta_kwh(x: float) -> str:
    # Always include sign to keep columns stable.
    return f"{x:+{DELTA_KWH_WIDTH}.{DELTA_KWH_PREC}f}"


def _fmt_power_w(x: float) -> str:
    # Round to integer watts for display.
    return f"{int(round(x)):{POWER_W_WIDTH}d}"


def _fmt_nan(width: int) -> str:
    return f"{'nan':>{width}}"


def main() -> int:
    cfg = _load_config()
    p1_cfg = cfg.get("p1Meter_2")
    if not isinstance(p1_cfg, dict):
        raise ValueError("Missing or invalid config key: p1Meter_2")

    baseline: Optional[Baseline] = _load_baseline(datetime.now())

    # Loop forever; user stops with Ctrl+C.
    try:
        while True:
            tick = _sleep_until_next_minute()

            try:
                reading = _read_p1(p1_cfg)
                cur_import = reading["import_kwh"]
                cur_export = reading["export_kwh"]
                cur_power = reading["active_power_w"]

                if baseline is None:
                    baseline = Baseline(ts=tick, import_kwh=cur_import, export_kwh=cur_export)
                    _save_baseline(baseline)
                else:
                    # Refresh baseline at the first tick of each new hour.
                    baseline_hour = baseline.ts.replace(minute=0, second=0, microsecond=0)
                    tick_hour = tick.replace(minute=0, second=0, microsecond=0)
                    if tick.minute == 0 and tick_hour > baseline_hour:
                        baseline = Baseline(ts=tick, import_kwh=cur_import, export_kwh=cur_export)
                        _save_baseline(baseline)

                d_import = cur_import - baseline.import_kwh
                d_export = cur_export - baseline.export_kwh

                line = (
                    f"{tick.strftime(TS_FMT)} "
                    f"{_fmt_delta_kwh(d_import)} "
                    f"{_fmt_delta_kwh(d_export)} "
                    f"{_fmt_power_w(cur_power)}"
                )
                print(line, flush=True)

            except Exception as e:
                # Preserve column alignment even on errors.
                line = (
                    f"{tick.strftime(TS_FMT)} "
                    f"{_fmt_nan(DELTA_KWH_WIDTH)} "
                    f"{_fmt_nan(DELTA_KWH_WIDTH)} "
                    f"{_fmt_nan(POWER_W_WIDTH)}"
                    f"  # {e}"
                )
                print(line, flush=True)

    except KeyboardInterrupt:
        return 0


if __name__ == "__main__":
    raise SystemExit(main())

