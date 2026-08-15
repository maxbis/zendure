# Shortwave radiation dialog

## Purpose

The new GUI exposes the shortwave radiation forecast from its shared More menu. The overview appears in a Graphite Signal Dark dialog, so it does not occupy permanent dashboard space or fetch data until requested.

## Location

- Dialog markup and runtime URL configuration: `app/index.php`
- Client behavior and SVG chart rendering: `app/assets/js/shortwave-radiation.js`
- Component styles: `app/assets/css/app.css` (`.app-shortwave-*`)
- Shared data endpoint: `main/api/shortwave_radiation_api.php`
- More-menu dialog trigger: `themes/graphite-signal-dark/partials/footer-more.php`

## Inputs and outputs

Inputs:

- `window.GRAPHITE_APP_CONFIG.shortwaveRadiationUrl`
- API hourly timestamps and `shortwave_radiation` values
- API hourly and daily units, timezone, and cache timestamp

Outputs:

- A horizontally scrollable area chart of hourly radiation in W/m²
- Day headings with integrated daily totals in Wh/m²
- Local date and six-hour time markers
- A textual screen-reader summary of each daily total
- Loading, refresh, and retry states

## Flow and behavior

1. The page loads without requesting radiation data.
2. The user expands More and selects Shortwave Radiation.
3. The More panel closes and `GraphiteDialog` opens the radiation dialog.
4. The component requests `main/api/shortwave_radiation_api.php` when no displayed payload exists or the displayed cache timestamp is at least four hours old.
5. The response is validated before chart calculations run.
6. The chart groups hourly values per forecast date and sums them into daily Wh/m² totals.
7. Reopening the dialog reuses a displayed payload that is still within the four-hour display window.
8. Refresh and retry explicitly request the endpoint again; the endpoint continues to enforce its own upstream cache policy.
9. Resizing an open dialog recalculates the chart width without making another request.

## Edge cases and failure modes

- When the endpoint returns non-JSON, an unsuccessful response, mismatched arrays, invalid values, or invalid timestamps, then the dialog displays a retryable error.
- When a refresh fails after valid data was already rendered, then the prior chart remains available with the error state visible.
- When the forecast contains more days than fit in the dialog, then the chart remains horizontally scrollable by touch, pointer, or keyboard.
- When the dialog closes, then focus returns to the More-menu trigger through the shared Graphite dialog behavior.
- When reduced motion is requested, then shared Graphite dialog motion rules apply.

## Related files

- [Old and new GUI overview](../../gui-overview.md)
- [Graphite More footer menu](../../../themes/graphite-signal-dark/footer-more.md)
- [Old GUI shortwave radiation layout](../../../main/page-layout.md)
