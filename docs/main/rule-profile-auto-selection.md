# Automatic Rule Profile Selection

## Purpose

Automatic rule profile selection chooses a date-specific rule profile from the predicted daily shortwave-radiation total. Manual mode remains the default and continues using the saved manual profile.

## Location

- Profile configuration: `main/data/rule_profiles.json`
- Calculated runtime state: `main/data/rule_profile_auto_state.json`
- Rule editor: `main/edit_rules.php`
- Manual evaluation API: `main/api/evaluate_auto_profiles_api.php`
- Nightly CLI: `tools/evaluate_auto_profiles.py`
- Schedule integration: `main/data/resolve_schedule_conditions.php`

## Inputs and outputs

Profile inputs:

- `selection_mode`: `manual` or `auto`.
- `active_profile_id`: manual profile and automatic fallback.
- `swr_min_wh_m2`: inclusive lower boundary, or `null` for no lower boundary.
- `swr_max_wh_m2`: exclusive upper boundary, or `null` for no upper boundary.
- Profile array order: automatic matching priority from first to last.

The evaluator consumes `days[]` from `main/api/shortwave_radiation_api.php`. It writes a date-indexed state containing the predicted SWR, selected profile, selection reason, cache timestamp, and evaluation timestamp.

The resolved schedule response includes `profileSelection` metadata for the requested date.

## Flow and behavior

1. The nightly command invokes the shared PHP SWR endpoint locally. `--shortwave-url` remains available for deployments that prefer HTTP.
2. The endpoint returns a fresh cache, refreshes an expired cache, or returns a valid stale cache when upstream refresh fails.
3. The evaluator processes every future forecast date returned by the endpoint.
4. Profiles are checked in stored left-to-right order.
5. When `minimum <= SWR < maximum`, then the first matching profile wins.
6. When a valid SWR matches no range, then the first actual profile is selected.
7. The evaluator writes state atomically while holding a lock.
8. When schedule rules are resolved, then the selection for that requested date replaces the manual profile only in automatic mode.

Recommended production cron entry:

```cron
CRON_TZ=Europe/Amsterdam
55 23 * * * cd /var/www/zendure && python3 tools/evaluate_auto_profiles.py >> logs/auto-profile.log 2>&1
```

Use the installation path and timezone for the deployed system. The nightly command excludes the almost-completed current date. The rule editor action includes today and requests an immediate automation schedule refresh afterward.

## Edge cases and failure modes

- When upstream refresh fails but the SWR cache is valid, then the endpoint returns stale data with `cacheStatus: stale` and refresh error metadata.
- When no new forecast exists for a date but stored SWR exists, then the stored SWR is re-evaluated against the latest profile ranges.
- When no SWR exists but a date already has a selection, then that selection is retained.
- When neither SWR nor a date selection exists, then the current manual/effective profile is carried forward.
- Missing forecast data never triggers the first-profile default. That default applies only to a valid SWR that matches no range.
- `Show All` is not considered during SWR range matching, but it can remain the manual fallback.
- Overlapping ranges are allowed. The first matching profile wins.
- A profile with both boundaries empty matches every valid SWR and shadows later profiles.
- Manual and cron evaluation are serialized with a state-file lock.
- Automatic state is runtime data and is excluded by the repository JSON ignore rules.

## Related files

- [Shortwave radiation API](api/shortwave-radiation-api.md)
- [Rule editor user manual](../user_manuals/edit_rules_user_manual.md)
- [Schedule resolution](../data/schedule-resolution-technical.md)
