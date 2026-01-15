# Charge Schedule Manager - Page Description

## Overview

The Charge Schedule Manager (`charge_schedule.php`) is a comprehensive dashboard for managing and monitoring the Zendure battery system. It provides real-time visualization of charge/discharge schedules, electricity prices, automation status, battery state, and grid usage.

## Page Sections

### 1. Schedule Panels

**Location**: Top of the page, two-column layout

**Left Panel - Today's Schedule**:
- Displays the resolved schedule for the current day
- Shows time slots with their charge/discharge values
- Highlights the currently active time slot
- Values can be:
  - Numeric (e.g., `+500 W` for charging, `-300 W` for discharging)
  - `netzero` - Net zero mode (discharge to balance grid)
  - `netzero+` - Solar charge mode (charge from solar excess)
- Time slots are color-coded by time of day (night/morning/afternoon/evening)

**Right Panel - Schedule Entries**:
- Table showing all schedule entries stored in the system
- Each entry has:
  - **Key**: 12-character time pattern (e.g., `202412251200` for Dec 25, 2024 at 12:00)
  - **Value**: Charge/discharge value or special mode
  - **Type**: Badge indicating if entry is "Exact" or "Wildcard" (contains `*`)
- Actions: Add, Auto, Clear buttons for managing entries

**Data Source**: 
- File: `/data/charge_schedule.json`
- API: `schedule/api/charge_schedule_api.php`
- Loaded server-side on page load, updated via AJAX

---

### 2. Schedule Overview Bar Graph

**Location**: Below schedule panels, full-width

**Content**:
- Visual bar graph representation of today's and tomorrow's schedule
- Each hour is represented as a bar
- Bar colors indicate:
  - Green: Charging (positive values)
  - Red: Discharging (negative values)
  - Gray: Standby/neutral
  - Special colors for `netzero` and `netzero+` modes
- Current hour is highlighted
- Clickable bars allow quick editing of schedule entries

**Data Source**:
- Same as Schedule Panels
- Rendered client-side by `schedule_renderer.js`
- Updates dynamically when schedule changes

---

### 3. Price Overview Bar Graph

**Location**: Below schedule graph, full-width

**Content**:
- Bar graph showing electricity prices for today and tomorrow
- Each hour displays:
  - Price in cents (€/kWh)
  - Color gradient from green (low price) to red (high price)
  - Current hour highlighted
- Tomorrow's prices shown only after 15:00 (when available)
- Bars are clickable to create schedule entries at that time

**Data Source**:
- API: Configured via `config.json` → `priceUrls.get_price` or `priceUrls.get_prices`
- Fetched client-side by `price_overview_bar.js`
- Price files stored in `/data/price_YYYYMMDD.json` format
- Retrieved via `data/api/data_api.php?type=price&date=YYYYMMDD`

---

### 4. Price Statistics

**Location**: Below price graph

**Content**:
- Four metric cards displaying:
  - **Minimum Price**: Lowest price for the day with time
  - **Maximum Price**: Highest price for the day with time
  - **Average Price**: Mean price across all hours
  - **Current Price**: Price for the current hour

**Data Source**:
- Same price API as Price Overview Bar Graph
- Calculated client-side by `price_statistics.js`
- Updates when price data refreshes

---

### 5. Schedule Calculator

**Location**: Below price statistics

**Content**:
- Three calculation cards showing energy sums:
  - **Today - Full Day**: Total charge/discharge for entire day
  - **Today - From Current Time**: Remaining charge/discharge from now
  - **Tomorrow - Full Day**: Total charge/discharge for tomorrow
- Each card shows:
  - **Total Sum**: Net energy (Wh) - positive (charge) or negative (discharge)
  - **Charge (Positive)**: Total charging energy (Wh)
  - **Discharge (Negative)**: Total discharging energy (Wh)
- Values are color-coded (green for charge, red for discharge)

**Data Source**:
- Schedule data from `charge_schedule_api.php`
- Calculated server-side in `partials/calculate.php`
- Uses resolved schedule with duration calculations
- `netzero` evaluates to -350W, `netzero+` evaluates to +350W

---

### 6. Automation Status

**Location**: Below schedule calculator, full-width

**Content**:
- Displays recent automation events (commands sent to Zendure battery)
- Shows up to 20 most recent entries
- Each entry displays:
  - **Type Badge**: Color-coded by event type:
    - `change`: Schedule value change (most common)
    - `start`: Automation process started
    - `stop`: Automation process stopped
    - Other custom types
  - **Time**: Relative time (e.g., "2 minutes ago") with full timestamp
  - **Details**: Old value → New value transition
