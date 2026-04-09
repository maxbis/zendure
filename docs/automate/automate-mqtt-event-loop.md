# `automate_mqtt.py` Event Loop

This note documents the current control-loop behavior of [`_run_cycle()`](/Users/maxbisschop/dev/www/zendure/automate/automate_mqtt.py#L1237) in `automate_mqtt.py`.

It focuses on:

- when the loop wakes
- what decides whether a full control pass runs
- how MQTT-triggered runs, stale fallback, periodic control, and schedule-slot boundaries interact
- how the loop avoids running the full pipeline twice in the same iteration

## Decision Flow

```text
_run_cycle()
|
|-- _sleep_interrupted()
|   |
|   |-- normal timed sleep
|   |-- break early on configured second marks (for example :00)
|   `-- break early on MQTT wake event when a thresholded power change arrives
|
|-- _log_mqtt_diagnostics_if_needed()
|
|-- mqtt_changed = consume_power_change_event()
|-- mqtt_fresh = not is_stale(...)
|-- periodic_due = _should_run_periodic_control()
|-- boundary_due = _has_pending_schedule_boundary()
|
|-- if mqtt is fresh AND no mqtt_changed AND no periodic_due AND no boundary_due:
|   `-- skip the full control pipeline for this iteration
|
|-- else run exactly one full control pipeline:
|   |
|   |-- if mqtt_changed and mqtt_fresh:
|   |   `-- use _get_mqtt_p1_data()
|   |
|   |-- elif not mqtt_fresh:
|   |   `-- use _accumulate_p1_data() fallback
|   |
|   `-- elif periodic_due or boundary_due:
|       `-- prefer _get_mqtt_p1_data(), else fall back to _accumulate_p1_data()
|
`-- after a successful full control pass:
    `-- remember the current active schedule-slot signature
```

## What Triggers A Full Control Pass

`automate_mqtt.py` runs the full control pipeline when any of these is true:

1. A thresholded MQTT power-change event arrived.
2. The cached MQTT reading became stale.
3. The periodic fallback interval elapsed (`mqttPowerMeter.periodicControlIntervalSeconds`).
4. The active resolved schedule slot changed since the last successful control run.

The fourth case is important: a top-of-hour change is no longer forced to wait for the periodic fallback interval when MQTT is fresh and unchanged. Any schedule-slot boundary can trigger an immediate control pass.

## Schedule Boundary Behavior

The runtime tracks the last successfully applied schedule slot using a small signature derived from the active resolved schedule entry:

- `time`
- `value`
- `key`

The signature is read from already cached schedule data; boundary detection does not call `fetch_schedule()` by itself.

If the active signature differs from the last remembered one, `_has_pending_schedule_boundary()` returns `True` and the current iteration is allowed to run the normal full control pipeline immediately.

This is integrated into the existing loop decision. It does **not** create a second full pipeline run in the same iteration.

## Full Control Pipeline

When the loop decides to run control, it reuses [`_run_full_control_pipeline()`](/Users/maxbisschop/dev/www/zendure/automate/automate_mqtt.py#L1204):

1. Update cached P1 state from the chosen reading.
2. Refresh schedule from the schedule API if the configured refresh interval elapsed.
3. Resolve desired power from the current cached schedule.
4. Read one Zendure snapshot for this iteration.
5. Apply runtime conditions and battery-limit checks.
6. Apply power settings through the normal deduplicated device-control path.
7. Run standby handling.
8. Record `last_full_control_run_ts`.

If the full control pass succeeds, the loop stores the current schedule-slot signature as the new baseline. If it fails, the signature is not advanced, so the boundary condition can trigger another attempt later.

## No Double-Run Guarantee

The loop can observe multiple reasons to run in the same iteration, for example:

- an MQTT threshold-crossing event and a schedule boundary at the same time
- periodic fallback due and a schedule boundary at the same time

Even in those cases, `_run_cycle()` executes the full control pipeline only once. The reasons are collapsed into a single decision before choosing the P1 source and calling `_run_full_control_pipeline()`.

## Idle Case

When MQTT is enabled, fresh, unchanged, periodic control is not due, and no schedule boundary is pending, the loop does not run the full control pipeline.

In that idle case it only:

- sleeps / waits for wake
- refreshes MQTT diagnostics logging
- optionally logs that the MQTT power delta stayed below the configured threshold

## Read / Write Summary Per Full Control Pass

Worst case for one full control pass:

- 1 P1 read
  - usually from MQTT cache
  - falls back to HTTP only when MQTT is stale or unavailable
- 1 schedule API read
  - only if `_refresh_schedule_if_needed()` decides the refresh interval elapsed
- 1 Zendure read
- 1 Zendure write
  - only if the deduplicated power-application path decides a new command is needed

Boundary detection itself is read-only over cached schedule data.

## Assumptions

- This note documents the runtime as implemented in `automate_mqtt.py` after schedule-slot boundary triggering was added.
- It describes the loop behavior only; it does not change `/api/refresh` semantics.
- “Schedule boundary” means any change in the active resolved schedule entry, not only exact top-of-hour transitions.
