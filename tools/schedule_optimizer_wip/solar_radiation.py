#!/usr/bin/env python3
"""
Fetch direct solar radiation per hour from Open-Meteo (KNMI seamless) for Amsterdam.
Output: JSON in the same format as sun_height.json (timezone, location, hourly data).
Covers today and the next 3 days (4 days total).
"""

import json
import urllib.request
from collections import defaultdict
from pathlib import Path

# Location: Amsterdam / Europe (match Open-Meteo and sun_height)
LOCATION_NAME = "Amsterdam"
LOCATION_REGION = "Europe"
TIMEZONE = "Europe/Amsterdam"
LATITUDE = 52.374
LONGITUDE = 4.8897

# 4 days: today + next 3 days
FORECAST_DAYS = 4

BASE_URL = "https://api.open-meteo.com/v1/forecast"
SCRIPT_DIR = Path(__file__).resolve().parent
OUTPUT_FILE = SCRIPT_DIR / "data" / "solar_radiation.json"


def fetch_direct_radiation() -> dict:
    """Fetch hourly direct_radiation from Open-Meteo. Returns API response as dict."""
    params = {
        "latitude": LATITUDE,
        "longitude": LONGITUDE,
        "hourly": "direct_radiation",
        "models": "knmi_seamless",
        "timezone": TIMEZONE.replace("/", "%2F"),
        "forecast_days": FORECAST_DAYS,
    }
    qs = "&".join(f"{k}={v}" for k, v in params.items())
    url = f"{BASE_URL}?{qs}"
    with urllib.request.urlopen(url, timeout=15) as resp:
        return json.loads(resp.read().decode())


def build_hourly_by_date(api_data: dict) -> tuple[list[str], dict]:
    """
    Parse API hourly time + direct_radiation into per-date hour "0".."23".
    Returns (sorted list of date strings, dict date -> { "0": val, ... "23": val }).
    """
    times = api_data["hourly"]["time"]
    values = api_data["hourly"]["direct_radiation"]
    by_date = defaultdict(dict)
    for t_iso, val in zip(times, values):
        # ISO "2026-02-07T00:00" -> date and hour
        date_part = t_iso.split("T")[0]
        hour_part = t_iso.split("T")[1][:2]
        hour_key = str(int(hour_part))  # "00" -> "0", "09" -> "9"
        by_date[date_part][hour_key] = round(float(val), 1)
    dates_sorted = sorted(by_date.keys())
    # Normalize to "0".."23" keys for consistency with sun_height
    out = {}
    for d in dates_sorted:
        out[d] = {str(h): by_date[d].get(str(h), 0.0) for h in range(24)}
    return dates_sorted, out


def main() -> None:
    api_data = fetch_direct_radiation()
    dates_sorted, direct_radiation = build_hourly_by_date(api_data)

    payload = {
        "dates": dates_sorted,
        "timezone": TIMEZONE,
        "location": f"{LOCATION_NAME}, {LOCATION_REGION}",
        "description": "Direct solar radiation (W/m²) per hour, start of hour. Source: Open-Meteo, model knmi_seamless.",
        "direct_radiation": direct_radiation,
    }

    OUTPUT_FILE.parent.mkdir(parents=True, exist_ok=True)
    with open(OUTPUT_FILE, "w", encoding="utf-8") as f:
        json.dump(payload, f, indent=2)

    print(f"Wrote {OUTPUT_FILE} for dates {dates_sorted[0]} to {dates_sorted[-1]} ({TIMEZONE}).")


if __name__ == "__main__":
    main()
