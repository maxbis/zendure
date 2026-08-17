# Shortwave Radiation API

## Purpose

The endpoint supplies hourly radiation and integrated daily Wh/m² forecasts for UI charts, planning, and automatic rule profile selection.

## Location

- Source: `main/api/shortwave_radiation_api.php`
- Cache: `main/data/shortwave_radiation_cache_<location-key>.json`

## Inputs and outputs

Optional query parameters override the shared installation latitude, longitude, and timezone. The response contains hourly values, daily totals, `cachedAt`, and cache freshness metadata.

## Flow and behavior

1. A fresh cache is returned directly.
2. A missing or expired cache triggers an Open-Meteo request.
3. A successful request replaces the cache atomically enough for existing readers through the endpoint's locked write.
4. A failed refresh returns the previous valid cache with `cacheStatus: stale`.
5. A failed refresh without a usable cache returns an error.

## Edge cases and failure modes

- `refreshError` is informational when stale data is returned successfully.
- Consumers that require only the legacy fields remain compatible with the added cache metadata.
- Invalid or incomplete cached JSON is treated as missing rather than stale.

## Related files

- [Automatic rule profiles](../rule-profile-auto-selection.md)
- [Shortwave dialog](../../app/assets/js/shortwave-radiation.md)
