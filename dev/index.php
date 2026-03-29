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
    <style>
        :root {
            --bg: #f4efe6;
            --panel: #fffaf2;
            --panel-strong: #fffdf8;
            --text: #1f2933;
            --muted: #52606d;
            --line: #d7c7af;
            --accent: #b35c2e;
            --accent-soft: #fff1e2;
            --shadow: 0 18px 40px rgba(73, 47, 24, 0.10);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Segoe UI", Arial, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(255, 240, 217, 0.9), transparent 36%),
                linear-gradient(180deg, #f8f1e7 0%, #f2eadf 52%, #ede3d5 100%);
            color: var(--text);
        }

        main {
            width: min(1080px, calc(100% - 32px));
            margin: 28px auto 40px;
        }

        .hero,
        .section-card {
            background: color-mix(in srgb, var(--panel) 88%, white);
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: var(--shadow);
        }

        .hero {
            padding: 28px;
            margin-bottom: 18px;
        }

        .eyebrow {
            margin: 0 0 10px;
            font-size: 0.78rem;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--accent);
            font-weight: 700;
        }

        h1 {
            margin: 0 0 10px;
            font-size: clamp(2rem, 4vw, 3.2rem);
            line-height: 1;
        }

        .hero-text {
            margin: 0;
            max-width: 760px;
            color: var(--muted);
            line-height: 1.55;
            font-size: 1rem;
        }

        .note {
            margin-top: 18px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 999px;
            background: var(--accent-soft);
            border: 1px solid #e9c8a5;
            color: #6f3b1c;
            font-size: 0.94rem;
        }

        .sections {
            display: grid;
            gap: 16px;
        }

        .section-card {
            padding: 22px;
        }

        .section-header {
            margin-bottom: 16px;
        }

        h2 {
            margin: 0 0 6px;
            font-size: 1.25rem;
        }

        .section-description {
            margin: 0;
            color: var(--muted);
            line-height: 1.5;
        }

        .link-list {
            display: grid;
            gap: 12px;
        }

        .link-item {
            padding: 14px 16px;
            border-radius: 14px;
            background: var(--panel-strong);
            border: 1px solid #eadcc9;
        }

        .link-title-link {
            color: var(--accent);
            font-weight: 700;
            text-decoration: none;
        }

        .link-title-link:hover,
        .link-title-link:focus-visible {
            text-decoration: underline;
        }

        .link-label {
            margin: 0 0 6px;
            font-size: 1rem;
        }

        .link-path {
            margin: 0 0 6px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 0.92rem;
            color: var(--muted);
            word-break: break-all;
        }

        .link-description {
            margin: 0;
            color: var(--muted);
            line-height: 1.5;
        }

        @media (max-width: 720px) {
            main {
                width: min(100% - 20px, 1080px);
                margin: 12px auto 24px;
            }

            .hero,
            .section-card {
                border-radius: 14px;
            }

            .hero,
            .section-card {
                padding: 16px;
            }

            .note {
                display: block;
                border-radius: 14px;
            }
        }
    </style>
</head>
<body>
<main>
    <section class="hero">
        <p class="eyebrow">Dev - Test Links</p>
    </section>

    <div class="sections">
        <?php foreach ($sections as $section): ?>
            <section class="section-card">
                <div class="section-header">
                    <h2><?php echo htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
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
</main>
</body>
</html>
