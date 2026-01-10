<?php
/**
 * Simple Metric Item Partial
 * Displays a metric with just a label and value (no bar)
 * 
 * @param string $label The metric label
 * @param string|int|float $value The metric value (can be HTML)
 * @param string|null $valueColor Optional color for the value
 * @param string|null $ariaLabel Optional ARIA label for accessibility
 */
if (!isset($label) || !isset($value)) {
    return;
}
$ariaLabel = $ariaLabel ?? $label;
$valueColor = $valueColor ?? null;
?>
<div class="metric-item" role="status" aria-label="<?php echo htmlspecialchars($ariaLabel); ?>">
    <div class="metric-label">
        <span><?php echo htmlspecialchars($label); ?></span>
        <div class="metric-value"<?php echo $valueColor ? ' style="color: ' . htmlspecialchars($valueColor) . ';"' : ''; ?>>
            <?php echo $value; ?>
        </div>
    </div>
</div>
