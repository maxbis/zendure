#!/usr/bin/env python3
"""
Sun height per hour for schedule_optimizer.

Computes solar elevation (degrees) for each hour 0–23 of a single day.
Location: Amsterdam / Europe. Output: JSON with date, timezone, and 24 hourly sun heights.
Elevation is at the start of each hour (local time). Negative values = below horizon.
"""

import argparse
import json
from datetime import date, datetime, timedelta
from pathlib import Path
from zoneinfo import ZoneInfo

from astral import LocationInfo
from astral.sun import elevation

# Location: Amsterdam / Europe
LOCATION_NAME = "Amsterdam"
LOCATION_REGION = "Europe"
TIMEZONE = "Europe/Amsterdam"
LATITUDE = 52.3676
LONGITUDE = 4.9041

SCRIPT_DIR = Path(__file__).resolve().parent
OUTPUT_FILE = SCRIPT_DIR / "data" / "sun_height.json"


def get_location():
    """Return LocationInfo for Amsterdam (observer used for sun elevation)."""
    return LocationInfo(
        name=LOCATION_NAME,
        region=LOCATION_REGION,
        timezone=TIMEZONE,
        latitude=LATITUDE,
        longitude=LONGITUDE,
    )


def compute_sun_heights_for_date(target_date: date, location: LocationInfo) -> dict:
    """
    Compute sun elevation (degrees) for each hour 0–23 on target_date in local time.
    Returns dict keyed by hour "0".."23" with elevation in degrees (rounded to 2 decimals).
    Negative = below horizon.
    """
    tz = ZoneInfo(TIMEZONE)
    observer = location.observer
    result = {}
    for h in range(24):
        # Start of hour in local time
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


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Compute sun elevation per hour for a given day (Amsterdam)."
    )
    parser.add_argument(
        "day_offset",
        type=int,
        nargs="?",
        default=0,
        help="Day offset: 0=today, 1=tomorrow, 2=day after (default: 0)",
    )
    args = parser.parse_args()
    if args.day_offset not in (0, 1, 2):
        parser.error("day_offset must be 0, 1, or 2")

    tz = ZoneInfo(TIMEZONE)
    today_local = date.today()
    # Use local "today" in Amsterdam for consistency with hourly slots
    now_amsterdam = datetime.now(tz)
    today_amsterdam = now_amsterdam.date()
    target_date = today_amsterdam + timedelta(days=args.day_offset)

    location = get_location()
    sun_degrees = compute_sun_heights_for_date(target_date, location)

    payload = {
        "date": target_date.isoformat(),
        "timezone": TIMEZONE,
        "location": f"{LOCATION_NAME}, {LOCATION_REGION}",
        "description": "Sun elevation in degrees; negative = below horizon. At start of each hour (local time).",
        "sun_degrees": sun_degrees,
    }

    OUTPUT_FILE.parent.mkdir(parents=True, exist_ok=True)
    with open(OUTPUT_FILE, "w", encoding="utf-8") as f:
        json.dump(payload, f, indent=2)

    print(f"Wrote {OUTPUT_FILE} for date {target_date} ({TIMEZONE}).")


if __name__ == "__main__":
    main()
