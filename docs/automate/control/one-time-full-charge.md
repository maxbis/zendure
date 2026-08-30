# One-time Full Charge Override

## Purpose

The one-time full-charge control temporarily lets automation charge beyond the configured maximum state of charge up to 100%. It is an operational exception for an existing schedule, not a persistent configuration change and not a command that starts charging by itself.

## Location

- Control page: `automate/control/index.php`
- Command definitions: `automate/control/commands.php`
- Authenticated proxy: `automate/control/command.php`
- Pi API: `automate/automate_api.py`
- Runtime state and battery-limit enforcement: `automate/device_controller.py`

## Inputs and Outputs

- `GET /api/full_charge_once` returns the current override status.
- `POST /api/full_charge_once?state=on` arms the one-time exception.
- `POST /api/full_charge_once?state=off` cancels the exception.
- The configured maximum continues to come from `battery.maxChargePercent` in `common/config/system.json`.
- The effective maximum is 100% while the exception is active and otherwise equals the configured maximum.

Status includes:

- Whether the exception is active.
- Configured and effective maximum percentages.
- The 100% target while active.
- Activation and expiry timestamps.
- Whether a process restart resets the exception.
- The most recent reset reason when available.

## Flow and Behavior

1. The authenticated control page loads status through its same-origin command proxy.
2. When the operator confirms **Allow 100% Once**, then the proxy sends the whitelisted command to the Pi API.
3. When automation arms the exception, then it preserves the configured maximum and changes only its in-memory effective maximum to 100%.
4. When an existing fixed or dynamic schedule requests charging, then the effective 100% maximum is used by the normal battery-limit checks.
5. When live battery SoC reaches 100%, then automation restores the configured maximum before finishing that control iteration and blocks additional charging.
6. When the operator cancels, the 24-hour deadline passes, or automation restarts, then the configured maximum becomes effective again.
7. While active, the control page refreshes status every 30 seconds.

## Edge Cases and Failure Modes

- When no schedule or solar-surplus opportunity requests charging, then the exception does not charge the battery and eventually expires.
- When the exception is armed more than once, then the original activation and expiry remain unchanged.
- When cancellation is requested more than once, then the command remains safe and the configured maximum stays effective.
- When the configured maximum is already 100%, then the API reports that no exception is needed.
- When automation restarts unexpectedly, then the memory-only exception is cleared rather than recovered.
- When the Pi API is unavailable, then the page reports unavailable status and does not assume that a command succeeded.
- When `POST /api/max_charge_level` is called, then it remains read-only and returns HTTP 405; the one-time endpoint is the only supported exception.

## Related Files

- `common/config/system.json`
- `docs/automate/automate-www.md`
- `docs/automate/device-controller.md`
- `tools/tests/automate/test_automate_www_runtime.py`
- `tools/tests/automate/test_device_controller_battery_limits.py`
