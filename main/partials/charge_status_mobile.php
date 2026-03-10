<!-- Charge/Discharge Status Section -->
<div class="card" data-component="charge-status-main">
    <div class="metric-section">
        <h3 class="card-header card-header--no-line charge-status-main-title">🔋 Charge/Discharge</h3>

        <div id="charge-status-error" class="charge-status-error" hidden></div>
        <div id="charge-status-empty" class="charge-status-empty" hidden></div>

        <div id="charge-status-content" class="charge-status-mobile" data-actual-power="0" style="display:grid; grid-template-columns:minmax(0, 0.5fr) minmax(0, 1.5fr); gap:10px;">
            <div class="charge-status-box">
                <div class="charge-status-box-title">Status</div>
                <div class="charge-status-box-content">
                    <div class="charge-status-subtitle" data-role="status-title" style="font-size: 0.75rem; margin-bottom: 10px;">--</div>
                    <div class="charge-status-indicator standby" data-role="status-indicator" style="padding: 10px; border-radius: 6px;">
                        <div class="charge-status-icon" data-role="status-icon" style="font-size: 1.5rem;">--</div>
                    </div>
                </div>
            </div>

            <div class="charge-status-box">
                <div class="charge-status-box-title">Power</div>
                <div class="charge-status-box-content">
                    <div class="charge-power-display" style="padding: 10px; border-radius: 6px;">
                        <div class="charge-power-label-value" style="margin-bottom: 8px;">
                            <span class="charge-power-value" data-role="power-value" style="font-size: 1.1rem; font-weight: 700;">0 W</span>
                        </div>
                        <div class="charge-power-bar-container" style="height: 14px;">
                            <div class="charge-power-bar-label left" data-role="power-min-label" style="font-size: 0.6rem;">0</div>
                            <div class="charge-power-bar-label center" style="font-size: 0.6rem;">0</div>
                            <div class="charge-power-bar-label right" data-role="power-max-label" style="font-size: 0.6rem;">0</div>
                            <div class="charge-power-bar-center"></div>
                            <div id="charge-power-bar-fill" class="charge-power-bar-fill" data-role="power-bar-fill" style="width: 0;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="charge-status-box">
                <div class="charge-status-box-header">
                    <div class="charge-status-box-title">Battery</div>
                    <button class="charge-refresh-btn" id="charge-details-toggle" type="button" onclick="toggleChargeStatusDetails()" title="Show/hide additional battery details">
                        <span class="refresh-icon charge-details-toggle-icon">▼</span>
                        <span class="refresh-text charge-details-toggle-text">Show more</span>
                    </button>
                </div>
                <div class="charge-status-box-content">
                    <div class="charge-battery-display" style="padding: 10px; border-radius: 6px;">
                        <div class="charge-battery-label-value" style="margin-bottom: 8px;">
                            <span class="charge-battery-value" data-role="battery-summary-value" style="font-size: 1rem; font-weight: 600;">--</span>
                        </div>
                        <div class="charge-battery-bar" style="height: 14px;">
                            <div class="charge-battery-bar-marker min" data-role="battery-min-marker" title="Minimum"></div>
                            <div class="charge-battery-bar-marker max" data-role="battery-max-marker" title="Maximum"></div>
                            <div class="charge-battery-bar-fill" data-role="battery-summary-fill" style="width: 0%;"></div>
                        </div>
                    </div>
                    <div class="charge-status-details-collapsible" id="charge-status-details-collapsible">
                        <div class="charge-battery-display" data-metric="wifi-signal">
                            <div class="charge-battery-label-value">
                                <span class="charge-battery-label">WiFi:</span>
                                <span class="charge-battery-value">–/10 (-- dBm)</span>
                            </div>
                            <div class="charge-battery-bar">
                                <div class="charge-battery-bar-fill" style="width: 0%;"></div>
                            </div>
                        </div>
                        <div class="charge-battery-display" data-metric="system-temp">
                            <div class="charge-battery-label-value">
                                <span class="charge-battery-label">Temp:</span>
                                <span class="charge-battery-value">-- °C</span>
                            </div>
                            <div class="charge-battery-bar">
                                <div class="charge-battery-bar-fill" style="width: 0%;"></div>
                            </div>
                        </div>
                        <div class="charge-battery-display" data-metric="battery1-level">
                            <div class="charge-battery-label-value">
                                <span class="charge-battery-label">B1:</span>
                                <span class="charge-battery-value">--%</span>
                            </div>
                            <div class="charge-battery-bar">
                                <div class="charge-battery-bar-marker min" title="Minimum"></div>
                                <div class="charge-battery-bar-marker max" title="Maximum"></div>
                                <div class="charge-battery-bar-fill" style="width: 0%;"></div>
                            </div>
                        </div>
                        <div class="charge-battery-display" data-metric="battery1-temp">
                            <div class="charge-battery-label-value">
                                <span class="charge-battery-label">B1:</span>
                                <span class="charge-battery-value">-- °C</span>
                            </div>
                            <div class="charge-battery-bar">
                                <div class="charge-battery-bar-fill" style="width: 0%;"></div>
                            </div>
                        </div>
                        <div class="charge-battery-display" data-metric="battery2-level">
                            <div class="charge-battery-label-value">
                                <span class="charge-battery-label">B2:</span>
                                <span class="charge-battery-value">--%</span>
                            </div>
                            <div class="charge-battery-bar">
                                <div class="charge-battery-bar-marker min" title="Minimum"></div>
                                <div class="charge-battery-bar-marker max" title="Maximum"></div>
                                <div class="charge-battery-bar-fill" style="width: 0%;"></div>
                            </div>
                        </div>
                        <div class="charge-battery-display" data-metric="battery2-temp">
                            <div class="charge-battery-label-value">
                                <span class="charge-battery-label">B2:</span>
                                <span class="charge-battery-value">-- °C</span>
                            </div>
                            <div class="charge-battery-bar">
                                <div class="charge-battery-bar-fill" style="width: 0%;"></div>
                            </div>
                        </div>
                        <div class="charge-status-header charge-status-header--details" data-role="mobile-last-update" hidden></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="charge-status-header" data-role="desktop-last-update" hidden></div>
    </div>
</div>
