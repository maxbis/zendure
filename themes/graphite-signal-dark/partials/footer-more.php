<?php
/**
 * Shared Graphite Signal Dark expandable "More" footer menu.
 *
 * Expected variables:
 * - $gsdFooterMoreItems (array): list of menu entries. Each entry:
 *   - href (string, required unless dialogId is provided)
 *   - dialogId (string, optional): renders a button targeting a dialog
 *   - label (string, required)
 *   - description (string, optional)
 *   - icon (string, optional sprite symbol id; default "bidirectional")
 * - $gsdFooterMoreSpriteHref (string, optional): path to sprite.svg
 * - $gsdFooterMorePanelId (string, optional): id for the expandable panel
 */

if (!isset($gsdFooterMoreItems) || !is_array($gsdFooterMoreItems) || $gsdFooterMoreItems === []) {
    return;
}

$gsdFooterMoreSpriteHref = $gsdFooterMoreSpriteHref
    ?? '../themes/graphite-signal-dark/assets/icons/sprite.svg';
$gsdFooterMorePanelId = $gsdFooterMorePanelId ?? 'gsd-footer-more-panel';
?>
<footer
    class="gsd-footer-more"
    data-gsd-footer-more
    data-theme="graphite-signal-dark"
>
    <div
        class="gsd-footer-more__panel"
        id="<?= htmlspecialchars($gsdFooterMorePanelId, ENT_QUOTES, 'UTF-8'); ?>"
        data-role="gsd-footer-more-panel"
        hidden
    >
        <nav class="gsd-footer-more__nav" aria-label="More">
            <?php foreach ($gsdFooterMoreItems as $item): ?>
                <?php
                if (!is_array($item)) {
                    continue;
                }
                $href = isset($item['href']) ? (string) $item['href'] : '';
                $dialogId = isset($item['dialogId']) ? trim((string) $item['dialogId']) : '';
                $label = isset($item['label']) ? (string) $item['label'] : '';
                if (($href === '' && $dialogId === '') || $label === '') {
                    continue;
                }
                $description = isset($item['description']) ? (string) $item['description'] : '';
                $icon = isset($item['icon']) && (string) $item['icon'] !== ''
                    ? (string) $item['icon']
                    : 'bidirectional';
                ?>
                <?php if ($dialogId !== ''): ?>
                    <button
                        class="gsd-footer-more__item"
                        type="button"
                        aria-haspopup="dialog"
                        data-gsd-dialog-target="<?= htmlspecialchars($dialogId, ENT_QUOTES, 'UTF-8'); ?>"
                    >
                <?php else: ?>
                    <a
                        class="gsd-footer-more__item"
                        href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>"
                    >
                <?php endif; ?>
                    <span class="gsd-footer-more__item-icon" aria-hidden="true">
                        <svg class="gsd-icon">
                            <use href="<?= htmlspecialchars($gsdFooterMoreSpriteHref . '#' . $icon, ENT_QUOTES, 'UTF-8'); ?>"></use>
                        </svg>
                    </span>
                    <span class="gsd-footer-more__item-copy">
                        <span class="gsd-footer-more__item-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php if ($description !== ''): ?>
                            <span class="gsd-footer-more__item-description"><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="gsd-footer-more__item-chevron" aria-hidden="true">
                        <svg class="gsd-icon">
                            <use href="<?= htmlspecialchars($gsdFooterMoreSpriteHref . '#chevron-right', ENT_QUOTES, 'UTF-8'); ?>"></use>
                        </svg>
                    </span>
                <?php if ($dialogId !== ''): ?>
                    </button>
                <?php else: ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
    </div>

    <div class="gsd-footer-more__bar">
        <button
            class="gsd-footer-more__toggle"
            type="button"
            data-role="gsd-footer-more-toggle"
            aria-expanded="false"
            aria-controls="<?= htmlspecialchars($gsdFooterMorePanelId, ENT_QUOTES, 'UTF-8'); ?>"
        >
            <svg class="gsd-icon" aria-hidden="true">
                <use href="<?= htmlspecialchars($gsdFooterMoreSpriteHref . '#more', ENT_QUOTES, 'UTF-8'); ?>"></use>
            </svg>
            <span>More</span>
            <svg class="gsd-icon gsd-footer-more__toggle-chevron" aria-hidden="true">
                <use href="<?= htmlspecialchars($gsdFooterMoreSpriteHref . '#chevron-up', ENT_QUOTES, 'UTF-8'); ?>"></use>
            </svg>
        </button>
    </div>
</footer>
