# Prices and energy plan

## Purpose

The new GUI's Prices & Energy Plan component combines hourly consumer prices, resolved schedule actions, and solar events for the today-and-tomorrow planning horizon. It provides summary metrics above a horizontally scrollable 48-hour timeline.

## Location

- Component markup: [`app/index.php`](../../../../app/index.php)
- Client-side behavior: [`app/assets/js/price-plan.js`](../../../../app/assets/js/price-plan.js)
- Component styles: [`app/assets/css/app.css`](../../../../app/assets/css/app.css)
- Shared application configuration: [`app/shared-system-configuration.md`](../../shared-system-configuration.md)

## Inputs and outputs

The component reads:

- Today and tomorrow consumer prices from the configured price endpoints.
- Resolved schedule entries from the configured schedule endpoint.
- Rule colors from the configured rules endpoint.
- Price-conversion values from `window.GRAPHITE_APP_CONFIG` for deriving spot prices.
- Sunrise and sunset events calculated by `app/index.php` for the configured installation.

The price summary presents four metrics in this order:

- Current: the current hour's consumer price.
- Horizon low: the lowest available hourly consumer price across today and tomorrow.
- Horizon average: the arithmetic mean of every available hourly consumer price across today and tomorrow.
- Horizon high: the highest available hourly consumer price across today and tomorrow.

Each metric displays three decimal places. Horizon average excludes missing or invalid hourly prices rather than treating them as zero.

## Flow and behavior

1. The component loads price, schedule, and rule-color sources.
2. It normalizes today and tomorrow into a 48-hour sequence.
3. It filters that sequence to finite hourly prices for the low, average, and high calculations.
4. It renders the four summary metrics and the hourly timeline.
5. Activating a summary metric opens the shared price tooltip.
6. Current, low, and high tooltips identify their specific date and hour and show consumer and derived spot prices.
7. The Horizon average tooltip identifies how many hourly prices contributed and shows both the average consumer price and average derived spot price.
8. Activating the same metric again, clicking outside it, scrolling, resizing, or pressing `Escape` closes the tooltip. Escape returns focus to the trigger.

On wide layouts the four metrics use one row. At viewport widths up to 600 px they use a two-column grid.

The timeline places sunrise and sunset badges in the date-heading row at their exact fractional-hour positions. Dashed markers extend from that row through the corresponding hourly column.

## Edge cases and failure modes

- When no finite prices are available, then the low, average, and high metrics display an unavailable value and their tooltip triggers are disabled.
- When tomorrow's prices are pending, then Horizon average uses only the available today-and-tomorrow values and its tooltip reports the contributing hour count.
- When the current hour has no price, then Current is unavailable even if other horizon metrics can still be calculated.
- When price conversion is configured differently, then the tooltip's spot values use that same conversion for each contributing consumer price.
- When sunrise or sunset data is absent or invalid, then that solar marker is omitted without preventing the price timeline from rendering.
- When all primary price and schedule requests fail, then the component displays its error state instead of stale summary metrics.

## Related files

- [Old and new GUI overview](../../gui-overview.md)
- [Current energy status and battery details](current-energy-status.md)
- [Shared system configuration](../../shared-system-configuration.md)
