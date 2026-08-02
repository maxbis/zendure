<?php

declare(strict_types=1);

final class SystemConfigException extends RuntimeException
{
}

function systemConfigDefaultPath(): string
{
    return dirname(__DIR__) . '/config/system.json';
}

/**
 * Load and validate the shared system configuration.
 *
 * @return array{
 *   schemaVersion: int,
 *   battery: array{capacityWh: int, minChargePercent: int, maxChargePercent: int},
 *   installation: array{name: string, latitude: float, longitude: float, timezone: string},
 *   priceConversion: array{
 *     supplierMarkupEurPerKwh: float,
 *     energyTaxEurPerKwh: float,
 *     vatMultiplier: float,
 *     consumerPrecision: int,
 *     spotPrecision: int
 *   }
 * }
 */
function loadSystemConfig(?string $configPath = null): array
{
    $path = $configPath ?? systemConfigDefaultPath();
    if (!is_file($path)) {
        throw new SystemConfigException('System configuration file not found: ' . $path);
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new SystemConfigException('Unable to read system configuration file: ' . $path);
    }

    try {
        $rootValue = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new SystemConfigException(
            'Invalid JSON in system configuration file ' . $path . ': ' . $error->getMessage(),
            0,
            $error
        );
    }

    if (!$rootValue instanceof stdClass || !is_array($decoded)) {
        throw new SystemConfigException('Expected an object at $.');
    }

    return validateSystemConfig($decoded);
}

/**
 * Validate and normalize an already decoded system configuration.
 *
 * @param array<mixed> $config
 * @return array<string, mixed>
 */
function validateSystemConfig(array $config): array
{
    systemConfigAssertExactKeys(
        $config,
        ['schemaVersion', 'battery', 'installation', 'priceConversion'],
        '$'
    );

    $schemaVersion = systemConfigRequireInteger($config['schemaVersion'], '$.schemaVersion', 1, 1);
    $battery = systemConfigRequireObject($config['battery'], '$.battery');
    $installation = systemConfigRequireObject($config['installation'], '$.installation');
    $priceConversion = systemConfigRequireObject($config['priceConversion'], '$.priceConversion');

    systemConfigAssertExactKeys(
        $battery,
        ['capacityWh', 'minChargePercent', 'maxChargePercent'],
        '$.battery'
    );
    $capacityWh = systemConfigRequireInteger($battery['capacityWh'], '$.battery.capacityWh', 1);
    $minChargePercent = systemConfigRequireInteger(
        $battery['minChargePercent'],
        '$.battery.minChargePercent',
        0,
        99
    );
    $maxChargePercent = systemConfigRequireInteger(
        $battery['maxChargePercent'],
        '$.battery.maxChargePercent',
        1,
        100
    );
    if ($minChargePercent >= $maxChargePercent) {
        throw new SystemConfigException(
            '$.battery.minChargePercent must be lower than $.battery.maxChargePercent.'
        );
    }

    systemConfigAssertExactKeys(
        $installation,
        ['name', 'latitude', 'longitude', 'timezone'],
        '$.installation'
    );
    $name = systemConfigRequireNonBlankString($installation['name'], '$.installation.name');
    $latitude = systemConfigRequireNumber($installation['latitude'], '$.installation.latitude', -90.0, 90.0);
    $longitude = systemConfigRequireNumber($installation['longitude'], '$.installation.longitude', -180.0, 180.0);
    $timezone = systemConfigRequireNonBlankString($installation['timezone'], '$.installation.timezone');
    if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
        throw new SystemConfigException('$.installation.timezone is not a recognized IANA timezone.');
    }

    systemConfigAssertExactKeys(
        $priceConversion,
        [
            'supplierMarkupEurPerKwh',
            'energyTaxEurPerKwh',
            'vatMultiplier',
            'consumerPrecision',
            'spotPrecision',
        ],
        '$.priceConversion'
    );
    $supplierMarkup = systemConfigRequireNumber(
        $priceConversion['supplierMarkupEurPerKwh'],
        '$.priceConversion.supplierMarkupEurPerKwh',
        0.0
    );
    $energyTax = systemConfigRequireNumber(
        $priceConversion['energyTaxEurPerKwh'],
        '$.priceConversion.energyTaxEurPerKwh',
        0.0
    );
    $vatMultiplier = systemConfigRequireNumber(
        $priceConversion['vatMultiplier'],
        '$.priceConversion.vatMultiplier',
        0.0,
        null,
        true
    );
    $consumerPrecision = systemConfigRequireInteger(
        $priceConversion['consumerPrecision'],
        '$.priceConversion.consumerPrecision',
        0,
        12
    );
    $spotPrecision = systemConfigRequireInteger(
        $priceConversion['spotPrecision'],
        '$.priceConversion.spotPrecision',
        0,
        12
    );

    return [
        'schemaVersion' => $schemaVersion,
        'battery' => [
            'capacityWh' => $capacityWh,
            'minChargePercent' => $minChargePercent,
            'maxChargePercent' => $maxChargePercent,
        ],
        'installation' => [
            'name' => $name,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'timezone' => $timezone,
        ],
        'priceConversion' => [
            'supplierMarkupEurPerKwh' => $supplierMarkup,
            'energyTaxEurPerKwh' => $energyTax,
            'vatMultiplier' => $vatMultiplier,
            'consumerPrecision' => $consumerPrecision,
            'spotPrecision' => $spotPrecision,
        ],
    ];
}

