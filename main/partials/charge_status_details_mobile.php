<!-- Charge/Discharge Status Details Section - Mobile (JS-rendered) -->
<div class="card charge-status-details-mobile">
    <div class="metric-section">
        <div class="charge-status-details-header">
            <h2 class="section-title charge-status-details-title">⚡ System &amp; Grid</h2>
            <button class="charge-refresh-btn" id="charge-details-toggle" onclick="toggleChargeStatusDetails()" title="Show/hide additional status details">
                <span class="refresh-icon charge-details-toggle-icon">▼</span>
                <span class="refresh-text charge-details-toggle-text">Show more</span>
            </button>
        </div>

        <div class="charge-status-content" id="charge-status-details-content">
            <!-- Grid -->
            <div class="charge-power-box">
                <div class="charge-power-box-content">
                    <div class="charge-power-label-value">
                        <span class="charge-power-label">Grid:</span>
                        <span class="charge-power-value"><span class="charge-value-highlight">–</span> W</span>
                    </div>
                    <div class="charge-grid-bar-container">
                        <div class="charge-grid-bar-label left">-1200 W</div>
                        <div class="charge-grid-bar-label center">0</div>
                        <div class="charge-grid-bar-label right">+1200 W</div>
                        <div class="charge-grid-bar-center"></div>
                        <div class="charge-grid-bar-fill" style="width: 0%;"></div>
                    </div>
                </div>
            </div>

            <!-- Collapsible section: WiFi/System + Battery 1 & 2 levels and temps (2x2 grid) -->
            <div class="charge-status-details-collapsible" id="charge-status-details-collapsible">
                <!-- WiFi Signal -->
                <div class="charge-battery-display" data-metric="wifi-signal">
                    <div class="charge-battery-label-value">
                        <span class="charge-battery-label">WiFi Signal:</span>
                        <span class="charge-battery-value">–/10 (-- dBm)</span>
                    </div>
                    <div class="charge-battery-bar">
                        <div class="charge-battery-bar-fill" style="width: 0%;"></div>
                    </div>
                </div>

                <!-- System Temperature -->
                <div class="charge-battery-display" data-metric="system-temp">
                    <div class="charge-battery-label-value">
                        <span class="charge-battery-label">System Temp:</span>
                        <span class="charge-battery-value">-- °C</span>
                    </div>
                    <div class="charge-battery-bar">
                        <div class="charge-battery-bar-fill" style="width: 0%;"></div>
                    </div>
                </div>

                <!-- Battery 1 Level -->
                <div class="charge-battery-display" data-metric="battery1-level">
                    <div class="charge-battery-label-value">
                        <span class="charge-battery-label">B1:</span>
                        <span class="charge-battery-value">--% (-- kWh - -- kWh)</span>
                    </div>
                    <div class="charge-battery-bar">
                        <div class="charge-battery-bar-marker min" title="Minimum"></div>
                        <div class="charge-battery-bar-marker max" title="Maximum"></div>
                        <div class="charge-battery-bar-fill" style="width: 0%;"></div>
                    </div>
                </div>

                <!-- Battery 1 Temperature -->
                <div class="charge-battery-display" data-metric="battery1-temp">
                    <div class="charge-battery-label-value">
                        <span class="charge-battery-label">B1 Temp:</span>
                        <span class="charge-battery-value">-- °C</span>
                    </div>
                    <div class="charge-battery-bar">
                        <div class="charge-battery-bar-fill" style="width: 0%;"></div>
                    </div>
                </div>

                <!-- Battery 2 Level -->
                <div class="charge-battery-display" data-metric="battery2-level">
                    <div class="charge-battery-label-value">
                        <span class="charge-battery-label">B2:</span>
                        <span class="charge-battery-value">--% (-- kWh - -- kWh)</span>
                    </div>
                    <div class="charge-battery-bar">
                        <div class="charge-battery-bar-marker min" title="Minimum"></div>
                        <div class="charge-battery-bar-marker max" title="Maximum"></div>
                        <div class="charge-battery-bar-fill" style="width: 0%;"></div>
                    </div>
                </div>

                <!-- Battery 2 Temperature -->
                <div class="charge-battery-display" data-metric="battery2-temp">
                    <div class="charge-battery-label-value">
                        <span class="charge-battery-label">B2 Temp:</span>
                        <span class="charge-battery-value">-- °C</span>
                    </div>
                    <div class="charge-battery-bar">
                        <div class="charge-battery-bar-fill" style="width: 0%;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