- First entry expanded by default, others collapsed
- Click to expand/collapse all entries
- Refresh button to manually update

**Data Source**:
- File: `/data/automation_status.json`
- API: `schedule/api/automation_status_api.php`
- Loaded server-side on page load
- Updated by automation process (`automate/automate.py`) when commands are sent
- Entries older than 3 days are automatically cleaned up

---

### 7. Charge/Discharge Status

**Location**: Below automation status, full-width

**Content**:
- **Status Indicator**: Large icon and text showing current state:
  - 🟢 **Charging**: Battery is charging
  - 🔴 **Discharging**: Battery is discharging
  - ⚪ **Standby**: Battery is idle
- **Power Display**: 
  - Current power value (W) with color coding
  - Time estimate until max/min level reached
  - Visual bar graph (-1200W to +1200W range)
- **Battery Level**:
  - Percentage and capacity (kWh)
  - Shows total capacity and usable capacity (above minimum)
  - Visual bar with min/max markers (20% min, 90% max)

**Data Source**:
- API: `data/api/data_api.php?type=zendure`
- File: `/data/zendure_data.json` (cached)
- Updated by automation process or external data collection
- Properties used:
  - `acMode`: 0=Standby, 1=Charging, 2=Discharging
  - `outputPackPower`: Power going to battery (positive = charging)
  - `outputHomePower`: Power from battery (positive = discharging)
  - `electricLevel`: Battery percentage (0-100)
  - `solarInputPower`: Solar input power
  - `packData`: Array of battery pack information

---

### 8. System & Grid Details

**Location**: Below charge status, full-width, collapsible

**Content**:
- **Grid Power**: 
  - Current grid usage (W) from P1 meter
  - Visual bar graph (-1200W to +1200W range)
  - Positive = drawing from grid, Negative = feeding to grid
- **WiFi Signal**: 
  - Signal strength score (0-10) and dBm value
  - Color-coded bar (green=good, yellow=fair, orange=weak, red=very weak)
- **System Temperature**: 
  - Temperature in °C with heating/cooling icon
  - Color-coded bar (blue=cold, green=normal, red=hot)
- **Battery 1 & 2 Levels** (collapsible):
  - Individual pack state of charge (SoC) and capacity
  - Visual bars with min/max markers
- **Battery 1 & 2 Temperatures** (collapsible):
  - Individual pack temperatures with heating/cooling icons
  - Color-coded temperature bars

**Data Sources**:
- **Zendure Data**: Same as Charge/Discharge Status
  - `rssi`: WiFi signal strength (dBm)
  - `hyperTmp`: System temperature (hyper format)
  - `packData[0]` and `packData[1]`: Battery pack data
    - `socLevel`: State of charge percentage
    - `maxTemp`: Maximum temperature (hyper format)
    - `heatState`: Heating state (0=cooling, 1=heating)
- **P1 Meter Data**: 
  - API: `data/api/data_api.php?type=zendure_p1`
  - File: `/data/zendure_p1_data.json` (cached)
  - Updated by automation process reading from P1 meter device
  - Properties used:
    - `total_power`: Total grid power (W, positive=consuming, negative=feeding)

---

## Data Flow Summary

### Schedule Data
```
charge_schedule.json → charge_schedule_api.php → Page (server-side + AJAX)
```

### Price Data
```
External Price API → data_api.php → price_YYYYMMDD.json → Page (client-side)
```

### Automation Status
```
automate.py → automation_status_api.php → automation_status.json → Page (server-side)
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

The page uses configuration from `config/config.json` (or fallback to `run_schedule/config/config.json`):

- `scheduleApiUrl`: API endpoint for schedule operations
- `priceUrls.get_price` or `priceUrls.get_prices`: Price API endpoint
- `dataApiUrl` / `dataApiUrl-local`: Data API endpoint (based on `location`)
- `statusApiUrl` / `statusApiUrl-local`: Status API endpoint (based on `location`)
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
schedule/
├── charge_schedule.php          # Main page
├── partials/                    # Page sections
│   ├── schedule_panels.php
│   ├── schedule_overview_bar.php
│   ├── price_overview_bar.php
│   ├── price_statistics.php
│   ├── calculate.php
│   ├── automation_status.php
│   ├── charge_status.php
│   ├── charge_status_details.php
│   └── charge_status_data.php  # Shared data loader
├── api/                         # API endpoints
│   ├── charge_schedule_api.php
│   └── automation_status_api.php
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
