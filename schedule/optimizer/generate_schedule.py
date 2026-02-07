#!/usr/bin/env python3
"""Generate a day-ahead / two-day-ahead battery schedule and estimate profit."""

from __future__ import annotations

import argparse
import json
import math
from dataclasses import dataclass
from datetime import datetime, timedelta
from pathlib import Path
from typing import Dict, List, Optional, Tuple

import requests
from zoneinfo import ZoneInfo

TIMEZONE = "Europe/Amsterdam"

# Battery constraints
BATTERY_CAPACITY_KWH = 5.76
MIN_SOC_KWH = 0.864
MAX_SOC_KWH = 5.299
MAX_CHARGE_W = 1200
MAX_DISCHARGE_W = 1200

# Pricing / efficiency
PRICE_DELTA_MIN = 0.12
ROUND_TRIP_EFF = 0.80
TAX_DIVISOR = 1.21
SOLAR_FIXED_DEDUCTION = 0.10880

# Solar assumptions
PANEL_COUNT_EAST = 2
PANEL_COUNT_WEST = 4
PANEL_WATT = 450
BASE_HOME_W = 200
SOLAR_THRESHOLD_W = 50

# Wildcard baseline
BASELINE_RULES = {
    "********0000": 0,
    "********1200": "netzero+",
    "********1500": 0,
}


@dataclass
class HourSlot:
    date: str  # YYYYMMDD
    hour: int
    price: float
    radiation: float

    @property
    def key(self) -> str:
        return f"{self.date}{self.hour:02d}00"


@dataclass
class ProfitTotals:
    total: float
    by_date: Dict[str, float]
    charged_grid_kwh: float
    charged_solar_kwh: float
    discharged_kwh: float


def load_config(config_path: Path) -> Dict[str, object]:
    with config_path.open("r", encoding="utf-8") as f:
        return json.load(f)


def fetch_prices(price_url: str) -> Dict[str, object]:
    response = requests.get(price_url, timeout=15)
    response.raise_for_status()
    data = response.json()
    if not isinstance(data, dict) or "today" not in data:
        raise ValueError("Price API returned unexpected data")
    return data


def fetch_radiation(lat: float, lon: float, tz: str) -> Dict[str, float]:
    url = (
        "https://api.open-meteo.com/v1/forecast"
        f"?latitude={lat}&longitude={lon}"
        "&hourly=direct_radiation"
        "&models=knmi_seamless"
        f"&timezone={tz}"
        "&forecast_days=3"
    )
    response = requests.get(url, timeout=15)
    response.raise_for_status()
    data = response.json()
    hourly = data.get("hourly", {})
    times = hourly.get("time", [])
    radiation = hourly.get("direct_radiation", [])
    result: Dict[str, float] = {}
    for t, r in zip(times, radiation):
        # Expected format: 2026-02-07T12:00 (local time)
        try:
            dt = datetime.fromisoformat(t)
        except ValueError:
            continue
        key = dt.strftime("%Y%m%d%H")
        result[key] = float(r)
    return result


def percentile(values: List[float], p: float) -> float:
    if not values:
        return 0.0
    if len(values) == 1:
        return values[0]
    values = sorted(values)
    idx = (len(values) - 1) * p
    lower = math.floor(idx)
    upper = math.ceil(idx)
    if lower == upper:
        return values[lower]
    weight = idx - lower
    return values[lower] * (1 - weight) + values[upper] * weight


def classify_prices(prices: List[float]) -> Tuple[float, float]:
    low = percentile(prices, 0.25)
    high = percentile(prices, 0.75)
    return low, high


def estimate_solar_w(radiation_w_m2: float) -> float:
    total_panel_w = (PANEL_COUNT_EAST + PANEL_COUNT_WEST) * PANEL_WATT
    if radiation_w_m2 <= 0:
        return 0.0
    estimate = total_panel_w * (radiation_w_m2 / 1000.0)
    return min(total_panel_w, max(0.0, estimate))


def p_excl(p_incl: float) -> float:
    return (p_incl / TAX_DIVISOR) - SOLAR_FIXED_DEDUCTION


