<dialog
    class="gsd-dialog app-scarcity-dialog"
    id="app-scarcity-dialog"
    aria-labelledby="app-scarcity-dialog-title"
    data-component="scarcity-dialog"
>
    <header class="gsd-dialog__header app-scarcity-dialog__header">
        <h2 class="gsd-dialog__title app-scarcity-dialog__title" id="app-scarcity-dialog-title" aria-label="Scarcity bands">
            <button
                class="app-scarcity-dialog__title-trigger"
                type="button"
                data-role="scarcity-bands-trigger"
                aria-label="Show scarcity band prices"
                aria-haspopup="dialog"
                aria-controls="app-scarcity-bands-dialog"
                aria-expanded="false"
            >
                <span class="app-scarcity-dots" data-role="scarcity-dialog-dots" aria-hidden="true">
                    <i data-state="loading"></i>
                </span>
                <span>Scarcity bands</span>
            </button>
        </h2>
        <div class="app-scarcity-dialog__actions">
            <button
                class="app-state-pill scarcity-v1__pill scarcity-v1__refill-trigger"
                type="button"
                data-role="scarcity-refill-pill"
                aria-label="Explain scheduled refill percentage"
                aria-haspopup="dialog"
                aria-controls="app-scarcity-refill-dialog"
                aria-expanded="false"
            >Checking</button>
            <button class="gsd-icon-btn" type="button" aria-label="Close scarcity overview" data-gsd-dialog-close data-gsd-initial-focus>
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

    <dialog class="gsd-dialog scarcity-v1__bands-dialog app-scarcity-detail-dialog" id="app-scarcity-bands-dialog" aria-labelledby="app-scarcity-bands-dialog-title">
        <header class="gsd-dialog__header gsd-dialog__header--simple">
            <h3 class="gsd-dialog__title" id="app-scarcity-bands-dialog-title">Scarcity bands</h3>
            <button class="gsd-icon-btn" type="button" aria-label="Close scarcity band prices" data-gsd-dialog-close>
                <svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#close"></use></svg>
            </button>
        </header>
        <div class="gsd-dialog__body scarcity-v1__bands-dialog-body">
            <div class="scarcity-v1__bands-list" data-role="scarcity-bands-list"></div>
        </div>
    </dialog>

    <dialog class="gsd-dialog scarcity-v1__refill-dialog app-scarcity-detail-dialog" id="app-scarcity-refill-dialog" aria-labelledby="app-scarcity-refill-dialog-title">
        <header class="gsd-dialog__header gsd-dialog__header--simple">
            <h3 class="gsd-dialog__title" id="app-scarcity-refill-dialog-title">Scheduled refill</h3>
            <button class="gsd-icon-btn" type="button" aria-label="Close scheduled refill explanation" data-gsd-dialog-close>
                <svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#close"></use></svg>
            </button>
        </header>
        <div class="gsd-dialog__body scarcity-v1__refill-dialog-body">
            <p class="scarcity-v1__refill-explanation" data-role="scarcity-refill-explanation">Waiting for the scheduled refill calculation.</p>
            <dl class="scarcity-v1__refill-facts">
                <div><dt>Starting SoC</dt><dd data-role="scarcity-refill-starting-soc">—</dd></div>
                <div><dt>Maximum allowed</dt><dd data-role="scarcity-refill-maximum-soc">—</dd></div>
                <div><dt>Available gap</dt><dd data-role="scarcity-refill-available-gap">—</dd></div>
                <div><dt>Scheduled gain</dt><dd data-role="scarcity-refill-scheduled-gain">—</dd></div>
            </dl>
            <p class="scarcity-v1__refill-equation" data-role="scarcity-refill-equation">—</p>
        </div>
    </dialog>
</dialog>
