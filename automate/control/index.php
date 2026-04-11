<?php

// Validate user access
$validateFile = __DIR__ . '/../../login/validate.php';
require_once $validateFile;

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/commands.php';

$baseUrl = restartApiBaseUrl();
$hasApiBase = $baseUrl !== '';

$commandProxyUrl = 'command.php';

$commandUi = restartCommandDefinitions();
$commandGroups = [
    'process' => [
        'title' => 'Process',
        'description' => 'Lifecycle controls for the automation service.',
    ],
    'automation' => [
        'title' => 'Automation',
        'description' => 'Temporarily pause or resume schedule-driven control.',
    ],
    'slow_charge' => [
        'title' => 'Slow Charge',
        'description' => 'Preset runtime override buttons for SLOW_CHARGE_MAX_POWER.',
    ],
    'logging' => [
        'title' => 'Logging',
        'description' => 'Change runtime log verbosity for troubleshooting.',
    ],
];
$groupedCommands = [];
foreach ($commandUi as $key => $cfg) {
    if (array_key_exists('ui', $cfg) && $cfg['ui'] === false) {
        continue;
    }
    $groupKey = isset($cfg['group']) && is_string($cfg['group']) ? $cfg['group'] : 'other';
    if (!isset($groupedCommands[$groupKey])) {
        $groupedCommands[$groupKey] = [];
    }
    $groupedCommands[$groupKey][$key] = $cfg;
}
foreach ($groupedCommands as $groupKey => $commands) {
    uasort($commands, static function (array $a, array $b): int {
        $aOrder = isset($a['order']) ? (int) $a['order'] : 999;
        $bOrder = isset($b['order']) ? (int) $b['order'] : 999;
        if ($aOrder === $bOrder) {
            $aLabel = isset($a['label']) ? (string) $a['label'] : '';
            $bLabel = isset($b['label']) ? (string) $b['label'] : '';
            return strcmp($aLabel, $bLabel);
        }
        return $aOrder <=> $bOrder;
    });
    $groupedCommands[$groupKey] = $commands;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>⚡Zendure Energy Manager</title>
  <link rel="stylesheet" href="../../main/assets/css/general_mobile.css">
  <link rel="stylesheet" href="../../main/assets/css/charge_schedule_mobile.css">
  <link rel="stylesheet" href="../../main/assets/css/charge_status_defines.css">
  <style>
    body.mobile-dark { align-items: flex-start; }
    .control-wrap { width: 100%; }
    .control-subtitle {
      color: var(--text-secondary);
      margin-bottom: 10px;
      line-height: 1.4;
    }
    .control-endpoint {
      font-size: 13px;
      color: var(--text-tertiary);
      word-break: break-all;
      background: var(--bg-tertiary);
      border: 1px solid var(--border-color);
      border-radius: 8px;
      padding: 8px 10px;
      margin-top: 8px;
    }
    .command-list {
      display: grid;
      gap: 12px;
      margin-top: 14px;
    }
    .command-group {
      background: var(--bg-tertiary);
      border: 1px solid var(--border-color);
      border-radius: 10px;
      padding: 12px;
    }
    .command-group-title {
      font-size: 15px;
      font-weight: 600;
      color: var(--text-primary);
    }
    .command-group-desc {
      margin: 6px 0 12px;
      font-size: 13px;
      color: var(--text-secondary);
      line-height: 1.4;
    }
    .command-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }
    .control-form {
      display: grid;
      gap: 10px;
    }
    .control-row {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      align-items: flex-end;
    }
    .control-input-wrap {
      display: flex;
      flex-direction: column;
      gap: 6px;
      flex: 0 0 120px;
      min-width: 0;
    }
    .control-label {
      font-size: 13px;
      color: var(--text-secondary);
    }
    .control-input {
      width: 120px;
      min-height: 38px;
      padding: 8px 10px;
      font-size: 15px;
      color: var(--text-primary);
      background: var(--bg-secondary);
      border: 1px solid var(--border-color);
      border-radius: 8px;
      box-sizing: border-box;
    }
    .control-help {
      margin-top: 8px;
      font-size: 13px;
      color: var(--text-tertiary);
      line-height: 1.4;
    }
    .control-help-inline {
      margin-top: 0;
      font-size: 13px;
      color: var(--text-tertiary);
      line-height: 1.4;
      max-width: 520px;
    }
    .netzero-submit {
      align-self: flex-end;
      min-height: 38px;
      white-space: nowrap;
      min-width: 0;
    }
    @media (max-width: 820px) {
      .control-row {
        align-items: stretch;
      }
      .netzero-submit {
        flex: 1 0 100%;
        width: 100%;
      }
      .control-help-inline {
        max-width: 100%;
      }
    }
    @media (max-width: 640px) {
      .control-row {
        flex-direction: column;
        align-items: stretch;
      }
      .control-input-wrap {
        flex-basis: 100%;
      }
      .control-input {
        width: 100%;
      }
      .netzero-submit {
        width: 100%;
      }
    }
    .command-btn {
      padding: 6px 12px;
      font-size: 14px;
      min-height: 34px;
    }
    .btn-process {
      background: #8d4a1f;
      border-color: #c96e34;
      color: #fff1e8;
    }
    .btn-process:hover:not(:disabled) {
      background: #a95a24;
      border-color: #df8245;
      color: #fff7f1;
    }
    .btn-resume {
      background: #1f6f63;
      border-color: #2d9d8d;
      color: #e7fffa;
    }
    .btn-resume:hover:not(:disabled) {
      background: #278575;
      border-color: #35b09f;
      color: #f4fffd;
    }
    .btn-refresh {
      background: #2d6ea3;
      border-color: #5aa7e7;
      color: #eef8ff;
    }
    .btn-refresh:hover:not(:disabled) {
      background: #377fb9;
      border-color: #78bbf1;
      color: #f7fbff;
    }
    .btn-debug {
      background: #465f8d;
      border-color: #6988be;
      color: #eff4ff;
    }
    .btn-debug:hover:not(:disabled) {
      background: #5470a3;
      border-color: #84a1d4;
      color: #f7faff;
    }
    .btn-info {
      background: #477fba;
      border-color: #6cb0ef;
      color: #f2f9ff;
    }
    .btn-info:hover:not(:disabled) {
      background: #5692d0;
      border-color: #86c0f5;
      color: #fbfdff;
    }
    .btn-warning {
      background: #7a5618;
      border-color: #bc8525;
      color: #ffe09a;
    }
    .btn-warning:hover:not(:disabled) {
      background: #94691d;
      border-color: #d89b33;
      color: #ffebbd;
    }
    .btn-danger {
      background: #8d2926;
      border-color: #c4413d;
      color: #fff0ef;
    }
    .btn-danger:hover:not(:disabled) {
      background: #a9322f;
      border-color: #dd5955;
      color: #fff7f6;
    }
    .command-status {
      margin-top: 14px;
      font-size: 14px;
      color: var(--text-secondary);
      min-height: 1.2em;
    }
    .command-status.ok { color: #72b77b; }
    .command-status.err { color: #e57373; }
    .command-status.warn { color: #ffb74d; }

    .modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.65);
      display: none;
      align-items: center;
      justify-content: center;
      padding: 16px;
      z-index: 2000;
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
    }
    .modal-backdrop.open {
      display: flex;
      opacity: 1;
      visibility: visible;
      pointer-events: auto;
    }
    .modal {
      width: min(480px, 100%);
      background: var(--bg-secondary);
      border-radius: 12px;
      border: 1px solid var(--border-color);
      box-shadow: 0 20px 48px rgba(0, 0, 0, 0.45);
      overflow: hidden;
    }
    .modal-head {
      padding: 16px 18px 12px;
      border-bottom: 1px solid var(--border-color);
      font-size: 18px;
      font-weight: 600;
      color: var(--text-primary);
    }
    .modal-body {
      padding: 14px 18px 18px;
      color: var(--text-secondary);
      font-size: 14px;
      line-height: 1.45;
    }
    .modal-actions {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      padding: 14px 18px 16px;
      border-top: 1px solid var(--border-color);
      background: var(--bg-secondary);
    }
    .control-modal .btn { min-width: 88px; }
    .control-modal .btn-outline:hover:not(:disabled) {
      color: #64b5f6;
      border-color: #64b5f6;
    }
    .btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none;
      box-shadow: none;
    }
  </style>
