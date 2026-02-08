#!/usr/bin/env python3
"""
Update combined solar + price dataset for today and tomorrow.

Sources:
- Sun elevation (astral)
- Direct solar radiation (Open-Meteo, KNMI seamless)
- Prices (local price API)

Output: data/combined_solar_price.json (merged with existing data)
"""

from __future__ import annotations

import json
import urllib.request
from collections import defaultdict
from datetime import date, datetime, timedelta
from pathlib import Path
from zoneinfo import ZoneInfo

from astral import LocationInfo
from astral.sun import elevation

FIELDS = ("sun_degrees", "direct_radiation", "price", "spot_price")

# Location: Amsterdam / Europe
LOCATION_NAME = "Amsterdam"
LOCATION_REGION = "Europe"
TIMEZONE = "Europe/Amsterdam"
LATITUDE = 52.374
LONGITUDE = 4.8897

SCRIPT_DIR = Path(__file__).resolve().parent
ROOT = SCRIPT_DIR.parent
CONFIG_FILE = ROOT / "config" / "config.json"
OUTPUT_FILE = SCRIPT_DIR / "data" / "combined_solar_price.json"

OPEN_METEO_BASE_URL = "https://api.open-meteo.com/v1/forecast"
OPEN_METEO_MODEL = "knmi_seamless"


def load_json(path: Path) -> dict | None:
    if not path.exists():
        return None
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def get_location() -> LocationInfo:
    return LocationInfo(
        name=LOCATION_NAME,
        region=LOCATION_REGION,
        timezone=TIMEZONE,
        latitude=LATITUDE,
        longitude=LONGITUDE,
    )


def compute_sun_heights_for_date(target_date: date) -> dict:
    tz = ZoneInfo(TIMEZONE)
    observer = get_location().observer
    result: dict[str, float] = {}
    for h in range(24):
        dt = datetime(
            target_date.year,
            target_date.month,
            target_date.day,
            h,
            0,
            0,
            tzinfo=tz,
        )
        deg = elevation(observer, dt, with_refraction=True)
        result[str(h)] = round(deg, 2)
    return result


def build_sun_heights_by_date(target_dates: list[str]) -> dict:
    out: dict[str, dict] = {}
    for d in target_dates:
        target_date = date.fromisoformat(d)
        out[d] = compute_sun_heights_for_date(target_date)
    return out


def fetch_json(url: str, timeout: int = 15) -> dict:
    with urllib.request.urlopen(url, timeout=timeout) as resp:
        return json.loads(resp.read().decode())


def build_open_meteo_url(forecast_days: int) -> str:
    params = {
        "latitude": LATITUDE,
        "longitude": LONGITUDE,
        "hourly": "direct_radiation",
        "models": OPEN_METEO_MODEL,
        "timezone": TIMEZONE.replace("/", "%2F"),
        "forecast_days": forecast_days,
    }
    qs = "&".join(f"{k}={v}" for k, v in params.items())
    return f"{OPEN_METEO_BASE_URL}?{qs}"


def fetch_direct_radiation(target_dates: list[str]) -> dict:
    url = build_open_meteo_url(forecast_days=2)
    api_data = fetch_json(url)
    times = api_data["hourly"]["time"]
    values = api_data["hourly"]["direct_radiation"]
    by_date: dict[str, dict] = defaultdict(dict)
    for t_iso, val in zip(times, values):
        date_part = t_iso.split("T")[0]
        if date_part not in target_dates:
            continue
        hour_part = t_iso.split("T")[1][:2]
        hour_key = str(int(hour_part))
        by_date[date_part][hour_key] = round(float(val), 1) if val is not None else None
    out: dict[str, dict] = {}
    for d in target_dates:
        day = {}
        for h in range(24):
            hour_key = str(h)
            day[hour_key] = by_date.get(d, {}).get(hour_key)
        out[d] = day
    return out


def load_price_api_url() -> str:
    config = load_json(CONFIG_FILE)
    if not config:
        raise SystemExit(f"Missing config file: {CONFIG_FILE}")
    price_urls = config.get("priceUrls", {})
    url = price_urls.get("get_price")
    if not url:
        raise SystemExit("Missing config priceUrls.get_price")
    return url


def normalize_price_hourly(price_data: dict | None) -> dict | None:
    if not price_data:
        return None
    out = {}
    for h in range(24):
        key = str(h).zfill(2)
        val = price_data.get(key)
        out[str(h)] = float(val) if val is not None else None
    return out


