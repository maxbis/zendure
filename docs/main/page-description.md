# Charge Schedule Manager - Page Description

## Overview

The Charge Schedule Manager (`charge_schedule_mobile.php`) is a comprehensive dashboard for managing and monitoring the Zendure battery system. It provides real-time visualization of charge/discharge schedules, electricity prices, automation status, battery state, and grid usage.

## Page Sections

### 1. Charge/Discharge Status

**Location**: Top of the page

**Content**:
- current charge/discharge/standby state
- current power value
- battery percentage and capacity overview

**Data Source**:
- same-origin proxy: `main/api/charge_status_all_proxy.php`
- upstream automation runtime API configured through `main/config/config.json`

---

### 2. System & Grid Details

**Location**: Below the charge/discharge status

**Content**:
- current grid power from the selected meter
- WiFi signal
- system temperature
- battery-pack details

**Data Source**:
- same as charge/discharge status

---

### 3. Price Overview Bar Graph

**Location**: Below system/grid details

**Content**:
- Bar graph showing electricity prices for today and tomorrow
- Each hour displays:
  - Price in cents (€/kWh)
  - Color gradient from green (low price) to red (high price)
  - Current hour highlighted
- Tomorrow's prices shown only after 15:00 (when available)
- Bars are clickable to create schedule entries at that time

**Data Source**:
- API: Configured via `main/config/config.json` → `priceApiUrl`
- Fetched client-side by `price_overview_bar.js`
- Price files stored in `/main/data/price/YYYYMM/priceYYYYMMDD.json` format (organized by year-month)
- Retrieved via `main/data/api/data_api.php?type=price&date=YYYYMMDD`

---

### 4. Energy Graph

**Location**: Below the price graph

**Content**:
- Wh charged and discharged per hour
- based on the automation runtime's stored status history

**Data Source**:
- same-origin proxy: `main/api/energy_graph_proxy.php`
- source data from the automation runtime `/api/wh_per_hour` endpoint

---

### 5. Automation Status

**Location**: Below the energy graph

**Content**:
- Displays recent automation events (commands sent to Zendure battery)
- Shows recent entries from the automation runtime API
- Includes manual refresh from the web UI

**Data Source**:
- API: `main/api/automation_status_proxy.php`
- upstream automation runtime `/api/automation_status`

---

### 6. Schedule Panels

**Location**: Below automation status

**Content**:
- left panel: today's resolved schedule
- right panel: stored schedule entries
- add, edit, auto-calculate, and clear actions

**Data Source**: 
- File: `/main/data/charge_schedule.json`
- API: `main/data/api/data_api.php?type=schedule&resolved=1`

---

## Data Flow Summary

### Schedule Data
```
main/data/charge_schedule.json → main/data/api/data_api.php?type=schedule → Page (server-side + AJAX)
```

### Price Data
```
External Price API → data_api.php → price_YYYYMMDD.json → Page (client-side)
```

### Automation Status
```
automate_www.py → /api/automation_status → main/api/automation_status_proxy.php → Page
```

### Battery Status
```
Zendure Device → Data Collector → data_api.php → zendure_data.json → Page (server-side)
```

### Grid Status (P1 Meter)
```
P1 Meter Device → Data Collector → data_api.php → zendure_p1_data.json → Page (server-side)
```

---

## Configuration

The page uses configuration from `main/config/config.json` (or fallback to `main/run_schedule/config/config.json`):

- `scheduleApiUrl`: API endpoint for schedule operations
- `priceApiUrl`: Price API endpoint
- `apiBaseUrlPiControl`: Base URL for the automation runtime API
- `chargeStatusApi` / `allApi`: unified charge-status endpoint
- `automationStatusApi`: automation-status endpoint
- `wh-per-hourApi`: energy-graph endpoint
- `location`: `'local'` or `'remote'` - determines which API URLs to use

---

## Technical Notes

- **Timezone**: All times use `Europe/Amsterdam` timezone
- **Schedule Resolution**: Wildcard patterns (containing `*`) are resolved to specific dates
- **Data Caching**: Battery and P1 data are cached in JSON files, updated periodically
- **Real-time Updates**: Some sections refresh automatically via JavaScript
- **Error Handling**: All sections display error messages if data is unavailable
- **Responsive Design**: Page adapts to mobile and desktop viewports

---

## File Structure

```
main/
├── charge_schedule_mobile.php          # Main page
├── partials/                    # Page sections
│   ├── schedule_panels_mobile.php
│   ├── price_overview_bar_mobile.php
│   ├── automation_status.php
│   ├── charge_status_mobile.php
│   ├── charge_status_details_mobile.php
│   ├── energy_graph_mobile.php
│   └── edit_modal.php
├── api/                         # API endpoints
│   ├── charge_schedule_api.php
│   ├── calculate_schedule_api.php
│   ├── automation_status_proxy.php
│   ├── charge_status_all_proxy.php
│   └── energy_graph_proxy.php
└── assets/                      # Frontend resources
    ├── js/                      # JavaScript modules
    └── css/                     # Stylesheets
```

---

## User Interactions

1. **View Schedule**: Browse today's schedule and all entries
2. **Edit Schedule**: Click bars or use Add/Edit buttons to modify entries
3. **View Prices**: See current and future electricity prices
4. **Monitor Status**: Track battery state, automation events, and grid usage
5. **Calculate Energy**: View projected charge/discharge totals
6. **Refresh Data**: Manual refresh buttons for automation status and charge status

---

## Dependencies

- PHP 7.4+ with JSON support
- Modern browser with JavaScript enabled
- Access to data API endpoints
- Valid authentication (via `login/validate.php`)
