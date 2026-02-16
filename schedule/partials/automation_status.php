<?php
/**
 * Automation Status Partial
 * Displays automation status entries with collapsible functionality.
 *
 * The list (error / empty / entries) is rendered by JavaScript, not PHP.
 * PHP only outputs the section shell and the API URL for JS.
 *
 * Where JS injects: below the header, inside .metric-section.
 * JS code: schedule/assets/js/schedule_renderer.js — function renderAutomationStatus()
 * JS trigger: schedule/assets/js/charge_status.js — refreshStatus() (on load via startAutoRefresh() and on Refresh click)
 */
?>
<!-- Automation Status Section -->
<div class="card">
    <div class="metric-section">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <h3 class="card-header">🤖 Automation Status</h3>
            <button class="automation-refresh-btn no-select" id="automation-refresh-btn" title="Refresh (hold for full reload)">
                <span class="refresh-icon">↻</span>
                <span class="refresh-text">Refresh</span>
            </button>
        </div>
        <?php
        // Ensure ConfigLoader is available
        if (!class_exists('ConfigLoader')) {
            require_once __DIR__ . '/../includes/config_loader.php';
        }

        // Build API URL for JavaScript (JS fetches and renders the list)
        // Use same-origin proxy to avoid CORS.
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $basePath = dirname($scriptName);
        $automationStatusUrl = $scheme . '://' . $host . $basePath . '/api/automation_status_proxy.php?type=all&limit=20';

        echo '<script>const AUTOMATION_STATUS_API_URL = ' . json_encode($automationStatusUrl, JSON_UNESCAPED_SLASHES) . ';</script>';
        ?>
        <!--
            JS injection point: content below is injected by JavaScript.
            JS appends one of: #automation-status-error, #automation-status-empty, or #automation-entries-wrapper.
            See: schedule/assets/js/schedule_renderer.js — renderAutomationStatus()
            Called from: schedule/assets/js/charge_status.js — refreshStatus() (on load via startAutoRefresh() and on Refresh click)
        -->
    </div>
</div>
