<?php

declare(strict_types=1);

require_once __DIR__ . '/../login/validate.php';
require_once __DIR__ . '/../common/php/system_config.php';
require_once __DIR__ . '/includes/system_configuration_fields.php';

$systemConfig = loadSystemConfig();
date_default_timezone_set($systemConfig['installation']['timezone']);
$groups = systemConfigurationFieldGroups($systemConfig);
$fieldCount = array_sum(array_map(
    static fn (array $group): int => count($group['fields']),
    $groups
));

function configurationEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function configurationNumber(mixed $value, int $maximumDecimals = 6): string
{
    if (!is_numeric($value)) {
        return (string) $value;
    }
    $formatted = number_format((float) $value, $maximumDecimals, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
}

function configurationDisplayValue(array $field, mixed $value = null): string
{
    $value ??= $field['value'];
    $type = $field['type'];
    if ($type === 'boolean') {
        return $value ? 'Enabled' : 'Disabled';
    }
    if ($type === 'ratio') {
        return configurationNumber((float) $value * 100, 2) . '%';
    }
    if ($type === 'currency_rate') {
        return '€' . configurationNumber($value, 6) . '/kWh';
    }
    if ($type === 'percent') {
        return configurationNumber($value, 2) . '%';
    }
    if ($type === 'percentile') {
        return 'P' . configurationNumber($value, 2);
    }
    if ($type === 'coordinate') {
        return configurationNumber($value, 6) . '°';
    }
    if ($type === 'number') {
        $formatted = configurationNumber($value, 6);
        return $field['unit'] === '×' ? $formatted . '×' : $formatted;
    }
    if (in_array($type, ['integer', 'power', 'signed_power'], true)) {
        return number_format((float) $value, 0, '.', ',')
            . ($field['unit'] !== null ? ' ' . $field['unit'] : '');
    }
    return (string) $value;
}

function configurationEditabilityLabel(string $editability): string
{
    return match ($editability) {
        'advanced' => 'Advanced later',
        'protected' => 'Protected',
        'linked' => 'Linked value',
        default => 'Editable later',
    };
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0d0f12">
    <title>System configuration · Zendure</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/icons/app-icon-32.png">
    <link rel="stylesheet" href="../themes/graphite-signal-dark/assets/css/theme.css">
    <link rel="stylesheet" href="../themes/graphite-signal-dark/assets/css/components.css">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/configuration.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/configuration.css'); ?>">
    <script src="assets/js/configuration-catalog.js?v=<?= (int) filemtime(__DIR__ . '/assets/js/configuration-catalog.js'); ?>" defer></script>
</head>
<body data-theme="graphite-signal-dark">
    <main class="app-shell configuration-shell" data-component="configuration-catalog">
        <header class="app-topbar configuration-topbar">
            <div class="app-brand">
                <span class="app-brand__mark" aria-hidden="true"><img src="assets/icons/app-icon-180.png" alt=""></span>
                <div class="app-brand__copy">
                    <h1 class="app-brand__title">System configuration</h1>
                    <p class="app-brand__meta"><?= $fieldCount ?> webserver parameters · read-only preview</p>
                </div>
            </div>
            <div class="app-topbar__actions">
                <a class="gsd-btn gsd-btn--secondary" href="optimizer.php">Optimizer</a>
                <a class="gsd-btn gsd-btn--secondary" href="./">Live app</a>
            </div>
        </header>

        <section class="configuration-intro" aria-labelledby="configuration-intro-title">
            <svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#info"></use></svg>
            <div>
                <strong id="configuration-intro-title">Read-only, edit-ready catalog</strong>
                <p>These values are stored on the webserver. Planned editability, constraints, effects, and recommended defaults are already defined for a later editing phase.</p>
            </div>
        </section>

        <section class="configuration-exclusion" aria-label="Excluded automation settings">
            <div>
                <span class="configuration-eyebrow">Intentionally excluded</span>
                <strong>Pi automation safety settings</strong>
                <p>Minimum and maximum SoC, physical charge and discharge caps, installation timezone, and the schema version remain outside this webserver settings page.</p>
            </div>
            <span class="gsd-badge gsd-badge--warning">Pi-owned</span>
        </section>

        <section class="gsd-card configuration-toolbar" aria-label="Filter configuration">
            <label class="gsd-field">
                <span class="gsd-field__label">Find a parameter</span>
                <input class="gsd-input" type="search" placeholder="Search label, path, explanation, or effect" data-role="configuration-filter" autocomplete="off">
            </label>
            <p><strong data-role="visible-count"><?= $fieldCount ?></strong> of <?= $fieldCount ?> parameters shown</p>
        </section>

        <div class="configuration-empty" data-role="empty-state" hidden>
            <strong>No matching parameters</strong>
            <p>Try a broader term or clear the search field.</p>
        </div>

        <div class="configuration-groups">
            <?php foreach ($groups as $group): ?>
                <details
                    class="gsd-card configuration-group"
                    data-configuration-group
                    <?= $group['open'] ? 'open' : '' ?>
                >
                    <summary>
                        <span>
                            <strong><?= configurationEscape($group['title']) ?></strong>
                            <small><?= configurationEscape($group['description']) ?></small>
                        </span>
                        <span class="configuration-group__count" data-role="group-count"><?= count($group['fields']) ?></span>
                    </summary>
                    <div class="configuration-group__body">
                        <div class="configuration-fields">
                            <?php foreach ($group['fields'] as $fieldIndex => $field): ?>
                                <?php
                                $tooltipId = 'configuration-tip-' . preg_replace(
                                    '/[^a-z0-9]+/',
                                    '-',
                                    strtolower($group['id'] . '-' . $fieldIndex . '-' . $field['path'])
                                );
                                $searchText = implode(' ', array_filter([
                                    $field['label'],
                                    $field['path'],
                                    $field['description'],
                                    $field['constraint'],
                                    $field['effect'],
                                    $field['recommended_note'],
                                ]));
                                ?>
                                <article
                                    class="configuration-field"
                                    data-configuration-field
                                    data-search-text="<?= configurationEscape(strtolower($searchText)) ?>"
                                >
                                    <header class="configuration-field__header">
                                        <div>
                                            <span class="configuration-field__label"><?= configurationEscape($field['label']) ?></span>
                                            <code><?= configurationEscape($field['path']) ?></code>
                                        </div>
                                        <span class="configuration-tooltip">
                                            <button
                                                class="configuration-tooltip__trigger"
                                                type="button"
                                                aria-label="Explain <?= configurationEscape($field['label']) ?>"
                                                aria-describedby="<?= configurationEscape($tooltipId) ?>"
                                            >i</button>
                                            <span class="configuration-tooltip__content" id="<?= configurationEscape($tooltipId) ?>" role="tooltip">
                                                <strong><?= configurationEscape($field['label']) ?></strong>
                                                <span><?= configurationEscape($field['description']) ?></span>
                                            </span>
                                        </span>
                                    </header>

                                    <div class="configuration-field__value-row">
                                        <strong class="configuration-field__value"><?= configurationEscape(configurationDisplayValue($field)) ?></strong>
                                        <span class="configuration-editability configuration-editability--<?= configurationEscape($field['editability']) ?>">
                                            <?= configurationEscape(configurationEditabilityLabel($field['editability'])) ?>
                                        </span>
                                    </div>

                                    <dl class="configuration-field__metadata">
                                        <?php if ($field['recommended'] !== null): ?>
                                            <div>
                                                <dt>Recommended default</dt>
                                                <dd><?= configurationEscape(configurationDisplayValue($field, $field['recommended'])) ?></dd>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($field['recommended_note'] !== null): ?>
                                            <div class="configuration-field__wide">
                                                <dt>Recommendation</dt>
                                                <dd><?= configurationEscape($field['recommended_note']) ?></dd>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($field['constraint'] !== null): ?>
                                            <div class="configuration-field__wide">
                                                <dt>Constraint</dt>
                                                <dd><?= configurationEscape($field['constraint']) ?></dd>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($field['effect'] !== null): ?>
                                            <div class="configuration-field__wide">
                                                <dt>Effect</dt>
                                                <dd><?= configurationEscape($field['effect']) ?></dd>
                                            </div>
                                        <?php endif; ?>
                                    </dl>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>

        <footer class="configuration-footer">
            <p>Source: <code>common/config/system.json</code>. This page does not write configuration.</p>
            <a href="docs.php?doc=common%2Fconfig%2Fsystem-configuration.md">Configuration documentation</a>
        </footer>
    </main>
</body>
</html>