def build_hour_slots(
    price_data: Dict[str, object],
    radiation_by_key: Dict[str, float],
    start_dt: datetime,
) -> List[HourSlot]:
    slots: List[HourSlot] = []
    today_prices = price_data.get("today", {})
    tomorrow_prices = price_data.get("tomorrow", {})
    dates = price_data.get("dates", {})

    today_date = dates.get("today")
    tomorrow_date = dates.get("tomorrow")

    if not today_date:
        today_date = start_dt.strftime("%Y%m%d")

    def add_day(day_date: str, day_prices: Dict[str, float], min_hour: int = 0) -> None:
        for hour in range(min_hour, 24):
            hour_key = f"{hour:02d}"
            if hour_key not in day_prices:
                continue
            price = float(day_prices[hour_key])
            rad_key = f"{day_date}{hour:02d}"
            radiation = float(radiation_by_key.get(rad_key, 0.0))
            slots.append(HourSlot(date=day_date, hour=hour, price=price, radiation=radiation))

    start_date = start_dt.strftime("%Y%m%d")

    if today_prices and today_date and start_date <= today_date:
        min_hour = start_dt.hour if start_date == today_date else 0
        add_day(today_date, today_prices, min_hour)

    if tomorrow_date and isinstance(tomorrow_prices, dict):
        add_day(tomorrow_date, tomorrow_prices)

    return slots


def generate_schedule(
    slots: List[HourSlot],
    initial_soc_percent: float,
    initial_cost_price: Optional[float],
) -> Tuple[Dict[str, object], ProfitTotals]:
    if not slots:
        raise ValueError("No future hours available to schedule")

    prices = [slot.price for slot in slots]
    low_threshold, high_threshold = classify_prices(prices)

    # Build future max price lookup
    future_max: List[Optional[float]] = [None] * len(slots)
    max_so_far: Optional[float] = None
    for i in range(len(slots) - 1, -1, -1):
        if max_so_far is None:
            future_max[i] = None
        else:
            future_max[i] = max_so_far
        max_so_far = slots[i].price if max_so_far is None else max(max_so_far, slots[i].price)

    schedule: Dict[str, object] = dict(BASELINE_RULES)

    soc_kwh = max(0.0, min(BATTERY_CAPACITY_KWH, (initial_soc_percent / 100.0) * BATTERY_CAPACITY_KWH))
    soc_kwh = max(MIN_SOC_KWH, min(MAX_SOC_KWH, soc_kwh))

    if initial_cost_price is None:
        initial_cost_price = slots[0].price

    stored_kwh = soc_kwh
    stored_cost = stored_kwh * initial_cost_price

    profit_total = 0.0
    profit_by_date: Dict[str, float] = {}
    charged_grid_kwh = 0.0
    charged_solar_kwh = 0.0
    discharged_kwh = 0.0

    for idx, slot in enumerate(slots):
        action: object = 0

        price_class = "MID"
        if slot.price <= low_threshold:
            price_class = "LOW"
        elif slot.price >= high_threshold:
            price_class = "HIGH"

        solar_w = estimate_solar_w(slot.radiation)
        net_surplus_w = max(0.0, solar_w - BASE_HOME_W)

        if net_surplus_w >= SOLAR_THRESHOLD_W:
            action = "netzero+"
        else:
            future_best = future_max[idx]
            if (
                price_class == "LOW"
                and future_best is not None
                and (future_best - slot.price) >= PRICE_DELTA_MIN
                and (soc_kwh + (MAX_CHARGE_W / 1000.0)) <= MAX_SOC_KWH
            ):
                action = MAX_CHARGE_W
            elif (
                price_class == "HIGH"
                and (soc_kwh - (MAX_DISCHARGE_W / 1000.0)) >= MIN_SOC_KWH
            ):
                action = -MAX_DISCHARGE_W
            else:
                action = 0

        # Update SOC and profit estimates
        if action == "netzero+":
            charge_kwh = min(
                net_surplus_w / 1000.0,
                MAX_CHARGE_W / 1000.0,
                max(0.0, MAX_SOC_KWH - soc_kwh),
            )
            if charge_kwh > 0:
                soc_kwh += charge_kwh
                charged_solar_kwh += charge_kwh
                stored_kwh += charge_kwh
                stored_cost += p_excl(slot.price) * charge_kwh
        elif action == MAX_CHARGE_W:
            charge_kwh = MAX_CHARGE_W / 1000.0
            soc_kwh += charge_kwh
            charged_grid_kwh += charge_kwh
            stored_kwh += charge_kwh
            stored_cost += slot.price * charge_kwh
        elif action == -MAX_DISCHARGE_W:
            discharge_kwh = MAX_DISCHARGE_W / 1000.0
            soc_kwh -= discharge_kwh
            discharged_kwh += discharge_kwh

            if stored_kwh > 0:
                avg_cost = stored_cost / stored_kwh
            else:
                avg_cost = slot.price

            stored_kwh = max(0.0, stored_kwh - discharge_kwh)
            stored_cost = max(0.0, stored_cost - avg_cost * discharge_kwh)

            profit = (slot.price - avg_cost) * discharge_kwh * ROUND_TRIP_EFF
            profit_total += profit
            profit_by_date[slot.date] = profit_by_date.get(slot.date, 0.0) + profit

        # Only add overrides when action differs from baseline default
        baseline = 0
        hour_key = f"{slot.hour:02d}00"
        if hour_key == "0000":
            baseline = BASELINE_RULES.get("********0000", 0)
        elif hour_key == "1200":
            baseline = BASELINE_RULES.get("********1200", 0)
        elif hour_key == "1500":
            baseline = BASELINE_RULES.get("********1500", 0)

        if action != baseline:
            schedule[slot.key] = action

    totals = ProfitTotals(
        total=profit_total,
        by_date=profit_by_date,
        charged_grid_kwh=charged_grid_kwh,
        charged_solar_kwh=charged_solar_kwh,
        discharged_kwh=discharged_kwh,
    )

    return schedule, totals


