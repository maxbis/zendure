#!/usr/bin/env python3
"""
Estimate round-trip efficiency from the hourly /api/wh_per_hour dataset.

The script groups contiguous net-charge hours into charge legs, then looks for a
following discharge leg that returns the battery to the same SoC or lower. Each
paired leg forms an approximate cycle:

    round_trip_efficiency = discharged_output_wh / charged_input_wh

Because the source data is hourly and electric_level is integer SoC, this is an
approximation intended for operational analysis rather than lab-grade testing.

Usage examples:
  python3 tools/round_trip_efficiency.py
  python3 tools/round_trip_efficiency.py --days 7
  python3 tools/round_trip_efficiency.py --base-url http://81.204.237.36:1611
  python3 tools/round_trip_efficiency.py --input-json /tmp/wh.json
"""

from __future__ import annotations

import argparse
import json
import math
import os
import sys
import time
import urllib.error
import urllib.request
from dataclasses import dataclass
from typing import Optional


DEFAULT_DAYS = 7
DEFAULT_TIMEOUT_SECONDS = 12
DEFAULT_RETRIES = 5
DEFAULT_BASE_WH = 5760.0
DEFAULT_CONFIG_PATH = os.path.join(
    os.path.dirname(os.path.dirname(__file__)),
    "main",
    "config",
    "config.json",
)


@dataclass
class HourRow:
    date: str
    hour: str
    charged_wh: float
    discharged_wh: float
    electric_level: Optional[int]

    @property
    def timestamp_label(self) -> str:
        return f"{self.date} {self.hour}:00"


@dataclass
class Leg:
    kind: str
    start_index: int
    end_index: int
    start_soc: int
    end_soc: int
    energy_wh: float
    avg_power_w: float


@dataclass
class Cycle:
    charge_leg: Leg
    discharge_leg: Leg
    efficiency: float


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--days", type=int, default=DEFAULT_DAYS, help="History window in days.")
    parser.add_argument(
        "--base-url",
        default=None,
        help="Automation runtime base URL, for example http://81.204.237.36:1611",
    )
    parser.add_argument(
        "--input-json",
        default=None,
        help="Read /api/wh_per_hour JSON from a local file instead of fetching it.",
    )
    parser.add_argument(
        "--base-wh",
        type=float,
        default=None,
        help="Battery capacity in Wh. Defaults to config.json baseWh or 5760.",
    )
    parser.add_argument(
        "--band-size",
        type=int,
        default=200,
        help="Power band size in watts for reporting. Default: 200.",
    )
    parser.add_argument(
        "--min-cycle-wh",
        type=float,
        default=200.0,
        help="Minimum charged energy for a paired cycle to be included. Default: 200 Wh.",
    )
    return parser.parse_args()


def load_config(config_path: str) -> dict:
    if not os.path.exists(config_path):
        return {}
    try:
        with open(config_path, "r", encoding="utf-8") as handle:
            data = json.load(handle)
        return data if isinstance(data, dict) else {}
    except Exception:
        return {}


def resolve_base_url(args: argparse.Namespace, config: dict) -> str:
    if args.base_url:
        return args.base_url.rstrip("/")
    raw = config.get("apiBaseUrlPiControl")
    if isinstance(raw, str) and raw.strip():
        return raw.rstrip("/")
    return "http://81.204.237.36:1611"


def resolve_base_wh(args: argparse.Namespace, config: dict) -> float:
    if args.base_wh and args.base_wh > 0:
        return float(args.base_wh)
    raw = config.get("baseWh")
    if isinstance(raw, (int, float)) and raw > 0:
        return float(raw)
    return DEFAULT_BASE_WH


def fetch_json(url: str, timeout: int = DEFAULT_TIMEOUT_SECONDS, retries: int = DEFAULT_RETRIES) -> dict:
    last_error = "unknown error"
    for attempt in range(retries):
        try:
            with urllib.request.urlopen(url, timeout=timeout) as response:
                payload = json.load(response)
            if not isinstance(payload, dict):
                raise ValueError("Expected JSON object")
            return payload
        except (urllib.error.URLError, TimeoutError, ValueError, json.JSONDecodeError) as exc:
            last_error = str(exc)
            if attempt < retries - 1:
                time.sleep(1.5)
    raise RuntimeError(f"Failed to fetch {url}: {last_error}")


