#!/usr/bin/env python3
"""
Sun height per hour for schedule_optimizer, with time_start and time_end at 20° elevation.

Copy of sun_height.py with two extra output keys:
- time_start: local time (HH:MM) when sun reaches 20° altitude (rising), interpolated.
- time_end:   local time (HH:MM) when sun goes below 20° altitude (setting), interpolated.

Location: Amsterdam / Europe. Output: JSON with date, timezone, 24 hourly sun heights,
and time_start / time_end as HH:MM strings.
"""

import argparse
import json
import sys
from datetime import date, datetime, timedelta
from zoneinfo import ZoneInfo
from typing import Optional, Tuple

from astral import LocationInfo
from astral.sun import elevation

# Elevation threshold for "sun up" / "sun down" times (degrees)
SUN_UP_DOWN_DEGREES = 20.0

# Location: Amsterdam / Europe
LOCATION_NAME = "Amsterdam"
LOCATION_REGION = "Europe"
TIMEZONE = "Europe/Amsterdam"
LATITUDE = 52.3676
LONGITUDE = 4.9041

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


def _interpolate_minutes(hour: int, e0: float, e1: float, target: float) -> Optional[float]:
    """
    Linear interpolation: elevation at start of hour is e0, at start of next hour is e1.
    Return minutes-from-midnight when elevation equals target, or None if no crossing.
    """
    if e1 == e0:
        return None
    # fraction of the hour (0..1) where we hit target
    frac = (target - e0) / (e1 - e0)
    if not 0 <= frac <= 1:
        return None
    return 60 * hour + 60 * frac


def compute_20deg_times(sun_degrees: dict) -> Tuple[Optional[str], Optional[str]]:
    """
    From hourly sun elevations, compute time_start (sun up at 20°) and time_end (sun down at 20°).
    Returns (time_start, time_end) as "HH:MM" strings or None if not found.
    """
    target = SUN_UP_DOWN_DEGREES
    hours = [int(h) for h in sun_degrees.keys()]
    hours.sort()
    elevations = [sun_degrees[str(h)] for h in hours]

    time_start_minutes: Optional[float] = None
    time_end_minutes: Optional[float] = None

    # Sun up: first crossing with e[h] < 20 <= e[h+1] (or e[h] <= 20 < e[h+1])
    for i in range(len(hours) - 1):
        e0, e1 = elevations[i], elevations[i + 1]
        if e0 < target <= e1 or (e0 <= target < e1 and e0 != e1):
            time_start_minutes = _interpolate_minutes(hours[i], e0, e1, target)
            break

    # Sun down: last crossing with e[h] >= 20 > e[h+1] (or e[h] > 20 >= e[h+1])
    for i in range(len(hours) - 1, 0, -1):
        e0, e1 = elevations[i - 1], elevations[i]
        if e0 >= target > e1 or (e0 > target >= e1 and e0 != e1):
            time_end_minutes = _interpolate_minutes(hours[i - 1], e0, e1, target)
            break

    def minutes_to_hhmm(m: Optional[float]) -> Optional[str]:
        if m is None:
            return None
        total = int(round(m))
        h = total // 60
        mi = total % 60
        if h >= 24:
            h, mi = 23, 59
        return f"{h:02d}:{mi:02d}"

    return (minutes_to_hhmm(time_start_minutes), minutes_to_hhmm(time_end_minutes))


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Compute sun elevation per hour and 20° up/down times (Amsterdam)."
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
    now_amsterdam = datetime.now(tz)
    today_amsterdam = now_amsterdam.date()
    target_date = today_amsterdam + timedelta(days=args.day_offset)

    location = get_location()
    sun_degrees = compute_sun_heights_for_date(target_date, location)
    time_start, time_end = compute_20deg_times(sun_degrees)

    payload = {
        "date": target_date.isoformat(),
        "timezone": TIMEZONE,
        "location": f"{LOCATION_NAME}, {LOCATION_REGION}",
        "description": "Sun elevation in degrees; negative = below horizon. At start of each hour (local time). time_start/time_end: sun at 20° (rising/setting), HH:MM.",
        "sun_degrees": sun_degrees,
        "time_start": time_start,
        "time_end": time_end,
    }

    json.dump(payload, sys.stdout, indent=2)


if __name__ == "__main__":
    main()
