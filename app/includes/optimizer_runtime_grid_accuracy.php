<?php

declare(strict_types=1);

const RT_GRID_FORECAST_ABSOLUTE_TOLERANCE_WH = 100;
const RT_GRID_FORECAST_WARNING_TOLERANCE_WH = 250;
const RT_GRID_FORECAST_GOOD_ERROR_RATIO = 0.15;
const RT_GRID_FORECAST_WARNING_ERROR_RATIO = 0.50;

function rtGridForecastStatus(mixed $actual, mixed $forecast): ?string
{
    if (!is_numeric($actual) || !is_numeric($forecast)) {
        return null;
    }

    $actualWh = (float) $actual;
    $forecastWh = (float) $forecast;
    if (!is_finite($actualWh) || !is_finite($forecastWh)) {
        return null;
    }

    $errorWh = abs($actualWh - $forecastWh);
    if ($errorWh < RT_GRID_FORECAST_ABSOLUTE_TOLERANCE_WH) {
        return 'good';
    }

    $errorRatio = $forecastWh == 0.0 ? INF : $errorWh / abs($forecastWh);
    if ($errorRatio <= RT_GRID_FORECAST_GOOD_ERROR_RATIO) {
        return 'good';
    }
    if ($errorWh < RT_GRID_FORECAST_WARNING_TOLERANCE_WH || $errorRatio < RT_GRID_FORECAST_WARNING_ERROR_RATIO) {
        return 'warning';
    }
    return 'bad';
}

function rtGridForecastStatusLabel(string $status): string
{
    $goodPercent = (int) (RT_GRID_FORECAST_GOOD_ERROR_RATIO * 100);
    $warningPercent = (int) (RT_GRID_FORECAST_WARNING_ERROR_RATIO * 100);

    return match ($status) {
        'good' => sprintf('Grid forecast error under %d Wh or within %d%% of forecast', RT_GRID_FORECAST_ABSOLUTE_TOLERANCE_WH, $goodPercent),
        'warning' => sprintf('Grid forecast error under %d Wh or more than %d%% and less than %d%% off forecast', RT_GRID_FORECAST_WARNING_TOLERANCE_WH, $goodPercent, $warningPercent),
        default => sprintf('Grid forecast error at least %d Wh and at least %d%% off forecast', RT_GRID_FORECAST_WARNING_TOLERANCE_WH, $warningPercent),
    };
}
