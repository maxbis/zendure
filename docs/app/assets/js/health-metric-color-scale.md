# Health metric colour scale

## Purpose

Provides temperature and Wi-Fi RSSI colours for the new GUI battery Health dialog, matching the old GUI battery-details colouring.

## Location

- Client-side helper: [`app/assets/js/health-metric-color-scale.js`](../../../../app/assets/js/health-metric-color-scale.js)
- Loaded from [`app/index.php`](../../../../app/index.php)
- Consumed by [`current-energy-status.js`](../../../../app/assets/js/current-energy-status.js)

## Inputs and outputs

- `temperatureColor(celsius)` returns a hex colour, or `null` when the value is not finite.
- `wifiRssiScore(rssi)` returns a 0–10 score for RSSI in dBm, or `null` when the value is not finite.
- `wifiRssiColor(rssi)` returns a hex colour from that score, or `null` when the value is not finite.

## Flow and behavior

1. Temperature is clamped to −10°C…40°C, then mapped with the same bands as `getTempColorEnhancedJS` in [`main/assets/js/schedule_renderer.js`](../../../../main/assets/js/schedule_renderer.js).
2. Wi-Fi RSSI is scored linearly from −90 dBm (0) to −30 dBm (10), matching the old GUI battery Wi-Fi bar.
3. That score selects green (≥8), yellow (≥5), orange (≥3), or red.

## Edge cases and failure modes

- When the input is missing or not finite, then colour helpers return `null` and the consumer keeps the default text colour.
- When RSSI is stronger than −30 dBm or weaker than −90 dBm, then the score clamps to 10 or 0.

## Related files

- [Current energy status and battery details](current-energy-status.md)
- Old GUI source of truth: [`main/assets/js/schedule_renderer.js`](../../../../main/assets/js/schedule_renderer.js)
