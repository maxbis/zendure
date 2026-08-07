# Prices and energy plan

## Purpose

The new GUI's Prices & Energy Plan component combines hourly consumer prices, resolved schedule actions, solar events, and predicted battery levels for the today-and-tomorrow planning horizon. It provides summary metrics above a horizontally scrollable 48-hour timeline.

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
- Live battery forecast state published by `current-energy-status.js`.
- Chained hourly predictions calculated by `battery-forecast.js`.

The price summary presents four metrics in this order:

- Current: the current hour's consumer price.
- From now low: the lowest available hourly consumer price from the current hour through tomorrow.
- Daily averages: today's arithmetic mean, with tomorrow's mean included in the tooltip when tomorrow is available.
- From now high: the highest available hourly consumer price from the current hour through tomorrow.

Each metric displays three decimal places. Daily averages exclude missing or invalid hourly prices rather than treating them as zero.

## Flow and behavior

1. The component loads price, schedule, and rule-color sources.
2. It normalizes today and tomorrow into a 48-hour sequence.
3. It filters that sequence to finite hourly prices for the low, average, and high calculations.
4. It renders the four summary metrics and the hourly timeline.
5. Activating a summary metric opens the shared price tooltip.
6. Current, low, and high tooltips identify their specific date and hour and show consumer and derived spot prices.
7. The Daily averages tooltip identifies how many hourly prices contributed and shows both the average consumer price and average derived spot price.
8. Activating the same metric again, clicking outside it, scrolling, resizing, or pressing `Escape` closes the tooltip. Escape returns focus to the trigger.
9. Build one battery forecast from the live percentage through the end of tomorrow whenever schedules render or live battery state refreshes.
10. When a current or future schedule action tooltip opens, show predicted start/now percentage, end percentage, percentage-point change, effective power, and the assumption source.
11. When the selected action is in the current hour, calculate only the minutes remaining and identify that duration in the tooltip.
12. When a runtime battery threshold changes the action during an hour, show the primary-to-fallback power transition; when the hour starts on the threshold, show the fallback power directly.
13. When a slot was produced by the discharge-target planner, show the requested reserve at the next solar-capable net-zero slot, anchor time, calculated power, predicted anchor percentage, status, and planner explanation.
14. Quietly reload resolved schedules every five minutes so continuously calculated limits update without reloading prices or switching the component into its loading state.
15. When a slot was produced by `full_at_netzero_minus`, show its calculated NZ+ minimum in the limit badge and show current SoC, target, remaining eligible hours, and the next NZ- anchor in the tooltip.

On wide layouts the four metrics use one row. At viewport widths up to 600 px they use a two-column grid.

The timeline places sunrise and sunset badges in the date-heading row at their exact fractional-hour positions. Dashed markers extend from that row through the corresponding hourly column.

## Edge cases and failure modes

- When no finite prices are available, then the low, average, and high metrics display an unavailable value and their tooltip triggers are disabled.
- When tomorrow's prices are pending, then Daily averages shows today's available average and omits tomorrow's comparison.
- When the current hour has no price, then Current is unavailable even if other horizon metrics can still be calculated.
- When price conversion is configured differently, then the tooltip's spot values use that same conversion for each contributing consumer price.
- When sunrise or sunset data is absent or invalid, then that solar marker is omitted without preventing the price timeline from rendering.
- When all primary price and schedule requests fail, then the component displays its error state instead of stale summary metrics.
- When live battery state is not ready, then a current or future action tooltip displays `Waiting for a live battery reading`.
- When live battery state is stale, then the tooltip reports that the forecast is unavailable instead of showing an outdated prediction.
- When a schedule tooltip represents an hour that has already ended, then it does not display a battery forecast section.
- When live battery state refreshes while a schedule tooltip is open, then its forecast content is rebuilt in place.
- When target planning is unavailable or limited, then the tooltip reports `Calculation unavailable` or `Best effort` and shows the planner reason directly below the target values.
- When tomorrow's prices exist but no NZ+ or charging-capable NZ± slot exists in the loaded schedule, then the tooltip explains that prices and the solar-charge anchor are independent inputs.
- When the five-minute schedule-only refresh fails, then keep the last rendered schedule and allow the next refresh or manual reload to recover.

## Related files

- [Old and new GUI overview](../../gui-overview.md)
- [Current energy status and battery details](current-energy-status.md)
- [Battery level forecast](battery-forecast.md)
- [Shared system configuration](../../shared-system-configuration.md)
- [Target battery planner](../../../main/data/target-battery-planner.md)
