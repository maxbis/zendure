<?php
/**
 * Build numeric context values derived from daily price data.
 *
 * Prices are interpreted as EUR/kWh in source files and normalized to cents/kWh
 * for condition evaluation to match existing "price >= 25" usage.
 *
 * @param array $priceByHour
 * @return array{
 *   min_price:?float,
 *   max_price:?float,
 *   min_price_hour:?int,
 *   max_price_hour:?int,
 *   max_price_hour_am:?int,
 *   max_price_hour_pm:?int,
 *   spread_price:?float,
 *   ranking_by_hour:array<int,int>,
 *   rank_to_hour:array<int,int>
 * }
 */
function buildPriceContext(array $priceByHour): array
{
    $minPrice = null;
    $maxPrice = null;
    $minHour = null;
    $maxHour = null;
    $maxPriceAm = null;
    $maxHourAm = null;
    $maxPricePm = null;
    $maxHourPm = null;
    $pairs = [];

    for ($hour = 0; $hour < 24; $hour++) {
        $hourKey = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
        if (!array_key_exists($hourKey, $priceByHour) || !is_numeric($priceByHour[$hourKey])) {
            continue;
        }
        $priceCents = $priceByHour[$hourKey] * 100.00;
        $pairs[] = ['hour' => $hour, 'price' => $priceCents];

        if ($minPrice === null || $priceCents < $minPrice) {
            $minPrice = $priceCents;
            $minHour = $hour;
        }
        if ($maxPrice === null || $priceCents > $maxPrice) {
            $maxPrice = $priceCents;
            $maxHour = $hour;
        }
        if ($hour < 12) {
            if ($maxPriceAm === null || $priceCents > $maxPriceAm) {
                $maxPriceAm = $priceCents;
                $maxHourAm = $hour;
            }
        } else {
            if ($maxPricePm === null || $priceCents > $maxPricePm) {
                $maxPricePm = $priceCents;
                $maxHourPm = $hour;
            }
        }
    }

    usort($pairs, function ($a, $b) {
        if ($a['price'] < $b['price']) {
            return -1;
        }
        if ($a['price'] > $b['price']) {
            return 1;
        }
        // Within equal price, earlier hour gets lower rank.
        return $a['hour'] - $b['hour'];
    });

    $rankingByHour = [];
    $rankToHour = [];
    foreach ($pairs as $idx => $pair) {
        $rank = $idx + 1; // 1-based rank from lowest to highest price
        $hourVal = (int) $pair['hour'];
        $rankingByHour[$hourVal] = $rank; // hour -> rank
        $rankToHour[$rank] = $hourVal;    // rank -> hour
    }

    return [
        'min_price' => $minPrice,
        'max_price' => $maxPrice,
        'min_price_hour' => $minHour,
        'max_price_hour' => $maxHour,
        'max_price_hour_am' => $maxHourAm,
        'max_price_hour_pm' => $maxHourPm,
        'spread_price' => ($minPrice !== null && $maxPrice !== null) ? ($maxPrice - $minPrice) : null,
        'ranking_by_hour' => $rankingByHour,
        'rank_to_hour' => $rankToHour,
    ];
}
