## Schedule Optimizer

Utilities to build a combined dataset of:
- Sun elevation per hour (Amsterdam)
- Direct solar radiation per hour (Open-Meteo, KNMI seamless)
- Electricity price per hour (from `data/price/YYYYMM/priceYYYYMMDD.json`)

The output is a single JSON file used by scheduling logic: `data/combined_solar_price.json`.

### What’s in here
- `sun_height.py`: computes hourly sun elevation for a single day.
- `solar_radiation.py`: fetches hourly direct radiation for today + next 3 days.
- `combine_solar_price.py`: merges sun height, solar radiation, and price into one file.
- `data/`: input and output JSON files.
- `requirements.txt`: Python dependency (`astral`).

### Outputs
- `data/sun_height.json`: sun elevation for one day (hours `0`–`23`).
- `data/solar_radiation.json`: direct radiation for multiple days.
- `data/combined_solar_price.json`: merged per-date, per-hour dataset with:
  - `sun_degrees`
  - `direct_radiation`
  - `price` (integer: €/kWh × 1000)
  - `spot_price` (integer: €/kWh × 1000, derived)

### Usage
Install dependencies:
```bash
pip install -r requirements.txt
```

Generate sun height for today (Amsterdam time):
```bash
python sun_height.py
```

Generate sun height for tomorrow or the next day:
```bash
python sun_height.py 1
python sun_height.py 2
```

Fetch solar radiation (today + next 3 days):
```bash
python solar_radiation.py
```

Merge sun height, radiation, and price into a single file:
```bash
python combine_solar_price.py
```

### Notes
- Prices are read from `../data/price/YYYYMM/priceYYYYMMDD.json` relative to this folder.
- All times are in `Europe/Amsterdam`.
