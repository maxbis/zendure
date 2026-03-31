<?php
// Validate user access
$validateFile = __DIR__ . '/../../login/validate.php';
require_once $validateFile;

$configPath = __DIR__ . '/config.json';
$statusMessage = '';
$statusClass = '';

/**
 * Parse submitted value based on original value type.
 * Returns array [bool $ok, mixed $value, string $error]
 */
function parseSubmittedValue(string $raw, $original): array
{
    if (is_bool($original)) {
        $normalized = strtolower(trim($raw));
        $truthy = ['1', 'true', 'yes', 'on'];
        $falsy = ['0', 'false', 'no', 'off'];

        if (in_array($normalized, $truthy, true)) {
            return [true, true, ''];
        }
        if (in_array($normalized, $falsy, true)) {
            return [true, false, ''];
        }

        return [false, null, 'Expected a boolean (true/false).'];
    }

    if (is_int($original)) {
        $trimmed = trim($raw);
        if ($trimmed === '' || !preg_match('/^-?\d+$/', $trimmed)) {
            return [false, null, 'Expected an integer value.'];
        }

        return [true, (int) $trimmed, ''];
    }

    if (is_float($original)) {
        $trimmed = trim($raw);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return [false, null, 'Expected a numeric value.'];
        }

        return [true, (float) $trimmed, ''];
    }

    if (is_array($original) || is_object($original)) {
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [false, null, 'Expected valid JSON for array/object value.'];
        }

        return [true, $decoded, ''];
    }

    if ($original === null) {
        $trimmed = trim($raw);
        if ($trimmed === '' || strtolower($trimmed) === 'null') {
            return [true, null, ''];
        }
        return [false, null, 'Expected null (leave empty or use "null").'];
    }

    return [true, $raw, ''];
}

if (!is_file($configPath)) {
    http_response_code(500);
    echo 'Config file not found: ' . htmlspecialchars($configPath, ENT_QUOTES, 'UTF-8');
    exit;
}

$configRaw = @file_get_contents($configPath);
if ($configRaw === false) {
    http_response_code(500);
    echo 'Unable to read config file.';
    exit;
}

