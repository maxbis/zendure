#!/usr/bin/env python3
"""
Estimate next-day hourly charge/discharge percentages from a source day profile.

The script reads status_updates from SQLite, reconstructs hourly charge/discharge
energy (Wh) from power change points, then maps the source-day hourly profile to
the next day.

Output:
- Estimated hourly charge/discharge percentages (24h)
- Subtotals by day part:
  - before charging
  - charging
  - after charging
- Day total

Percentage formula:
  pct = hourly_wh / ((base_wh / 100) * efficiency)
Default efficiency is 0.9.
"""

from __future__ import annotations

import argparse
import json
import os
import sqlite3
import sys
import time
from dataclasses import dataclass
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo

EVENT_TYPE_CHANGE = "change"
DEFAULT_TZ = "Europe/Amsterdam"
DEFAULT_BASE_WH = 5760.0
DEFAULT_EFFICIENCY = 0.9
DEFAULT_DB = os.path.join("..", "automate", "data", "status_updates.db")
LAST_OPEN_SEGMENT_MAX_SECONDS = 3600


@dataclass(frozen=True)
class HourEstimate:
    hour: int
    charge_wh: float
    discharge_wh: float
    charge_pct: float
    discharge_pct: float


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Estimate next-day charge/discharge percentages per hour."
    )
    parser.add_argument(
        "db_path",
        nargs="?",
        default=DEFAULT_DB,
        help=f"Path to status_updates SQLite DB (default: {DEFAULT_DB})",
    )
    parser.add_argument(
        "--timezone",
        default=DEFAULT_TZ,
        help=f"IANA timezone (default: {DEFAULT_TZ})",
    )
    parser.add_argument(
        "--base-wh",
        type=float,
        default=DEFAULT_BASE_WH,
        help=f"Total battery capacity in Wh (default: {int(DEFAULT_BASE_WH)})",
    )
    parser.add_argument(
        "--efficiency",
        type=float,
        default=DEFAULT_EFFICIENCY,
        help=f"Efficiency coefficient (default: {DEFAULT_EFFICIENCY})",
    )
    parser.add_argument(
        "--source-date",
        default=None,
        help="Source date in YYYY-MM-DD. Default: current local date if data exists, else latest date with data.",
    )
    return parser.parse_args()


def load_change_points(db_path: str) -> list[tuple[int, float]]:
    query = (
        "SELECT new_value, timestamp FROM status_updates "
        "WHERE type = ? AND new_value IS NOT NULL ORDER BY timestamp ASC"
    )
    points: list[tuple[int, float]] = []
    with sqlite3.connect(db_path) as conn:
        cur = conn.execute(query, (EVENT_TYPE_CHANGE,))
        for new_value_raw, ts_raw in cur.fetchall():
            if ts_raw is None:
                continue
            try:
                ts = int(ts_raw)
            except (TypeError, ValueError):
                continue

            value = new_value_raw
            if isinstance(new_value_raw, str):
                try:
                    value = json.loads(new_value_raw)
                except (json.JSONDecodeError, TypeError, ValueError):
                    continue
            if isinstance(value, bool):
                continue
            if not isinstance(value, (int, float)):
                continue
            points.append((ts, float(value)))
    return points


def build_segments(points: list[tuple[int, float]], now_ts: int) -> list[tuple[int, int, float]]:
    segments: list[tuple[int, int, float]] = []
    if not points:
        return segments

    for idx, (start_ts, power_w) in enumerate(points):
        if idx < len(points) - 1:
            end_ts = points[idx + 1][0]
        else:
            end_ts = min(now_ts, start_ts + LAST_OPEN_SEGMENT_MAX_SECONDS)
        if end_ts > start_ts:
            segments.append((start_ts, end_ts, power_w))
    return segments


def pick_source_date(
    source_date_arg: str | None,
    segments: list[tuple[int, int, float]],
    tz: ZoneInfo,
) -> str:
    if source_date_arg:
        try:
            datetime.strptime(source_date_arg, "%Y-%m-%d")
        except ValueError as exc:
            raise ValueError(f"Invalid --source-date '{source_date_arg}', expected YYYY-MM-DD") from exc
        return source_date_arg

    today_str = datetime.now(tz).strftime("%Y-%m-%d")
    for seg_start, seg_end, _power in reversed(segments):
        # If any segment touches today, prefer today as source profile.
        seg_start_date = datetime.fromtimestamp(seg_start, tz).strftime("%Y-%m-%d")
        seg_end_date = datetime.fromtimestamp(max(seg_start, seg_end - 1), tz).strftime("%Y-%m-%d")
        if today_str == seg_start_date or today_str == seg_end_date:
            return today_str

    if not segments:
        return today_str

    # Fallback: latest date observed in segments.
    latest_ts = max(seg_end for _seg_start, seg_end, _power in segments)
    return datetime.fromtimestamp(latest_ts, tz).strftime("%Y-%m-%d")


