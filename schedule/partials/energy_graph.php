<?php
/**
 * Energy Graph Partial
 * Bar chart of Wh per hour from automation_status.json (type=change entries with numeric newValue).
 * Self-contained so it can stand alone when other schedule parts are removed.
 */
?>

<style>
    .energy-graph-wrapper { margin-top: 20px; }
    .energy-graph-card h2 { margin: 0 0 4px 0; }
    .energy-graph-subtitle { margin: 0 0 16px 0; color: #666; font-size: 0.9rem; }
    .energy-graph-header { display: flex; gap: 24px; align-items: flex-start; flex-wrap: wrap; }
    .energy-graph-heading { flex: 1 1 420px; min-width: 320px; }
    .energy-graph-chart { width: 100%; min-width: 0; margin-top: 8px; }
    .energy-graph-table { flex: 0 0 320px; min-width: 280px; }
    .energy-graph-canvas { height: 220px; max-height: 220px; background: #f8fafc; border-radius: 10px; padding: 8px 10px 4px; }
    .energy-graph-canvas canvas { display: block; width: 100%; height: 100%; }
    .energy-graph-daily { border: 1px solid #eee; border-radius: 8px; padding: 12px 16px; background: #fff; }
    .energy-graph-daily .section-title { margin: 0 0 8px 0; font-weight: 700; }
    .energy-graph-daily-table-wrapper {
        max-height: 13em; /* ~8 rows including header */
        overflow-y: auto;
        overflow-x: hidden;
        margin-top: 4px;
        scrollbar-width: thin;
    }
    .energy-graph-daily-table-wrapper::-webkit-scrollbar {
        width: 8px;
    }
    .energy-graph-daily-table-wrapper::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 4px;
    }
    .energy-graph-daily-table-wrapper::-webkit-scrollbar-thumb {
        background: #ccc;
        border-radius: 4px;
    }
    .energy-graph-daily-table-wrapper::-webkit-scrollbar-thumb:hover {
        background: #bbb;
    }
    .energy-graph-daily table { border-collapse: collapse; width: 100%; max-width: 380px; border-spacing: 0; }
    .energy-graph-daily th, .energy-graph-daily td { text-align: left; padding: 4px 8px 4px 0; }

    @media (max-width: 900px) {
        .energy-graph-header { flex-direction: column; }
        .energy-graph-heading,
        .energy-graph-table { width: 100%; min-width: 0; }
        .energy-graph-chart { margin-top: 16px; }
        .energy-graph-daily { padding: 12px; }
    }
</style>

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
