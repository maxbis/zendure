<?php
/**
 * Battery Packs Partial
 * Displays battery pack status (Charge Level, Temperature, Status) in a compact grid
 * Data is fetched from data_api.php?type=zendure
 */

// Include helper files
require_once __DIR__ . '/../includes/bars.php';
require_once __DIR__ . '/../includes/renderers.php';

// Fetch battery pack data from API
$batteryPackData = null;
$batteryPackError = null;

// Build HTTP URL to the API endpoint
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
// Get base path: remove /schedule/charge_schedule.php to get base path
$basePath = dirname(dirname($scriptName));
$batteryPackApiUrl = $scheme . '://' . $host . $basePath . '/data/api/data_api.php?type=zendure';

try {
    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'ignore_errors' => true,
            'method' => 'GET',
            'header' => 'User-Agent: Charge-Schedule-Page'
        ]
    ]);
    
    $jsonData = @file_get_contents($batteryPackApiUrl, false, $context);
    
    if ($jsonData === false || empty($jsonData)) {
        // Try alternative: direct file path (for local file access)
        $apiFilePath = __DIR__ . '/../../data/api/data_api.php';
        if (file_exists($apiFilePath)) {
            // Temporarily set GET parameters and capture output
            $originalGet = $_GET;
            $_GET['type'] = 'zendure';
            
            ob_start();
            include $apiFilePath;
            $jsonData = ob_get_clean();
            
            $_GET = $originalGet;
        }
    }
    
    if (!empty($jsonData)) {
        $apiResponse = json_decode($jsonData, true);
        if ($apiResponse && isset($apiResponse['success']) && $apiResponse['success']) {
            // Extract packData from response
            $batteryPackData = $apiResponse['data']['packData'] ?? [];
            if (empty($batteryPackData)) {
                $batteryPackData = [];
            }
        } else {
            $errorMsg = isset($apiResponse['error']) ? $apiResponse['error'] : 'Unknown error';
            $batteryPackError = 'Failed to load battery pack data: ' . htmlspecialchars($errorMsg);
        }
    } else {
        $batteryPackError = 'Battery pack data unavailable (no data returned from API)';
    }
} catch (Exception $e) {
    $batteryPackError = 'Battery pack data unavailable: ' . htmlspecialchars($e->getMessage());
}

?>

<!-- Battery Packs Section -->
<div class="card" style="margin-top: 12px; padding-bottom: 12px;">
    <div class="metric-section">
        <h3>🔋 Battery Packs</h3>
        
        <?php if ($batteryPackError): ?>
            <div class="battery-packs-error" id="battery-packs-error">
                <p><?php echo htmlspecialchars($batteryPackError); ?></p>
            </div>
        <?php elseif (empty($batteryPackData)): ?>
            <div class="battery-packs-empty" id="battery-packs-empty">
                <p>No battery pack data available</p>
            </div>
        <?php else: ?>
            <div class="battery-grid" id="battery-grid">
                <?php foreach ($batteryPackData as $index => $pack): ?>
                    <?php
                    // Calculate battery temperature (using same conversion as Unit Temperature)
                    $batteryMaxTemp = $pack['maxTemp'] ?? 0;
                    $batteryTempCelsius = convertHyperTmp($batteryMaxTemp);
                    $batteryTempColor = getTempColor($batteryTempCelsius);
                    ?>
                    <div class="battery-card">
                        <h4>
                            <?php echo getBatteryStateIcon($pack['state'] ?? 0); ?>
                            Battery Pack <?php echo $index + 1; ?>
                            <?php if (isset($pack['sn'])): ?>
                                <span class="battery-serial">(<?php echo htmlspecialchars(substr($pack['sn'], -8)); ?>)</span>
                            <?php endif; ?>
                        </h4>

                        <!-- Battery Charge Level (socLevel) -->
                        <div class="battery-metric">
                            <?php
                            $socPercent = $pack['socLevel'] ?? 0;
                            $socColor = getBatteryLevelColor($socPercent);
                            // Use generic component with single centered label style and no wrapper
                            renderMetricBar(
                                'Charge Level',
                                $socPercent,
                                $socColor,
                                0,
                                100,
                                'linear',
                                0.7,
                                null,
                                $socColor,
                                '%',
                                '0%',  // Single centered label
                                null,      // No right label
                                'Battery Charge Level: ' . $socPercent . ' percent',
                                ($socPercent > 8),  // Show value in bar if > 8%
                                null,      // No extra content
                                true       // No wrapper (we're inside battery-card)
                            );
                            ?>
                        </div>

                        <!-- Battery Temperature (maxTemp) -->
                        <div class="battery-metric">
                            <?php
                            renderMetricBar(
                                'Temperature (maxTemp)',
                                $batteryTempCelsius,
                                $batteryTempColor,
                                -10,
                                40,
                                'linear',
                                0.7,
                                null,
                                $batteryTempColor,
                                '°C',
                                '-10°C',
                                '+40°C',
                                'Battery Temperature: ' . number_format($batteryTempCelsius, 1) . ' degrees Celsius',
                                true,  // showValueInBar
                                null,  // extraValueContent
                                true   // noWrapper (we're inside battery-card)
                            );
                            ?>
                        </div>

                        <!-- Battery State -->
                        <?php
                        $batteryState = $pack['state'] ?? 0;
                        $stateClass = '';
                        switch ($batteryState) {
                            case 0: $stateClass = 'stand-by'; break;
                            case 1: $stateClass = 'charging'; break;
                            case 2: $stateClass = 'discharging'; break;
                        }
                        ?>
                        <div class="status-indicator <?php echo $stateClass; ?>">
                            <div class="status-indicator-dot"></div>
                            <div class="status-indicator-text">
                                <span><?php echo getBatteryStateText($batteryState); ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
