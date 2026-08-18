<?php
$appPricePlanReadOnly = $appPricePlanReadOnly ?? false;
?>
        <section
            class="gsd-card app-price-plan"
            data-component="price-plan"
            data-mode="<?= $appPricePlanReadOnly ? 'simulation' : 'live'; ?>"
            data-state="loading"
            aria-labelledby="price-plan-title"
            aria-busy="true"
        >
            <header class="app-section-heading">
                <div>
                    <h2 id="price-plan-title">Prices &amp; energy plan</h2>
                    <p data-role="price-plan-date">Loading today and tomorrow</p>
                </div>
                <div class="app-section-heading__actions">
                    <span class="app-tomorrow-status" data-role="tomorrow-status">
                        <span class="app-day-availability" data-role="tomorrow-availability" data-availability="loading" aria-hidden="true"></span>
                        <span data-role="tomorrow-status-label">Checking tomorrow</span>
                    </span>
                    <button
                        class="gsd-icon-btn app-price-view-toggle"
                        type="button"
                        data-role="price-view-toggle"
                        aria-label="Show 48-hour overview"
                        title="Show 48-hour overview"
                        aria-pressed="false"
                    >
                        <svg class="gsd-icon" aria-hidden="true"><use data-role="price-view-icon" href="../themes/graphite-signal-dark/assets/icons/sprite.svg#chart-bars-detail"></use></svg>
                    </button>
                    <button class="gsd-icon-btn app-price-refresh" type="button" aria-label="Refresh prices and energy plan" data-role="price-refresh">
                        <svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#refresh"></use></svg>
                    </button>
                    <span class="gsd-sr-only" data-role="price-view-status" aria-live="polite"></span>
                </div>
            </header>

            <div class="app-price-plan__loading" data-role="price-loading" role="status">
                <span class="app-loading-orb" aria-hidden="true"></span>
                <span>Loading prices and resolved schedule</span>
            </div>

            <div class="app-price-plan__error" data-role="price-error" role="alert" hidden>
                <span data-role="price-error-message">Price and schedule data could not be loaded.</span>
                <button class="gsd-btn gsd-btn--secondary" type="button" data-role="price-retry">Try again</button>
            </div>

            <div class="app-price-plan__content" data-role="price-content" hidden>
                <div class="app-price-summary" aria-label="<?= $appPricePlanReadOnly ? 'Price summary for the historical simulation' : 'Price summary for the current hour through tomorrow'; ?>">
                    <button class="app-price-kpi" type="button" data-role="price-current-kpi" disabled>
                        <span data-role="price-current-label">Current</span>
                        <strong data-role="price-current">—</strong>
                    </button>
                    <button class="app-price-kpi" type="button" data-role="price-low-kpi" disabled>
                        <span class="app-price-kpi__label--desktop"><?= $appPricePlanReadOnly ? 'Simulation low' : 'From now low'; ?></span>
                        <span class="app-price-kpi__label--mobile" aria-hidden="true">Low</span>
                        <strong class="app-price-kpi--low" data-role="price-low">—</strong>
                    </button>
                    <button class="app-price-kpi" type="button" data-role="price-average-kpi" disabled>
                        <span class="app-price-kpi__label--desktop">Average</span>
                        <span class="app-price-kpi__label--mobile" aria-hidden="true">Average</span>
                        <strong data-role="price-average">—</strong>
                    </button>
                    <button class="app-price-kpi" type="button" data-role="price-high-kpi" disabled>
                        <span class="app-price-kpi__label--desktop"><?= $appPricePlanReadOnly ? 'Simulation high' : 'From now high'; ?></span>
                        <span class="app-price-kpi__label--mobile" aria-hidden="true">High</span>
                        <strong class="app-price-kpi--high" data-role="price-high">—</strong>
                    </button>
                </div>

                <div class="app-chart-scroll-shell" data-role="price-scroll-shell">
                    <button
                        class="app-chart-scroll-btn app-chart-scroll-btn--prev"
                        type="button"
                        data-role="price-scroll-prev"
                        aria-label="Scroll prices and energy plan left"
                        tabindex="-1"
                        hidden
                    >
                        <svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#chevron-left"></use></svg>
                    </button>
                    <button
                        class="app-chart-scroll-btn app-chart-scroll-btn--next"
                        type="button"
                        data-role="price-scroll-next"
                        aria-label="Scroll prices and energy plan right"
                        tabindex="-1"
                        hidden
                    >
                        <svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#chevron-right"></use></svg>
                    </button>
                    <div class="app-price-timeline-scroll" data-role="price-scroll" tabindex="0" aria-label="Scrollable hourly price and schedule timeline">
                        <div class="app-price-timeline" data-role="price-timeline"></div>
                    </div>
                </div>

                <div class="app-price-legend" aria-label="Timeline legend">
                    <span><i class="app-price-legend__swatch app-price-legend__swatch--low"></i><span class="app-price-legend__label--desktop">Low price</span><span class="app-price-legend__label--mobile">Low</span></span>
                    <span><i class="app-price-legend__swatch app-price-legend__swatch--current"></i><span class="app-price-legend__label--desktop"><?= $appPricePlanReadOnly ? 'Simulation start' : 'Current hour'; ?></span><span class="app-price-legend__label--mobile"><?= $appPricePlanReadOnly ? 'Start' : 'Now'; ?></span></span>
                    <span><i class="app-price-legend__swatch app-price-legend__swatch--high"></i><span class="app-price-legend__label--desktop">High price</span><span class="app-price-legend__label--mobile">High</span></span>
                    <span><i class="app-price-legend__swatch app-price-legend__swatch--plan"></i><span class="app-price-legend__label--desktop">Scheduled action</span><span class="app-price-legend__label--mobile">Plan</span></span>
                    <span><i class="app-price-legend__swatch app-price-legend__swatch--limited" aria-hidden="true"></i><span class="app-price-legend__label--desktop">Limit value</span><span class="app-price-legend__label--mobile">Limit</span></span>
                </div>

            </div>
        </section>