$config = json_decode($configRaw, true);
if (!is_array($config)) {
    http_response_code(500);
    echo 'Config file contains invalid JSON.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = isset($_POST['config']) && is_array($_POST['config']) ? $_POST['config'] : [];
    $updated = [];
    $errors = [];

    foreach ($config as $key => $originalValue) {
        $submitted = isset($input[$key]) ? (string) $input[$key] : '';
        [$ok, $parsed, $error] = parseSubmittedValue($submitted, $originalValue);

        if (!$ok) {
            $errors[] = $key . ': ' . $error;
            continue;
        }

        $updated[$key] = $parsed;
    }

    if ($errors === []) {
        $encoded = json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            $statusMessage = 'Failed to encode JSON: ' . json_last_error_msg();
            $statusClass = 'err';
        } elseif (@file_put_contents($configPath, $encoded . PHP_EOL) === false) {
            $statusMessage = 'Failed to write config file.';
            $statusClass = 'err';
        } else {
            $statusMessage = 'Config saved successfully.';
            $statusClass = 'ok';
            $config = $updated;
        }
    } else {
        $statusMessage = 'Could not save config. ' . implode(' ', $errors);
        $statusClass = 'err';

        // Keep user-submitted values visible after validation errors.
        foreach ($config as $key => $originalValue) {
            if (array_key_exists($key, $input)) {
                if (is_array($originalValue) || is_object($originalValue)) {
                    $candidate = json_decode((string) $input[$key], true);
                    $config[$key] = json_last_error() === JSON_ERROR_NONE ? $candidate : $originalValue;
                } elseif (is_int($originalValue)) {
                    $config[$key] = is_numeric($input[$key]) ? (int) $input[$key] : $originalValue;
                } elseif (is_float($originalValue)) {
                    $config[$key] = is_numeric($input[$key]) ? (float) $input[$key] : $originalValue;
                } elseif (is_bool($originalValue)) {
                    $norm = strtolower(trim((string) $input[$key]));
                    if (in_array($norm, ['1', 'true', 'yes', 'on'], true)) {
                        $config[$key] = true;
                    } elseif (in_array($norm, ['0', 'false', 'no', 'off'], true)) {
                        $config[$key] = false;
                    }
                } else {
                    $config[$key] = (string) $input[$key];
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>⚡Zendure Energy Manager</title>
  <link rel="stylesheet" href="../assets/css/general_mobile.css">
  <link rel="stylesheet" href="../assets/css/charge_schedule_mobile.css">
  <link rel="stylesheet" href="../assets/css/charge_status_defines.css">
  <style>
    body.mobile-dark {
      align-items: flex-start;
    }
    .config-wrap {
      width: 100%;
    }
    .config-subtitle {
      color: var(--text-secondary);
      margin-bottom: 10px;
      line-height: 1.4;
      font-size: 14px;
    }
    .config-file {
      font-size: 12px;
      color: var(--text-tertiary);
      background: var(--bg-tertiary);
      border: 1px solid var(--border-color);
      border-radius: 8px;
      padding: 8px 10px;
      margin-bottom: 12px;
      word-break: break-all;
    }
    .config-status {
      margin-bottom: 12px;
      font-size: 14px;
      color: var(--text-secondary);
    }
    .config-status.ok { color: #81c784; }
    .config-status.err { color: #e57373; }
    .config-grid {
      display: grid;
      gap: 10px;
    }
    .config-item {
      border: 1px solid var(--border-color);
      border-radius: 8px;
      background: var(--bg-tertiary);
      padding: 10px;
    }
    .config-key {
      font-family: monospace;
      font-size: 13px;
      color: var(--text-primary);
      margin-bottom: 6px;
      font-weight: 600;
      word-break: break-word;
    }
    .config-type {
      font-size: 12px;
      color: var(--text-tertiary);
      margin-bottom: 8px;
    }
    .config-input,
    .config-textarea {
      width: 100%;
      background: #181818;
      color: var(--text-secondary);
      border: 1px solid var(--border-color);
      border-radius: 8px;
      padding: 8px 10px;
      font-size: 14px;
    }
    .config-textarea {
      min-height: 88px;
      resize: vertical;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      line-height: 1.35;
    }
    .config-input:focus,
    .config-textarea:focus {
      outline: none;
      border-color: #64b5f6;
      box-shadow: 0 0 0 1px rgba(100, 181, 246, 0.35);
    }
    .actions {
      margin-top: 14px;
      display: flex;
      justify-content: flex-end;
      gap: 10px;
    }
    .actions form,
    .actions a {
      margin: 0;
    }
    @media (max-width: 600px) {
      .actions {
        justify-content: stretch;
      }
      .actions .btn {
        flex: 1;
      }
    }
  </style>
</head>
<body class="mobile-dark">
  <div class="container config-wrap">
    <div class="header">
      <h1>⚡ Zendure Energy Manager</h1>
    </div>

    <div class="card">
      <h2 class="card-header">Edit Config</h2>
      <p class="config-subtitle">Edit current config values and save as valid JSON. NETZERO_TARGET_W shifts the dynamic grid target: try -10 to prefer slight export, use positive values to prefer slight import, and 0 to keep exact netzero.</p>
      <p class="config-file"><?= htmlspecialchars($configPath, ENT_QUOTES, 'UTF-8') ?></p>

      <?php if ($statusMessage !== ''): ?>
        <p class="config-status <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusMessage, ENT_QUOTES, 'UTF-8') ?></p>
      <?php endif; ?>

      <form method="post" action="">
        <div class="config-grid">
          <?php foreach ($config as $key => $value): ?>
            <?php
              $type = gettype($value);
              $isJsonType = is_array($value) || is_object($value);
              if ($isJsonType) {
                  $displayValue = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
              } elseif (is_bool($value)) {
                  $displayValue = $value ? 'true' : 'false';
              } elseif ($value === null) {
                  $displayValue = 'null';
              } else {
                  $displayValue = (string) $value;
              }
            ?>
            <div class="config-item">
              <div class="config-key"><?= htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') ?></div>
              <div class="config-type">Type: <?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?></div>

              <?php if ($isJsonType): ?>
                <textarea class="config-textarea" name="config[<?= htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') ?>]" spellcheck="false"><?= htmlspecialchars((string) $displayValue, ENT_QUOTES, 'UTF-8') ?></textarea>
              <?php else: ?>
                <input class="config-input" type="text" name="config[<?= htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') ?>]" value="<?= htmlspecialchars((string) $displayValue, ENT_QUOTES, 'UTF-8') ?>">
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="actions">
          <a class="btn btn-outline" href="/main">Cancel</a>
          <button class="btn btn-primary" type="submit">Save</button>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
