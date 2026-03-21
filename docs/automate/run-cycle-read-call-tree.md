# `_run_cycle()` Read Call Tree

This note documents the current read behavior of [`_run_cycle()`](/D:/www/zendure/automate/automate_www.py#L2405) in `automate_www.py`.
It focuses on which calls perform real reads, which only use cached state, and the worst-case number of reads in a single iteration.

## Call Tree

```text
_run_cycle()
|
|-- _sleep_interrupted()
|   `-- no API read
|
|-- _accumulate_p1_data()
|   |-- get_power_meter_reader(...)
|   `-- power_meter_reader.read()
|       `-- READ: configured power meter / P1
|
|-- _update_p1_state(p1_data)
|   `-- no API read
|
|-- _handle_user_input()
|   `-- no API read
|
|-- _refresh_schedule_if_needed()
|   `-- if refresh interval elapsed:
|       `-- schedule_controller.fetch_schedule()
|           `-- READ: schedule API
|
|-- _calculate_desired_power()
|   |-- schedule_controller.get_desired_power(refresh=False)
|   |   `-- uses already-fetched schedule data
|   `-- no direct API read in this function
|
|-- _read_zendure_snapshot()
|   |-- get_reader(...)
|   `-- reader.read_zendure(update_json=True)
|       `-- READ: Zendure device API
|
|-- if not pause_override_active:
|   |
|   |-- controller.check_battery_limits(zendure_data=zendure_data)
|   |   `-- uses the iteration snapshot to update limit_state
|   |       no API read
|   |
|   |-- _apply_runtime_conditions(desired_power)
|   |   `-- uses cached reader.last_zendure_data only
|   |       no API read
|   |
|   `-- _check_battery_limits(desired_power, prechecked=True)
|       `-- uses controller.limit_state only
|           no API read
|
|-- _update_zendure_state()
|   `-- copies cached reader.last_zendure_data to api_state.last_zendure
|       no API read
|
|-- _apply_power_settings(desired_power, p1_data)
|   `-- controller.set_power(desired_power, p1_data, schedule_entry, zendure_data)
|       |
|       |-- fixed power mode (for example 0, 500, -800)
|       |   `-- no extra READ before send
|       |
|       `-- dynamic mode ('netzero' / 'netzero+')
|           `-- [calculate_netzero_power()](/D:/www/zendure/automate/device_controller.py#L747)
|               `-- uses caller-supplied zendure_data when available
|                   no API read in the normal _run_cycle() path
|           `-- _send_power_feed(target_power)
|               `-- WRITE: Zendure device API
|
`-- _handle_standby_check()
    `-- may call controller.set_standby_mode()
        `-- WRITE only, no read
```

The loop now takes one pre-write Zendure snapshot and reuses it for battery-limit handling and dynamic power resolution.
[`check_battery_limits()`](/D:/www/zendure/automate/device_controller.py#L509) still supports doing its own read, but in the normal `_run_cycle()` path it receives caller-supplied data instead.

## Worst Case Per Iteration

Worst case in one normal `_run_cycle()` iteration:

- 1 read from the configured power meter via `_accumulate_p1_data()`
- 1 read from the schedule API via `_refresh_schedule_if_needed()`
- 1 Zendure read via `_read_zendure_snapshot()`

That means:

- 3 total reads per iteration in the worst case
- 1 of those reads is a Zendure device read

## Assumptions

- This describes the normal `_run_cycle()` path only.
- It does not include concurrent HTTP-triggered refreshes such as `/api/zendure` or `/api/p1`.
- It documents the current implementation as-is and does not propose refactors.
