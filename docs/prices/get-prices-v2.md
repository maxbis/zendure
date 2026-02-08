# Price API v2 Documentation

**Endpoint:** `prices/get_prices_v2.php`

This API fetches electricity prices from external sources (enever.nl), stores them locally in an organized directory structure, and returns the last two available dates of price data.

---

## Overview

The Price API v2 is a **self-contained** PHP script that:
- Fetches today's and tomorrow's electricity prices from external APIs
- Stores prices in organized directory structure: `data/price/YYYYMM/priceYYYYMMDD.json`
- Implements smart update logic: only fetches when needed
- Returns the last two available dates of price data
- Can be used as both a **library** (require/include) and an **API endpoint** (HTTP GET)

---

## API Endpoint Usage

### HTTP GET Request

**URL:** `prices/get_prices_v2.php`  
**Method:** `GET`  
**Query Parameters:** None

**Response Format:**
```json
{
  "today": { "00": 0.123, "01": 0.125, ... "23": 0.130 },
  "tomorrow": { "00": 0.125, "01": 0.128, ... "23": 0.132 },
  "dates": { "today": "20260127", "tomorrow": "20260128" },
  "updateResults": { "today": true, "tomorrow": true }
}
```

**Response Fields:**
- `today` (object|null): Price data for today. Keys are hour strings `"00"` through `"23"`, values are float prices in EUR/kWh.
- `tomorrow` (object|null): Price data for tomorrow. Same format. `null` if not available.
- `dates.today` (string|null): Date in YYYYMMDD format.
- `dates.tomorrow` (string|null): Date in YYYYMMDD format.
- `updateResults.today` (bool): Whether today's prices were successfully fetched/updated.
- `updateResults.tomorrow` (bool): Whether tomorrow's prices were successfully fetched/updated.

**Error Response:** Returns `{ "error": "...", "today": null, "tomorrow": null, "dates": { "today": null, "tomorrow": null } }`

**HTTP Headers:** `Access-Control-Allow-Origin: *`, `Content-Type: application/json`

---

## Configuration

Reads from `config/config.json`:

**Required Config Keys:**
- `priceUrls.today`: External API URL for today's prices (enever.nl)
- `priceUrls.tomorrow`: External API URL for tomorrow's prices (enever.nl)
- `tomorrowFetchHour` (optional, default: 15): Hour when tomorrow's prices become available

---

## Update Logic

**Today's Prices:** Fetched if price file for today does not exist. Skipped if file exists.

**Tomorrow's Prices:** Fetched if current hour >= `tomorrowFetchHour` AND file for tomorrow does not exist. Skipped otherwise.

**Fallback:** If today's file doesn't exist, uses yesterday's data as "today" if available.

---

## File Storage Structure

```
data/price/YYYYMM/priceYYYYMMDD.json
```

Example: `data/price/202601/price20260127.json`

---

## Related APIs

- **Data API:** `data/api/data_api.php?type=price&date=YYYYMMDD`
- **Schedule API:** Uses price data for auto-calculation
- **Mobile Page:** Uses this API via `PRICE_API_URL` config
