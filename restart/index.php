<?php
// Validate user access
$validateFile = __DIR__ . '/../login/validate.php';
require_once $validateFile;

require_once __DIR__ . '/../main/includes/config_loader.php';

$baseUrl = ConfigLoader::get('apiBaseUrlPiControl', '');
$baseUrl = is_string($baseUrl) ? trim($baseUrl) : '';
$restartApiUrl = rtrim($baseUrl, '/') . '/api/restart';
$hasApiBase = $baseUrl !== '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>⚡Zendure Energy Manager</title>
  <link rel="stylesheet" href="../main/assets/css/general_mobile.css">
  <link rel="stylesheet" href="../main/assets/css/charge_schedule_mobile.css">
  <link rel="stylesheet" href="../main/assets/css/charge_status_defines.css">
  <style>
    body.mobile-dark {
      align-items: flex-start;
    }
    .restart-wrap {
      width: 100%;
    }
    .restart-subtitle {
      color: var(--text-secondary);
      margin-bottom: 10px;
      line-height: 1.4;
    }
    .restart-endpoint {
      font-size: 13px;
      color: var(--text-tertiary);
      word-break: break-all;
      background: var(--bg-tertiary);
      border: 1px solid var(--border-color);
      border-radius: 8px;
      padding: 8px 10px;
      margin-top: 8px;
    }
    .restart-status {
      margin-top: 12px;
      font-size: 14px;
      color: var(--text-secondary);
    }
    .restart-status.ok { color: #81c784; }
    .restart-status.err { color: #e57373; }
    .restart-status.warn { color: #ffb74d; }

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
    .restart-modal .btn {
      min-width: 88px;
    }
    .restart-modal .btn-danger {
      background: #702525;
    }
    .restart-modal .btn-danger:hover:not(:disabled) {
      background: #e53935;
    }
    .restart-modal .btn-outline:hover:not(:disabled) {
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
  <div class="container restart-wrap">
    <div class="header">
      <h1>⚡ Zendure Energy Manager</h1>
    </div>
    <div class="card">
    <h2 class="card-header card-header--no-line">Restart Pi Control</h2>
    <?php if ($hasApiBase): ?>
      <p class="restart-subtitle">Request a restart of the automation process.</p>
      <p class="restart-endpoint"><?= htmlspecialchars($restartApiUrl, ENT_QUOTES, 'UTF-8') ?></p>
      <p id="status" class="restart-status">Waiting for confirmation...</p>
    <?php else: ?>
      <p>Cannot restart: <code>apiBaseUrlPiControl</code> is not configured.</p>
      <p class="restart-status err">Set <code>apiBaseUrlPiControl</code> and reload this page.</p>
    <?php endif; ?>
    </div>
  </div>

  <?php if ($hasApiBase): ?>
  <div id="confirmBackdrop" class="modal-backdrop open restart-modal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
    <div class="modal">
      <div class="modal-head" id="confirmTitle">Confirm Restart</div>
      <div class="modal-body">
        This will restart the Pi Control automation process immediately.
        Active control will stop briefly during restart.
      </div>
      <div class="modal-actions">
        <button id="cancelBtn" class="btn btn-outline" type="button">Cancel</button>
        <button id="restartBtn" class="btn btn-danger" type="button">Restart</button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($hasApiBase): ?>
  <script>
    (function () {
      var statusEl = document.getElementById('status');
      var backdrop = document.getElementById('confirmBackdrop');
      var cancelBtn = document.getElementById('cancelBtn');
      var restartBtn = document.getElementById('restartBtn');
      var restartUrl = <?= json_encode($restartApiUrl, JSON_UNESCAPED_SLASHES) ?>;

      function setStatus(text, kind) {
        statusEl.textContent = text;
        statusEl.classList.remove('ok', 'err', 'warn');
        if (kind) {
          statusEl.classList.add(kind);
        }
      }

      cancelBtn.addEventListener('click', function () {
        backdrop.classList.remove('open');
        setStatus('Restart canceled.', 'warn');
      });

      restartBtn.addEventListener('click', function () {
        restartBtn.disabled = true;
        cancelBtn.disabled = true;
        setStatus('Restart requested...');

        fetch(restartUrl, {
          method: 'POST',
          headers: { 'Accept': 'application/json' }
        })
        .then(function (res) {
          if (!res.ok) {
            throw new Error('HTTP ' + res.status);
          }
          return res.json().catch(function () { return {}; });
        })
        .then(function () {
          backdrop.classList.remove('open');
          setStatus('Restart requested successfully.', 'ok');
        })
        .catch(function (err) {
          backdrop.classList.remove('open');
          var msg = (err && err.message) ? String(err.message) : '';
          // During restart, the API socket can close before fetch resolves.
          // Treat that transport error as expected restart-in-progress behavior.
          if (msg.includes('Failed to fetch') || msg.includes('NetworkError') || msg.includes('Load failed')) {
            setStatus('Restart requested. Connection closed while restarting (expected).', 'ok');
            return;
          }
          setStatus('Restart request failed: ' + msg, 'err');
        });
      });
    })();
  </script>
  <?php endif; ?>
</body>
</html>
