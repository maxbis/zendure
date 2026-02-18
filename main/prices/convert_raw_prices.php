<?php
/**
 * Converts jeroen.nl raw price files (priceYYYYMMDD-raw.json) to standard format
 * (priceYYYYMMDD.json with hour keys "00"-"23" and float values).
 *
 * Usage: php convert_raw_prices.php [--dry-run]   Convert *-raw.json to standard (conversion applied only here).
 *        php convert_raw_prices.php --revert     Revert previously converted priceYYYYMMDD.json to base prices.
 */

define('DATA_BASE_DIR', __DIR__ . '/../data');
define('PRICE_DIR', DATA_BASE_DIR . '/price');

// Energy price conversion (apply to raw/base prices to get consumer price)
define('ENERGY_TAX', 0.0917);
define('ENERGY_VAT', 1.21);
define('ENERGY_SUPPLIER', 0.0219); // inkoopvergoeding (procurement fee) – aligned with get_prices_v4

/** price = (price_from_json + energy_supplier + energy_tax) * energy_vat */
function applyPriceConversion($price) {
    return ($price + ENERGY_SUPPLIER + ENERGY_TAX) * ENERGY_VAT;
}

/** Inverse of applyPriceConversion: recover base price from converted value */
function revertPriceConversion($price) {
    return ($price / ENERGY_VAT) - ENERGY_SUPPLIER - ENERGY_TAX;
}

/**
 * Convert jeroen.nl raw array to hourly prices (same logic as get_prices_v3).
 *
 * @param array $data Raw array of entries with datum_nl, prijs_excl_belastingen
 * @return array|null Hour keys "00"-"23" with float values, or null
 */
function rawToHourlyPrices($data) {
    if (!is_array($data) || empty($data)) {
        return null;
    }

    $sum = [];
    $count = [];

    foreach ($data as $entry) {
        if (!isset($entry['datum_nl']) || !isset($entry['prijs_excl_belastingen'])) {
            continue;
        }
        $datumNl = $entry['datum_nl'];
        $prijsStr = $entry['prijs_excl_belastingen'];
        if (empty($datumNl) || $prijsStr === null || $prijsStr === '') {
            continue;
        }
        try {
            $dt = new DateTime($datumNl);
        } catch (Exception $e) {
            continue;
        }
        $hour = $dt->format('H');
        $price = (float)str_replace(',', '.', $prijsStr);

        if (!isset($sum[$hour])) {
            $sum[$hour] = 0.0;
            $count[$hour] = 0;
        }
        $sum[$hour] += $price;
        $count[$hour] += 1;
    }

    if (empty($sum) || count($sum) < 24) {
        return null;
    }

    $prices = [];
    foreach ($sum as $hour => $total) {
        $basePrice = $total / max(1, $count[$hour]);
        $prices[$hour] = applyPriceConversion($basePrice);
    }
    ksort($prices);
    return $prices;
}

/**
 * Extract date Ymd from first entry datum_nl.
 *
 * @param array $data Raw array
 * @return string|null Ymd or null
 */
function rawToDateYmd($data) {
    if (!is_array($data) || empty($data) || !isset($data[0]['datum_nl'])) {
        return null;
    }
    try {
        $dt = new DateTime($data[0]['datum_nl']);
        return $dt->format('Ymd');
    } catch (Exception $e) {
        return null;
    }
}

$dryRun = in_array('--dry-run', $argv ?? [], true);
$revert = in_array('--revert', $argv ?? [], true);

if (!is_dir(PRICE_DIR)) {
    fwrite(STDERR, "Price directory not found: " . PRICE_DIR . "\n");
    exit(1);
}

if ($revert) {
    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(PRICE_DIR, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        $name = $file->getFilename();
        if (!preg_match('/^price(\d{8})\.json$/', $name) || strpos($name, '-raw') !== false) {
            continue;
        }
        $json = @file_get_contents($path);
        if ($json === false) {
            fwrite(STDERR, "Skip (unreadable): $path\n");
            continue;
        }
        $prices = json_decode($json, true);
        if (!is_array($prices)) {
            fwrite(STDERR, "Skip (invalid JSON): $path\n");
            continue;
        }
        $reverted = [];
        foreach ($prices as $hour => $value) {
            $reverted[$hour] = revertPriceConversion((float)$value);
        }
        ksort($reverted);
        $jsonOut = json_encode($reverted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($dryRun) {
            echo "Would revert $path\n";
        } else {
            if (file_put_contents($path, $jsonOut, LOCK_EX) === false) {
                fwrite(STDERR, "Failed to write: $path\n");
                continue;
            }
            echo "Reverted $path\n";
        }
        $count++;
    }
    echo "Done. Reverted $count price file(s).\n";
    exit(0);
}

$count = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(PRICE_DIR, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    $path = $file->getPathname();
    $name = $file->getFilename();
    if (!preg_match('/^price(\d{8})-raw\.json$/', $name, $m)) {
        continue;
    }

    $dateStr = $m[1];
    $json = @file_get_contents($path);
    if ($json === false || $json === '') {
        fwrite(STDERR, "Skip (empty/unreadable): $path\n");
        continue;
    }

    $raw = json_decode($json, true);
    if (!is_array($raw) || empty($raw)) {
        fwrite(STDERR, "Skip (invalid JSON or not array): $path\n");
        continue;
    }

    $dateFromData = rawToDateYmd($raw);
    if ($dateFromData && $dateFromData !== $dateStr) {
        $dateStr = $dateFromData;
    }

    $prices = rawToHourlyPrices($raw);
    if (!$prices) {
        fwrite(STDERR, "Skip (could not convert to hourly): $path\n");
        continue;
    }

    $outDir = dirname($path);
    $outPath = $outDir . '/price' . $dateStr . '.json';
    $jsonOut = json_encode($prices, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    if ($dryRun) {
        echo "Would write $outPath (" . count($prices) . " hours)\n";
    } else {
        if (file_put_contents($outPath, $jsonOut, LOCK_EX) === false) {
            fwrite(STDERR, "Failed to write: $outPath\n");
            continue;
        }
        echo "Wrote $outPath\n";
    }
    $count++;
}

echo "Done. Converted $count file(s).\n";
