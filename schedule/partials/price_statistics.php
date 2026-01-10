<?php
/**
 * Price Statistics Partial
 * Displays price statistics (Minimum, Maximum, Average, Current Price) in cards
 * Data is fetched and rendered by JavaScript (price_statistics.js)
 */
?>

<!-- Price Statistics Section -->
<div class="card" style="margin-top: 12px; padding-bottom: 12px;">
    <div class="metric-section">
        <h3>⚡ Price Statistics</h3>
        <div id="price-statistics-grid" class="price-statistics-grid">
            <!-- Cards will be populated by JavaScript -->
            <div class="price-stat-card">
                <div class="price-stat-title">MINIMUM PRICE</div>
                <div class="price-stat-value" id="price-stat-min-value">-</div>
                <div class="price-stat-detail" id="price-stat-min-detail">-</div>
            </div>
            <div class="price-stat-card">
                <div class="price-stat-title">MAXIMUM PRICE</div>
                <div class="price-stat-value" id="price-stat-max-value">-</div>
                <div class="price-stat-detail" id="price-stat-max-detail">-</div>
            </div>
            <div class="price-stat-card">
                <div class="price-stat-title">AVERAGE PRICE</div>
                <div class="price-stat-value" id="price-stat-avg-value">-</div>
                <div class="price-stat-detail" id="price-stat-avg-detail" style="display: none;"></div>
            </div>
            <div class="price-stat-card">
                <div class="price-stat-title">CURRENT PRICE</div>
                <div class="price-stat-value" id="price-stat-current-value">-</div>
                <div class="price-stat-detail" id="price-stat-current-detail">-</div>
            </div>
        </div>
    </div>
</div>
