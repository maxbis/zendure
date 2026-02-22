# Config Keys Used in Schedule (Current `main/` App)

This file reflects the currently active schedule UI entrypoint:
`main/charge_schedule_mobile.php`.

## Direct Config Reads in `main/charge_schedule_mobile.php`

1. `scheduleApiUrl`
- Injected into JS as `API_URL`
- Current local value in config: `http://localhost/zendure/main/data/api/data_api.php?type=schedule&resolved=1`

2. `priceApiUrl`
- Injected into JS as `PRICE_API_URL`

3. `calculate_schedule_apiUrl`
- Injected into JS as `CALCULATE_SCHEDULE_API_URL`

4. `zendureFetchApiUrl` (via `ConfigLoader::getWithLocation(...)`)
- Location-aware fetch endpoint selection

5. `MIN_CHARGE_LEVEL`
- Injected into JS as `CHARGE_STATUS_MIN_CHARGE_LEVEL`

6. `MAX_CHARGE_LEVEL`
- Injected into JS as `CHARGE_STATUS_MAX_CHARGE_LEVEL`

7. `baseWh`
- Injected into JS as `BASE_WH`

8. `minGridPower`
- Injected into JS as `GRID_MIN_POWER`

9. `maxGridPower`
- Injected into JS as `GRID_MAX_POWER`

## Related Proxy Endpoints (Config Used Server-Side)

- `main/api/charge_status_all_proxy.php`:
  - `chargeStatusApi` (fallback `allApi`)
  - `apiBaseUrlPiControl`

- `main/api/automation_status_proxy.php`:
  - `automationStatusApi`
  - `apiBaseUrlPiControl`

- `main/api/energy_graph_proxy.php`:
  - `wh-per-hourApi`
  - `apiBaseUrlPiControl`
  - `whPerHourCacheMinutes`
  - `baseWh`

## Notes

- This document intentionally tracks the active `main/` mobile schedule app.
- Legacy `schedule/` paths from older docs are not part of the current runtime path in this workspace.
