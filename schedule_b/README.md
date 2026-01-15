# Charge Schedule Manager

A web-based application for viewing, editing, and visualizing charge/discharge schedules for energy management systems.

## Architecture Overview

The application uses a **Progressive Enhancement** architecture:
- **PHP** provides fast initial server-side rendering
- **JavaScript** handles all dynamic updates via API calls
- **Clear separation** between utilities, API communication, and rendering logic

## Directory Structure

```
schedule/
├── charge_schedule.php          # Main entry point
├── api/
│   ├── charge_schedule_api.php      # REST API endpoint
│   ├── charge_schedule_functions.php # Schedule logic functions
│   └── calculate_schedule_api.php   # Auto-calculation endpoint
├── assets/
│   ├── css/                     # Stylesheets
│   │   ├── charge_schedule.css
│   │   ├── schedule_calculator.css
│   │   ├── schedule_panels.css
│   │   └── ...
│   └── js/                      # JavaScript modules
│       ├── schedule_utils.js        # Shared utility functions
│       ├── schedule_api.js          # API communication layer
│       ├── schedule_renderer.js     # DOM rendering functions
│       ├── charge_schedule.js       # Main application logic
│       ├── edit_modal.js            # Edit entry modal
│       ├── confirm_dialog.js        # Confirmation dialogs
│       ├── price_overview_bar.js    # Price visualization
│       ├── price_statistics.js      # Price statistics
│       ├── schedule_calculator.js   # Schedule calculator
│       ├── automation_status.js     # Automation status display
│       └── charge_status.js         # Charge/discharge status
├── partials/
│   ├── schedule_panels.php          # Schedule panels HTML
│   ├── schedule_overview_bar.php    # Bar graph HTML
│   ├── price_overview_bar.php       # Price graph HTML
│   ├── price_statistics.php         # Price stats HTML
│   ├── calculate.php                # Calculator HTML
│   ├── automation_status.php        # Automation status HTML
│   ├── charge_status.php            # Charge status HTML
│   ├── charge_status_details.php    # Detailed charge status
│   ├── edit_modal.php               # Edit modal HTML
│   └── confirm_dialog.php           # Confirm dialog HTML
├── includes/
│   └── status.php                   # Automation status helpers
└── README.md                        # This file
```

## JavaScript Module Architecture

### Module Loading Order

Scripts are loaded in a specific order to ensure dependencies are available:

```html
<!-- 1. Core modules (must load first) -->
<script src="assets/js/schedule_utils.js"></script>
<script src="assets/js/schedule_api.js"></script>
<script src="assets/js/schedule_renderer.js"></script>

<!-- 2. UI components -->
<script src="assets/js/edit_modal.js"></script>
<script src="assets/js/confirm_dialog.js"></script>

<!-- 3. Feature modules -->
<script src="assets/js/price_overview_bar.js"></script>
<script src="assets/js/price_statistics.js"></script>
<script src="assets/js/schedule_calculator.js"></script>
<script src="assets/js/automation_status.js"></script>
<script src="assets/js/charge_status.js"></script>

<!-- 4. Main application (must load last) -->
<script src="assets/js/charge_schedule.js"></script>
```

### Core Modules

#### `schedule_utils.js`
Shared utility functions used across the application.

**Functions:**
- `getValueLabel(value)` - Convert schedule values to display labels
- `getTimeClass(hour)` - Get CSS class for time-of-day styling
- `getValueClass(value)` - Get CSS class for value category (charge/discharge/neutral)
- `formatDateYYYYMMDD(date)` - Format Date object as YYYYMMDD string
- `formatTime(time)` - Format HHmm as HH:mm
- `buildHourMap(resolved)` - Build hour→value map from resolved schedule

**Why it exists:** Eliminates code duplication. Previously `getValueLabel()` was duplicated in 4 different files.

#### `schedule_api.js`
Handles all API communication with the backend.

