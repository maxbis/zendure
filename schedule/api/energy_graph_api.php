<?php
/**
 * Energy Graph API
 * Returns Wh per hour and Wh per day for the energy graph partial (JSON).
 * Same computation as schedule/partials/energy_graph_data.php.
 */
date_default_timezone_set('Europe/Amsterdam');

require_once __DIR__ . '/../../login/validate.php';
require_once __DIR__ . '/../partials/energy_graph_data.php';

header('Content-Type: application/json');
echo json_encode([
    'whPerHour' => $whPerHour,
    'whPerDay'  => $whPerDay,
    'baseWh'    => $baseWh
]);
