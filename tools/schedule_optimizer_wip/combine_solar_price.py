#!/usr/bin/env python3
"""
Combine sun height, solar radiation, and price into one JSON.
Reads: sun_height.json, solar_radiation.json, and data/price/YYYYMM/priceYYYYMMDD.json.
Output: combined_solar_price.json with sun_degrees, direct_radiation, price per date and hour.
"""

import copy
import json
from pathlib import Path

FIELDS = ("sun_degrees", "direct_radiation", "price", "spot_price")

SCRIPT_DIR = Path(__file__).resolve().parent
ROOT = SCRIPT_DIR.parent

SUN_HEIGHT_FILE = SCRIPT_DIR / "data" / "sun_height.json"
SOLAR_RADIATION_FILE = SCRIPT_DIR / "data" / "solar_radiation.json"
PRICE_DIR = ROOT / "data" / "price"
OUTPUT_FILE = SCRIPT_DIR / "data" / "combined_solar_price.json"


def load_json(path: Path) -> dict | None:
    if not path.exists():
        return None
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def price_file_path(date_str: str) -> Path:
    """date_str YYYY-MM-DD -> data/price/YYYYMM/priceYYYYMMDD.json"""
    yyyymmdd = date_str.replace("-", "")
    yyyymm = yyyymmdd[:6]
    return PRICE_DIR / yyyymm / f"price{yyyymmdd}.json"


def get_price_for_hour(price_data: dict | None, hour: str) -> float | None:
    """Hour "0".."23". Price files use "00".."23"."""
    if price_data is None:
        return None
    key = hour.zfill(2)
    val = price_data.get(key)
    return float(val) if val is not None else None


def spot_price_from_price(price: float | None) -> float | None:
    """Spot price (excl. tax) from retail price. Formula can be changed here."""
    if price is None:
        return None
    return price / 1.21 - 0.08


def price_to_int(price: float | None, decimals: int = 3) -> int | None:
    """Round price to decimals and return as integer (price * 10**decimals). None stays None."""
    if price is None:
        return None
    return round(price * (10**decimals))


def main() -> None:
    sun_height = load_json(SUN_HEIGHT_FILE)
    solar_radiation = load_json(SOLAR_RADIATION_FILE)

    if not solar_radiation or "dates" not in solar_radiation or "direct_radiation" not in solar_radiation:
        raise SystemExit("solar_radiation.json missing or invalid (need 'dates' and 'direct_radiation')")

    new_dates = solar_radiation["dates"]
    direct_radiation = solar_radiation["direct_radiation"]
    sun_height_date = sun_height.get("date") if sun_height else None
    sun_degrees_by_date = sun_height.get("sun_degrees") if sun_height else None

    existing_payload = load_json(OUTPUT_FILE)
    existing_combined = existing_payload.get("combined") if existing_payload else None
    existing_dates = existing_payload.get("dates", []) if existing_payload else []
    if not isinstance(existing_combined, dict):
        existing_combined = {}
    if not isinstance(existing_dates, list):
        existing_dates = []

    new_combined = {}
    for date_str in new_dates:
        rad_by_hour = direct_radiation.get(date_str)
        if rad_by_hour is None:
            continue
        price_path = price_file_path(date_str)
        price_data = load_json(price_path)
        day = {}
        for h in range(24):
            hour_key = str(h)
            sun_deg = sun_degrees_by_date[hour_key] if (sun_height_date == date_str and sun_degrees_by_date) else None
            rad = rad_by_hour.get(hour_key)
            price = get_price_for_hour(price_data, hour_key)
            spot = spot_price_from_price(price)
            day[hour_key] = {
                "sun_degrees": sun_deg,
                "direct_radiation": rad,
                "price": price_to_int(price),
                "spot_price": price_to_int(spot),
            }
        new_combined[date_str] = day

    combined = copy.deepcopy(existing_combined)
    merged_dates = sorted(set(existing_dates) | set(new_dates))

    for date_str in new_dates:
        if date_str not in combined:
            combined[date_str] = {}
        for h in range(24):
            hour_key = str(h)
            if hour_key not in combined[date_str]:
                combined[date_str][hour_key] = {}
            new_hour = new_combined.get(date_str, {}).get(hour_key, {})
            existing_hour = combined[date_str][hour_key]
            for key in FIELDS:
                new_val = new_hour.get(key)
                existing_val = existing_hour.get(key)
                combined[date_str][hour_key][key] = new_val if new_val is not None else existing_val

    payload = {
        "timezone": "Europe/Amsterdam",
        "location": "Amsterdam, Europe",
        "description": "Combined: sun elevation (deg), direct radiation (W/m²), price and spot_price in thousandths (€/kWh × 1000, integer) per hour.",
        "dates": merged_dates,
        "combined": combined,
    }

    OUTPUT_FILE.parent.mkdir(parents=True, exist_ok=True)
    with open(OUTPUT_FILE, "w", encoding="utf-8") as f:
        json.dump(payload, f, indent=2)

    print(f"Wrote {OUTPUT_FILE} for dates {merged_dates[0]} to {merged_dates[-1]}." if merged_dates else f"Wrote {OUTPUT_FILE}.")


if __name__ == "__main__":
    main()
