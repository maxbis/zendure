# Conditional Rules System

An independent Python-based system that evaluates conditional rules and generates schedule entries based on battery level, energy prices, and day of week.

## Overview

This system reads rules from `data/rules.json`, evaluates conditions against current data (battery level, prices), and generates schedule entries in the same format as the main schedule system. The output is written to `schedule/data/conditional_schedule.json` for testing before integration.

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

1. Install Python dependencies:
```bash
pip install -r requirements.txt
```

## Usage

Run the script:
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

Rules are defined in `data/rules.json`:

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
      "time_range": {
        "start": "1400",
        "end": "1500"
      },
      "days_of_week": [1, 2, 3, 4, 5, 6, 7],
      "date_range": null
    }
  ]
}
```

### Rule Fields

- **id**: Unique identifier for the rule
- **name**: Human-readable description
- **enabled**: Whether the rule is active (can be overridden by global `enabled`)
- **conditions**: Object containing condition checks
  - **battery_level**: `{ "operator": ">", "value": 80 }`
  - **price**: `{ "operator": ">", "value": 0.30, "hour": 14 }` (must specify hour 0-23)
- **action**: Schedule value (integer, `"netzero"`, `"netzero+"`, or `0`)
- **time_range**: `{ "start": "1400", "end": "1500" }` (HHmm format)
- **days_of_week**: Array of day numbers (1=Monday, 7=Sunday) or null for all days
- **date_range**: Optional date range (not yet implemented)

### Condition Operators

- `>` - Greater than
- `<` - Less than
- `>=` - Greater than or equal
- `<=` - Less than or equal
- `==` - Equal to
- `!=` - Not equal to

### Action Values

Actions match the schedule format exactly:
- **Integers**: `500` (charge), `-100` (discharge), `0` (stop)
- **Strings**: `"netzero"`, `"netzero+"`

## Schedule Entry Generation

For each matching rule, the system generates **two entries**:

1. **Start time entry**: Action value at start time
2. **End time entry**: Value `0` at end time

Example: Rule with `time_range: {start: "1400", end: "1500"}`, `action: -200` generates:
```json
{
  "202401141400": -200,
  "202401141500": 0,
  "202401151400": -200,
  "202401151500": 0
}
```

Entries are generated for both today and tomorrow.

## Data Sources

The script fetches data from:

1. **Battery Level**:
   - Primary: `data/zendure_data.json` (cached file)
   - Fallback: `data/api/data_api.php?type=zendure` (API)

2. **Energy Prices**:
   - From price API configured in `config/config.json`:
     - `priceUrls.get_prices` or `priceUrls.get_prices-local`
   - Returns hourly prices for today and tomorrow

3. **Configuration**:
   - Reads from `config/config.json` for API URLs

## Output

The generated schedule is written to:
- `schedule/data/conditional_schedule.json`

Format matches `data/charge_schedule.json`:
```json
{
  "202601141400": -100,
  "202601141500": 0,
  "202601151400": -100,
  "202601151500": 0
}
```

## Error Handling

- Missing data: Rules that can't be evaluated are skipped (logged as warnings)
- API failures: Falls back to cached data when available
- Invalid rules: Logged as errors, processing continues
- File errors: Logged, script exits with error code

## Logging

The script logs to stdout with timestamps:
- INFO: Normal operations (loading rules, evaluating, generating entries)
- WARNING: Missing data, skipped rules
- ERROR: Fatal errors (file not found, invalid JSON, etc.)

## Future Integration

Once tested, the conditional schedule can be merged with the main schedule:
- Manual entries in `data/charge_schedule.json` take precedence
- Conditional entries from `schedule/data/conditional_schedule.json` fill gaps
- A merge tool can combine both files

## Examples

See `data/rules.json` for example rules including:
- Battery level + price conditions
- Day of week filtering
- Different action types (charge, discharge, netzero)
