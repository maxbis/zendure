<?php

declare(strict_types=1);

/**
 * Return the webserver-owned system configuration as presentation-neutral field
 * metadata. Keeping this catalog separate from the page allows a later editing
 * phase to reuse paths, types, limits, explanations, and recommendations.
 *
 * @return list<array{
 *     id: string,
 *     title: string,
 *     description: string,
 *     open: bool,
 *     fields: list<array<string, mixed>>
 * }>
 */
function systemConfigurationFieldGroups(array $config): array
{
    $field = static function (
        string $path,
        string $label,
        mixed $value,
        string $description,
        string $type,
        ?string $unit = null,
        mixed $recommended = null,
        ?string $recommendedNote = null,
        string $editability = 'planned',
        ?string $constraint = null,
        ?string $effect = null
    ): array {
        return [
            'path' => $path,
            'label' => $label,
            'value' => $value,
            'description' => $description,
            'type' => $type,
            'unit' => $unit,
            'recommended' => $recommended,
            'recommended_note' => $recommendedNote,
            'editability' => $editability,
            'constraint' => $constraint,
            'effect' => $effect,
        ];
    };

    $battery = $config['battery'];
    $reserve = $battery['dynamicReserve'];
    $forecast = $config['forecast'];
    $schedule = $config['schedule'];
    $installation = $config['installation'];
    $prices = $config['priceConversion'];
    $reserveBandDefaults = [
        ['from' => 15, 'to' => 25, 'percentile' => 95, 'refill' => 100],
        ['from' => 25, 'to' => 40, 'percentile' => 85, 'refill' => 60],
        ['from' => 40, 'to' => 50, 'percentile' => 75, 'refill' => 25],
    ];

    $groups = [
        [
            'id' => 'optimizer-economics',
            'title' => 'Optimizer economics',
            'description' => 'Virtual costs and efficiency assumptions that determine whether charging and discharging are worthwhile.',
            'open' => true,
            'fields' => [
                $field(
                    'battery.roundTripEfficiency',
                    'Round-trip efficiency',
                    $battery['roundTripEfficiency'] ?? 0.85,
                    'Share of charged energy expected to be available again after both charging and discharging losses. Lower values require a larger price difference before grid arbitrage becomes attractive.',
                    'ratio',
                    '%',
                    0.85,
                    '85% is the measured working assumption for this installation.',
                    'planned',
                    'Greater than 0% and no more than 100%.',
                    'Used by the rolling optimizer, forecast P&L, and retained-energy valuation.'
                ),
                $field(
                    'battery.wearCostEurPerKwhDischarged',
                    'Battery wear cost',
                    $battery['wearCostEurPerKwhDischarged'] ?? 0.0,
                    'Virtual cost added for every AC-side kilowatt-hour discharged. It discourages low-value cycling but is not included as a cash expense on the energy bill.',
                    'currency_rate',
                    '€/kWh discharged',
                    0.0005,
                    'A deliberately small wear allowance; increase only when cycle preservation should weigh more heavily.',
                    'planned',
                    'Zero or greater.',
                    'Used only in optimizer path selection and optimizer diagnostics.'
                ),
                $field(
                    'battery.minimumGridArbitrageMarginEurPerKwhCharged',
                    'Minimum grid-arbitrage margin',
                    $battery['minimumGridArbitrageMarginEurPerKwhCharged'] ?? 0.0,
                    'Virtual profit hurdle applied to each kilowatt-hour that battery charging adds to grid import. Forecast solar-surplus charging is excluded, so this setting targets grid arbitrage without suppressing useful solar storage.',
                    'currency_rate',
                    '€/kWh grid charged',
                    0.02,
                    'Two euro cents adds a modest buffer for forecast error and marginal trades.',
                    'planned',
                    'Zero or greater.',
                    'Used directly in rolling-optimizer candidate costs.'
                ),
            ],
        ],
        [
            'id' => 'scarcity-policy',
            'title' => 'Scarcity policy',
            'description' => 'Controls how strongly the optimizer protects stored energy when forecast refill opportunities are limited.',
            'open' => true,
            'fields' => [
                $field(
                    'battery.dynamicReserve.enabled',
                    'Dynamic reserve',
                    $reserve['enabled'],
                    'Master switch for refill-aware scarcity bands. When disabled, the optimizer can use the full configured SoC range without additional low-energy scarcity costs.',
                    'boolean',
                    null,
                    true,
                    'Enabled is recommended when the optimizer should preserve energy during weak refill forecasts.',
                    'planned',
                    'Enabled or disabled.',
                    'Takes effect on the next optimizer calculation.'
                ),
                $field(
                    'battery.dynamicReserve.priceHistoryDays',
                    'Price-history window',
                    $reserve['priceHistoryDays'],
                    'Number of completed local days used to build the historical consumer-price distribution behind scarcity reference prices.',
                    'integer',
                    'days',
                    21,
                    'Three weeks balances recent market conditions with enough hourly observations.',
                    'planned',
                    'At least 1 day; scarcity still requires at least 168 valid hourly prices.',
                    'Used when rebuilding scarcity price percentiles.'
                ),
                $field(
                    'battery.dynamicReserve.priceHistoryHalfLifeDays',
                    'Price-history half-life',
                    $reserve['priceHistoryHalfLifeDays'],
                    'Controls recency weighting. Prices this many days old receive half the weight of the newest completed day.',
                    'number',
                    'days',
                    7,
                    'Seven days follows weekly price patterns while adapting to newer conditions.',
                    'planned',
                    'Must be greater than zero.',
                    'Changes the weighted percentile references for every scarcity band.'
                ),
                $field(
                    'battery.dynamicReserve.referencePriceCapEurPerKwh',
                    'Scarcity reference-price cap',
                    $reserve['referencePriceCapEurPerKwh'],
                    'Maximum historical consumer price allowed when valuing protected battery energy. This prevents exceptional price spikes from creating an excessive scarcity penalty.',
                    'currency_rate',
                    '€/kWh',
                    0.80,
                    'Caps exceptional historical spikes while retaining a strong scarcity signal.',
                    'planned',
                    'Zero or greater.',
                    'Limits the virtual value calculated for all scarcity bands.'
                ),
                $field(
                    'battery.dynamicReserve.cheapGridChargePercentile',
                    'Cheap-grid boundary percentile',
                    $reserve['cheapGridChargePercentile'],
                    'Historical price percentile used to classify future hours as cheap enough to count as grid-refill opportunities.',
                    'percent',
                    '%',
                    25,
                    'The lowest quarter of historical prices is a practical definition of cheap charging.',
                    'planned',
                    'Between 0% and 100%.',
                    'Influences forecast refill capability and therefore which scarcity bands activate.'
                ),
            ],
        ],
        [
            'id' => 'battery-model',
            'title' => 'Advanced battery model',
            'description' => 'Physical modelling values used on the webserver. These are not the Pi controller’s SoC or safety-power limits.',
            'open' => false,
            'fields' => [
                $field(
                    'battery.capacityWh',
                    'Nominal battery capacity',
                    $battery['capacityWh'],
                    'Total battery capacity used to translate state-of-charge percentages into watt-hours for planning, graphs, forecasts, and backtests.',
                    'integer',
                    'Wh',
                    5760,
                    'Use the installed system’s documented usable nominal capacity.',
                    'advanced',
                    'Positive whole number.',
                    'Changing this alters modeled energy quantities throughout the web application.'
                ),
                $field(
                    'battery.efficiency',
                    'Legacy forecast efficiency',
                    $battery['efficiency'],
                    'One-way efficiency assumption used by legacy battery forecasts and schedule tools. It is separate from the rolling optimizer’s round-trip efficiency.',
                    'ratio',
                    '%',
                    0.90,
                    '90% remains the established one-way forecast assumption.',
                    'advanced',
                    'Greater than 0% and no more than 100%.',
                    'Used by legacy webserver forecast and backtest calculations.'
                ),
            ],
        ],
        [
            'id' => 'forecast-inputs',
            'title' => 'Forecast inputs',
            'description' => 'Solar and household-demand assumptions used when constructing future optimizer slots.',
            'open' => true,
            'fields' => [
                $field(
                    'forecast.solarSafetyPercent',
                    'Solar forecast safety',
                    $forecast['solarSafetyPercent'],
                    'Percentage of forecast solar production treated as safely available. A value below 100% adds a buffer against optimistic weather forecasts.',
                    'percent',
                    '%',
                    90,
                    '90% keeps a modest ten-percent forecast buffer.',
                    'planned',
                    'Between 0% and 100%.',
                    'Used by webserver battery forecasts; the rolling optimizer uses its own forecast slot construction.'
                ),
                $field(
                    'forecast.householdUsageProfilePath',
                    'Generated household-profile path',
                    $forecast['householdUsageProfilePath'],
                    'Repository-relative location of the generated household-demand profile. The optimizer uses that profile when it is valid and falls back to the configured hourly values otherwise.',
                    'path',
                    null,
                    null,
                    'No universal default; the path must match the deployed repository layout.',
                    'protected',
                    'Must resolve inside the repository.',
                    'Normally changed only when the profile-generation layout changes.'
                ),
            ],
        ],
        [
            'id' => 'schedule-limits',
            'title' => 'Optimizer command limits',
            'description' => 'Webserver scheduling range and resolution. These are distinct from the Pi controller’s physical safety caps.',
            'open' => true,
            'fields' => [
                $field(
                    'schedule.minPowerW',
                    'Maximum scheduled discharge',
                    $schedule['minPowerW'],
                    'Most-negative fixed power the webserver schedule may request. Negative values represent battery discharge.',
                    'signed_power',
                    'W',
                    -1800,
                    'Leaves headroom below the current physical discharge cap.',
                    'planned',
                    'Must be below zero, align with the power step, and remain within the Pi safety cap.',
                    'Limits optimizer actions and schedule editing on the webserver.'
                ),
                $field(
                    'schedule.maxPowerW',
                    'Maximum scheduled charge',
                    $schedule['maxPowerW'],
                    'Largest fixed charging power the webserver schedule may request. Positive values represent charging.',
                    'power',
                    'W',
                    1200,
                    'Matches the current supported charging range.',
                    'planned',
                    'Must be above zero, align with the power step, and remain within the Pi safety cap.',
                    'Limits optimizer actions and schedule editing on the webserver.'
                ),
                $field(
                    'schedule.powerStepW',
                    'Power step',
                    $schedule['powerStepW'],
                    'Increment used when the optimizer evaluates candidate battery powers and when schedule controls quantize entered values.',
                    'integer',
                    'W',
                    100,
                    'A 100 W step gives useful resolution without making optimization unnecessarily expensive.',
                    'planned',
                    'Positive whole number; related power limits and deadband must be exact multiples.',
                    'A smaller value increases optimizer state exploration and runtime.'
                ),
                $field(
                    'schedule.activeHourDeadbandW',
                    'Active-hour deadband',
                    $schedule['activeHourDeadbandW'],
                    'Minimum modeled power difference required before replacing the already-published command within the same clock hour. Zero disables stabilization.',
                    'integer',
                    'W',
                    0,
                    'Zero is appropriate while every recalculation should publish its preferred active-hour command.',
                    'planned',
                    'Zero or a positive multiple of the power step.',
                    'Used only during executable optimizer-plan publication.'
                ),
            ],
        ],
        [
            'id' => 'installation',
            'title' => 'Webserver installation model',
            'description' => 'Location metadata used by solar calculations and page presentation. The shared timezone is Pi-owned and intentionally excluded.',
            'open' => false,
            'fields' => [
                $field(
                    'installation.name',
                    'Installation name',
                    $installation['name'],
                    'Human-readable label for the configured installation. It currently serves as metadata and does not alter optimizer decisions.',
                    'text',
                    null,
                    null,
                    'Site-specific; there is no meaningful universal default.',
                    'planned',
                    'Non-empty text.',
                    'Presentation metadata only.'
                ),
                $field(
                    'installation.latitude',
                    'Latitude',
                    $installation['latitude'],
                    'North-south coordinate used with longitude and time to request radiation data and calculate local sunrise and sunset.',
                    'coordinate',
                    '°',
                    null,
                    'Site-specific; use the actual installation coordinate.',
                    'advanced',
                    'Between -90° and 90°.',
                    'Influences solar timing and forecast inputs.'
                ),
                $field(
                    'installation.longitude',
                    'Longitude',
                    $installation['longitude'],
                    'East-west coordinate used with latitude and time to request radiation data and calculate local sunrise and sunset.',
                    'coordinate',
                    '°',
                    null,
                    'Site-specific; use the actual installation coordinate.',
                    'advanced',
                    'Between -180° and 180°.',
                    'Influences solar timing and forecast inputs.'
                ),
            ],
        ],
        [
            'id' => 'price-conversion',
            'title' => 'Price conversion',
            'description' => 'Tariff components used to translate between consumer purchase prices and spot-market sale prices.',
            'open' => true,
            'fields' => [
                $field(
                    'priceConversion.supplierMarkupEurPerKwh',
                    'Supplier markup',
                    $prices['supplierMarkupEurPerKwh'],
                    'Supplier’s fixed per-kWh markup removed when converting a VAT-inclusive consumer price back to its underlying spot price.',
                    'currency_rate',
                    '€/kWh',
                    null,
                    'Contract-specific; use the current electricity-supply agreement.',
                    'planned',
                    'Zero or greater.',
                    'Affects displayed sale prices, optimizer export value, and P&L calculations.'
                ),
                $field(
                    'priceConversion.energyTaxEurPerKwh',
                    'Energy tax',
                    $prices['energyTaxEurPerKwh'],
                    'Per-kWh energy tax included in the consumer purchase price and removed when deriving the underlying spot price.',
                    'currency_rate',
                    '€/kWh',
                    null,
                    'Tax-year and jurisdiction specific; update from the applicable tariff.',
                    'planned',
                    'Zero or greater.',
                    'Affects displayed sale prices, optimizer export value, and P&L calculations.'
                ),
                $field(
                    'priceConversion.vatMultiplier',
                    'VAT multiplier',
                    $prices['vatMultiplier'],
                    'Multiplier used to remove VAT from the consumer price during spot-price conversion. For 21% VAT the multiplier is 1.21.',
                    'number',
                    '×',
                    null,
                    'Jurisdiction-specific; 1.21 represents 21% VAT.',
                    'planned',
                    'Must be greater than zero.',
                    'Affects every consumer-to-spot conversion.'
                ),
                $field(
                    'priceConversion.consumerPrecision',
                    'Consumer-price precision',
                    $prices['consumerPrecision'],
                    'Number of decimal places retained when calculating and presenting consumer purchase prices.',
                    'integer',
                    'decimals',
                    4,
                    'Four decimals preserves sub-cent tariff detail without noisy display values.',
                    'planned',
                    'Whole number from 0 through 12.',
                    'Controls rounding of consumer-price conversions.'
                ),
                $field(
                    'priceConversion.spotPrecision',
                    'Spot-price precision',
                    $prices['spotPrecision'],
                    'Number of decimal places retained when deriving spot-market prices used for sale valuation.',
                    'integer',
                    'decimals',
                    6,
                    'Six decimals avoids material rounding during repeated price calculations.',
                    'planned',
                    'Whole number from 0 through 12.',
                    'Controls rounding of derived spot prices.'
                ),
            ],
        ],
    ];

    foreach ($reserve['bands'] as $index => $band) {
        $number = $index + 1;
        $prefix = "battery.dynamicReserve.bands.{$index}";
        $bandDefault = $reserveBandDefaults[$index] ?? null;
        $groups[1]['fields'][] = $field(
            "{$prefix}.fromSocPercent",
            "Band {$number} start SoC",
            $band['fromSocPercent'],
            "Lower state-of-charge boundary of scarcity band {$number}. Energy discharged through this band can receive its additional virtual scarcity value when the band is active.",
            'percent',
            '% SoC',
            $bandDefault['from'] ?? null,
            $bandDefault === null ? 'No universal default for an additional band.' : 'The original three-band policy is the recommended installation baseline.',
            'linked',
            $index === 0
                ? 'Must equal the Pi-owned minimum SoC; later bands must start where the previous band ends.'
                : 'Must equal the previous band’s ending SoC.',
            'Defines which stored energy receives this band’s scarcity value.'
        );
        $groups[1]['fields'][] = $field(
            "{$prefix}.toSocPercent",
            "Band {$number} end SoC",
            $band['toSocPercent'],
            "Upper state-of-charge boundary of scarcity band {$number}. A higher boundary protects a larger portion of the battery when this band activates.",
            'percent',
            '% SoC',
            $bandDefault['to'] ?? null,
            $bandDefault === null ? 'No universal default for an additional band.' : 'The original three-band policy is the recommended installation baseline.',
            'planned',
            'Must exceed the start, remain below maximum SoC, and meet the next band without a gap.',
            'Controls the amount of battery energy covered by this band.'
        );
        $groups[1]['fields'][] = $field(
            "{$prefix}.pricePercentile",
            "Band {$number} price percentile",
            $band['pricePercentile'],
            "Historical consumer-price percentile used as the reference value for energy in scarcity band {$number}. A higher percentile requires a more exceptional price before protected energy is spent.",
            'percentile',
            'percentile',
            $bandDefault['percentile'] ?? null,
            $bandDefault === null ? 'No universal default for an additional band.' : 'The original descending percentile ladder is the recommended installation baseline.',
            'planned',
            'Between 0 and 100; each shallower band must be lower than the band below it.',
            'Determines this band’s virtual scarcity value.'
        );
        $groups[1]['fields'][] = $field(
            "{$prefix}.activateWhenExpectedRefillBelowPercent",
            "Band {$number} refill activation",
            $band['activateWhenExpectedRefillBelowPercent'],
            "Scarcity band {$number} activates when forecast refill capability is at or below this percentage of the current gap to maximum SoC.",
            'percent',
            '% refill',
            $bandDefault['refill'] ?? null,
            $bandDefault === null ? 'No universal default for an additional band.' : 'The original descending activation ladder is the recommended installation baseline.',
            'planned',
            'Between 0% and 100%; each shallower band must be lower than the band below it.',
            'Controls when this band starts influencing optimizer discharge decisions.'
        );
    }

    $usageFields = [];
    foreach ($forecast['defaultHouseholdUsageWByHour'] as $hour => $watts) {
        $hourLabel = is_int($hour) ? sprintf('%02d:00', $hour) : (string) $hour;
        $usageFields[] = $field(
            "forecast.defaultHouseholdUsageWByHour.{$hourLabel}",
            "Fallback load at {$hourLabel}",
            $watts,
            "Expected average household demand during the hour starting at {$hourLabel}. This value is used only when the generated household profile is missing, stale, or invalid.",
            'integer',
            'W',
            null,
            'No universal default; prefer a measured household profile for this installation.',
            'planned',
            'Zero or a positive whole number.',
            'Used as part of the complete 24-hour fallback profile.'
        );
    }
    array_splice($groups, 4, 0, [[
        'id' => 'fallback-load-profile',
        'title' => 'Fallback household profile',
        'description' => 'Twenty-four hourly demand values used together when the generated profile is unavailable or invalid.',
        'open' => false,
        'fields' => $usageFields,
    ]]);

    return $groups;
}

