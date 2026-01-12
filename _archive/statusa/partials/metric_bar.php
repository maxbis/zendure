<?php
/**
 * Unidirectional Bar Metric Item Partial
 * Displays a metric with a label, value, and a progress bar (0 to max)
 * 
 * @param string $label The metric label
 * @param string|int|float $value The metric value
 * @param array $barData Bar data from calculateBarWidth() or calculateBarWidthNonLinear()
 * @param string $barColor Color for the bar fill
 * @param int|float $min Minimum value for the bar (default: 0)
 * @param int|float $max Maximum value for the bar
 * @param string|null $valueColor Optional color for the value text
 * @param string|null $valueSuffix Optional suffix for value (e.g., 'W', '%', '°C')
 * @param string|null $leftLabel Optional left label for bar (default: min value)
 * @param string|null $rightLabel Optional right label for bar (default: max value)
 * @param string|null $ariaLabel Optional ARIA label for accessibility
 * @param bool $showValueInBar Whether to show the value inside the bar
 * @param array|null $extraValueContent Optional extra content to display after value (e.g., warning icons)
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
    <div class="metric-label">
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
    <div class="metric-label">
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
                <?php if ($showValueInBar && shouldDisplayBarValue($barData['percent'])): ?>
                    <?php echo htmlspecialchars($valueDisplay); ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
<?php if (!$noWrapper): ?>
</div>
<?php endif; ?>

