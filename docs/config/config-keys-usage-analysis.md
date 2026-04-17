# Config Keys Usage Analysis (Current Runtime)

This is a current, runtime-focused mapping for the active app in this workspace (`main/charge_schedule_mobile.php` + `main/api/*` proxies).

## Primary Schedule Page Keys

Used directly by `main/charge_schedule_mobile.php`:

- `scheduleApiUrl`
- `priceApiUrl`
- `calculate_schedule_apiUrl`
- `zendureFetchApiUrl` (via `ConfigLoader::getWithLocation(...)`)
- `MIN_CHARGE_LEVEL` (default: 20)
- `MAX_CHARGE_LEVEL` (default: 90)
- `baseWh` (default: 5760)
- `minGridPower` (default: -1200)
- `maxGridPower` (default: 1200)
- `include_conditions` (boolean, enables condition-rule resolution in schedule)
- `priceProxyNoData` (default: 0.24, fallback price when proxy has no data)
- `popupPowerEfficiency` (default: 0.9, efficiency factor for power calculations)
- `popupNetzeroReferenceW` (default: 200, reference power in watts for netzero mode)
- `popupNetzeroMinusReferenceW` (default: -180, reference power in watts for netzero- mode)
- `popupNetzeroPlusReferenceW` (default: 300, reference power in watts for netzero+ mode)
- `priceConversion.supplierMarkupEurPerKwh` (default: 0.0219, added to spot/source price before VAT)
- `priceConversion.energyTaxEurPerKwh` (default: 0.0898, added to spot/source price before VAT)
- `priceConversion.vatMultiplier` (default: 1.21, VAT multiplier used for price conversion)
- `priceConversion.consumerPrecision` (default: 4, rounding digits for spot/source -> consumer conversion)
- `priceConversion.spotPrecision` (default: 6, rounding digits for consumer -> spot/source conversion)

## Proxy/API Keys

Used by server-side proxy endpoints:

- `main/api/charge_status_all_proxy.php`
  - `chargeStatusApi` (fallback `allApi`)
  - `apiBaseUrlPiControl`

- `main/api/automation_status_proxy.php`
  - `automationStatusApi`
  - `apiBaseUrlPiControl`

- `main/api/energy_graph_proxy.php`
  - `wh-per-hourApi`
  - `apiBaseUrlPiControl`
  - `whPerHourCacheMinutes`
  - `baseWh`

## Notes

- The active local schedule endpoint configured today is:
  - `http://localhost/zendure/main/data/api/data_api.php?type=schedule&resolved=1`
- Earlier docs referenced legacy `schedule/` paths and older key maps; this file supersedes those for current operations.
- `main/includes/price_conversion.php` is the canonical PHP helper for price conversion.
- `main/assets/js/price_conversion.js` mirrors the same config-backed math for browser UI display.