</head>
<body class="mobile-dark">
  <div class="container control-wrap">
    <div class="header">
      <h1>⚡ Zendure Energy Manager</h1>
    </div>

    <div class="card">
      <h2 class="card-header card-header--no-line">Automation Commands</h2>
      <?php if ($hasApiBase): ?>
        <p class="control-subtitle">Run control actions via secure same-origin command proxy.</p>
        <p class="control-endpoint">Proxy: <?= htmlspecialchars($commandProxyUrl, ENT_QUOTES, 'UTF-8') ?></p>
        <p class="control-endpoint">API Base: <?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?></p>

        <div class="command-list">
          <?php foreach ($groupedCommands as $groupKey => $commands): ?>
            <?php
              $groupTitle = $commandGroups[$groupKey]['title'] ?? 'Other';
              $groupDescription = $commandGroups[$groupKey]['description'] ?? 'Additional control actions.';
            ?>
            <div class="command-group">
              <div class="command-group-title"><?= htmlspecialchars($groupTitle, ENT_QUOTES, 'UTF-8') ?></div>
              <div class="command-group-desc"><?= htmlspecialchars($groupDescription, ENT_QUOTES, 'UTF-8') ?></div>
              <div class="command-actions">
                <?php foreach ($commands as $key => $cfg): ?>
                  <button
                    type="button"
                    class="btn <?= htmlspecialchars($cfg['buttonClass'], ENT_QUOTES, 'UTF-8') ?> command-btn"
                    data-command="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"
                    title="<?= htmlspecialchars($cfg['description'], ENT_QUOTES, 'UTF-8') ?>"
                  >
                    <?= htmlspecialchars($cfg['label'], ENT_QUOTES, 'UTF-8') ?>
                  </button>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>

          <div class="command-group">
            <div class="command-group-title">Runtime Overrides</div>
            <div class="command-group-desc">Adjust the runtime <code>NETZERO_TARGET_W</code> target and battery charge limits used by automation.</div>
            <div class="control-form">
              <div class="control-row">
                <div class="control-input-wrap">
                  <label class="control-label" for="netzeroTargetInput">Target Watts</label>
                  <input id="netzeroTargetInput" class="control-input" type="number" step="1" inputmode="numeric" placeholder="0">
                </div>
                <button id="netzeroTargetSubmit" type="button" class="btn btn-refresh command-btn netzero-submit">Set Target</button>
              </div>
              <div class="control-help-inline">Use negative values to prefer export, positive values to prefer import, and <code>0</code> for exact netzero.</div>
              <div class="control-row">
                <div class="control-input-wrap">
                  <label class="control-label" for="minChargeLevelInput">Min Charge %</label>
                  <input id="minChargeLevelInput" class="control-input" type="number" step="1" min="0" max="100" inputmode="numeric" placeholder="15">
                </div>
                <div class="control-input-wrap">
                  <label class="control-label" for="maxChargeLevelInput">Max Charge %</label>
                  <input id="maxChargeLevelInput" class="control-input" type="number" step="1" min="0" max="100" inputmode="numeric" placeholder="93">
                </div>
                <button id="chargeLevelSubmit" type="button" class="btn btn-refresh command-btn netzero-submit">Set Charge Limits</button>
              </div>
            </div>
            <div class="control-help">Charge limits are runtime-only percentages and are normalized so min never exceeds max.</div>
          </div>
        </div>

        <p id="status" class="command-status">Waiting for command...</p>
      <?php else: ?>
        <p>Cannot run commands: <code>apiBaseUrlPiControl</code> is not configured.</p>
        <p class="command-status err">Set <code>apiBaseUrlPiControl</code> and reload this page.</p>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($hasApiBase): ?>
  <div id="confirmBackdrop" class="modal-backdrop control-modal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
    <div class="modal">
      <div class="modal-head" id="confirmTitle">Confirm Action</div>
      <div class="modal-body" id="confirmBody">Are you sure?</div>
      <div class="modal-actions">
        <button id="cancelBtn" class="btn btn-outline" type="button">Cancel</button>
        <button id="confirmBtn" class="btn btn-danger" type="button">Run</button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($hasApiBase): ?>
  <script>
    (function () {
      var statusEl = document.getElementById('status');
      var buttons = Array.prototype.slice.call(document.querySelectorAll('.command-btn'));
      var netzeroInput = document.getElementById('netzeroTargetInput');
      var netzeroSubmit = document.getElementById('netzeroTargetSubmit');
      var minChargeLevelInput = document.getElementById('minChargeLevelInput');
      var maxChargeLevelInput = document.getElementById('maxChargeLevelInput');
      var chargeLevelSubmit = document.getElementById('chargeLevelSubmit');
      var proxyUrl = <?= json_encode($commandProxyUrl, JSON_UNESCAPED_SLASHES) ?>;
      var commands = <?= json_encode($commandUi, JSON_UNESCAPED_SLASHES) ?>;

      var backdrop = document.getElementById('confirmBackdrop');
      var confirmTitle = document.getElementById('confirmTitle');
      var confirmBody = document.getElementById('confirmBody');
      var confirmBtn = document.getElementById('confirmBtn');
      var cancelBtn = document.getElementById('cancelBtn');
      var pendingCommand = null;

      function setStatus(text, kind) {
        statusEl.textContent = text;
        statusEl.classList.remove('ok', 'err', 'warn');
        if (kind) statusEl.classList.add(kind);
      }

      function setButtonsDisabled(disabled) {
        buttons.forEach(function (btn) {
          btn.disabled = !!disabled;
        });
      }

      function parseJsonResponse(res) {
        return res.json()
          .catch(function () { return {}; })
          .then(function (payload) {
            if (!res.ok || payload.ok === false) {
              var msg = payload.error || ('HTTP ' + res.status);
              throw new Error(msg);
            }
            return payload;
          });
      }

      function runCommand(commandKey) {
        var cfg = commands[commandKey];
        if (!cfg) {
          setStatus('Unknown command: ' + commandKey, 'err');
          return;
        }

        setButtonsDisabled(true);
        setStatus('Sending command: ' + cfg.label + ' ...');

        fetch(proxyUrl, {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({ command: commandKey })
        })
          .then(parseJsonResponse)
          .then(function (payload) {
            var msg = payload.message || (cfg.label + ' command completed.');
            setStatus(msg, 'ok');
          })
          .catch(function (err) {
            var msg = (err && err.message) ? String(err.message) : 'Unknown error';
            if (commandKey === 'restart' && (msg.includes('Failed to fetch') || msg.includes('NetworkError') || msg.includes('Load failed'))) {
              setStatus('Restart command sent. Connection closed while restarting (expected).', 'ok');
              return;
            }
            setStatus(cfg.label + ' failed: ' + msg, 'err');
          })
          .finally(function () {
            setButtonsDisabled(false);
          });
      }

      function loadNetzeroTarget() {
        if (!netzeroInput) {
          return;
        }
        fetch(proxyUrl + '?command=get_netzero_target_w', {
          method: 'GET',
          headers: {
            'Accept': 'application/json'
          }
        })
          .then(parseJsonResponse)
          .then(function (payload) {
            if (typeof payload.upstreamBody === 'object' && payload.upstreamBody !== null && typeof payload.upstreamBody.netzeroTargetW !== 'undefined') {
              netzeroInput.value = String(payload.upstreamBody.netzeroTargetW);
            } else if (typeof payload.netzeroTargetW !== 'undefined') {
              netzeroInput.value = String(payload.netzeroTargetW);
            }
          })
          .catch(function () {
            // Keep page usable if the initial value fetch fails.
          });
      }

      function applyChargeLevelPayload(payload) {
        if (typeof payload.upstreamBody === 'object' && payload.upstreamBody !== null) {
          payload = payload.upstreamBody;
        }
        if (minChargeLevelInput && typeof payload.minChargeLevel !== 'undefined') {
          minChargeLevelInput.value = String(payload.minChargeLevel);
        }
        if (maxChargeLevelInput && typeof payload.maxChargeLevel !== 'undefined') {
          maxChargeLevelInput.value = String(payload.maxChargeLevel);
        }
      }

      function loadChargeLevels() {
        if (!minChargeLevelInput && !maxChargeLevelInput) {
          return;
        }
        fetch(proxyUrl + '?command=get_min_charge_level', {
          method: 'GET',
          headers: {
            'Accept': 'application/json'
          }
        })
          .then(parseJsonResponse)
          .then(applyChargeLevelPayload)
          .catch(function () {
            // Keep page usable if the initial value fetch fails.
          });
      }

      function submitNetzeroTarget() {
        if (!netzeroInput || !netzeroSubmit) {
          return;
        }
        var rawValue = String(netzeroInput.value || '').trim();
        if (!/^-?\d+$/.test(rawValue)) {
          setStatus('Netzero Target failed: enter a whole number in watts.', 'err');
          return;
        }

        var value = Number(rawValue);
        setButtonsDisabled(true);
        setStatus('Sending command: Set Netzero Target ...');

        fetch(proxyUrl, {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({ command: 'set_netzero_target_w', value: value })
        })
          .then(parseJsonResponse)
          .then(function (payload) {
            if (typeof payload.upstreamBody === 'object' && payload.upstreamBody !== null && typeof payload.upstreamBody.netzeroTargetW !== 'undefined') {
              netzeroInput.value = String(payload.upstreamBody.netzeroTargetW);
            }
            setStatus(payload.message || ('NETZERO_TARGET_W set to ' + value + ' W'), 'ok');
          })
          .catch(function (err) {
            var msg = (err && err.message) ? String(err.message) : 'Unknown error';
            setStatus('Set Netzero Target failed: ' + msg, 'err');
          })
          .finally(function () {
            setButtonsDisabled(false);
          });
      }

      function parseWholePercent(rawValue) {
        var trimmed = String(rawValue || '').trim();
        if (!/^-?\d+$/.test(trimmed)) {
          return null;
        }
        return Number(trimmed);
      }

      function submitChargeLevel(commandKey, inputEl, label) {
        if (!inputEl || !chargeLevelSubmit) {
          return;
        }
        var value = parseWholePercent(inputEl.value);
        if (value === null) {
          setStatus(label + ' failed: enter a whole number percentage.', 'err');
          return;
        }

        setButtonsDisabled(true);
        setStatus('Sending command: ' + label + ' ...');

        fetch(proxyUrl, {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({ command: commandKey, value: value })
        })
          .then(parseJsonResponse)
          .then(function (payload) {
            applyChargeLevelPayload(payload);
            setStatus(payload.message || (label + ' updated.'), 'ok');
          })
          .catch(function (err) {
            var msg = (err && err.message) ? String(err.message) : 'Unknown error';
            setStatus(label + ' failed: ' + msg, 'err');
          })
          .finally(function () {
            setButtonsDisabled(false);
          });
      }

      function submitChargeLimits() {
        if (!minChargeLevelInput || !maxChargeLevelInput || !chargeLevelSubmit) {
          return;
        }
        var minValue = parseWholePercent(minChargeLevelInput.value);
        var maxValue = parseWholePercent(maxChargeLevelInput.value);
        if (minValue === null || maxValue === null) {
          setStatus('Set Charge Limits failed: enter whole number percentages for min and max.', 'err');
          return;
        }

        var normalizedMin = minValue;
        var normalizedMax = maxValue;
        if (normalizedMin > normalizedMax) {
          normalizedMax = normalizedMin;
        }

        setButtonsDisabled(true);
        setStatus('Sending command: Set Charge Limits ...');

        fetch(proxyUrl, {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({ command: 'set_min_charge_level', value: normalizedMin })
        })
          .then(parseJsonResponse)
          .then(function () {
            return fetch(proxyUrl, {
              method: 'POST',
              headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json'
              },
              body: JSON.stringify({ command: 'set_max_charge_level', value: normalizedMax })
            });
          })
          .then(parseJsonResponse)
          .then(function (payload) {
            applyChargeLevelPayload(payload);
            setStatus(payload.message || ('Charge limits set to MIN=' + normalizedMin + '% MAX=' + normalizedMax + '%'), 'ok');
          })
          .catch(function (err) {
            var msg = (err && err.message) ? String(err.message) : 'Unknown error';
            setStatus('Set Charge Limits failed: ' + msg, 'err');
          })
          .finally(function () {
            setButtonsDisabled(false);
          });
      }

      function maybeConfirmAndRun(commandKey) {
        var cfg = commands[commandKey];
        if (!cfg) return;

        var needsConfirm = cfg.confirmTitle && cfg.confirmBody && cfg.confirmAction;
        if (!needsConfirm) {
          runCommand(commandKey);
          return;
        }

        pendingCommand = commandKey;
        confirmTitle.textContent = cfg.confirmTitle;
        confirmBody.textContent = cfg.confirmBody;
        confirmBtn.textContent = cfg.confirmAction;
        backdrop.classList.add('open');
      }

      buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
          var commandKey = this.getAttribute('data-command') || '';
          maybeConfirmAndRun(commandKey);
        });
      });

      if (netzeroSubmit) {
        netzeroSubmit.addEventListener('click', submitNetzeroTarget);
      }
      if (chargeLevelSubmit) {
        chargeLevelSubmit.addEventListener('click', submitChargeLimits);
      }
      if (netzeroInput) {
        netzeroInput.addEventListener('keydown', function (event) {
          if (event.key === 'Enter') {
            event.preventDefault();
            submitNetzeroTarget();
          }
        });
      }
      if (minChargeLevelInput) {
        minChargeLevelInput.addEventListener('keydown', function (event) {
          if (event.key === 'Enter') {
            event.preventDefault();
            submitChargeLevel('set_min_charge_level', minChargeLevelInput, 'Set Min Charge Level');
          }
        });
      }
      if (maxChargeLevelInput) {
        maxChargeLevelInput.addEventListener('keydown', function (event) {
          if (event.key === 'Enter') {
            event.preventDefault();
            submitChargeLevel('set_max_charge_level', maxChargeLevelInput, 'Set Max Charge Level');
          }
        });
      }

      cancelBtn.addEventListener('click', function () {
        pendingCommand = null;
        backdrop.classList.remove('open');
        setStatus('Command canceled.', 'warn');
      });

      confirmBtn.addEventListener('click', function () {
        if (!pendingCommand) {
          backdrop.classList.remove('open');
          return;
        }
        var cmd = pendingCommand;
        pendingCommand = null;
        backdrop.classList.remove('open');
        runCommand(cmd);
      });

      loadNetzeroTarget();
      loadChargeLevels();
    })();
  </script>
  <?php endif; ?>
</body>
</html>
