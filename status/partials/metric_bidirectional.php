<?php
/**
 * Bidirectional Bar Metric Item Partial
 * Displays a metric with a label, value, and a bidirectional progress bar (negative to positive)
 * 
 * @param string $label The metric label
 * @param int|float $value The metric value (can be negative or positive)
 * @param array $barData Bar data from getBidirectionalBarDataNonLinear()
 * @param string $barClass CSS class for the bar (e.g., 'charging', 'discharging', 'importing', 'exporting')
 * @param int|float $min Minimum value for the bar (negative)
 * @param int|float $max Maximum value for the bar (positive)
 * @param string|null $valueColor Optional color for the value text
 * @param string|null $valueSuffix Optional suffix for value (e.g., 'W')
 * @param string|null $leftLabel Optional left label for bar (default: min value)
 * @param string|null $centerLabel Optional center label (default: '0')
 * @param string|null $rightLabel Optional right label for bar (default: max value)
 * @param string|null $ariaLabel Optional ARIA label for accessibility
 * @param string|null $statusText Optional status text for ARIA (e.g., 'charging', 'discharging', 'exporting', 'importing')
 */
if (!isset($label) || !isset($value) || !isset($barData) || !isset($barClass) || !isset($min) || !isset($max)) {
    return;
}
$ariaLabel = $ariaLabel ?? $label;
$valueColor = $valueColor ?? null;
$valueSuffix = $valueSuffix ?? '';
$leftLabel = $leftLabel ?? (string)$min . $valueSuffix;
$centerLabel = $centerLabel ?? '0';
$rightLabel = $rightLabel ?? (string)$max . $valueSuffix;
$statusText = $statusText ?? ($value > 0 ? 'positive' : ($value < 0 ? 'negative' : 'zero'));

// Build value display
$valueDisplay = abs($value);
if ($valueSuffix) {
    $valueDisplay .= ' ' . $valueSuffix;
}
// Handle optional extra content (e.g., time remaining)
$extraValueContent = $extraValueContent ?? null;
?>
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
    <div class="bidirectional-bar-container" role="progressbar" aria-valuemin="<?php echo $min; ?>" aria-valuemax="<?php echo $max; ?>" aria-valuenow="<?php echo $value; ?>" aria-label="<?php echo htmlspecialchars($ariaLabel . ': ' . $valueDisplay . ' ' . $statusText); ?>">
        <div class="bidirectional-bar-label left"><?php echo htmlspecialchars($leftLabel); ?></div>
        <div class="bidirectional-bar-label center"><?php echo htmlspecialchars($centerLabel); ?></div>
        <div class="bidirectional-bar-label right"><?php echo htmlspecialchars($rightLabel); ?></div>
        <div class="bidirectional-bar-center"></div>
        <?php if ($barData['width'] > 0): ?>
            <div class="bidirectional-bar-fill <?php echo htmlspecialchars($barClass); ?>" style="width: <?php echo $barData['width']; ?>%; min-width: 8px;">
            </div>
        <?php endif; ?>
    </div>
</div>

