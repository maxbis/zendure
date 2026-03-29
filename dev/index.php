<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Amsterdam');

$exampleDate = date('Ymd');
$sections = [
    [
        'title' => 'Main Pages',
        'description' => 'Primary entry points for the app and the main manual testing screens.',
        'links' => [
            [
                'href' => '/',
                'label' => 'Main dashboard',
                'description' => 'Canonical app entry that redirects to the charge schedule dashboard.',
            ],
            [
                'href' => '/main/charge_schedule_mobile.php',
                'label' => 'Charge schedule page',
                'description' => 'Direct link to the main dashboard with status, prices, automation, and schedule panels.',
            ],
            [
                'href' => '/automate',
                'label' => 'Automate control page',
                'description' => 'Automation control UI for viewing available control actions without triggering them.',
            ],
            [
                'href' => '/pathlab',
                'label' => 'PathLab',
                'description' => 'Read-only prototype page showing the expected battery path for today and tomorrow.',
            ],
        ],
    ],
    [
        'title' => 'Rule And Schedule Tools',
        'description' => 'Pages and safe JSON endpoints for checking rules and resolved schedule output.',
        'links' => [
            [
                'href' => '/main/edit_rules.php',
                'label' => 'Rules editor',
                'description' => 'UI for reviewing the current rules configuration and rule structure.',
            ],
            [
                'href' => '/main/edit_rules_help.php',
                'label' => 'Rules help',
                'description' => 'Reference page describing supported rule fields, operators, and runtime metadata.',
            ],
            [
                'href' => '/main/edit_rules.php?api=1',
                'label' => 'Rules JSON',
                'description' => 'Read-only JSON output of the saved rules file used by the rules editor.',
            ],
            [
                'href' => '/main/api/charge_schedule_api.php',
                'label' => 'Schedule API (today)',
                'description' => 'Current schedule API response including stored entries and today\'s resolved schedule.',
            ],
            [
                'href' => '/main/api/charge_schedule_api.php?date=' . rawurlencode($exampleDate),
                'label' => 'Schedule API (example date)',
                'description' => 'Same schedule API for a concrete YYYYMMDD date so a specific day can be checked quickly.',
            ],
            [
                'href' => '/main/data/api/data_api.php?type=schedule&resolved=1',
                'label' => 'Resolved schedule data API',
                'description' => 'Generic data API view of stored schedule data with resolved schedule output for inspection.',
            ],
        ],
    ],
    [
        'title' => 'Status And Diagnostics APIs',
        'description' => 'Browser-safe JSON endpoints for runtime state, diagnostics, and available command help.',
        'links' => [
            [
                'href' => '/main/api/automation_status_api.php?type=all&limit=10',
                'label' => 'Automation status API',
                'description' => 'Recent automation status entries from the local JSON-backed status API.',
            ],
            [
                'href' => '/main/api/charge_status_all_proxy.php',
                'label' => 'Charge status proxy',
                'description' => 'Unified same-origin charge status payload used by the main dashboard.',
            ],
            [
                'href' => '/main/api/automation_status_proxy.php',
                'label' => 'Automation status proxy',
                'description' => 'Same-origin proxy response for the upstream automation runtime status feed.',
            ],
            [
                'href' => '/main/api/energy_graph_proxy.php',
                'label' => 'Energy graph proxy',
                'description' => 'Transformed `wh_per_hour` payload used by the energy graph and recent energy summaries.',
            ],
            [
                'href' => '/main/data/api/data_api.php?type=automation_status',
                'label' => 'Automation status data file',
                'description' => 'Generic data API view of the stored automation status JSON file.',
            ],
            [
                'href' => '/main/data/api/data_api.php?type=list',
                'label' => 'Data API type list',
                'description' => 'Lists the available generic data API types that can be requested for testing.',
            ],
            [
                'href' => '/automate/control/command.php',
                'label' => 'Command endpoint help',
                'description' => 'GET help output for the command proxy, including available commands without executing any of them.',
            ],
        ],
    ],
    [
        'title' => 'PathLab And Derived Data',
        'description' => 'Endpoints used by PathLab and related forecast or derived-data views.',
        'links' => [
            [
                'href' => '/pathlab/api/path_data.php',
                'label' => 'PathLab data API',
                'description' => 'Computed PathLab JSON payload with summary, slots, and any upstream warnings.',
            ],
            [
                'href' => '/main/api/shortwave_radiation_api.php',
                'label' => 'Shortwave radiation API',
                'description' => 'Solar forecast JSON from Open-Meteo, used for PathLab and solar-related testing.',
            ],
        ],
    ],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Zendure Dev Links</title>
    <link rel="icon" type="image/x-icon" href="../main/favicon.ico">
    <link rel="icon" type="image/png" sizes="16x16" href="../main/favicon-16x16.png">
    <link rel="icon" type="image/png" sizes="32x32" href="../main/favicon-32x32.png">
    <link rel="apple-touch-icon" href="../main/apple-touch-icon.png">
    <link rel="stylesheet" href="../main/assets/css/general_mobile.css">
    <link rel="stylesheet" href="../main/assets/css/charge_schedule_mobile.css">
    <style>
        .eyebrow {
            margin: 0 0 10px;
            font-size: 0.78rem;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: #64b5f6;
            font-weight: 700;
        }

        body.mobile-dark {
            align-items: flex-start;
        }

        body.mobile-dark .container {
            width: 100%;
            max-width: 600px;
        }

        body.mobile-dark .header,
        body.mobile-dark .card {
            max-width: 600px;
        }

        .intro-card p {
            color: var(--text-secondary);
            line-height: 1.5;
        }

        .dev-note {
            margin-top: 12px;
            padding: 10px 12px;
            border-radius: 8px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .section-header {
            margin-bottom: 14px;
        }

        .section-header .card-header {
            margin-bottom: 8px;
        }

        .section-description {
            margin: 0;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        .link-list {
            display: grid;
            gap: 8px;
        }

        .link-item {
            padding: 12px;
            border-radius: 8px;
            border: 1px solid color-mix(in srgb, var(--border-color) 82%, transparent);
            background: color-mix(in srgb, var(--bg-tertiary) 88%, #0b1222);
            transition: all 0.2s ease-out;
        }

        .link-item:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
            border-color: #555;
        }

        .link-title-link {
            color: #64b5f6;
            font-weight: 700;
            text-decoration: none;
        }

        .link-title-link:hover,
        .link-title-link:focus-visible {
            text-decoration: underline;
        }

        .link-label {
            margin: 0 0 6px;
            font-size: 0.98rem;
        }

        .link-path {
            margin: 0 0 6px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 0.82rem;
            color: var(--text-tertiary);
            word-break: break-all;
        }

        .link-description {
            margin: 0;
            color: var(--text-secondary);
            line-height: 1.5;
            font-size: 0.9rem;
        }

        .section-card {
            margin-bottom: var(--card-gap);
        }

        @media (max-width: 720px) {
            .link-item {
                padding: 10px;
            }
        }
    </style>
</head>
<body class="mobile-dark">
<div class="container">
    <div class="header">
        <h1>⚡ Zendure Energy Manager Dev Links</h1>
    </div>


    <?php foreach ($sections as $section): ?>
        <section class="card section-card">
                <div class="section-header">
                    <h2 class="card-header"><?php echo htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                    <p class="section-description"><?php echo htmlspecialchars($section['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                </div>

                <div class="link-list">
                    <?php foreach ($section['links'] as $link): ?>
                        <article class="link-item">
                            <p class="link-label">
                                <a class="link-title-link" href="<?php echo htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                            </p>
                            <p class="link-path">
                                <?php echo htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <p class="link-description"><?php echo htmlspecialchars($link['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
        </section>
    <?php endforeach; ?>
</div>
</body>
</html>
