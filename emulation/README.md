# P1 Emulator

This emulator serves a local P1 endpoint for automate testing.

- Same path style as your current setup: `/api/v1/data`
- Default port: `1612`
- One poll advances one step
- End-of-list behavior: stays on the last step
- Scenario file hot-reloads when changed (and resets to step 0)

## Run

```bash
cd /Users/maxbisschop/dev/www/zendure/emulation
python3 p1_emulator.py
```

Custom options:

```bash
python3 p1_emulator.py --host 0.0.0.0 --port 1612 --file ./p1_steps.json --endpoint /api/v1/data
```

## Endpoints

- `GET /api/v1/data` -> returns next step payload
- `GET /api/emulation/status` -> emulator status
- `POST /api/emulation/reset` -> reset step pointer to 0
- `GET /api/test` -> quick health/check metadata

## Scenario format

```json
{
  "defaults": {
    "meter_id": "emulated-p1",
    "expected_battery_mode": "idle",
    "expected_battery_power_w": 0
  },
  "steps": [
    { "active_power_w": -300, "expected_battery_mode": "idle", "expected_battery_power_w": 0 },
    { "active_power_w": -120, "expected_battery_mode": "idle", "expected_battery_power_w": 0 },
    { "active_power_w": 50, "expected_battery_mode": "discharge", "expected_battery_power_w": -50 }
  ]
}
```

Notes:
- `steps` must be a non-empty array.
- Each step must have `active_power_w` (integer).
- `total_power` is accepted as an alias and normalized to `active_power_w`.
- Any extra keys are passed through in the API response unchanged.
- This allows test metadata like expected battery behavior per step.

The response also includes:
- `timestamp`
- `emulation_step_index`
- `emulation_step_total`
- `emulation_finished`

## Integrate with automate

In `automate/config/config.jsonc`:

```jsonc
"p1Meter": {
  "ip": "127.0.0.1:1612",
  "endpoint": "/api/v1/data",
  "totalPowerPath": "active_power_w"
}
```

Then restart automate.

## Automate Emulator Client

Use the client to poll the P1 emulator, compute expected battery commands for
`netzero` / `netzero+`, and print step-by-step results.

```bash
cd /Users/maxbisschop/dev/www/zendure/emulation
python3 automate_emulator.py --p1-base-url http://127.0.0.1:1612 --reset-on-start
```

Useful flags:
- `--mode netzero|netzero+` fallback mode if a step has no `expected_mode`
- `--min-abs-power 30` deadband
- `--max-discharge 800` and `--max-charge 1200` command limits
- `--json-output` for machine-readable lines

Exit code:
- `0` when all checked steps match expectations (or no expectations)
- `2` when at least one step mismatches
