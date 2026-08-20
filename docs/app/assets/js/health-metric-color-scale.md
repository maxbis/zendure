# Health metric colour scale

## Purpose

Provides temperature and Wi-Fi RSSI colours for the new GUI battery Health dialog, matching the old GUI battery-details colouring.

## Location

- Client-side helper: [`app/assets/js/health-metric-color-scale.js`](../../../../app/assets/js/health-metric-color-scale.js)
- Loaded from [`app/index.php`](../../../../app/index.php)
- Consumed by [`current-energy-status.js`](../../../../app/assets/js/current-energy-status.js)

## Inputs and outputs

- `temperatureColor(celsius)` returns a hex colour, or `null` when the value is not finite.
- `wifiRssiDetails(rssi)` returns the discrete 0–10 score, description, and colour for RSSI in dBm, or `null` when the value is not finite.
- `wifiRssiScore(rssi)` and `wifiRssiColor(rssi)` return the corresponding values from those details.

## Flow and behavior

1. Temperature is clamped to −10°C…40°C, then mapped with the same bands as `getTempColorEnhancedJS` in [`main/assets/js/schedule_renderer.js`](../../../../main/assets/js/schedule_renderer.js).
2. Wi-Fi RSSI uses discrete signal-quality bands: below −90 is 0; −90…−86 is 1; −85…−83 is 2; −82…−81 is 3; −80…−76 is 4; −75…−71 is 5; −70…−68 is 6; −67…−64 is 7; −63…−58 is 8; −57…−50 is 9; and above −50 dBm is 10.
3. Each band provides its own description and colour from dark gray through deep green.

## Edge cases and failure modes

- When the input is missing or not finite, then colour helpers return `null` and the consumer keeps the default text colour.
- When RSSI is below −90 dBm or above −50 dBm, then the score is 0 or 10 respectively.

## Related files

- [Current energy status and battery details](current-energy-status.md)
- Old GUI source of truth: [`main/assets/js/schedule_renderer.js`](../../../../main/assets/js/schedule_renderer.js)