def write_json(path: Path, payload: Dict[str, object]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8") as f:
        json.dump(payload, f, indent=2, ensure_ascii=False)


def main() -> int:
    parser = argparse.ArgumentParser(description="Generate charge/discharge schedule.")
    parser.add_argument("--config", default="config/config.json", help="Path to config.json")
    parser.add_argument("--price-url", default=None, help="Override price API URL")
    parser.add_argument("--soc", type=float, required=True, help="Current SOC percent")
    parser.add_argument("--lat", type=float, default=52.374, help="Latitude for radiation forecast")
    parser.add_argument("--lon", type=float, default=4.8897, help="Longitude for radiation forecast")
    parser.add_argument("--timezone", default=TIMEZONE, help="Timezone name for forecast")
    parser.add_argument("--output", default="data/charge_schedule.json", help="Schedule output JSON")
    parser.add_argument("--profit-output", default="data/charge_schedule_profit.json", help="Profit output JSON")
    parser.add_argument("--initial-cost-price", type=float, default=None, help="Initial kWh cost basis")
    args = parser.parse_args()

    config_path = Path(args.config)
    config = load_config(config_path)

    price_url = args.price_url
    if not price_url:
        price_urls = config.get("priceUrls", {}) if isinstance(config, dict) else {}
        price_url = price_urls.get("get_prices") or price_urls.get("get_price")

    if not price_url:
        raise SystemExit("Price API URL not found in config or args")

    price_data = fetch_prices(price_url)

    radiation_by_key = fetch_radiation(args.lat, args.lon, args.timezone)

    tz = ZoneInfo(args.timezone)
    now = datetime.now(tz)
    if now.minute > 0 or now.second > 0 or now.microsecond > 0:
        now = now.replace(minute=0, second=0, microsecond=0) + timedelta(hours=1)
    else:
        now = now.replace(second=0, microsecond=0)

    slots = build_hour_slots(price_data, radiation_by_key, now)

    schedule, totals = generate_schedule(
        slots=slots,
        initial_soc_percent=args.soc,
        initial_cost_price=args.initial_cost_price,
    )

    write_json(Path(args.output), schedule)

    profit_payload = {
        "generated_at": datetime.now(tz).isoformat(timespec="seconds"),
        "initial_soc_percent": args.soc,
        "initial_cost_price": args.initial_cost_price or slots[0].price,
        "profit_total": round(totals.total, 4),
        "profit_by_date": {k: round(v, 4) for k, v in totals.by_date.items()},
        "charged_grid_kwh": round(totals.charged_grid_kwh, 4),
        "charged_solar_kwh": round(totals.charged_solar_kwh, 4),
        "discharged_kwh": round(totals.discharged_kwh, 4),
    }

    write_json(Path(args.profit_output), profit_payload)

    print(f"Schedule written to {args.output}")
    print(f"Profit estimate written to {args.profit_output}")
    print(f"Estimated profit total: {profit_payload['profit_total']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