def load_hourly_payload(args: argparse.Namespace, base_url: str) -> dict:
    if args.input_json:
        with open(args.input_json, "r", encoding="utf-8") as handle:
            payload = json.load(handle)
        if not isinstance(payload, dict):
            raise RuntimeError("Input JSON must be an object keyed by date.")
        return payload
    url = f"{base_url}/api/wh_per_hour?days={args.days}"
    return fetch_json(url)


def build_rows(payload: dict) -> list[HourRow]:
    rows: list[HourRow] = []
    for date_key in sorted(payload):
        day_rows = payload.get(date_key)
        if not isinstance(day_rows, list):
            continue
        for row in day_rows:
            if not isinstance(row, dict):
                continue
            try:
                charged_wh = float(row.get("charged_wh", 0) or 0)
                discharged_wh = float(row.get("discharged_wh", 0) or 0)
            except (TypeError, ValueError):
                continue
            level_raw = row.get("electric_level")
            try:
                electric_level = int(level_raw) if level_raw is not None else None
            except (TypeError, ValueError):
                electric_level = None
            rows.append(
                HourRow(
                    date=date_key,
                    hour=str(row.get("hour", "00")).zfill(2),
                    charged_wh=charged_wh,
                    discharged_wh=discharged_wh,
                    electric_level=electric_level,
                )
            )
    return rows


def net_kind(row: HourRow, next_row: HourRow) -> Optional[str]:
    if row.electric_level is None or next_row.electric_level is None:
        return None
    dsoc = next_row.electric_level - row.electric_level
    if row.charged_wh > row.discharged_wh and dsoc > 0:
        return "charge"
    if row.discharged_wh > row.charged_wh and dsoc < 0:
        return "discharge"
    return None


def build_leg(rows: list[HourRow], start_index: int, kind: str, base_wh: float) -> Leg:
    end_index = start_index
    energy_wh = 0.0
    while end_index < len(rows) - 1 and net_kind(rows[end_index], rows[end_index + 1]) == kind:
        row = rows[end_index]
        energy_wh += row.charged_wh if kind == "charge" else row.discharged_wh
        end_index += 1

    start_soc = rows[start_index].electric_level
    end_soc = rows[end_index].electric_level
    if start_soc is None or end_soc is None:
        raise RuntimeError("Leg boundaries require electric_level values.")

    hours = max(1, end_index - start_index + 1)
    avg_power_w = energy_wh / hours
    return Leg(
        kind=kind,
        start_index=start_index,
        end_index=end_index,
        start_soc=start_soc,
        end_soc=end_soc,
        energy_wh=energy_wh,
        avg_power_w=avg_power_w,
    )


def pair_cycles(rows: list[HourRow], base_wh: float, min_cycle_wh: float) -> list[Cycle]:
    cycles: list[Cycle] = []
    index = 0
    while index < len(rows) - 1:
        if net_kind(rows[index], rows[index + 1]) != "charge":
            index += 1
            continue

        charge_leg = build_leg(rows, index, "charge", base_wh)
        if charge_leg.energy_wh < min_cycle_wh:
            index = charge_leg.end_index + 1
            continue

        target_soc = charge_leg.start_soc
        probe = charge_leg.end_index + 1
        discharge_leg: Optional[Leg] = None
        while probe < len(rows) - 1:
            current_kind = net_kind(rows[probe], rows[probe + 1])
            if current_kind == "charge":
                break
            if current_kind != "discharge":
                probe += 1
                continue
            candidate = build_leg(rows, probe, "discharge", base_wh)
            if candidate.end_soc <= target_soc and candidate.energy_wh > 0:
                discharge_leg = candidate
                break
            probe = candidate.end_index + 1

        if discharge_leg is not None and charge_leg.energy_wh > 0:
            cycles.append(
                Cycle(
                    charge_leg=charge_leg,
                    discharge_leg=discharge_leg,
                    efficiency=discharge_leg.energy_wh / charge_leg.energy_wh,
                )
            )
            index = discharge_leg.end_index + 1
        else:
            index = charge_leg.end_index + 1
    return cycles


def band_label(avg_power_w: float, band_size: int) -> str:
    lower = int(math.floor(avg_power_w / band_size) * band_size)
    upper = lower + band_size - 1
    return f"{lower}-{upper}W"


