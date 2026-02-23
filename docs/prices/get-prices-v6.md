# Price API v6 Documentation

**Endpoint:** `main/prices/get_prices_v6.php`

This API returns today’s and tomorrow’s electricity prices in a v5-style JSON format. It uses **ENTSO-E** (Document Type A44) as the data source and reads or writes files under `main/data/price/YYYYMM/priceYYYYMMDD.json`.

---

## Overview

get_prices_v6 is a PHP script that:

- Returns **today** and **tomorrow** hourly consumer prices (EUR/kWh) in a single JSON response
- Reads from local price files when present: `main/data/price/YYYYMM/priceYYYYMMDD.json`
- If no file exists for today: fetches from ENTSO-E A44 (NL domain), saves the file, then returns the data
- If no file exists for tomorrow and current time (NL) is **≥ 14:00**: fetches tomorrow from ENTSO-E, saves, and returns it
- Can be used over **HTTP** (JSON response with CORS headers) or in **CLI** (JSON to stdout)

### Configuration

The ENTSO-E API requires a security token. Place it in **`main/prices/config.json`** (git-ignored):

```json
{
    "ENTSOE_SECURITY_TOKEN": "your-entsoe-api-token"
}
```

Create this file locally; it is not committed to the repository.

---

## API usage

### HTTP GET

**URL:** `main/prices/get_prices_v6.php`  
**Method:** `GET`  
**Query parameters:** None

**Success response:** `200` with JSON body (see [Output JSON](#output-json)).

**Headers:**  
`Content-Type: application/json`, `Access-Control-Allow-Origin: *`, plus CORS method/header allowlists.

---

## Output JSON

The response is a single object with four top-level keys.

### Top-level structure

| Key             | Type   | Description |
|-----------------|--------|-------------|
| `today`         | object \| null | Hourly consumer prices for today (see [Hour map](#hour-map)). `null` if unavailable. |
| `tomorrow`      | object \| null | Hourly consumer prices for tomorrow. `null` if not yet available or on failure. |
| `dates`         | object | Dates for which `today` and `tomorrow` apply (YYYYMMDD strings). |
| `updateResults` | object | Whether today/tomorrow were **newly fetched** from ENTSO-E in this request. |

### Hour map (`today` / `tomorrow`)

When not `null`, each of `today` and `tomorrow` is an **object**:

- **Keys:** Hour of day as two-digit strings: `"00"`, `"01"`, … `"23"` (NL local time).
- **Values:** **Consumer price** in **EUR per kWh** (float), including:
  - Day-ahead energy (from ENTSO-E, converted from EUR/MWh to EUR/kWh)
  - Inkoopvergoeding
  - Belasting
  - BTW (21%)

Example (excerpt):

```json
{
  "00": 0.241048,
  "01": 0.237814,
  "02": 0.231199,
  "06": 0.241348,
  "12": 0.237285,
  "23": 0.247579
}
```

There are up to 24 entries per day; keys may be in any order (often sorted when saved to disk).

### `dates` object

| Key       | Type   | Description |
|-----------|--------|-------------|
| `today`   | string | Date for `today` in **YYYYMMDD** (e.g. `"20260219"`). |
| `tomorrow`| string \| null | Date for `tomorrow` in **YYYYMMDD**, or `null` if tomorrow’s data is not returned. |

### `updateResults` object

| Key       | Type | Description |
|-----------|------|-------------|
| `today`   | bool | `true` if today’s data was **fetched from ENTSO-E** in this request and saved; `false` if it was **loaded from an existing file**. |
| `tomorrow`| bool | `true` if tomorrow’s data was **fetched from ENTSO-E** in this request and saved; `false` otherwise (e.g. loaded from file or not requested). |

---

## Full response example

```json
{
  "today": {
    "00": 0.241048,
    "01": 0.237814,
    "02": 0.231199,
    "03": 0.223049,
    "04": 0.219867,
    "05": 0.22811,
    "06": 0.241348,
    "07": 0.253069,
    "08": 0.262489,
    "09": 0.263829,
    "10": 0.253626,
    "11": 0.239605,
    "12": 0.237285,
    "13": 0.239759,
    "14": 0.245038,
    "15": 0.254062,
    "16": 0.261086,
    "17": 0.28145,
    "18": 0.284977,
    "19": 0.281861,
    "20": 0.268391,
    "21": 0.257946,
    "22": 0.255238,
    "23": 0.247579
  },
  "tomorrow": {
    "00": 0.235,
    "01": 0.232,
    "02": 0.228,
    "03": 0.221,
    "04": 0.218,
    "05": 0.225,
    "06": 0.238,
    "07": 0.251,
    "08": 0.260,
    "09": 0.261,
    "10": 0.252,
    "11": 0.238,
    "12": 0.236,
    "13": 0.239,
    "14": 0.244,
    "15": 0.253,
    "16": 0.260,
    "17": 0.280,
    "18": 0.283,
    "19": 0.281,
    "20": 0.267,
    "21": 0.256,
    "22": 0.254,
    "23": 0.246
  },
  "dates": {
    "today": "20260219",
    "tomorrow": "20260220"
  },
  "updateResults": {
    "today": false,
    "tomorrow": true
  }
}
```

- `today` / `tomorrow`: hour → consumer price (EUR/kWh).
- `dates`: which calendar days the two maps refer to.
- `updateResults`: in this example, today was read from file, tomorrow was just fetched from ENTSO-E.

---

## Stored price files

When data is fetched from ENTSO-E, it is written to:

```
main/data/price/YYYYMM/priceYYYYMMDD.json
```

Example: `main/data/price/202602/price20260219.json`

**File content:** A single JSON object that is exactly the same shape as `today` or `tomorrow` in the API response: keys `"00"`–`"23"`, values consumer price (EUR/kWh). No `dates` or `updateResults`; only the hour map.

---

## Update logic

| Data      | When it is fetched from ENTSO-E |
|-----------|----------------------------------|
| **Today** | When there is **no** existing file for today’s date. |
| **Tomorrow** | When the current hour (Europe/Amsterdam) is **≥ 14** and there is **no** existing file for tomorrow’s date. |

Otherwise the script only reads from the existing price files and does not call ENTSO-E.

---

## Data source and pricing

- **Source:** ENTSO-E Transparency Platform, Document Type **A44** (Day-ahead prices), in-domain and out-domain **10YNL----------L** (Netherlands).
- **Resolution:** 15-minute periods; the script aggregates to **hourly** by averaging the four quarter-hour prices per hour in NL time.
- **Consumer price** is computed from the hourly average EUR/MWh as:  
  `(kwh_price + inkoopvergoeding + belasting) * BTW`  
  (constants are defined in the script.)

---

## CLI

When run from the command line (`php get_prices_v6.php`), the script outputs the same JSON to stdout and does not send HTTP headers or status codes.

---

## Related

- **Price API v2:** `main/prices/get_prices_v2.php` (different source/config; see [get-prices-v2.md](get-prices-v2.md)).
- **Data API:** `main/data/api/` for other price/data access patterns.
