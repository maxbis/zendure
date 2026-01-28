# 📊 Price API v2 Documentation

**Endpoint:** `prices/get_prices_v2.php`

This API fetches electricity prices from external sources (enever.nl), stores them locally in an organized directory structure, and returns the last two available dates of price data.

---

## 🎯 Overview

The Price API v2 is a **self-contained** PHP script that:
- Fetches today's and tomorrow's electricity prices from external APIs
- Stores prices in organized directory structure: `data/price/YYYYMM/priceYYYYMMDD.json`
- Implements smart update logic: only fetches when needed
- Returns the last two available dates of price data
- Can be used as both a **library** (require/include) and an **API endpoint** (HTTP GET)

---

## 📡 API Endpoint Usage

### HTTP GET Request

**URL:** `prices/get_prices_v2.php`  
**Method:** `GET`  
**Query Parameters:** None

**Response Format:**
```json
{
  "today": {
    "00": 0.123,
    "01": 0.125,
    "02": 0.120,
    ...
    "23": 0.130
  },
  "tomorrow": {
    "00": 0.125,
    "01": 0.128,
    ...
    "23": 0.132
  },
  "dates": {
    "today": "20260127",
    "tomorrow": "20260128"
  },
  "updateResults": {
    "today": true,
    "tomorrow": true
  }
}
```

**Response Fields:**
- `today` (object|null): Price data for today (or yesterday if today not available). Keys are hour strings `"00"` through `"23"`, values are float prices in EUR/kWh.
- `tomorrow` (object|null): Price data for tomorrow. Same format as `today`. `null` if not available.
- `dates.today` (string|null): Date string in `YYYYMMDD` format for the "today" data.
- `dates.tomorrow` (string|null): Date string in `YYYYMMDD` format for the "tomorrow" data.
- `updateResults.today` (bool): Whether today's prices were successfully fetched/updated.
- `updateResults.tomorrow` (bool): Whether tomorrow's prices were successfully fetched/updated.

**Error Response:**
```json
{
  "error": "Failed to load configuration",
  "today": null,
  "tomorrow": null,
  "dates": {
    "today": null,
    "tomorrow": null
  }
}
```

**HTTP Headers:**
- `Access-Control-Allow-Origin: *` (CORS enabled)
- `Content-Type: application/json`

---

## 📚 Library Usage

### Include and Call

```php
require_once 'prices/get_prices_v2.php';

$result = getPriceData();

if (isset($result['error'])) {
    echo "Error: " . $result['error'];
} else {
    $todayPrices = $result['today'];
    $tomorrowPrices = $result['tomorrow'];
    $todayDate = $result['dates']['today'];
    $tomorrowDate = $result['dates']['tomorrow'];
}
```

**Return Value:** Same structure as API endpoint response (see above).

---

## ⚙️ Configuration

The API reads configuration from `config/config.json`:

**Required Config Keys:**
```json
{
  "priceUrls": {
    "today": "https://enever.nl/apiv3/stroomprijs_vandaag.php?token=...",
    "tomorrow": "https://enever.nl/apiv3/stroomprijs_morgen.php?token=..."
  },
  "tomorrowFetchHour": 15
}
```

**Configuration Details:**
- `priceUrls.today`: External API URL for today's prices (enever.nl endpoint)
- `priceUrls.tomorrow`: External API URL for tomorrow's prices (enever.nl endpoint)
- `tomorrowFetchHour` (optional, default: `15`): Hour of day (0-23) when tomorrow's prices become available. Tomorrow's prices are only fetched if current hour >= this value.

---

## 🔄 Update Logic

The API implements **smart update logic** to avoid unnecessary API calls:

### Today's Prices
- ✅ **Fetched if:** Price file for today (`priceYYYYMMDD.json`) does not exist
- ⏭️ **Skipped if:** File already exists

### Tomorrow's Prices
- ✅ **Fetched if:**
  1. Current hour >= `tomorrowFetchHour` (default: 15:00 / 3 PM)
  2. Price file for tomorrow does not exist
- ⏭️ **Skipped if:**
  1. Current hour < `tomorrowFetchHour` (too early)
  2. File already exists

### Fallback Behavior
- If today's file doesn't exist, the API will use yesterday's data as "today" if available
- Tomorrow's data is optional and may be `null` if not yet available

---

## 📁 File Storage Structure

Prices are stored in an organized directory structure:

```
data/
└── price/
    ├── 202601/
    │   ├── price20260127.json
    │   ├── price20260128.json
    │   └── ...
    ├── 202602/
    │   ├── price20260201.json
    │   └── ...
    └── ...
```

**File Format:**
- **Directory:** `data/price/YYYYMM/` (year-month)
- **Filename:** `priceYYYYMMDD.json` (e.g., `price20260127.json`)
- **Content:** JSON object with hour keys (`"00"` through `"23"`) and float price values

**Example File Content:**
```json
{
  "00": 0.123,
  "01": 0.125,
  "02": 0.120,
  "03": 0.118,
  ...
  "23": 0.130
}
```

---

## 🔌 External API Integration

The API fetches data from **enever.nl** endpoints:

### Expected External API Response Format

```json
{
  "status": "true",
  "data": [
    {
      "datum": "2026-01-27 00:00:00",
      "prijsNE": 0.123
    },
    {
      "datum": "2026-01-27 01:00:00",
      "prijsNE": 0.125
    },
    ...
  ]
}
```

