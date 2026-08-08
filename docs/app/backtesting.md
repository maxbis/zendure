# Historical rule backtesting

## Purpose

The historical backtest page replays the current saved rule set against two completed days of archived hourly prices. It starts from a user-selected battery percentage and renders the result with the same Prices & energy plan component as the live app.

This is a planning simulation. It does not replay physical device behavior, calculate actual savings, or send commands.

## Location

- Test page: [`app/test.php`](../../app/test.php)
- Read-only API: [`main/api/backtest_schedule_api.php`](../../main/api/backtest_schedule_api.php)
- Shared rule evaluator: [`main/data/resolve_schedule_conditions.php`](../../main/data/resolve_schedule_conditions.php)
- Shared component markup: [`app/partials/price-plan.php`](../../app/partials/price-plan.php)
- Shared component behavior: [`app/assets/js/price-plan.js`](../../app/assets/js/price-plan.js)

## Inputs and outputs

The page accepts:

- A completed historical date. The scenario begins at 00:00 in the installation timezone.
- A starting battery percentage from 0 through 100.

The API reads:

- Hourly consumer prices for the selected date and following date.
- The current base schedule.
- The current conditional rules and active rule profile.
- Shared battery, forecast, power, location, and price-conversion settings.

The API returns two days of prices, resolved actions, rule metadata, solar events, the simulation reference time, and the selected starting battery percentage.

## Flow and behavior

1. The authenticated test page submits the selected date and starting battery percentage.
2. The backtest API loads prices from MariaDB `price_ticks`.
3. When database rows are unavailable, then the API checks the short-lived JSON price cache.
4. The date-driven rule evaluator applies the current rules to each historical hourly price map.
5. Exact dated manual schedule entries keep priority over conditional or wildcard entries.
6. Target-battery planning uses the selected percentage and midnight on the selected date instead of live battery state and wall-clock time.
7. The shared Prices & energy plan renderer displays the two-day scenario in simulation mode.
8. Runtime battery-level conditions are evaluated by the existing chained battery forecast.

## Safety and failure modes

- When the page is in simulation mode, then schedule editing is absent.
- When the page is in simulation mode, then it does not call automation refresh, live controller, or device-command endpoints.
- When a non-GET request reaches the backtest API, then it returns `405 Method Not Allowed`.
- When the date or battery percentage is invalid, then the API returns a validation error.
- When either historical day has no stored prices, then the scenario fails instead of mixing historical and live prices.
- When MariaDB is unavailable, then the API may use a matching JSON cache file.

## Limitations

- The current saved rules and active profile are applied. Historical rule versions are not stored or replayed.
- The scenario starts at midnight; an arbitrary historical start hour is not supported.
- Net-zero modes use the configured household profile for battery forecasting. Exact watts require historical P1 measurements.
- Actual energy flows, device constraints, weather, and financial outcomes are not reproduced.

## Related files

- [Prices and energy plan](assets/js/price-plan.md)
- [Target battery planner](../main/data/target-battery-planner.md)
- [Price tick storage](../prices/price-ticks.md)
- [Schedule resolution](../data/schedule-resolution-technical.md)