**Functions:**
- `fetchScheduleData(apiUrl, date)` - Fetch schedule for a specific date
- `clearOldEntries(apiUrl, simulate)` - Clear outdated entries
- `calculateSchedule(apiUrl, simulate)` - Auto-calculate optimal schedule
- `saveScheduleEntry(apiUrl, key, value, originalKey)` - Save/update entry
- `deleteScheduleEntry(apiUrl, key)` - Delete entry

**Why it exists:** Single source of truth for all API calls. Consistent error handling and response parsing.

#### `schedule_renderer.js`
All DOM manipulation and rendering functions.

**Functions:**
- `renderToday(resolved, currentHour, currentTime)` - Render today's schedule list
- `renderEntries(entries)` - Render schedule entries table
- `renderMiniTimeline(resolved, currentTime)` - Render mini timeline visualization
- `renderBarGraph(todayResolved, tomorrowResolved, ...)` - Render bar graph for today/tomorrow

**Why it exists:** Consolidates all rendering logic. Previously split between `schedule_panels.js` and `schedule_overview_bar.js`.

#### `charge_schedule.js`
Main application orchestrator.

**Key Functions:**
- `refreshData()` - Fetch data and update all UI components
- `handleClearClick()` - Handle "Clear" button
- `handleAutoClick()` - Handle "Auto" button
- DOMContentLoaded event handler - Initialize application

**Why it exists:** Coordinates between API, renderer, and user interactions. Contains business logic.

## Data Flow

### Initial Page Load

```
1. User requests charge_schedule.php
2. PHP loads schedule from charge_schedule.json
3. PHP renders initial HTML with data (fast!)
4. Browser loads JavaScript modules
5. charge_schedule.js calls refreshData()
6. Fresh data fetched via API
7. All UI components re-rendered with fresh data
```

### User Actions (Add/Edit/Delete)

```
1. User clicks entry → EditModal opens
2. User saves → schedule_api.js sends POST/DELETE
3. API returns success
4. refreshData() called
5. All UI components updated
```

### Auto Calculate

```
1. User clicks "Auto" button
2. handleAutoClick() calls calculateSchedule(simulate=true)
3. Shows confirmation with count
4. User confirms → calculateSchedule(simulate=false)
5. refreshData() updates UI
```

## PHP Backend

### Main Files

#### `charge_schedule.php`
- Entry point for the application
- Loads configuration from `config.json`
- Performs initial server-side render
- Injects API URLs as JavaScript constants
- Includes partials for HTML structure

#### `api/charge_schedule_api.php`
REST API endpoint supporting:
- `GET ?date=YYYYMMDD` - Fetch schedule for date
- `POST {action: "simulate|delete"}` - Clear old entries
- `POST {key, value, originalKey?}` - Save/update entry
- `DELETE {key}` - Delete entry

Returns JSON:
```json
{
  "success": true|false,
  "entries": [...],
  "resolved": [...],
  "date": "YYYYMMDD",
  "currentHour": "HH00",
  "currentTime": "HHmm",
  "error": "..." // if success=false
}
```

#### `api/charge_schedule_functions.php`
Core schedule logic:
- `loadSchedule($file)` - Load schedule from JSON
- `writeScheduleAtomic($file, $schedule)` - Atomic write to JSON
- `resolveScheduleForDate($schedule, $date)` - Resolve wildcards and build timeline
- `clearOldEntries($schedule, $simulate)` - Find/remove old entries

#### `api/calculate_schedule_api.php`
Auto-calculation endpoint:
- Fetches electricity prices
- Finds optimal charge/discharge times
- Creates schedule entries automatically

## Configuration

The application reads configuration from `config/config.json`:

```json
{
  "scheduleApiUrl": "api/charge_schedule_api.php",
  "calculate_schedule_apiUrl": "api/calculate_schedule_api.php",
  "priceUrls": {
    "get_price": "..."
  },
  "location": "local|remote",
  "zendureFetchApiUrl": "...",
  "zendureFetchApiUrl-local": "..."
}
```

## Schedule Data Format

