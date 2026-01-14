from __future__ import annotations

import re
import sys
from collections import defaultdict
from dataclasses import dataclass
from datetime import datetime, timedelta
from pathlib import Path

# Path to the log file to analyze.
# You can set this to an absolute path, or leave it as the default repo-relative path.
LOG_PATH: Path = (Path(__file__).resolve().parents[2] / "automate" / "log" / "automate.log")

# Output rounding: round to whole Wh for readability.
ROUND_TO_WHOLE_WH: bool = True


TS_RE = re.compile(r"^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]")
POWER_RE = re.compile(
    r"^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+Power:\s+(-?\d+)\b"
)


@dataclass(frozen=True)
class PowerSample:
    ts: datetime
    watts: int


def _parse_ts(ts_str: str) -> datetime:
    # Log timestamps are assumed local time; no timezone offset present.
    return datetime.strptime(ts_str, "%Y-%m-%d %H:%M:%S")


def read_power_samples_and_file_end(log_path: Path) -> tuple[list[PowerSample], datetime]:
    samples: list[PowerSample] = []
    last_ts: datetime | None = None

    with log_path.open("r", encoding="utf-8", errors="replace") as f:
        for line in f:
            m_ts = TS_RE.match(line)
            if m_ts:
                last_ts = _parse_ts(m_ts.group(1))

            m_power = POWER_RE.match(line)
            if m_power:
                ts = _parse_ts(m_power.group(1))
                watts = int(m_power.group(2))
                samples.append(PowerSample(ts=ts, watts=watts))

    if last_ts is None:
        raise ValueError(f"No timestamped lines found in log: {log_path}")
    if not samples:
        raise ValueError(f"No 'Power:' samples found in log: {log_path}")

    samples.sort(key=lambda s: s.ts)
    return samples, last_ts


def integrate_hourly_wh(samples: list[PowerSample], file_end: datetime) -> dict[datetime, tuple[float, float]]:
    """
    Returns dict: hour_start(datetime) -> (charge_wh, discharge_wh)
    - charge_wh accumulates positive energy
    - discharge_wh accumulates absolute value of negative energy
    """
    charge_wh_by_hour: dict[datetime, float] = defaultdict(float)
    discharge_wh_by_hour: dict[datetime, float] = defaultdict(float)

    for idx, s in enumerate(samples):
        start = s.ts
        end = samples[idx + 1].ts if idx + 1 < len(samples) else file_end
        if end <= start:
            continue

        cur = start
        while cur < end:
            hour_start = cur.replace(minute=0, second=0, microsecond=0)
            next_hour = hour_start + timedelta(hours=1)
            seg_end = end if end <= next_hour else next_hour

            seconds = (seg_end - cur).total_seconds()
            wh = s.watts * (seconds / 3600.0)

            if wh >= 0:
                charge_wh_by_hour[hour_start] += wh
            else:
                discharge_wh_by_hour[hour_start] += -wh

            cur = seg_end

    all_hours = set(charge_wh_by_hour.keys()) | set(discharge_wh_by_hour.keys())
    return {h: (charge_wh_by_hour.get(h, 0.0), discharge_wh_by_hour.get(h, 0.0)) for h in all_hours}


def _format_wh(x: float) -> str:
    if ROUND_TO_WHOLE_WH:
        return str(int(round(x)))
    return f"{x:.3f}"


def main() -> int:
    log_path = LOG_PATH
    if len(sys.argv) > 1:
        log_path = Path(sys.argv[1])

    samples, file_end = read_power_samples_and_file_end(log_path)
    hourly = integrate_hourly_wh(samples, file_end)

    for hour_start in sorted(hourly.keys()):
        charge, discharge = hourly[hour_start]
        date_str = hour_start.strftime("%Y%m%d")
        hour_str = hour_start.strftime("%H00")
        print(f"{date_str} {hour_str} {_format_wh(charge)} {_format_wh(discharge)}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())