**Fields:**
- `status`: Must be `"true"` for success
- `data`: Array of price entries
  - `datum`: Date/time string (parsed to extract date and hour)
  - `prijsNE`: Price value (EUR/kWh) as float

**Error Handling:**
- HTTP errors (non-200 status) are logged and skipped
- Invalid JSON responses are logged and skipped
- Missing `status: "true"` is logged and skipped
- Missing or invalid date/price fields are skipped (entry ignored)

---

## 🖥️ CLI Usage

The script can also be run from the command line:

```bash
php prices/get_prices_v2.php
```

**CLI Output:**
```
🔌 Electricity Price Fetcher v2
==================================================

📊 Fetching today prices...
✅ Saved prices to data/price/202601/price20260127.json

📊 Fetching tomorrow prices...
✅ Saved prices to data/price/202601/price20260128.json

📊 Update Results:
  Today: ✅
  Tomorrow: ✅

📅 Available Data:
  Today: 20260127 ✅
  Tomorrow: 20260128 ✅
```

---

## 🔍 Internal Functions

### Core Functions

| Function | Description |
|----------|-------------|
| `loadConfig()` | Loads configuration from `config/config.json` |
| `fetchPricesFromApi($url)` | Fetches price data from external API endpoint |
| `extractDateFromApiData($data)` | Extracts date (YYYYMMDD) from API response |
| `extractPricesFromApiData($data)` | Extracts prices organized by hour (00-23) |
| `getPriceDirectory($dateStr)` | Gets directory path for a date (YYYYMM) |
| `getPriceFilePath($dateStr)` | Gets full file path for a date |
| `priceFileExists($dateStr)` | Checks if price file exists |
| `savePriceData($dateStr, $prices)` | Saves price data to JSON file |
| `loadPriceData($dateStr)` | Loads price data from file |
| `fetchAndSavePrices($url, $label)` | Fetches from API and saves to file |
| `checkAndUpdatePrices($config)` | Checks if updates needed and performs them |
| `findAllAvailableDates()` | Finds all available price file dates |
| `getLastTwoAvailableDates()` | Gets last two available dates (today/tomorrow) |
| `getPriceData()` | **Main function** - orchestrates update and returns data |

---

## ⚠️ Error Handling

**Configuration Errors:**
- Missing config file → Returns error response
- Missing `priceUrls.today` or `priceUrls.tomorrow` → Returns error response
- Invalid JSON in config → Returns error response

**API Fetch Errors:**
- cURL initialization failure → Logged, skipped
- HTTP non-200 status → Logged, skipped
- Invalid JSON response → Logged, skipped
- Missing `status: "true"` → Logged, skipped
- Missing date/price fields → Entry skipped (not fatal)

**File System Errors:**
- Directory creation failure → Logged, returns false
- File write failure → Logged, returns false
- Invalid date format → Logged, returns false

**All errors are logged to PHP error log** (`error_log()`).

---

## 🔗 Integration with Data API

The price files created by this API can be accessed via the **Data API**:

```
GET /data/api/data_api.php?type=price&date=20260127
```

This returns the same price data structure stored in `data/price/202601/price20260127.json`.

---

## 📝 Notes

- **Timezone:** All dates/times use `Europe/Amsterdam` timezone (set via `date_default_timezone_set()`)
- **File Locking:** File writes use `LOCK_EX` flag for atomic writes
- **JSON Formatting:** Files are saved with `JSON_PRETTY_PRINT` for readability
- **CORS:** API endpoint enables CORS (`Access-Control-Allow-Origin: *`) for cross-origin requests
- **CLI Detection:** Script detects CLI vs web execution automatically
- **Directory Structure:** Year-month directories are created automatically if they don't exist

---

## 🚀 Example Usage

### JavaScript/Frontend
```javascript
fetch('https://www.wijs.ovh/zendure/prices/get_prices_v2.php')
  .then(response => response.json())
  .then(data => {
    if (data.error) {
      console.error('Error:', data.error);
    } else {
      console.log('Today:', data.dates.today, data.today);
      console.log('Tomorrow:', data.dates.tomorrow, data.tomorrow);
    }
  });
```

### PHP Backend
```php
require_once 'prices/get_prices_v2.php';

$prices = getPriceData();

if (!isset($prices['error'])) {
    $today = $prices['today'];
    $tomorrow = $prices['tomorrow'];
    
    // Access price for specific hour
    $priceAt3PM = $today['15'] ?? null;
}
```

---

## 🔄 Related APIs

- **Data API:** `data/api/data_api.php?type=price&date=YYYYMMDD` - Read price files
- **Schedule API:** Uses price data for auto-calculation
- **Mobile Page:** Uses this API via `PRICE_API_URL` config

---

## 📋 Summary

| Aspect | Details |
|--------|---------|
| **Endpoint** | `GET prices/get_prices_v2.php` |
| **Response** | JSON with `today`, `tomorrow`, `dates`, `updateResults` |
| **Storage** | `data/price/YYYYMM/priceYYYYMMDD.json` |
| **External API** | enever.nl (today/tomorrow endpoints) |
| **Update Logic** | Smart: only fetches when file missing |
| **Tomorrow Fetch** | After `tomorrowFetchHour` (default: 15:00) |
| **CORS** | Enabled (`*`) |
| **CLI Support** | Yes |
