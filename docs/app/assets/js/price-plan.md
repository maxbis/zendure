# Prices and energy plan

## Purpose

The new GUI's Prices & Energy Plan component combines hourly consumer prices, resolved schedule actions, solar events, and predicted battery levels for the today-and-tomorrow planning horizon. It provides summary metrics above a horizontally scrollable 48-hour timeline.

## Location

- Shared component markup: [`app/partials/price-plan.php`](../../../../app/partials/price-plan.php)
- Live page: [`app/index.php`](../../../../app/index.php)
- Historical simulation page: [`app/test.php`](../../../../app/test.php)
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

The component has two modes:

- When mode is `live`, then it uses today and tomorrow, permits hourly schedule edits, publishes the current price, and quietly refreshes resolved schedules.
- When mode is `simulation`, then it uses the selected historical date and following date, loads one read-only scenario payload, uses the supplied starting battery level, and exposes no schedule writes or automation refresh.

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
8. Activating the same metric again, clicking outside it, scrolling the page (not the tooltip body), resizing, pressing `Escape`, or using the header close control closes the tooltip. Escape and close return focus to the trigger.
9. Schedule and summary tooltips are interactive dialogs: they accept pointer events, keep a Graphite Signal Dark close control in the header, and scroll internally when content exceeds the viewport. Touch or scroll gestures inside the tooltip do not dismiss it.
10. Schedule tooltip headers show the hour range and consumer/spot price with the close control on the first row, then the rule name (when present) left-aligned on the second row.
11. Build one battery forecast from the live percentage through the end of tomorrow whenever schedules render or live battery state refreshes.
12. When a current or future schedule action tooltip opens, show predicted start/now percentage, end percentage, percentage-point change, effective power, and the assumption source.
13. When the selected action is in the current hour, calculate only the minutes remaining and identify that duration in the tooltip.
14. When a slot has a runtime battery condition, show the least-guaranteed-discharge estimate and label its source `runtime condition · least guaranteed discharge`; do not select primary or fallback from predicted SoC.
15. When a slot was produced by the discharge-target planner, show the requested reserve at the next solar-capable net-zero slot, anchor time, calculated power, predicted anchor percentage, status, and planner explanation.
16. Quietly reload resolved schedules every five minutes so continuously calculated limits update without reloading prices or switching the component into its loading state.
17. When the header refresh runs after the component is already ready, keep the current summary and timeline visible and only mark the refresh control busy; reserve the short loading panel for the first load or retry after an error so the page does not jump.
18. When a slot was produced by `full_at_netzero_minus`, show its calculated NZ+ minimum in the limit badge and show current SoC, target, remaining eligible hours, and the next NZ- anchor in the tooltip.
19. In simulation mode, treat midnight on the selected date as the reference time, label the selected and following days explicitly, and forecast from the supplied starting battery percentage.
20. When the display forecast processes an unbounded NZ± action, preserve battery SoC with a `0 W` estimate and label the assumption `NZ± assumed neutral`; continue applying explicit NZ± bounds when present.

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
- When the tooltip content is taller than the available viewport, then the body scrolls and page-level touchmove or scroll listeners do not close it.
- When target planning is unavailable or limited, then the tooltip reports `Calculation unavailable` or `Best effort` and shows the planner reason directly below the target values.
- When tomorrow's prices exist but no NZ+ or charging-capable NZ± slot exists in the loaded schedule, then the tooltip explains that prices and the solar-charge anchor are independent inputs.
- When the five-minute schedule-only refresh fails, then keep the last rendered schedule and allow the next refresh or manual reload to recover.
- When a manual refresh runs while the component is already ready, then keep the last rendered summary and timeline visible until the new data replaces it.
- When simulation mode is active, then visibility changes and timers do not trigger live schedule refreshes.
- When simulation prices are unavailable for either day, then show the API error and do not fall back to current prices.
- When simulation mode shows NZ-, then its battery forecast uses the configured household profile rather than historical P1 measurements.
- When simulation mode shows unbounded NZ±, then its battery forecast assumes `0 W`; explicit NZ± limits can still force a non-zero estimate.
- When simulation mode shows NZ+, then its baseline forecast is `0 W` because no solar-export forecast is available; explicit NZ+ limits can still force charging.
- When simulation mode shows a runtime-conditioned action, then compare its primary and fallback forecast powers and use the smaller discharge magnitude, or `0 W` when either outcome does not discharge.

## Related files

- [Old and new GUI overview](../../gui-overview.md)
- [Current energy status and battery details](current-energy-status.md)
- [Battery level forecast](battery-forecast.md)
- [Shared system configuration](../../shared-system-configuration.md)
- [Target battery planner](../../../main/data/target-battery-planner.md)
- [Historical rule backtesting](../../backtesting.md)
