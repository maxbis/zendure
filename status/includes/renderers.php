<?php
/**
 * Rendering Functions
 * Functions for rendering metric components using partials
 */

/**
 * Render a simple metric item (label + value only)
 * 
 * @param string $label The metric label
 * @param string|int|float $value The metric value (can be HTML)
 * @param string|null $valueColor Optional color for the value
 * @param string|null $ariaLabel Optional ARIA label
 */
function renderMetricSimple($label, $value, $valueColor = null, $ariaLabel = null) {
    $partialPath = __DIR__ . '/../partials/metric_simple.php';
    if (file_exists($partialPath)) {
        include $partialPath;
    }
}

/**
 * Render a unidirectional bar metric item
 * 
 * @param string $label The metric label
 * @param string|int|float $value The metric value
 * @param array $barData Bar data from calculateBarWidth() or calculateBarWidthNonLinear()
 * @param string $barColor Color for the bar fill
 * @param int|float $min Minimum value (default: 0)
 * @param int|float $max Maximum value
 * @param string|null $valueColor Optional color for the value text
 * @param string|null $valueSuffix Optional suffix (e.g., 'W', '%', '°C')
 * @param string|null $leftLabel Optional left label
 * @param string|null $rightLabel Optional right label
 * @param string|null $ariaLabel Optional ARIA label
 * @param bool $showValueInBar Whether to show value inside bar
 * @param string|array|null $extraValueContent Optional extra content after value
 * @param bool $noWrapper If true, don't wrap in metric-item div
 */
function renderMetricBar($label, $value, $barData, $barColor, $min = 0, $max = 100, $valueColor = null, $valueSuffix = '', $leftLabel = null, $rightLabel = null, $ariaLabel = null, $showValueInBar = false, $extraValueContent = null, $noWrapper = false) {
    $partialPath = __DIR__ . '/../partials/metric_bar.php';
    if (file_exists($partialPath)) {
        include $partialPath;
    }
}

/**
* Render a bidirectional bar metric item
* 
* @param string $label The metric label
* @param int|float $value The metric value (can be negative)
* @param array $barData Bar data from getBidirectionalBarDataNonLinear()
* @param string $barClass CSS class for the bar
* @param int|float $min Minimum value (negative)
* @param int|float $max Maximum value (positive)
* @param string|null $valueColor Optional color for the value text
* @param string|null $valueSuffix Optional suffix (e.g., 'W')
* @param string|null $leftLabel Optional left label
* @param string|null $centerLabel Optional center label
* @param string|null $rightLabel Optional right label
* @param string|null $ariaLabel Optional ARIA label
* @param string|null $statusText Optional status text for ARIA
* @param string|array|null $extraValueContent Optional extra content after value (e.g., time remaining)
*/
function renderMetricBidirectional($label, $value, $barData, $barClass, $min, $max, $valueColor = null, $valueSuffix = '', $leftLabel = null, $centerLabel = null, $rightLabel = null, $ariaLabel = null, $statusText = null, $extraValueContent = null) {
    $partialPath = __DIR__ . '/../partials/metric_bidirectional.php';
    if (file_exists($partialPath)) {
        include $partialPath;
    }
}