def summarize_cycles(cycles: list[Cycle], band_size: int) -> tuple[dict, dict, dict]:
    by_charge_band: dict[str, dict] = {}
    by_discharge_band: dict[str, dict] = {}
    overall = {"charge_wh": 0.0, "discharge_wh": 0.0, "count": 0}

    for cycle in cycles:
        charge_band = band_label(cycle.charge_leg.avg_power_w, band_size)
        discharge_band = band_label(cycle.discharge_leg.avg_power_w, band_size)

        for key, bucket_map in (
            (charge_band, by_charge_band),
            (discharge_band, by_discharge_band),
        ):
            bucket = bucket_map.setdefault(key, {"charge_wh": 0.0, "discharge_wh": 0.0, "count": 0})
            bucket["charge_wh"] += cycle.charge_leg.energy_wh
            bucket["discharge_wh"] += cycle.discharge_leg.energy_wh
            bucket["count"] += 1

        overall["charge_wh"] += cycle.charge_leg.energy_wh
        overall["discharge_wh"] += cycle.discharge_leg.energy_wh
        overall["count"] += 1

    return by_charge_band, by_discharge_band, overall


def print_cycle_table(cycles: list[Cycle], rows: list[HourRow]) -> None:
    if not cycles:
        print("No paired cycles found.")
        return
    print("Paired cycles:")
    for idx, cycle in enumerate(cycles, start=1):
        charge_start = rows[cycle.charge_leg.start_index].timestamp_label
        charge_end = rows[cycle.charge_leg.end_index].timestamp_label
        discharge_start = rows[cycle.discharge_leg.start_index].timestamp_label
        discharge_end = rows[cycle.discharge_leg.end_index].timestamp_label
        print(
            f"  {idx}. "
            f"charge {charge_start} -> {charge_end} "
            f"({cycle.charge_leg.start_soc}% -> {cycle.charge_leg.end_soc}%, "
            f"{cycle.charge_leg.energy_wh:.0f} Wh, avg {cycle.charge_leg.avg_power_w:.0f} W); "
            f"discharge {discharge_start} -> {discharge_end} "
            f"({cycle.discharge_leg.start_soc}% -> {cycle.discharge_leg.end_soc}%, "
            f"{cycle.discharge_leg.energy_wh:.0f} Wh, avg {cycle.discharge_leg.avg_power_w:.0f} W); "
            f"round-trip {cycle.efficiency * 100:.1f}%"
        )


def print_bucket_summary(title: str, bucket_map: dict[str, dict]) -> None:
    print(title)
    if not bucket_map:
        print("  no data")
        return
    for band in sorted(bucket_map, key=lambda item: int(item.split("-")[0])):
        bucket = bucket_map[band]
        charge_wh = bucket["charge_wh"]
        discharge_wh = bucket["discharge_wh"]
        if charge_wh <= 0:
            continue
        efficiency = discharge_wh / charge_wh
        print(
            f"  {band}: "
            f"{efficiency * 100:.1f}% "
            f"(charge {charge_wh:.0f} Wh, discharge {discharge_wh:.0f} Wh, cycles {bucket['count']})"
        )


def main() -> int:
    args = parse_args()
    config = load_config(DEFAULT_CONFIG_PATH)
    base_url = resolve_base_url(args, config)
    base_wh = resolve_base_wh(args, config)

    try:
        payload = load_hourly_payload(args, base_url)
    except Exception as exc:
        print(f"Failed to load hourly dataset: {exc}", file=sys.stderr)
        return 1

    rows = build_rows(payload)
    cycles = pair_cycles(rows, base_wh=base_wh, min_cycle_wh=args.min_cycle_wh)
    by_charge_band, by_discharge_band, overall = summarize_cycles(cycles, band_size=args.band_size)

    print(f"Base URL: {base_url}")
    print(f"History days: {args.days}")
    print(f"Base capacity: {base_wh:.0f} Wh")
    print(f"Paired cycles found: {overall['count']}")
    print()
    print_cycle_table(cycles, rows)
    print()

    if overall["charge_wh"] > 0:
        overall_eff = overall["discharge_wh"] / overall["charge_wh"]
        print(
            "Overall weighted round-trip efficiency: "
            f"{overall_eff * 100:.1f}% "
            f"(charge {overall['charge_wh']:.0f} Wh, discharge {overall['discharge_wh']:.0f} Wh)"
        )
    else:
        print("Overall weighted round-trip efficiency: no data")

    print()
    print_bucket_summary("By average charge power band:", by_charge_band)
    print()
    print_bucket_summary("By average discharge power band:", by_discharge_band)
    print()
    print(
        "Note: this script uses hourly aggregated data and integer SoC, so the reported "
        "efficiency is approximate."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
