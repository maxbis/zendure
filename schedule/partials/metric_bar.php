<?php
/**
 * Unidirectional Bar Metric Item Partial
 * Displays a metric with a label, value, and a progress bar (0 to max)
 * 
 * Note: This partial is included from renderMetricBar() function.
 * The $barData variable is calculated internally in renderMetricBar().
 * 
 * @var string $label The metric label
 * @var string|int|float $value The metric value
 * @var array $barData Bar data calculated by renderMetricBar() (available in scope)
 * @var string $barColor Color for the bar fill
 * @var int|float $min Minimum value for the bar (default: 0)
 * @var int|float $max Maximum value for the bar
 * @var string|null $valueColor Optional color for the value text
 * @var string|null $valueSuffix Optional suffix for value (e.g., 'W', '%', '°C')
 * @var string|null $leftLabel Optional left label for bar (default: min value)
 * @var string|null $rightLabel Optional right label for bar (default: max value)
 * @var string|null $ariaLabel Optional ARIA label for accessibility
 * @var bool $showValueInBar Whether to show the value inside the bar
 * @var array|null $extraValueContent Optional extra content to display after value (e.g., warning icons)
 * @var string|null $tooltip Optional tooltip text to display on hover over the metric label
 */
if (!isset($label) || !isset($value) || !isset($barData) || !isset($barColor) || !isset($max)) {
    return;
}
$min = $min ?? 0;
$ariaLabel = $ariaLabel ?? $label;
$valueColor = $valueColor ?? null;
$valueSuffix = $valueSuffix ?? '';
$leftLabel = $leftLabel ?? (string)$min . $valueSuffix;
$rightLabel = $rightLabel ?? (string)$max . $valueSuffix;
$showValueInBar = $showValueInBar ?? false;
$extraValueContent = $extraValueContent ?? null;
$noWrapper = $noWrapper ?? false;
$tooltip = $tooltip ?? null;

// Support single centered label (for battery section style)
// If leftLabel contains a dash or range indicator, use single centered label
$useSingleLabel = (strpos($leftLabel ?? '', '-') !== false && ($rightLabel === null || $rightLabel === ''));
$singleLabel = $useSingleLabel ? $leftLabel : null;

// Build value display
$valueDisplay = $value;
if ($valueSuffix) {
    $valueDisplay .= ' ' . $valueSuffix;
}
?>
<?php if (!$noWrapper): ?>
<div class="metric-item" role="status" aria-label="<?php echo htmlspecialchars($ariaLabel); ?>">
    <div class="metric-label"<?php echo $tooltip ? ' title="' . htmlspecialchars($tooltip) . '"' : ''; ?>>
        <span><?php echo htmlspecialchars($label); ?></span>
        <div class="metric-value"<?php echo $valueColor ? ' style="color: ' . htmlspecialchars($valueColor) . ';"' : ''; ?>>
            <?php echo htmlspecialchars($valueDisplay); ?>
            <?php if ($extraValueContent): ?>
                <?php
                if (is_array($extraValueContent)) {
                    echo implode('', $extraValueContent);
                } else {
                    echo $extraValueContent;
                }
                ?>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="metric-label"<?php echo $tooltip ? ' title="' . htmlspecialchars($tooltip) . '"' : ''; ?>>
        <span><?php echo htmlspecialchars($label); ?></span>
        <div class="metric-value"<?php echo $valueColor ? ' style="color: ' . htmlspecialchars($valueColor) . ';"' : ''; ?>>
            <?php echo htmlspecialchars($valueDisplay); ?>
            <?php if ($extraValueContent): ?>
                <?php
                if (is_array($extraValueContent)) {
                    echo implode('', $extraValueContent);
                } else {
                    echo $extraValueContent;
                }
                ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
    <div class="bar-container" role="progressbar" aria-valuemin="<?php echo $min; ?>" aria-valuemax="<?php echo $max; ?>" aria-valuenow="<?php echo $value; ?>" aria-label="<?php echo htmlspecialchars($ariaLabel . ': ' . $valueDisplay); ?>">
        <?php if ($useSingleLabel): ?>
            <div class="bar-label"><?php echo htmlspecialchars($singleLabel); ?></div>
        <?php else: ?>
            <div class="bidirectional-bar-label left"><?php echo htmlspecialchars($leftLabel); ?></div>
            <div class="bidirectional-bar-label right"><?php echo htmlspecialchars($rightLabel); ?></div>
        <?php endif; ?>
        <?php if ($barData['percent'] > 0): ?>
            <div class="bar-fill" style="width: <?php echo $barData['percent']; ?>%; background: <?php echo htmlspecialchars($barColor); ?>;<?php echo isset($barData['exceedsMax']) && $barData['exceedsMax'] ? ' border-right: 3px solid #ff9800;' : ''; ?>">
                <?php if ($showValueInBar && shouldDisplayBarValue($barData['percent'], $valueDisplayThreshold ?? 10)): ?>
                    <?php echo htmlspecialchars($valueDisplay); ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
<?php if (!$noWrapper): ?>
</div>
<?php endif; ?>
