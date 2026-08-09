# Current energy status and battery details

## Purpose

The new GUI's current-energy-status component retrieves live controller data and presents the current battery, power-flow, and grid state. Its battery detail dialog provides separate Energy and Health views (footer navigation) without leaving the monitoring page.

This document focuses on the battery detail interaction and its live-data contract. The new GUI remains a read-only consumer of controller status.

## Location

- Component markup: [`app/index.php`](../../../../app/index.php)
- Client-side behavior: [`app/assets/js/current-energy-status.js`](../../../../app/assets/js/current-energy-status.js)
- Grid state and color boundaries: [`app/assets/js/grid-exchange-color-scale.js`](../../../../app/assets/js/grid-exchange-color-scale.js)
- Component styles: [`app/assets/css/app.css`](../../../../app/assets/css/app.css)
- Status endpoint: [`main/api/charge_status_all_proxy.php`](../../../../main/api/charge_status_all_proxy.php)
- Graphite Signal Dark icons: [`themes/graphite-signal-dark/assets/icons/sprite.svg`](../../../../themes/graphite-signal-dark/assets/icons/sprite.svg)

## Inputs and outputs

The component requests `main/api/charge_status_all_proxy.php` every 20 seconds while the page is visible. The request has an eight-second timeout and bypasses the browser cache.

The battery detail dialog uses these Zendure status fields:

- `readings.properties.electricLevel`: overall battery percentage.
- `readings.properties.rssi`: controller Wi-Fi signal in dBm.
- `readings.properties.hyperTmp`: encoded controller temperature.
- `readings.packData[].socLevel`: individual battery-pack percentage.
- `readings.packData[].maxTemp`: encoded battery-pack temperature.
- `readings.properties.outputPackPower` and `outputHomePower`: preferred current charge or discharge rate.
- `readings.properties.acMode`, `inputLimit`, and `outputLimit`: fallback rate when the output-power readings are zero.

The component also uses the configured battery capacity and minimum and maximum charge percentages to calculate:

- Total stored energy in kWh (used on the battery card and accessibility text).
- Five percent of the configured usable range and of full capacity in kWh (`usable = (max − min) × capacity × 0.05`, `real = capacity × 0.05`), shown in the Energy dialog as `usable/real kWh`.
- Usable energy above the configured minimum charge percentage.
- Chargeable energy in kWh: remaining energy that can still be charged up to the configured maximum charge percentage.
- Projected usable energy and usable-range percentage at the next whole hour.

After each successful render, the component publishes `graphite:battery-forecast-state`. The event detail and `window.GRAPHITE_BATTERY_FORECAST_STATE` snapshot contain the live battery percentage, capacity, operating limits, reading timestamp, and stale state. The Prices & Energy Plan consumes this read-only snapshot for its chained hourly forecast.

Encoded controller and pack temperatures are converted to Celsius with `(value - 2731) / 10` and displayed to one decimal place.

The simplified battery-power and grid-exchange cards share one chevron calculation. When a card has an active flow, one chevron is always active and the remaining nine are dynamic:

```text
dynamic chevrons = min(9, round(actual power percentage / 10))
active chevrons = 1 + dynamic chevrons
```

With a 1200 W directional limit, an active flow below 60 W therefore shows one chevron, 60 W through just below 180 W shows two, and 1020 W or more shows all ten. The configured positive or negative directional limit is used when it differs from 1200 W.

## Flow and behavior

1. The component loads and normalizes the current controller response.
2. The simplified power view uses one base chevron plus nine dynamic chevrons while power is flowing. Charging starts at the left edge and advances toward the battery; discharging starts beside the battery at the right edge and advances left. Standby shows none.
3. The simplified grid-exchange view uses the same shared count and rendering functions. Export starts at the left edge and advances toward the grid; import starts beside the grid at the right edge and advances left. Readings from −10 W through +10 W are Balanced and show no chevrons.
4. When an overall battery percentage is available, the battery icon becomes a battery-detail trigger.
5. When a remaining charging or discharging time can be calculated, the detailed and simplified target labels also become triggers.
6. Activating a trigger opens the non-modal dialog on its Energy view.
7. The dialog header shows the current view title and a Graphite Signal Dark close control.
8. The Energy view opens with a usable-range summary strip: current SoC, percent of the configured min–max window, a filled track with SoC marker, and min/max labels. When current battery power would change SoC over the next 60 minutes, a dimmed fill shows the projected usable-range level (clamped to the configured min/max window).
9. The Energy view then shows how much energy 5% of the usable and real ranges equal, usable energy, chargeable energy, battery power, and projected usable energy at the next whole hour.
10. Activating **Health** in the footer slides to the Health view.
11. The Health view opens with a controller-temperature summary strip on a −5°C…+50°C scale, coloured with the same dynamic temperature colour as the value, then shows controller temperature, each reported battery pack's percentage and temperature, and controller Wi-Fi signal. Temperature and Wi-Fi values use the same dynamic colours as the old GUI battery details (`schedule_renderer.js`).
12. Activating **Energy** in the footer returns to the Energy view.
13. When live status refreshes while the dialog is open, the dialog is rebuilt with current values and keeps the selected Energy or Health view.
14. Publish the normalized battery state so the Prices & Energy Plan can rebuild its display-only hourly forecast.

