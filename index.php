<?php
/**
 * Root index.php - Redirects to charge schedule page
 */

// Preserve query parameters if they exist
$queryString = $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '';
$redirectUrl = 'schedule/charge_schedule.php' . $queryString;

// Perform redirect
header('Location: ' . $redirectUrl, true, 302);
exit;