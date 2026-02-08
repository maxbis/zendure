# Mobile Refresh Behavior

The mobile schedule page (`charge_schedule_mobile.php`) uses the **same JavaScript refresh logic** as the desktop schedule page. All refresh functions are defined in the schedule JS modules.

For the full documentation of refresh functions (triggers, APIs, update behavior), see:

**[docs/schedule/refresh-functions.md](../schedule/refresh-functions.md)**

## Mobile-Specific Behavior

The only difference on mobile:

- **Automation Refresh button**: Short tap → `refreshAllStatus()` (automation + charge status), same as desktop. Long-press → full page reload (mobile only).
