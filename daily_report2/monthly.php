<?php

declare(strict_types=1);

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = '../daily_report/monthly.php' . ($query !== '' ? '?' . $query : '');
header('Location: ' . $target, true, 302);
exit;
