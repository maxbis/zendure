<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/main/includes/app_energy_history.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This helper can only be run from the command line.\n");
    exit(1);
}

[$script, $startDate, $endDate, $table] = array_pad($argv, 4, null);
unset($script);

if (
    !is_string($startDate)
    || !is_string($endDate)
    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)
    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)
) {
    fwrite(STDERR, "Start and end dates must use YYYY-MM-DD.\n");
    exit(1);
}
if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
    fwrite(STDERR, "Table name may contain only letters, numbers, and underscores.\n");
    exit(1);
}

try {
    $pdo = appEnergyHistoryCreatePdo();
    $statement = $pdo->prepare(
        sprintf(
            'SELECT local_date, local_hour, estimated_home_load_wh
             FROM `%s`
             WHERE local_date >= :start_date AND local_date < :end_date
               AND estimated_home_load_wh IS NOT NULL
             ORDER BY local_date ASC, local_hour ASC',
            $table
        )
    );
    $statement->execute([
        'start_date' => $startDate,
        'end_date' => $endDate,
    ]);
    echo json_encode(
        $statement->fetchAll(),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
    );
    echo PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
