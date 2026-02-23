# Installation Manual

This document describes how to install and configure the Zendure project, including the cookie-based authentication used by `/login`.

## 1. Prerequisites

- PHP 8.x with web server (Apache/Nginx + PHP-FPM or equivalent)
- Python 3.10+ (for `automate/` scripts)
- Network access to your local devices (Zendure + P1 meter) if using live mode

## 2. Project Location

This manual assumes the project is available at:

- `/Users/maxbisschop/dev/www/zendure`

And served at:

- `http://localhost/zendure/`

## 3. Main Config File (Web App)

Main web config:

- `/Users/maxbisschop/dev/www/zendure/main/config/config.json`

Important keys to set:

- `MIN_CHARGE_LEVEL`, `MAX_CHARGE_LEVEL`
- `baseWh`, `minGridPower`, `maxGridPower`
- `priceApiUrl`
- `scheduleApiUrl`
- `apiBaseUrlPiControl`
- `chargeStatusApi`, `wh-per-hourApi`, `allApi`, `automationStatusApi`
- `include_conditions` (for condition merge in schedule resolved API)

### Example

```json
{
  "MIN_CHARGE_LEVEL": 15,
  "MAX_CHARGE_LEVEL": 96,
  "baseWh": 5760,
  "minGridPower": -800,
  "maxGridPower": 800,
  "priceApiUrl": "http://localhost/zendure/main/prices/get_prices_v6.php",
  "scheduleApiUrl": "http://localhost/zendure/main/data/api/data_api.php?type=schedule&resolved=1",
  "apiBaseUrlPiControl": "http://YOUR_PI_OR_CONTROL_HOST:1611",
  "chargeStatusApi": "${apiBaseUrlPiControl}/api/all",
  "wh-per-hourApi": "${apiBaseUrlPiControl}/api/wh_per_hour",
  "allApi": "${apiBaseUrlPiControl}/api/all",
  "automationStatusApi": "${apiBaseUrlPiControl}/api/automation_status",
  "include_conditions": true
}
```

## 4. Automate Config File

Automation config:

- `/Users/maxbisschop/dev/www/zendure/automate/config/config.jsonc`

Important keys to set:

- `TEST_MODE` (set `false` for live control)
- `deviceIp`, `deviceSn`
- `p1Meter.ip`, `p1Meter.endpoint`, `p1Meter.totalPowerPath`
- `apiUrl` (resolved schedule endpoint)
- loop and threshold values:
  - `LOOP_INTERVAL_SECONDS`
  - `API_REFRESH_INTERVAL_SECONDS`
  - `ZERO_COUNT_THRESHOLD_STANDBY`

Reference:

- `/Users/maxbisschop/dev/www/zendure/automate/config/config.md`

## 5. Schedule + Condition Data Files

Core schedule files:

- `/Users/maxbisschop/dev/www/zendure/main/data/charge_schedule.json`
- `/Users/maxbisschop/dev/www/zendure/main/data/charge_schedule_conditions.json`

Condition resolver endpoint:

- `http://localhost/zendure/main/data/resolve_schedule_conditions.php`

Rules editor:

- `http://localhost/zendure/main/edit_rules.php`

Manuals:

- `/Users/maxbisschop/dev/www/zendure/docs/user_manuals/edit_rules_user_manual.md`
- `/Users/maxbisschop/dev/www/zendure/docs/user_manuals/charge_schedule_mobile_user_manual.md`

## 6. Authentication (Cookie + Hash)

Authentication is validated by:

- `/Users/maxbisschop/dev/www/zendure/login/validate.php`

Login page:

- `/Users/maxbisschop/dev/www/zendure/login/index.php`

Allowed key storage:

- `/Users/maxbisschop/dev/www/zendure/login/validkeys.txt`

### How it works

1. User enters plain validation key on `/login/index.php`.
2. App hashes it with SHA-256.
3. App stores the hash in cookie `validation`.
4. Protected pages include `login/validate.php`.
5. `validate.php` compares cookie value against lines in `validkeys.txt` (exact string match).
6. If match: access granted and cookie expiration is refreshed.
7. If no match: HTTP 403.

### Important detail

`validkeys.txt` must contain **hashes** (the same SHA-256 format), not plain text keys.

## 7. Setting Up Authentication

1. Generate hash by using login page:
   - open `http://localhost/zendure/login/index.php`
   - enter your key
   - copy shown hash
2. Add hash as a separate line in:
   - `/Users/maxbisschop/dev/www/zendure/login/validkeys.txt`
3. Retry protected page, e.g.:
   - `http://localhost/zendure/main/charge_schedule_mobile.php`

## 8. Security Notes

- In production, set debug off in `validate.php`:
  - `define('LOGIN_VALIDATION_DEBUG', false);`
- Use HTTPS in production so cookie transport is protected.
- Restrict file permissions for:
  - `main/config/config.json`
  - `automate/config/config.jsonc`
  - `login/validkeys.txt`

## 9. First Functional Test

1. Open login and set validation key.
2. Open mobile page:
   - `http://localhost/zendure/main/charge_schedule_mobile.php`
3. Confirm schedule loads.
4. Open rules editor and save a test rule.
5. Check resolved conditions endpoint returns data.
6. Check schedule API resolved output:
   - `http://localhost/zendure/main/data/api/data_api.php?type=schedule&resolved=1`

## 10. Common Issues

- **403 Access Denied**
  - cookie missing/expired
  - hash not present in `validkeys.txt`
- **No schedule data**
  - wrong `scheduleApiUrl`
  - invalid JSON in schedule files
- **No condition merge**
  - `include_conditions` missing or false in `main/config/config.json`
- **Automation not acting**
  - `TEST_MODE` still true
  - incorrect `deviceIp` / `deviceSn` / P1 config