### Storage (`data/charge_schedule.json`)

```json
{
  "202601150930": 300,
  "202601151200": -200,
  "20260116****": "netzero",
  "********0800": "netzero+"
}
```

**Key format:** `YYYYMMDDHHNN` (12 characters)
- `*` = wildcard (matches any value)
- More specific keys override wildcards

**Value types:**
- `number` - Power in watts (positive=charge, negative=discharge)
- `"netzero"` - Net zero mode
- `"netzero+"` - Solar charge mode

### API Response (Resolved)

```json
{
  "resolved": [
    {"time": "0000", "value": null, "key": null},
    {"time": "0030", "value": null, "key": null},
    {"time": "0800", "value": "netzero+", "key": "********0800"},
    {"time": "0930", "value": 300, "key": "202601150930"}
  ]
}
```

Every 30-minute slot for the day with resolved values.

## UI Components

### Today's Schedule Panel
- Shows active schedule for current day
- Highlights current time slot
- Groups consecutive identical values
- Color-coded by time of day (morning/afternoon/evening/night)

### Schedule Entries Table
- Shows all schedule entries (not resolved)
- Sorted by key (chronological)
- Color-coded wildcards vs exact dates
- Click to edit

### Bar Graph
- Visual timeline for today and tomorrow
- 24 hours × 2 days
- Height = power magnitude
- Color = charge (blue) / discharge (red) / netzero (green)
- Click to add/edit entry

### Mini Timeline
- Compact 24-hour view
- Shows current hour
- Click hour to scroll to schedule item

## Development

### Adding a New Feature

1. **Add utility functions** to `schedule_utils.js` if needed
2. **Add API calls** to `schedule_api.js` if backend communication needed
3. **Add rendering** to `schedule_renderer.js` if DOM updates needed
4. **Add business logic** to `charge_schedule.js` or create new feature module
5. **Update PHP** if initial render needs new data
6. **Add to load order** in `charge_schedule.php` if new file created

### Best Practices

- ✅ Use utility functions from `schedule_utils.js` (avoid duplication)
- ✅ All API calls through `schedule_api.js` (consistent error handling)
- ✅ All DOM updates through renderer functions (predictable updates)
- ✅ Call `refreshData()` after any data modification
- ✅ Keep PHP partials simple (rendering only, no complex logic)
- ✅ Maintain load order dependencies

### Common Pitfalls

- ❌ Don't duplicate `getValueLabel()` - use the one in `schedule_utils.js`
- ❌ Don't make API calls directly - use functions in `schedule_api.js`
- ❌ Don't manipulate DOM outside renderer functions
- ❌ Don't forget to call `refreshData()` after data changes
- ❌ Don't load scripts out of order (utils → api → renderer → app)

## Browser Compatibility

- Modern browsers with ES6+ support
- Requires JavaScript enabled (progressive enhancement)
- Responsive design for mobile/tablet/desktop

## Testing

Manual testing checklist:
- [ ] Initial page load shows correct schedule
- [ ] Click "Add" opens modal with empty form
- [ ] Click entry in table opens modal with pre-filled data
- [ ] Save entry updates all views (table, timeline, bar graph)
- [ ] Delete entry removes from all views
- [ ] "Clear" button removes old entries
- [ ] "Auto" button calculates optimal schedule
- [ ] Bar graph click adds/edits entry for that hour
- [ ] Mini timeline click scrolls to corresponding hour
- [ ] Current time highlighted in all views

## Troubleshooting

### Changes not appearing
1. Check browser console for JavaScript errors
2. Verify API URL in browser network tab
3. Check `data/charge_schedule.json` has correct permissions
4. Clear browser cache

### Rendering issues
1. Check script load order in `charge_schedule.php`
2. Verify all utility functions are available (check console)
3. Check CSS files are loaded

### API errors
1. Check PHP error log
2. Verify `data/` directory is writable
3. Test API endpoint directly: `api/charge_schedule_api.php?date=20260115`

## License

Internal project for energy management system.
