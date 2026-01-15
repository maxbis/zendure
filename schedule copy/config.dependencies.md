# Config Keys Used in Schedule

## Config Keys Used in charge_schedule.php and its Partials

### 1. `scheduleApiUrl`
- **schedule/charge_schedule.php** (line 30-31)
- **schedule/partials/calculate.php** (line 27-28)

### 2. `priceUrls.get_price`
- **schedule/charge_schedule.php** (line 34-35)

### 3. `priceUrls.get_prices`
- **schedule/charge_schedule.php** (line 36-37) - used as fallback if `get_price` is not set

### 4. `location`
- **schedule/charge_schedule.php** (line 41)
- **schedule/partials/calculate.php** (line 32)
- Used to determine which API URL keys to use

### 5. `dataApiUrl`
- **schedule/partials/charge_status.php** (line 39)
- Used when `location !== 'local'` (remote or missing)

### 6. `dataApiUrl-local`
- **schedule/partials/charge_status.php** (line 37)
- Used when `location === 'local'`

### 7. `zendureFetchApiUrl-local`
- **schedule/charge_schedule.php** (line 43)
- **schedule/partials/calculate.php** (line 34)
- Used when `location === 'local'`

### 8. `zendureFetchApiUrl`
- **schedule/charge_schedule.php** (line 47)
- **schedule/partials/calculate.php** (line 38)
- Used when `location !== 'local'` (remote or missing)

---

## Summary

- **Total config keys**: 8 (including nested keys)
- **Files accessing config**:
  - `schedule/charge_schedule.php` - uses 5 keys
  - `schedule/partials/calculate.php` - uses 3 keys
  - `schedule/partials/charge_status.php` - uses 3 keys
  - `schedule/partials/automation_status.php` - uses 2 keys

**Note:** `dataApiUrl`/`dataApiUrl-local`, `statusApiUrl`/`statusApiUrl-local`, and `zendureFetchApiUrl`/`zendureFetchApiUrl-local` are used conditionally based on the `location` value. `zendureStoreApiUrl` has been removed - it is now derived from `dataApiUrl`/`dataApiUrl-local` by appending `?type=zendure`.
