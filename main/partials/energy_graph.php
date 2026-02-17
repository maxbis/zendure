<?php
/**
 * Energy Graph Partial
 * Bar chart of Wh per hour from automation_status.json (type=change entries with numeric newValue).
 * Self-contained so it can stand alone when other schedule parts are removed.
 */
?>

<div class="energy-graph-wrapper">
    <div class="card energy-graph-card">
        <div class="energy-graph-header">
            <div class="energy-graph-heading">
                <h2 class="section-title">Watt-hours per hour</h2>
                <p class="energy-graph-subtitle">Data from API.</p>
                <div class="energy-graph-chart">
                    <div class="energy-graph-canvas">
                        <canvas id="energyChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="energy-graph-table energy-graph-daily">
                <h2 class="section-title">Daily totals</h2>
                <div class="energy-graph-daily-table-wrapper">
                    <table>
                        <thead>
                        <tr>
                            <th>Date</th>
                            <th>Wh+</th>
                            <th>Wh-</th>
                            <th title="% of 5.76 kWh (net)">%</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr><td colspan="4">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