/** @param mixed $value @return array<mixed> */
function systemConfigRequireObject($value, string $path): array
{
    if (!is_array($value)) {
        throw new SystemConfigException('Expected an object at ' . $path . '.');
    }
    return $value;
}

/** @param array<mixed> $value @param list<string> $expected */
function systemConfigAssertExactKeys(array $value, array $expected, string $path): void
{
    $actual = array_keys($value);
    $missing = array_values(array_diff($expected, $actual));
    $unknown = array_values(array_diff($actual, $expected));
    if ($missing === [] && $unknown === []) {
        return;
    }

    $details = [];
    if ($missing !== []) {
        $details[] = 'missing: ' . implode(', ', $missing);
    }
    if ($unknown !== []) {
        $details[] = 'unknown: ' . implode(', ', array_map('strval', $unknown));
    }
    throw new SystemConfigException('Invalid properties at ' . $path . ' (' . implode('; ', $details) . ').');
}

/** @param mixed $value */
function systemConfigRequireInteger($value, string $path, int $minimum, ?int $maximum = null): int
{
    if (!is_int($value)) {
        throw new SystemConfigException($path . ' must be an integer.');
    }
    if ($value < $minimum) {
        throw new SystemConfigException($path . ' must be at least ' . $minimum . '.');
    }
    if ($maximum !== null && $value > $maximum) {
        throw new SystemConfigException($path . ' must be at most ' . $maximum . '.');
    }
    return $value;
}

/** @param mixed $value */
function systemConfigRequireNumber(
    $value,
    string $path,
    float $minimum,
    ?float $maximum = null,
    bool $exclusiveMinimum = false
): float {
    if (!is_int($value) && !is_float($value)) {
        throw new SystemConfigException($path . ' must be a number.');
    }

    $number = (float)$value;
    if (!is_finite($number)) {
        throw new SystemConfigException($path . ' must be a finite number.');
    }
    if (($exclusiveMinimum && $number <= $minimum) || (!$exclusiveMinimum && $number < $minimum)) {
        $comparison = $exclusiveMinimum ? 'greater than ' : 'at least ';
        throw new SystemConfigException($path . ' must be ' . $comparison . systemConfigFormatNumber($minimum) . '.');
    }
    if ($maximum !== null && $number > $maximum) {
        throw new SystemConfigException($path . ' must be at most ' . systemConfigFormatNumber($maximum) . '.');
    }
    return $number;
}

/** @param mixed $value */
function systemConfigRequireNonBlankString($value, string $path): string
{
    if (!is_string($value) || trim($value) === '') {
        throw new SystemConfigException($path . ' must be a non-blank string.');
    }
    return $value;
}

function systemConfigFormatNumber(float $value): string
{
    return rtrim(rtrim(number_format($value, 12, '.', ''), '0'), '.');
}