The dialog is positioned below its trigger when space permits. It moves above the trigger when it would otherwise extend beyond the bottom of the viewport, and its horizontal position is constrained to the viewport. When content exceeds the available viewport height, the body scrolls internally.

### Closing the dialog

- Activating the same trigger again closes the dialog.
- Activating the header close control closes the dialog and returns focus to the trigger.
- Clicking or tapping outside both the trigger and dialog closes it.
- Pressing `Escape` closes it and returns focus to the trigger.
- Scrolling or touching outside the dialog, or resizing the window, closes it.
- Touch or scroll gestures inside the dialog do not close it.

### Accessibility

- The detail container has `role="dialog"` and is explicitly non-modal.
- Triggers expose `aria-controls`, `aria-haspopup="dialog"`, and their expanded state.
- The current dialog title labels the dialog.
- The inactive Energy or Health panel is marked `aria-hidden="true"`.
- The Energy usable-range summary strip and Health temperature summary strip use `role="img"` with accessible labels for their metric and scale.
- The Energy/Health navigation lives in the footer as a quiet Graphite Signal Dark button with a view-specific accessible label.
- When reduced motion is requested, the sliding transition is disabled with the rest of the component animations.

## Edge cases and failure modes

- When the overall battery percentage is unavailable, then all battery-detail triggers are disabled and any open dialog closes.
- When battery percentage or configured min/max limits are missing or invalid, then the Energy view omits its summary strip and still shows the detail rows.
- When current battery power is near zero or the 60-minute projection matches the current usable-range level, then the Energy summary omits the dimmed projection fill.
- When controller temperature is missing or invalid, then the Health view omits its temperature summary strip and still shows the detail rows.
- When controller temperature is missing or invalid, then the Health view displays an em dash for that value.
- When a pack percentage or temperature is missing or invalid, then only the missing part of that pack row displays an em dash.
- When no `packData` array is returned, then the Health view displays `Battery packs: Unavailable`.
- When Wi-Fi RSSI is missing or invalid, then the Health view displays an em dash.
- When the health-metric colour scale is unavailable, then temperature and Wi-Fi values render without dynamic colour.
- Temperature colour follows the old GUI bands on a −10°C…40°C scale: blue ≤0, light yellow ≤5, yellow ≤15, green ≤25, orange ≤30, red above.
- Wi-Fi colour follows the old GUI RSSI score from −90…−30 dBm: green ≥8, yellow ≥5, orange ≥3, otherwise red.
- When the grid color-scale module is available, then its export and import boundaries also define the grid state and chevron deadband so these visual signals remain aligned.
- When the grid color-scale module is unavailable, then the state calculation defensively uses −10 W and +10 W as the same boundaries.
- When the status refresh fails after valid data has already rendered, then the existing values remain visible and the GUI reports that the latest reading could not be loaded.
- When the initial status load fails, then the component replaces its content with an unavailable state.
- When the status proxy returns HTTP 502, then the error state specifically identifies the energy controller as unavailable.
- When a response does not contain Zendure readings with `properties`, then normalization fails and the normal status error handling runs.
- When the battery percentage is unavailable, then the published forecast snapshot contains a null percentage and the price-plan forecast remains unavailable.

## Related files

- [Old and new GUI overview](../../gui-overview.md)
- [New GUI shared system configuration](../../shared-system-configuration.md)
- [Battery level forecast](battery-forecast.md)
- [Health metric colour scale](health-metric-color-scale.md)
- [Graphite Signal Dark style guide](../../../../themes/graphite-signal-dark/style-guide.md)
