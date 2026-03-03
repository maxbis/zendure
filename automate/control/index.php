<?php

require_once __DIR__ . '/../../login/validate.php';
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
    'logging' => [
        'title' => 'Logging',
        'description' => 'Change runtime log verbosity for troubleshooting.',
    ],
];
$groupedCommands = [];
foreach ($commandUi as $key => $cfg) {
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
    .command-btn {
      padding: 6px 12px;
      font-size: 14px;
      min-height: 34px;
    }
    .btn-warning {
      background: #68491f;
      border-color: #9b6a2a;
      color: #ffd27a;
    }
    .btn-warning:hover:not(:disabled) {
      background: #8b5f24;
      border-color: #bf7f2f;
      color: #ffe3a5;
    }
    .command-status {
      margin-top: 14px;
      font-size: 14px;
      color: var(--text-secondary);
      min-height: 1.2em;
    }
    .command-status.ok { color: #81c784; }
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
          .then(function (res) {
            return res.json()
              .catch(function () { return {}; })
              .then(function (payload) {
                if (!res.ok || payload.ok === false) {
                  var msg = payload.error || ('HTTP ' + res.status);
                  throw new Error(msg);
                }
                return payload;
              });
          })
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
    })();
  </script>
  <?php endif; ?>
</body>
</html>
