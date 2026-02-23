# Charge Schedule Mobile User Manual

This manual explains how to use the mobile app:

- `http://localhost/zendure/main/charge_schedule_mobile.php`

## 1. What This Page Does

The mobile page is the main operational dashboard for:

- battery charge/discharge status
- current grid/system metrics
- today/tomorrow price overview
- Wh-per-hour energy graph
- schedule viewing and editing
- automation status

## 2. Open The App

Open in your browser:

- `http://localhost/zendure/main/charge_schedule_mobile.php`

Access control is validated at page load.

## 3. Page Sections

Top-to-bottom layout:

1. **Charge/Discharge Status**
2. **System & Grid Details**
3. **Price Overview (mobile bars)**
4. **Energy Graph (Wh per hour)**
5. **Schedule Panels**
   - Schedule tab (today/tomorrow)
   - Schedule entries tab
6. **Automation Status**

## 4. Schedule Editing

Use the schedule section to manage entries.

- Tap **Add** to create an entry.
- Tap an existing row to edit it.
- In the modal, set:
  - date pattern (`YYYYMMDD` or `*` wildcards)
  - time pattern (`HHmm` or `*` wildcards)
  - mode/value:
    - fixed watts
    - `netzero`
    - `netzero+`
- Save or delete from the modal.

Notes:

- Wildcards are supported in both date and time patterns.
- `limit1hour` behavior can auto-restore the next hour for concrete keys.

## 5. Price Graph Behavior

The mobile page auto-scrolls the price graph to the current hour on load/refresh.

- If current bar is found, it scrolls to center it.
- If not, it scrolls near the estimated current-hour position.

## 6. Auto Refresh

Status and schedule-related UI refresh periodically through the page scripts.

- Data is fetched through configured API URLs.
- If backend data is unavailable, the app shows error/placeholder states.

## 7. Configuration Inputs

The page loads core values from:

- `/Users/maxbisschop/dev/www/zendure/main/config/config.json`

Used config includes (examples):

- `scheduleApiUrl`
- `priceApiUrl`
- `MIN_CHARGE_LEVEL`
- `MAX_CHARGE_LEVEL`
- `baseWh`
- `minGridPower`
- `maxGridPower`

## 8. APIs Used By This Page

Main endpoints used by the mobile UI include:

- schedule API (from `scheduleApiUrl`)
- price API (from `priceApiUrl`)
- `api/energy_graph_proxy.php`
- `api/charge_status_all_proxy.php`
- automation status API

## 9. Troubleshooting

If data looks stale or missing:

1. Tap refresh controls where available.
2. Reload the page.
3. Verify config URLs in `main/config/config.json`.
4. Verify backend APIs return valid JSON.
5. Check browser console for request errors.

If schedule changes do not appear:

1. Save in the edit modal.
2. Wait for refresh or reload the page.
3. Verify `main/data/charge_schedule.json` updates.

## 10. Related Manuals

- Rules editor manual: `/Users/maxbisschop/dev/www/zendure/docs/user_manuals/edit_rules_user_manual.md`

