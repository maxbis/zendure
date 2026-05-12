#!/usr/bin/env python3
import argparse
import json


parser = argparse.ArgumentParser()
parser.add_argument("--date", required=True)
parser.add_argument("--output")
args = parser.parse_args()

report = {
    "date": args.date,
    "timezone": "Europe/Amsterdam",
    "day_start_ts": 0,
    "day_end_ts": 0,
    "analysis_end_ts": 0,
    "is_partial_day": True,
    "price_file_found": True,
    "price_file_path": None,
    "price_source": "db:price_ticks",
    "price_hours_available": 24,
    "hours": [
        {
            "hour": "00",
            "charged_wh": 0.0,
            "discharged_wh": 0.0,
            "battery_pct_start": None,
            "battery_pct_end": None,
            "battery_pct_delta": None,
            "grid_from_wh": 0.0,
            "grid_to_wh": 0.0,
            "price_eur_per_kwh": 0.20,
            "grid_from_cost": 0.0,
            "grid_to_cost": 0.0,
            "net_cost": 0.0,
            "savings_eur": 0.0,
            "charge_cost_eur": 0.0,
            "is_partial_hour": False,
        }
    ],
    "totals": {
        "charged_wh": 0.0,
        "discharged_wh": 0.0,
        "battery_pct_delta_total": None,
        "grid_from_wh": 0.0,
        "grid_to_wh": 0.0,
        "grid_from_cost": 0.0,
        "grid_to_cost": 0.0,
        "net_cost": 0.0,
        "savings_eur": 0.0,
        "charge_cost_eur": 0.0,
    },
}

print(json.dumps(report))
