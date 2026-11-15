<dialog
    class="gsd-dialog app-scarcity-dialog"
    id="app-scarcity-dialog"
    aria-labelledby="app-scarcity-dialog-title"
    data-component="scarcity-dialog"
>
    <header class="gsd-dialog__header app-scarcity-dialog__header">
        <h2 class="gsd-dialog__title app-scarcity-dialog__title" id="app-scarcity-dialog-title">
            <span class="app-scarcity-dots" data-role="scarcity-dialog-dots" aria-hidden="true">
                <i data-state="loading"></i>
            </span>
            <span>Scarcity bands</span>
        </h2>
        <div class="app-scarcity-dialog__actions">
            <span class="app-state-pill scarcity-v1__pill" data-role="scarcity-refill-pill">Checking</span>
            <button class="gsd-icon-btn" type="button" aria-label="Close scarcity overview" data-gsd-dialog-close>
                <svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#close"></use></svg>
            </button>
        </div>
    </header>

    <div class="app-scarcity-dialog__loading" data-role="scarcity-loading" role="status">
        <span class="app-loading-orb" aria-hidden="true"></span>
        <span>Loading scarcity status</span>
    </div>

    <div class="app-scarcity-dialog__error" data-role="scarcity-error" role="alert" hidden>
        <svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#error"></use></svg>
        <span data-role="scarcity-error-message">Scarcity information could not be loaded.</span>
        <button class="gsd-btn gsd-btn--secondary" type="button" data-role="scarcity-retry">Try again</button>
    </div>

    <div class="gsd-dialog__body app-scarcity-dialog__body" data-role="scarcity-content" hidden>
        <?php require __DIR__ . '/scarcity-battery.php'; ?>
    </div>
</dialog>
