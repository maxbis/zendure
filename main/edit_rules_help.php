<?php

// Validate user access
$validateFile = __DIR__ . '/../login/validate.php';
require_once $validateFile;

// main/edit_rules_help.php
date_default_timezone_set('Europe/Amsterdam');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Rules Help</title>
    <link rel="stylesheet" href="../themes/graphite-signal-dark/assets/css/theme.css">
    <link rel="stylesheet" href="../themes/graphite-signal-dark/assets/css/components.css">
    <style>
        :root {
            --bg: var(--gsd-page);
            --card: var(--gsd-surface-1);
            --text: var(--gsd-text);
            --muted: var(--gsd-text-muted);
            --line: var(--gsd-border);
        }
        main {
            max-width: 980px;
            margin: 22px auto;
            padding: 0 16px 24px;
            display: grid;
            gap: 14px;
        }
        .top-links {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 6px;
        }
        section {
            background: var(--gsd-surface-1);
            border: 1px solid var(--gsd-border);
            border-radius: var(--gsd-radius-card);
            box-shadow: var(--gsd-shadow-card);
            padding: 14px;
        }
        h1, h2 {
            margin: 0 0 10px 0;
            color: var(--gsd-text);
            font-weight: 650;
            letter-spacing: -0.015em;
        }
        h1 { font-size: 1.5rem; }
        h2 { font-size: 1.125rem; }
        p, li { color: var(--gsd-text); line-height: 1.45; }
        .muted { color: var(--gsd-text-muted); }
        code, pre {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        code { color: var(--gsd-text-secondary); }
        pre {
            background: var(--gsd-page-deep);
            border: 1px solid var(--gsd-border);
            border-radius: var(--gsd-radius-sm);
            padding: 10px;
            overflow: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        .table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        th, td {
            border-bottom: 1px solid var(--gsd-border);
            text-align: left;
            vertical-align: top;
            padding: 7px;
        }
        th { color: var(--gsd-text-muted); }
        @media (max-width: 700px) {
            main {
                margin: 12px auto;
                padding: 0 10px 16px;
                gap: 10px;
            }
            section {
                padding: 10px;
            }
            body {
                font-size: 18px;
            }
            h1 { font-size: 1.45rem; }
            h2 { font-size: 1.2rem; }
            p, li {
                line-height: 1.55;
            }
            th, td {
                padding: 8px;
                font-size: 1rem;
            }
            pre {
                padding: 10px;
                font-size: 0.98rem;
            }
            .gsd-btn {
                min-height: 44px;
            }
        }
    </style>
</head>
<body data-theme="graphite-signal-dark">
<main>
    <section>
        <div class="top-links">
            <a class="gsd-btn gsd-btn--quiet" href="edit_rules.php">Back to Rule Editor</a>
        </div>
        <h1>Condition Rules Help</h1>
        <p class="muted">This page describes all fields supported by <code>main/data/charge_schedule_conditions.json</code> and the editor.</p>
    </section>

    <section>
        <h2>Rule Fields</h2>
        <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Field</th><th>Description</th><th>Example</th></tr>
            </thead>
            <tbody>
                <tr><td><code>value</code></td><td>Output schedule value. Can be integer watts, <code>netzero</code>, <code>netzero-</code>, <code>netzero+</code>, <code>empty_at_solar_charge</code>, or <code>full_at_netzero_minus</code>.</td><td><code>"value": "netzero"</code></td></tr>
                <tr><td><code>key</code> (optional)</td><td>Pattern key <code>YYYYMMDDHHmm</code> with optional <code>*</code> wildcards.</td><td><code>"key": "********1800"</code></td></tr>
                <tr><td><code>month</code> (optional)</td><td>Month filter list; accepts string list, array, or single value.</td><td><code>"month": "10,11,12,1,2,3"</code></td></tr>
                <tr><td><code>hour</code> (optional)</td><td>Hour filter list; accepts string list, array, or single value.</td><td><code>"hour": "1,2,17,18"</code></td></tr>
                <tr><td><code>min_time</code> (optional)</td><td>Lower bound hour (inclusive).</td><td><code>"min_time": "10"</code></td></tr>
                <tr><td><code>max_time</code> (optional)</td><td>Upper bound hour (inclusive).</td><td><code>"max_time": "11"</code></td></tr>
                <tr><td><code>min_power</code> (optional)</td><td>Minimum signed watt bound for <code>netzero</code> / <code>netzero+</code> rules. Negative = discharge, positive = charge. Defaults to <code>null</code>.</td><td><code>"min_power": -700</code></td></tr>
                <tr><td><code>max_power</code> (optional)</td><td>Maximum signed watt bound for <code>netzero</code> / <code>netzero+</code> rules. Negative = discharge, positive = charge. Defaults to <code>null</code>.</td><td><code>"max_power": -100</code></td></tr>
                <tr><td><code>fallback_value</code> (optional)</td><td>Optional fallback power used by runtime integrations when runtime conditions fail. Can be integer watts, <code>netzero</code>, or <code>netzero+</code>.</td><td><code>"fallback_value": 0</code></td></tr>
                <tr><td><code>target_soc_percent</code></td><td>Required reserve at the first future solar-capable net-zero start for <code>empty_at_solar_charge</code>: <code>netzero+</code>, or <code>netzero</code> when positive charging is allowed.</td><td><code>15</code></td></tr>
                <tr><td><code>max_discharge_power</code> (optional)</td><td>Positive watt cap for the discharge calculated by the target battery planner.</td><td><code>1600</code></td></tr>
                <tr><td><code>full_at_netzero_minus</code></td><td>Continuously recalculated charging objective. It targets the configured maximum battery level at the next NZ- start and resolves matching hours to NZ+ with a 100 W stepped minimum.</td><td><code>"value": "full_at_netzero_minus"</code></td></tr>
                <tr><td><code>conditions</code></td><td>Array of condition objects. Static rows use <code>condition_relation</code>; runtime-only rows force AND.</td><td><code>"conditions": [ ... ]</code></td></tr>
                <tr><td><code>condition_relation</code> (optional)</td><td>How static <code>conditions[]</code> rows are combined. Supported values: <code>and</code>, <code>or</code>. Default is <code>and</code>.</td><td><code>"condition_relation": "or"</code></td></tr>
            </tbody>
        </table>
        </div>
    </section>

    <section>
        <h2>Condition Fields</h2>
        <div class="table-wrap">
        <table>
            <thead>
                <tr><th><code>field</code></th><th>Meaning</th></tr>
            </thead>
            <tbody>
                <tr><td><code>price</code></td><td>Current hour price (cents/kWh).</td></tr>
                <tr><td><code>ranking</code></td><td>Daily rank (1..24) of current hour by price; sorted by price asc, then hour asc. Lower price = lower rank.</td></tr>
                <tr><td><code>min_price</code></td><td>Lowest daily price (cents/kWh).</td></tr>
                <tr><td><code>max_price</code></td><td>Highest daily price (cents/kWh).</td></tr>
                <tr><td><code>spread_price</code></td><td>Daily spread: <code>max_price - min_price</code> (cents/kWh).</td></tr>
                <tr><td><code>min_price_hour</code></td><td>Hour (0-23) when min price occurs (first occurrence).</td></tr>
                <tr><td><code>max_price_hour</code></td><td>Hour (0-23) when max price occurs (first occurrence).</td></tr>
                <tr><td><code>max_price_hour_am</code></td><td>Hour (0-11) when the AM half-day max price occurs (first occurrence).</td></tr>
                <tr><td><code>max_price_hour_pm</code></td><td>Hour (12-23) when the PM half-day max price occurs (first occurrence).</td></tr>
                <tr><td><code>sunrise_hour</code></td><td>Sunrise hour derived per rendered date using configured latitude/longitude. Rounded with <code>floor</code>.</td></tr>
                <tr><td><code>sunset_hour</code></td><td>Sunset hour derived per rendered date using configured latitude/longitude. Rounded with <code>ceil</code>.</td></tr>
                <tr><td><code>sunrise_offset_hour</code></td><td>Compares current hour to <code>sunrise_hour + offset</code>. Provide offset as numeric <code>value</code> (e.g. <code>-2</code>, <code>+1</code>).</td></tr>
                <tr><td><code>sunset_offset_hour</code></td><td>Compares current hour to <code>sunset_hour + offset</code>. Provide offset as numeric <code>value</code>.</td></tr>
                <tr><td><code>month</code></td><td>Current month number check, usually with <code>in</code>.</td></tr>
                <tr><td><code>hour</code></td><td>Current hour check, either list (<code>in</code>) or numeric compare (<code>&lt;, &gt;</code>) with <code>value_ref</code> and optional numeric <code>value</code> offset.</td></tr>
                <tr><td><code>min_time</code></td><td>Equivalent to hour &gt;= bound.</td></tr>
                <tr><td><code>max_time</code></td><td>Equivalent to hour &lt;= bound.</td></tr>
                <tr><td><code>electricity_level</code></td><td>Battery SoC percent condition for runtime evaluation. It is stored in rules and emitted as runtime metadata in resolved output; static resolver does not evaluate this field.</td></tr>
            </tbody>
        </table>
        </div>
    </section>

    <section>
        <h2>Operators</h2>
        <p><code>&gt;</code>, <code>&gt;=</code>, <code>&lt;</code>, <code>&lt;=</code>, <code>==</code>, <code>!=</code>, <code>in</code></p>
        <p class="muted"><code>in</code> is intended for list-like values such as <code>hour</code> and <code>month</code>.</p>
    </section>

    <section>
        <h2>Runtime Metadata</h2>
        <p>Rules using <code>electricity_level</code> are saved as normal rules. In resolved schedule output, those conditions are exposed under <code>runtime_conditions</code> while <code>value</code> remains unchanged for backward compatibility.</p>
        <p>When a rule contains runtime-only conditions such as <code>electricity_level</code>, its effective condition relation is forced to <code>AND</code>.</p>
        <p>When present, <code>min_power</code> and <code>max_power</code> are also exposed in resolved output for <code>netzero</code> / <code>netzero+</code> rules so the Python runtime can apply them.</p>
        <p class="muted"><code>fallback_value</code> does not have its own min/max fields.</p>
        <p>For <code>full_at_netzero_minus</code>, the planner uses the live battery percentage without a solar forecast or efficiency factor. It divides the remaining energy across the rule's remaining matching slots, rounds the NZ+ minimum upward to 100 W, and clamps it to the configured maximum charge power. If planning is unavailable, the default fallback is unbounded <code>netzero+</code>.</p>
    </section>

    <section>
        <h2>Condition Relation</h2>
        <p><code>condition_relation = and</code> means all static <code>conditions[]</code> rows must match.</p>
        <p><code>condition_relation = or</code> means any static <code>conditions[]</code> row may match.</p>
        <p>Top-level filters such as <code>month</code>, <code>hour</code>, <code>min_time</code>, and <code>max_time</code> are always evaluated as AND filters outside the relation toggle.</p>
    </section>

    <section>
        <h2>value / value_ref</h2>
        <p>A condition can use a literal <code>value</code>, a dynamic <code>value_ref</code>, or both.</p>
        <p>Supported <code>value_ref</code>: <code>min_price</code>, <code>max_price</code>, <code>spread_price</code>, <code>min_price_hour</code>, <code>max_price_hour</code>, <code>max_price_hour_am</code>, <code>max_price_hour_pm</code>, <code>sunrise_hour</code>, <code>sunset_hour</code>.</p>
        <p>When both are present, <code>value</code> is treated as a numeric offset added to <code>value_ref</code>. Example: <code>price &gt; max_price</code> with <code>value = -1</code> means <code>price &gt; max_price - 1</code>. Likewise <code>hour &lt; min_price_hour</code> with <code>value = 1</code> means <code>hour &lt; min_price_hour + 1</code>.</p>
    </section>

    <section>
        <h2>Sun Rules</h2>
        <p>Sunrise/sunset are calculated in the resolver for each rendered date using the installation latitude, longitude, and timezone from <code>common/config/system.json</code>. Rounding policy: <code>sunrise_hour = floor</code>, <code>sunset_hour = ceil</code>.</p>
        <p><code>sunrise_offset_hour</code> and <code>sunset_offset_hour</code> use the condition <code>value</code> as an offset in hours relative to sunrise/sunset:</p>
        <ul>
            <li><strong>Negative value</strong>: hours <em>before</em> sunrise/sunset (example: <code>-2</code> means 2 hours before).</li>
            <li><strong>Zero</strong>: exactly the sunrise/sunset anchor hour.</li>
            <li><strong>Positive value</strong>: hours <em>after</em> sunrise/sunset (example: <code>+1</code> means 1 hour after).</li>
        </ul>
        <p class="muted">The resolver compares the current slot hour against <code>sunrise_hour + offset</code> or <code>sunset_hour + offset</code> (clamped to 0..23).</p>
    </section>

    <section>
        <h2>Resolution Order</h2>
        <p>Schedule output is built in two stages:</p>
        <ol>
            <li><strong>Base schedule resolve</strong> from <code>charge_schedule.json</code> (manual entries and wildcards).</li>
            <li><strong>Condition merge</strong> from <code>charge_schedule_conditions.json</code>.</li>
        </ol>
        <p>Priority at merge time:</p>
        <ul>
            <li><code>manual non-wildcard key</code> (no <code>*</code>) wins and is not overridden by condition rules, including explicit <code>0</code>.</li>
            <li>Wildcard/empty base slots may be overridden by condition rules.</li>
        </ul>
        <p class="muted">Raw schedule entries may also use <code>auto</code> as a boundary marker in <code>charge_schedule.json</code>: from that exact time onward, inherited earlier manual values stop carrying forward and normal wildcard/rule resolution resumes.</p>
        <p class="muted">When available, UI source labels show the originating condition rule as <code>#&lt;index&gt; &lt;name&gt;</code>.</p>
    </section>

    <section>
        <h2>Examples</h2>
<pre>{
  "value": "netzero",
  "min_power": -700,
  "max_power": -100,
  "conditions": [
    { "field": "price", "op": ">=", "value": 25 }
  ]
}</pre>

<pre>{
  "value": "500",
  "conditions": [
    { "field": "min_price", "op": "<", "value": 0 },
    { "field": "hour", "op": "<", "value_ref": "min_price_hour" }
  ]
}</pre>

<pre>{
  "value": "800",
  "conditions": [
    { "field": "spread_price", "op": ">", "value": 12 }
  ]
}</pre>

<pre>{
  "value": "netzero",
  "conditions": [
    { "field": "ranking", "op": ">=", "value": 21 }
  ]
}</pre>
        <p class="muted">Because ranks are 1..24, <code>ranking &gt;= 21</code> selects 4 hours.</p>

<pre>{
  "value": 1200,
  "fallback_value": 0,
  "conditions": [
    { "field": "price", "op": "<", "value": 18 },
    { "field": "electricity_level", "op": "<", "value": 60 }
  ]
}</pre>

<pre>{
  "value": "netzero+",
  "conditions": [
    { "field": "sunset_offset_hour", "op": ">=", "value": -2 },
    { "field": "sunset_offset_hour", "op": "<=", "value": 0 }
  ]
}</pre>

<pre>{
  "value": "netzero",
  "conditions": [
    { "field": "sunrise_offset_hour", "op": ">=", "value": 0 },
    { "field": "sunrise_offset_hour", "op": "<=", "value": 2 }
  ]
}</pre>
        <p class="muted">Example above: apply from sunrise hour up to 2 hours after sunrise.</p>
    </section>
</main>
</body>
</html>