def compute_hourly_wh_for_date(
    segments: list[tuple[int, int, float]],
    target_date: str,
    tz: ZoneInfo,
) -> list[tuple[float, float]]:
    day_start_dt = datetime.strptime(target_date, "%Y-%m-%d").replace(tzinfo=tz)
    day_end_dt = day_start_dt + timedelta(days=1)
    day_start_ts = int(day_start_dt.timestamp())
    day_end_ts = int(day_end_dt.timestamp())

    hourly = [(0.0, 0.0) for _ in range(24)]  # (charge_wh, discharge_wh)

    for seg_start, seg_end, power_w in segments:
        overlap_start = max(seg_start, day_start_ts)
        overlap_end = min(seg_end, day_end_ts)
        if overlap_end <= overlap_start:
            continue

        cur = overlap_start
        while cur < overlap_end:
            cur_dt = datetime.fromtimestamp(cur, tz)
            hour_start_dt = cur_dt.replace(minute=0, second=0, microsecond=0)
            next_hour_dt = hour_start_dt + timedelta(hours=1)
            slice_end = min(overlap_end, int(next_hour_dt.timestamp()))
            seconds = max(0, slice_end - cur)
            if seconds <= 0:
                cur = slice_end
                continue

            wh = abs(power_w) * (seconds / 3600.0)
            hour_idx = hour_start_dt.hour
            charge_wh, discharge_wh = hourly[hour_idx]
            if power_w > 0:
                charge_wh += wh
            elif power_w < 0:
                discharge_wh += wh
            hourly[hour_idx] = (charge_wh, discharge_wh)
            cur = slice_end

    return hourly


def wh_to_pct(wh: float, base_wh: float, efficiency: float) -> float:
    one_percent_usable_wh = (base_wh / 100.0) * efficiency
    if one_percent_usable_wh <= 0:
        return 0.0
    return wh / one_percent_usable_wh


def build_hour_estimates(hourly_wh: list[tuple[float, float]], base_wh: float, efficiency: float) -> list[HourEstimate]:
    estimates: list[HourEstimate] = []
    for hour, (charge_wh, discharge_wh) in enumerate(hourly_wh):
        estimates.append(
            HourEstimate(
                hour=hour,
                charge_wh=charge_wh,
                discharge_wh=discharge_wh,
                charge_pct=wh_to_pct(charge_wh, base_wh, efficiency),
                discharge_pct=wh_to_pct(discharge_wh, base_wh, efficiency),
            )
        )
    return estimates


def split_day_parts(estimates: list[HourEstimate]) -> dict[str, list[HourEstimate]]:
    charging_hours = [e.hour for e in estimates if e.charge_pct > 0]
    if not charging_hours:
        return {
            "before charging": estimates[:],
            "charging": [],
            "after charging": [],
        }

    first_charge = min(charging_hours)
    last_charge = max(charging_hours)

    before = [e for e in estimates if e.hour < first_charge]
    charging = [e for e in estimates if first_charge <= e.hour <= last_charge]
    after = [e for e in estimates if e.hour > last_charge]
    return {
        "before charging": before,
        "charging": charging,
        "after charging": after,
    }


def summarize(estimates: list[HourEstimate]) -> tuple[float, float]:
    charge_total = sum(e.charge_pct for e in estimates)
    discharge_total = sum(e.discharge_pct for e in estimates)
    return charge_total, discharge_total


def print_report(source_date: str, target_date: str, estimates: list[HourEstimate]) -> None:
    print(f"Source day profile: {source_date}")
    print(f"Estimated next day: {target_date}")
    print()
    print("Hourly estimate (% of battery capacity basis, efficiency-adjusted)")
    print("hour  charge_%  discharge_%")
    for e in estimates:
        print(f"{e.hour:02d}    {e.charge_pct:7.2f}    {e.discharge_pct:10.2f}")

    print()
    print("Subtotals by day part")
    parts = split_day_parts(estimates)
    for label in ("before charging", "charging", "after charging"):
        charge_total, discharge_total = summarize(parts[label])
        print(
            f"{label:16s}  charge={charge_total:7.2f}%  discharge={discharge_total:7.2f}%"
        )

    day_charge, day_discharge = summarize(estimates)
    print()
    print("Day total")
    print(f"charge={day_charge:.2f}%  discharge={day_discharge:.2f}%")


def main() -> int:
    args = parse_args()
    db_path = args.db_path
    if not os.path.exists(db_path):
        print(f"Error: DB not found: {db_path}", file=sys.stderr)
        return 2

    if args.base_wh <= 0:
        print("--base-wh must be > 0", file=sys.stderr)
        return 2
    if args.efficiency <= 0:
        print("--efficiency must be > 0", file=sys.stderr)
        return 2

    try:
        tz = ZoneInfo(args.timezone)
    except Exception as exc:
        print(f"Invalid timezone '{args.timezone}': {exc}", file=sys.stderr)
        return 2

    points = load_change_points(db_path)
    segments = build_segments(points, now_ts=int(time.time()))
    source_date = pick_source_date(args.source_date, segments, tz)

    source_hourly_wh = compute_hourly_wh_for_date(segments, source_date, tz)
    estimates = build_hour_estimates(source_hourly_wh, args.base_wh, args.efficiency)

    source_dt = datetime.strptime(source_date, "%Y-%m-%d")
    target_date = (source_dt + timedelta(days=1)).strftime("%Y-%m-%d")

    print_report(source_date, target_date, estimates)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