<?php if (!$appPricePlanReadOnly): ?>
    <dialog class="gsd-dialog app-schedule-edit-dialog" id="app-schedule-edit-dialog" aria-labelledby="app-schedule-edit-title">
        <header class="gsd-dialog__header gsd-dialog__header--simple">
            <div class="app-schedule-edit-dialog__heading">
                <h2 class="gsd-dialog__title" id="app-schedule-edit-title" data-role="schedule-edit-title">Edit hourly override</h2>
                <p class="app-schedule-edit-dialog__price" data-role="schedule-edit-price-summary">Price (— / —)</p>
            </div>
            <button class="gsd-icon-btn" type="button" aria-label="Close dialog" title="Close without saving changes" data-gsd-dialog-close>
                <svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#close"></use></svg>
            </button>
        </header>
        <form data-role="schedule-edit-form">
            <div class="gsd-dialog__body">
                <fieldset class="app-edit-fieldset">
                    <legend>Battery action</legend>
                    <div class="app-mode-options">
                        <label title="Balance household load using battery discharge only"><input type="radio" name="schedule-mode" value="netzero-"><span><span class="app-mode-option__heading"><svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#bolt"></use></svg><b class="app-netzero-token">NZ<span class="app-netzero-sign">−</span></b></span>Discharge-only</span></label>
                        <label title="Balance household load using battery charging or discharging"><input type="radio" name="schedule-mode" value="netzero"><span><span class="app-mode-option__heading"><svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#bidirectional"></use></svg><b class="app-netzero-token">NZ<span class="app-netzero-sign">±</span></b></span>Bidirectional</span></label>
                        <label title="Balance household load using battery charging only"><input type="radio" name="schedule-mode" value="netzero+"><span><span class="app-mode-option__heading"><svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#sun"></use></svg><b class="app-netzero-token">NZ<span class="app-netzero-sign">+</span></b></span>Charge-only</span></label>
                        <label title="Set a constant battery power value for this hour"><input type="radio" name="schedule-mode" value="fixed"><span><span class="app-mode-option__heading"><svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#battery"></use></svg><b>W</b></span>Fixed power</span></label>
                        <label title="Let the controller choose the battery action automatically"><input type="radio" name="schedule-mode" value="auto"><span><span class="app-mode-option__heading"><svg class="gsd-icon" aria-hidden="true"><use href="../themes/graphite-signal-dark/assets/icons/sprite.svg#refresh"></use></svg><b>A</b></span>Automatic</span></label>
                    </div>
                </fieldset>

                <div class="app-edit-controls">
                    <div class="gsd-field" data-role="schedule-fixed-field" hidden>
                        <label class="gsd-field__label" for="schedule-edit-watts">Fixed power</label>
                        <div class="app-input-with-unit">
                            <input class="gsd-input" id="schedule-edit-watts" name="watts" type="number" step="<?= htmlspecialchars((string) ($appConfig['powerStepW'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" inputmode="numeric">
                            <span>W</span>
                        </div>
                        <small>Positive charges the battery; negative discharges it.</small>
                        <div class="app-fixed-power-panel">
                            <div class="app-limit-value app-fixed-power-value">
                                <span>Value</span>
                                <strong data-role="schedule-fixed-display">0 W</strong>
                            </div>
                            <div class="app-fixed-slider" data-role="schedule-fixed-slider">
                                <span class="app-fixed-slider__track" aria-hidden="true"></span>
                                <span class="app-fixed-slider__selection" data-role="schedule-fixed-selection" aria-hidden="true"></span>
                                <input type="range" step="<?= htmlspecialchars((string) ($appConfig['powerStepW'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-role="schedule-fixed-range" aria-label="Fixed power value">
                            </div>
                            <p class="app-fixed-power-summary" data-role="schedule-fixed-summary">Idle: 0 W</p>
                        </div>
                    </div>

                    <div class="app-limit-editor" data-role="schedule-limit-editor">
                        <div class="app-limit-toggle" role="group" aria-label="Apply explicit power limits">
                            <label title="Do not apply explicit minimum or maximum power limits"><input type="radio" name="limits-enabled" value="off" data-role="schedule-limits-disabled"><span>Off</span></label>
                            <label title="Apply the selected minimum and maximum power limits"><input type="radio" name="limits-enabled" value="on" data-role="schedule-limits-enabled"><span>On</span></label>
                        </div>
                        <div class="app-limit-editor__fields" data-role="schedule-limit-fields" hidden>
                            <input name="minimum-power" type="hidden">
                            <input name="maximum-power" type="hidden">
                            <div class="app-limit-values">
                                <div class="app-limit-value">
                                    <span>Min</span>
                                    <strong data-role="schedule-limit-min-display">—</strong>
                                </div>
                                <div class="app-limit-value">
                                    <span>Max</span>
                                    <strong data-role="schedule-limit-max-display">—</strong>
                                </div>
                            </div>
                            <div class="app-limit-slider" data-role="schedule-limit-slider">
                                <span class="app-limit-slider__track" aria-hidden="true"></span>
                                <span class="app-limit-slider__selection" data-role="schedule-limit-selection" aria-hidden="true"></span>
                                <input type="range" step="<?= htmlspecialchars((string) ($appConfig['powerStepW'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-role="schedule-limit-min-range" aria-label="Minimum power limit">
                                <input type="range" step="<?= htmlspecialchars((string) ($appConfig['powerStepW'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-role="schedule-limit-max-range" aria-label="Maximum power limit">
                            </div>
                        </div>
                        <p class="app-limit-editor__summary" data-role="schedule-limit-summary"></p>
                    </div>
                </div>

                <p class="app-edit-error" data-role="schedule-edit-error" role="alert" hidden></p>
            </div>
            <footer class="gsd-dialog__footer">
                <button class="gsd-btn gsd-btn--secondary" type="button" title="Close without saving changes" data-gsd-dialog-close>Cancel</button>
                <button class="gsd-btn gsd-btn--primary" type="submit" title="Save this hourly override" data-role="schedule-edit-save">Save schedule</button>
            </footer>
        </form>
    </dialog>
<?php endif; ?>
