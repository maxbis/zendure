# Config Keys Usage Analysis

Analysis of all keys in `config/config.json` and their usage across the entire codebase.

## Summary

Total keys analyzed: 27 (including nested keys)
- Used: 22 keys
- Unused: 5 keys

## Detailed Key Usage

### 1. `dataDir`
**Status:** ✅ USED
**Used in:**
- `data/api/xxx_zendure_fetch_api.php` - line 172
- `_archive/status/includes/config_loader.php` - line 27
- `_archive/statusa/includes/config_loader.php` - line 37
- `_archive/status/classes/read_zendure.php` - line 31
- `_archive/statusa/classes/read_zendure.php` - line 31
- `_archive/status/classes/read_zendure_p1.php` - line 40
- `_archive/statusa/classes/read_zendure_p1.php` - line 40

### 2. `dataFile`
**Status:** ✅ USED
**Used in:**
- `_archive/statusa/index.php` - line 28
- `_archive/status/includes/config_loader.php` - lines 42-43 (set from dataDir)
- `_archive/statusa/includes/config_loader.php` - line 26 (set from dataDir)

### 3. `location`
**Status:** ✅ USED
**Used in:**
- `schedule/charge_schedule.php` - line 41
- `schedule/partials/calculate.php` - line 32
- `schedule/partials/charge_status.php` - line 35
- `schedule/partials/automation_status.php` - line 28

### 4. `deviceIp`
**Status:** ✅ USED
**Used in:**
- `automate/device_controller.py` - lines 571, 1059
- `data/api/xxx_zendure_fetch_api.php` - line 189
- `data/api/data_files_test.py` - line 407

### 5. `deviceSn`
**Status:** ✅ USED
**Used in:**
- `automate/device_controller.py` - line 572

### 6. `p1MeterIp`
**Status:** ✅ USED
**Used in:**
- `automate/device_controller.py` - lines 1027, 1058
- `data/api/data_files_test.py` - line 408

### 7. `p1DeviceId`
**Status:** ❌ UNUSED
**Used in:** None (only hardcoded in `_archive/zendure_mqtt_p1.py`)

### 8. `apiUrl`
**Status:** ✅ USED
**Used in:**
- `automate/device_controller.py` - line 1223, 1256 (as CONFIG_KEY_SCHEDULE_API_URL)
- `data/api_client.php` - line 308

### 9. `scheduleApiUrl`
**Status:** ✅ USED
**Used in:**
- `schedule/charge_schedule.php` - line 30
- `schedule/partials/calculate.php` - line 27

### 10. `statusApiUrl`
**Status:** ✅ USED
**Used in:**
- `schedule/partials/automation_status.php` - line 32
- `automate/automate.py` - line 416
- `data/api_client.php` - line 309

### 11. `statusApiUrl-local`
**Status:** ✅ USED
**Used in:**
- `schedule/partials/automation_status.php` - line 30

### 12. `apiBasePath`
**Status:** ✅ USED (but only in archive)
**Used in:**
- `_archive/run_schedule/batch/update_prices.py` - line 73

### 13. `dataApiUrl`
**Status:** ✅ USED
**Used in:**
- `schedule/partials/charge_status.php` - line 39

### 14. `dataApiUrl-local`
**Status:** ✅ USED
**Used in:**
- `schedule/partials/charge_status.php` - line 37

### 15. `zendureStoreApiUrl`
**Status:** ✅ USED
**Used in:**
- `schedule/charge_schedule.php` - line 46
- `schedule/partials/calculate.php` - line 37
- `automate/device_controller.py` - line 1030, 1197

### 16. `zendureFetchApiUrl`
**Status:** ⚠️ SET BUT NOT USED
**Used in:**
- `schedule/charge_schedule.php` - line 47 (set but never used)
- `schedule/partials/calculate.php` - line 38 (set but never used)

### 17. `zendureStoreApiUrl-local`
**Status:** ✅ USED
**Used in:**
- `schedule/charge_schedule.php` - line 43
- `schedule/partials/calculate.php` - line 34

### 18. `zendureFetchApiUrl-local`
**Status:** ⚠️ SET BUT NOT USED
**Used in:**
- `schedule/charge_schedule.php` - line 44 (set but never used)
- `schedule/partials/calculate.php` - line 35 (set but never used)

### 19. `p1StoreApiUrl`
**Status:** ✅ USED
**Used in:**
- `automate/device_controller.py` - line 1029, 1148

### 20. `priceUrls.today`
**Status:** ✅ USED
**Used in:**
- `prices/get_prices_v2.php` - line 48, 56

### 21. `priceUrls.tomorrow`
**Status:** ✅ USED
**Used in:**
- `prices/get_prices_v2.php` - line 48, 57

### 22. `priceUrls.get_prices`
**Status:** ✅ USED
**Used in:**
- `schedule/charge_schedule.php` - line 36-37

### 23. `tomorrowFetchHour`
**Status:** ✅ USED
**Used in:**
- `prices/get_prices_v2.php` - line 53, 367

### 24. `mqtt.broker`
**Status:** ❌ UNUSED
**Used in:** None (hardcoded in `_archive/zendure_mqtt.py` and `_archive/zendure_mqtt_p1.py`)

### 25. `mqtt.port`
**Status:** ❌ UNUSED
**Used in:** None (hardcoded in `_archive/zendure_mqtt.py` and `_archive/zendure_mqtt_p1.py`)

### 26. `mqtt.appKey`
**Status:** ❌ UNUSED
**Used in:** None (hardcoded in `_archive/zendure_mqtt.py` and `_archive/zendure_mqtt_p1.py`)

### 27. `mqtt.appSecret`
**Status:** ❌ UNUSED
**Used in:** None (hardcoded in `_archive/zendure_mqtt.py` and `_archive/zendure_mqtt_p1.py`)

## Unused Keys Summary

### Completely Unused (5 keys):
1. `p1DeviceId` - Not used anywhere
2. `mqtt.broker` - Not used (hardcoded in archive scripts)
3. `mqtt.port` - Not used (hardcoded in archive scripts)
4. `mqtt.appKey` - Not used (hardcoded in archive scripts)
5. `mqtt.appSecret` - Not used (hardcoded in archive scripts)

### Set But Never Used (2 keys):
6. `zendureFetchApiUrl` - Set in code but variable never used
7. `zendureFetchApiUrl-local` - Set in code but variable never used

### Used Only in Archive (1 key):
8. `apiBasePath` - Only used in `_archive/run_schedule/batch/update_prices.py`

## Notes

- The `mqtt` keys appear to be for MQTT integration scripts that are in the `_archive` directory and use hardcoded values instead of config
- `zendureFetchApiUrl` and `zendureFetchApiUrl-local` are loaded from config but the variables are never actually used in the code
- `apiBasePath` is only used in archived code (`_archive/run_schedule/batch/update_prices.py`)
- Most keys are actively used in the main codebase (`/schedule`, `/prices`, `/automate`, `/data`)
