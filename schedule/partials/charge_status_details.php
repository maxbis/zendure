<!-- Charge/Discharge Status Details Section (JS-rendered) -->
<div class="card">
    <div class="metric-section">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <h2 class="section-title" style="margin: 0;">⚡ System &amp; Grid</h2>
            <button class="charge-refresh-btn" id="charge-details-toggle" onclick="toggleChargeStatusDetails()" title="Show/hide additional status details" style="margin-left: auto;">
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
                        <span class="charge-power-value">– W</span>
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

            <!-- Collapsible section: Battery 1 & 2 levels and temps -->
            <div class="charge-status-details-collapsible" id="charge-status-details-collapsible">
                <!-- Spacer for layout alignment -->
                <div class="charge-empty-box"></div>

                <!-- Battery 1 Level -->
                <div class="charge-battery-display" data-metric="battery1-level">
                    <div class="charge-battery-label-value">
                        <span class="charge-battery-label">Battery 1 Level:</span>
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
                        <span class="charge-battery-label">Battery 1 Temp:</span>
                        <span class="charge-battery-value">-- °C</span>
                    </div>
                    <div class="charge-battery-bar">
                        <div class="charge-battery-bar-fill" style="width: 0%;"></div>
                    </div>
                </div>

                <!-- Spacer for layout alignment -->
                <div class="charge-empty-box"></div>

                <!-- Battery 2 Level -->
                <div class="charge-battery-display" data-metric="battery2-level">
                    <div class="charge-battery-label-value">
                        <span class="charge-battery-label">Battery 2 Level:</span>
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
                        <span class="charge-battery-label">Battery 2 Temp:</span>
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

