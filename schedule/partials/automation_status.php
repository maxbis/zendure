<?php
/**
 * Automation Status Partial
 * Displays automation status entries with collapsible functionality
 * 
 * Fetches data from automation_status_api.php and displays entries.
 * Helper functions (formatRelativeTime, formatAutomationEntryDetails, etc.) 
 * are available via helpers.php included in the parent file.
 */
?>
<!-- Automation Status Section -->
<div class="card">
    <div class="metric-section">
        <h3>🤖 Automation Status</h3>
        <?php
        // Fetch automation status from API
        $automationStatusData = null;
        $automationStatusError = null;
        
        // Build HTTP URL to the API endpoint
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        // Get base path: remove /schedule/charge_schedule.php to get /Energy or root
        $basePath = dirname($scriptName);
        $automationStatusUrl = $scheme . '://' . $host . $basePath . '/api/automation_status_api.php?type=all&limit=20';
        
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'ignore_errors' => true,
                    'method' => 'GET',
                    'header' => 'User-Agent: Charge-Schedule-Page'
                ]
            ]);
            
            $jsonData = @file_get_contents($automationStatusUrl, false, $context);
            
            if ($jsonData === false || empty($jsonData)) {
                // Try alternative: direct file path (for local file access)
                $apiFilePath = __DIR__ . '/../api/automation_status_api.php';
                if (file_exists($apiFilePath)) {
                    // Temporarily set GET parameters and capture output
                    $originalGet = $_GET;
                    $_GET['type'] = 'all';
                    $_GET['limit'] = '20';
                    
                    ob_start();
                    include $apiFilePath;
                    $jsonData = ob_get_clean();
                    
                    $_GET = $originalGet;
                }
            }
            
            if (!empty($jsonData)) {
                $automationStatusData = json_decode($jsonData, true);
                if ($automationStatusData && isset($automationStatusData['success']) && $automationStatusData['success']) {
                    // Success
                    $automationStatusError = null;
                } else {
                    $errorMsg = isset($automationStatusData['error']) ? $automationStatusData['error'] : 'Unknown error';
                    $automationStatusError = 'Failed to load automation status: ' . htmlspecialchars($errorMsg);
                }
            } else {
                // Debug: show what we tried
                $debugInfo = 'Tried URL: ' . htmlspecialchars($automationStatusUrl);
                if (isset($apiFilePath)) {
                    $debugInfo .= ' | Fallback file: ' . htmlspecialchars($apiFilePath) . ' (exists: ' . (file_exists($apiFilePath) ? 'yes' : 'no') . ')';
                }
                $automationStatusError = 'Automation status unavailable (no data returned from API). ' . $debugInfo;
            }
        } catch (Exception $e) {
            $automationStatusError = 'Automation status unavailable: ' . htmlspecialchars($e->getMessage()) . ' | URL tried: ' . htmlspecialchars($automationStatusUrl ?? 'unknown');
        }
        
        if ($automationStatusError):
        ?>
            <div class="automation-status-error">
                <p><?php echo htmlspecialchars($automationStatusError); ?></p>
            </div>
        <?php elseif ($automationStatusData && isset($automationStatusData['lastChanges'])): 
            $entries = $automationStatusData['lastChanges'];
            $lastUpdate = $automationStatusData['lastUpdate'] ?? null;
        ?>
            <?php if ($lastUpdate): ?>
                <div class="automation-status-header">
                    <span class="automation-last-update">
                        Last update: <?php echo htmlspecialchars(formatRelativeTime($lastUpdate)); ?>
                        <span class="automation-timestamp-full">(<?php echo htmlspecialchars(date('Y-m-d H:i:s', $lastUpdate)); ?>)</span>
                    </span>
                </div>
            <?php endif; ?>
            
            <?php if (empty($entries)): ?>
                <div class="automation-status-empty">
                    <p>No automation entries yet</p>
                </div>
            <?php else: 
                $totalEntries = count($entries);
                $hasMoreEntries = $totalEntries > 1;
            ?>
                <div class="automation-entries-wrapper" id="automation-entries-wrapper">
                    <div class="automation-entries-list" id="automation-entries-list">
                        <?php foreach ($entries as $index => $entry): 
                            $entryType = $entry['type'] ?? 'unknown';
                            $entryTimestamp = $entry['timestamp'] ?? 0;
                            $entryDetails = formatAutomationEntryDetails($entry);
                            $badgeClass = getAutomationEntryTypeClass($entryType);
                            $badgeLabel = getAutomationEntryTypeLabel($entryType); # in zendure/includes/status.php
                            $isFirst = $index === 0;
                            $entryClass = 'automation-entry' . ($isFirst ? ' automation-entry-first' : ' automation-entry-collapsed');
                        ?>
                            <div class="<?php echo $entryClass; ?>" data-index="<?php echo $index; ?>" <?php if ($isFirst && $hasMoreEntries): ?>onclick="toggleAutomationEntries()" style="cursor: pointer;"<?php endif; ?>>
                                <span class="automation-entry-badge <?php echo $badgeClass; ?>">
                                    <?php echo htmlspecialchars($badgeLabel); ?>
                                </span>
                                <span class="automation-entry-time">
                                    <?php echo htmlspecialchars(formatRelativeTime($entryTimestamp)); ?>
                                    <span class="automation-entry-timestamp-full">(<?php echo htmlspecialchars(date('Y-m-d H:i:s', $entryTimestamp)); ?>)</span>
                                </span>
                                <span class="automation-entry-details">
                                    <?php echo htmlspecialchars($entryDetails); ?>
                                </span>
                                <?php if ($isFirst && $hasMoreEntries): ?>
                                    <span class="automation-entry-expand-icon">▼</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="automation-status-empty">
                <p>No automation entries yet</p>
            </div>
        <?php endif; ?>
    </div>
</div>

