<?php
declare(strict_types=1);

/**
 * Load KEY=VALUE lines from daily_report/.env into the process environment.
 * Does not override variables already set in the environment.
 */
function daily_report_bootstrap_env(): void
{
    $path = __DIR__ . DIRECTORY_SEPARATOR . '.env';
    if (!is_readable($path)) {
        return;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return;
    }
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
        $raw = substr($raw, 3);
    }

    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if ($line === '' || ($line[0] ?? '') === '#') {
            continue;
        }
        if (strncmp($line, 'export ', 7) === 0) {
            $line = trim(substr($line, 7));
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $name = trim(substr($line, 0, $eq));
        if ($name === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            continue;
        }
        $value = substr($line, $eq + 1);
        $value = trim($value);
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $quote = $value[0];
            $len = strlen($value);
            if ($len >= 2 && $value[$len - 1] === $quote) {
                $value = substr($value, 1, -1);
            }
        }
        if (getenv($name) !== false) {
            continue;
        }
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}
