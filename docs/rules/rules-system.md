# Conditional Rules System

An independent Python-based system that evaluates conditional rules and generates schedule entries based on battery level, energy prices, and day of week.

## Overview

This system reads rules from `rules/data/rules.json`, evaluates conditions against current data (battery level, prices), and generates schedule entries in the same format as the main schedule system. The output is written to `schedule/data/conditional_schedule.json` for testing before integration.

## Directory Structure

```
rules/
├── data/
│   └── rules.json          # Rule definitions
├── render_rules.py          # Main Python script
├── requirements.txt         # Python dependencies
└── README.md               # This file
```

## Installation

```bash
pip install -r requirements.txt
```

## Usage

```bash
python render_rules.py
```

The script will:
1. Load rules from `rules/data/rules.json`
2. Fetch current battery level (from cached JSON or API)
3. Fetch energy prices (from price API)
4. Evaluate each rule's conditions
5. Generate schedule entries for matching rules
6. Write output to `schedule/data/conditional_schedule.json`

## Rules Format

Rules are defined in `rules/data/rules.json`:

```json
{
  "enabled": true,
  "rules": [
    {
      "id": "rule_001",
      "name": "Discharge at 14:00 if battery >80% and 14:00 price >0.30",
      "enabled": true,
      "conditions": {
        "battery_level": { "operator": ">", "value": 80 },
        "price": { "operator": ">", "value": 0.30, "hour": 14 }
      },
      "action": -100,
      "time_range": { "start": "1400", "end": "1500" },
      "days_of_week": [1, 2, 3, 4, 5, 6, 7],
      "date_range": null
    }
  ]
}
```

### Rule Fields

- **id**: Unique identifier
- **name**: Human-readable description
- **enabled**: Whether the rule is active
- **conditions**: `battery_level`, `price` (must specify hour 0-23)
- **action**: Schedule value (integer, `"netzero"`, `"netzero+"`, or `0`)
- **time_range**: `{ "start": "1400", "end": "1500" }` (HHmm format)
- **days_of_week**: Array of day numbers (1=Monday, 7=Sunday) or null for all days

### Condition Operators

`>`, `<`, `>=`, `<=`, `==`, `!=`

## Schedule Entry Generation

For each matching rule, generates two entries: action value at start time, and `0` at end time. Entries generated for both today and tomorrow.

## Data Sources

1. **Battery Level**: `data/zendure_data.json` or `data/api/data_api.php?type=zendure`
2. **Energy Prices**: From price API (`priceUrls.get_prices` or `priceUrls.get_prices-local`)
3. **Configuration**: `config/config.json` for API URLs

## Output

Written to `schedule/data/conditional_schedule.json`. Format matches `data/charge_schedule.json`.
