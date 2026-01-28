# Mobile Page – External API Dependencies

This document lists **all API calls** required for the mobile schedule page (`schedule/charge_schedule_mobile.php`) to function. It covers endpoints the frontend calls directly and, where relevant, what those backends depend on externally.

---

## 1. API Endpoints the Mobile Page Calls

The mobile page uses the same JavaScript modules as the desktop schedule app. All URLs are injected from PHP (via `config/config.json` and `ConfigLoader`). The following endpoints **must be reachable** from the client for the mobile page to work.

| # | Purpose | Config key(s) | HTTP | Endpoint (typical) | When used |
|---|---------|----------------|------|--------------------|-----------|
| 1 | **Schedule CRUD** | `scheduleApiUrl` | GET, POST, DELETE | `data/api/data_api.php?type=schedule&resolved=1` or `schedule/api/charge_schedule_api.php` | Load today/tomorrow schedule, save/edit/delete entries, clear old entries |
| 2 | **Prices (today/tomorrow)** | `priceUrls.get_prices` / `priceUrls.get_prices-local` | GET | `prices/get_prices_v2.php` (or `get_price_v2.php` per config) | Price graph, schedule calculator, auto-calculate |
| 3 | **Calculate schedule** | `calculate_schedule_apiUrl` | POST | `schedule/api/calculate_schedule_api.php` | “Auto” button: simulate then calculate charge/discharge pairs |
| 4 | **Automation status** | `statusApiUrl` / `statusApiUrl-local` | GET | `schedule/api/automation_status_api.php?type=all&limit=20` | Automation Status section, manual refresh, auto-refresh |
| 5 | **Charge status (Zendure)** | `dataApiUrl` / `dataApiUrl-local` | GET | `data/api/data_api.php?type=zendure` | Charge/Discharge boxes (status, power, battery), refresh |
| 6 | **Charge status (P1 meter)** | Same base as above | GET | `data/api/data_api.php?type=zendure_p1` | System & Grid details when P1 is configured; optional |

---

## 2. Injected Script Variables

These globals are set by PHP so the JS can call the right URLs:

| Variable | Set in | Description |
|----------|--------|-------------|
| `API_URL` | `charge_schedule_mobile.php` | Schedule API base URL (`scheduleApiUrl`) |
| `PRICE_API_URL` | `charge_schedule_mobile.php` | Price API URL (`priceUrls.get_prices` or `get_prices-local`) |
| `CALCULATE_SCHEDULE_API_URL` | `charge_schedule_mobile.php` | Calculate-schedule API URL |
| `AUTOMATION_STATUS_API_URL` | `partials/automation_status.php` | Automation status API URL (base + `?type=all&limit=20`) |
| `CHARGE_STATUS_ZENDURE_API_URL` | `partials/charge_status_data.php` | Data API URL with `?type=zendure` |
| `CHARGE_STATUS_P1_API_URL` | `partials/charge_status_data.php` | Data API URL with `?type=zendure_p1` (optional) |

---

## 3. Request / Response Summary

### 3.1 Schedule API (`API_URL`)

- **GET** `?date=YYYYMMDD`  
  - Returns schedule entries, resolved slots, `currentHour`, `currentTime`.
- **POST**  
  - `{ "action": "simulate" }` or `{ "action": "delete" }`: clear old entries (simulate or actual).  
  - `{ "key": "...", "value": "..." }` or with `originalKey`: save/update entry.
- **DELETE**  
  - Body `{ "key": "YYYYMMDDHHmm" }`: delete one entry.

### 3.2 Price API (`PRICE_API_URL`)

- **GET** (no query params).  
- Returns JSON: `{ "today": { "00": …, "01": …, … }, "tomorrow": { … }, "dates": { "today": "YYYYMMDD", "tomorrow": "YYYYMMDD" } }`.

### 3.3 Calculate Schedule API (`CALCULATE_SCHEDULE_API_URL`)

- **POST**  
  - `{ "action": "simulate" }`: returns count of pairs/entries that would be added.  
  - `{ "action": "calculate" }`: performs calculation and writes schedule.

### 3.4 Automation Status API (`AUTOMATION_STATUS_API_URL`)

- **GET** (with `?type=all&limit=20`).  
- Returns JSON: `{ "success": true, "lastChanges": [ … ], "lastUpdate": … }`.

### 3.5 Charge Status – Zendure (`CHARGE_STATUS_ZENDURE_API_URL`)

- **GET** (URL already includes `?type=zendure`).  
- Returns JSON: `{ "success": true, "data": { "properties": { … } }, "timestamp": … }`.

### 3.6 Charge Status – P1 (`CHARGE_STATUS_P1_API_URL`)

- **GET** (URL already includes `?type=zendure_p1`).  
- Returns JSON: `{ "success": true, "data": { … } }`. Optional; used only if P1 is configured.

---

## 4. Backend External Dependencies

These are **not** called directly by the mobile page, but the endpoints above rely on them. They must work for the page to function fully.

| Backend | External dependency | Config / notes |
|---------|---------------------|----------------|
| **Prices** (`prices/get_prices_v2.php`) | **enever.nl** – `priceUrls.today`, `priceUrls.tomorrow` | Fetches today/tomorrow prices; tokens in config. |
| **Calculate schedule** (`calculate_schedule_api.php`) | **Price API** (`priceUrls.get_prices` or `get_prices`) | Uses it to fetch prices for auto-calculation. |
| **Data API** (`data/api/data_api.php`) | **Local JSON** (e.g. Zendure / P1 files) | Reads from files populated by device/meter ingest (e.g. MQTT, `deviceIp`, `p1Meter`). |
| **Automation status** (`automation_status_api.php`) | **Local** `data/automation_status.json` | No external HTTP; file-based. |

---

## 5. Config Keys Quick Reference

Relevant keys in `config/config.json` (and `-local` variants where used):

```
scheduleApiUrl
scheduleApiUrl (fallback: apiUrl)

priceUrls.get_prices
priceUrls.get_prices-local
priceUrls.get_price       (fallback)

calculate_schedule_apiUrl

statusApiUrl
statusApiUrl-local

dataApiUrl
dataApiUrl-local
```

`location` (`"local"` vs `"remote"`) controls whether `-local` URLs are used for status and prices.

---

## 6. Mobile-Specific Notes

- The mobile page reuses the same APIs as the desktop schedule page; no extra endpoints.
- Automation **Refresh** button: short tap → `refreshAllStatus()` (automation + charge status); long-press → full page reload.
- Auto-refresh runs every 20 seconds (when the tab is visible) and calls automation + charge status APIs.
- Price graph is filled via `PRICE_API_URL` during initial load and on schedule refresh.

---

## 7. Checklist for “Page Works”

Ensure all of the following are true:

- [ ] **Schedule API** (`API_URL`) is reachable (GET/POST/DELETE).
- [ ] **Price API** (`PRICE_API_URL`) is reachable and returns today/tomorrow prices.
- [ ] **Calculate API** (`CALCULATE_SCHEDULE_API_URL`) is reachable if you use the “Auto” button.
- [ ] **Automation status API** (`AUTOMATION_STATUS_API_URL`) is reachable.
- [ ] **Data API** for Zendure (`CHARGE_STATUS_ZENDURE_API_URL`) is reachable.
- [ ] **Data API** for P1 (`CHARGE_STATUS_P1_API_URL`) is configured and reachable if you use P1.
- [ ] **CORS**: Backends send `Access-Control-Allow-Origin: *` (or allow your frontend origin) for cross-origin requests.
- [ ] **Backend deps**: enever price URLs and device/meter data ingest are configured and working so the price and charge-status APIs can return data.