def fetch_prices_by_date(target_dates: list[str]) -> dict:
    url = load_price_api_url()
    resp = fetch_json(url)
    dates_info = resp.get("dates", {}) if isinstance(resp, dict) else {}
    today_raw = dates_info.get("today")
    tomorrow_raw = dates_info.get("tomorrow")

    by_date: dict[str, dict] = {}
    if today_raw:
        today_iso = f"{today_raw[:4]}-{today_raw[4:6]}-{today_raw[6:8]}"
        if today_iso in target_dates:
            by_date[today_iso] = normalize_price_hourly(resp.get("today")) or {}
    if tomorrow_raw:
        tomorrow_iso = f"{tomorrow_raw[:4]}-{tomorrow_raw[4:6]}-{tomorrow_raw[6:8]}"
        if tomorrow_iso in target_dates:
            by_date[tomorrow_iso] = normalize_price_hourly(resp.get("tomorrow")) or {}

    out: dict[str, dict] = {}
    for d in target_dates:
        day = {}
        for h in range(24):
            hour_key = str(h)
            day[hour_key] = by_date.get(d, {}).get(hour_key)
        out[d] = day
    return out


def spot_price_from_price(price: float | None) -> float | None:
    if price is None:
        return None
    return price / 1.21 - 0.08


def price_to_int(price: float | None, decimals: int = 3) -> int | None:
    if price is None:
        return None
    return round(price * (10**decimals))


def merge_combined(
    existing_payload: dict | None,
    target_dates: list[str],
    sun_heights: dict,
    radiation: dict,
    prices: dict,
) -> tuple[list[str], dict]:
    existing_combined = existing_payload.get("combined") if existing_payload else None
    existing_dates = existing_payload.get("dates", []) if existing_payload else []
    if not isinstance(existing_combined, dict):
        existing_combined = {}
    if not isinstance(existing_dates, list):
        existing_dates = []

    merged_dates = sorted(set(existing_dates) | set(target_dates))
    combined = json.loads(json.dumps(existing_combined))

    for date_str in target_dates:
        if date_str not in combined:
            combined[date_str] = {}
        for h in range(24):
            hour_key = str(h)
            if hour_key not in combined[date_str]:
                combined[date_str][hour_key] = {}
            existing_hour = combined[date_str][hour_key]
            new_hour = {
                "sun_degrees": sun_heights.get(date_str, {}).get(hour_key),
                "direct_radiation": radiation.get(date_str, {}).get(hour_key),
                "price": price_to_int(prices.get(date_str, {}).get(hour_key)),
                "spot_price": price_to_int(
                    spot_price_from_price(prices.get(date_str, {}).get(hour_key))
                ),
            }
            for key in FIELDS:
                new_val = new_hour.get(key)
                existing_val = existing_hour.get(key)
                combined[date_str][hour_key][key] = (
                    new_val if new_val is not None else existing_val
                )
    return merged_dates, combined


def main() -> None:
    tz = ZoneInfo(TIMEZONE)
    today = datetime.now(tz).date()
    tomorrow = today + timedelta(days=1)
    target_dates = [today.isoformat(), tomorrow.isoformat()]

    sun_heights = build_sun_heights_by_date(target_dates)
    radiation = fetch_direct_radiation(target_dates)
    prices = fetch_prices_by_date(target_dates)

    existing_payload = load_json(OUTPUT_FILE)
    merged_dates, combined = merge_combined(
        existing_payload, target_dates, sun_heights, radiation, prices
    )

    price_api_url = load_price_api_url()
    payload = {
        "timezone": TIMEZONE,
        "location": f"{LOCATION_NAME}, {LOCATION_REGION}",
        "description": "Combined: sun elevation (deg), direct radiation (W/m²), price and spot_price in thousandths (€/kWh × 1000, integer) per hour.",
        "generated_at": datetime.now(tz).isoformat(),
        "sources": {
            "sun_height": "astral",
            "solar_radiation": build_open_meteo_url(forecast_days=2),
            "price_api": price_api_url,
        },
        "range": {"start_date": target_dates[0], "end_date": target_dates[-1]},
        "dates": merged_dates,
        "combined": combined,
    }

    OUTPUT_FILE.parent.mkdir(parents=True, exist_ok=True)
    with open(OUTPUT_FILE, "w", encoding="utf-8") as f:
        json.dump(payload, f, indent=2)

    print(
        f"Wrote {OUTPUT_FILE} for dates {target_dates[0]} to {target_dates[-1]} ({TIMEZONE})."
    )


if __name__ == "__main__":
    main()
