<div class="modal-backdrop" id="edit-modal">
    <div class="modal-dialog">
        <div class="modal-header">
            <div class="modal-title" id="modal-title">✏️ Edit Entry</div>
            <button class="modal-close" id="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div class="edit-modal-grid">
                <div class="form-group">
                    <label>Date (YYYYMMDD)</label>
                    <input type="text" id="inp-date" maxlength="8" placeholder="20251222">
                </div>
                <div class="form-group">
                    <label>Time (HHMM)</label>
                    <input type="text" id="inp-time" maxlength="4" placeholder="0800">
                </div>
            </div>
            <div class="helper-text edit-modal-helper">Use <code>*</code> for wildcards.</div>

            <div class="form-group">
                <label>Value Mode</label>
                <div class="edit-modal-mode-options">
                    <label class="edit-modal-toggle edit-modal-toggle-radio">
                        <input type="radio" name="val-mode" value="netzero+" label="☀️ Only">
                        <span class="edit-modal-toggle-label"><span class="edit-modal-toggle-icon">☀️</span><span>NetZero+</span></span>
                    </label>
                    <label class="edit-modal-toggle edit-modal-toggle-radio">
                        <input type="radio" name="val-mode" value="netzero" label="Net Zero" checked>
                        <span class="edit-modal-toggle-label"><span class="edit-modal-toggle-icon">🔌</span><span>NetZero</span></span>
                    </label>
                    <label class="edit-modal-toggle edit-modal-toggle-radio">
                        <input type="radio" name="val-mode" value="fixed">
                        <span class="edit-modal-toggle-label"><span class="edit-modal-toggle-icon">⚡</span><span>Watts&nbsp;(W)</span></span>
                    </label>
                    <label class="edit-modal-toggle edit-modal-toggle-radio">
                        <input type="radio" name="val-mode" value="clear" label="Clear">
                        <span class="edit-modal-toggle-label"><span class="edit-modal-toggle-icon">🗑️</span><span>Clear (0 W)</span></span>
                    </label>
                </div>
                <input type="radio" name="val-mode" value="auto" label="Auto" class="edit-modal-hidden-mode" tabindex="-1" aria-hidden="true">
            </div>
            <div class="edit-modal-value-panel">
                <div class="form-group" id="group-watts">
                    <label>Watts (Positive = Charge, Negative = Discharge)</label>
                    <input type="number" id="inp-watts" placeholder="0" step="100">
                </div>
                <div class="edit-modal-grid" id="group-constraints" style="display:none;">
                    <input type="hidden" id="inp-min-value" value="">
                    <input type="hidden" id="inp-max-value" value="">
                    <div class="edit-modal-limits-panel">
                        <div class="edit-modal-limits-toggle" role="group" aria-label="Power limits enabled">
                            <label class="edit-modal-limits-toggle-option">
                                <input type="radio" name="limits-enabled" id="limits-off" value="off">
                                <span>Off</span>
                            </label>
                            <label class="edit-modal-limits-toggle-option">
                                <input type="radio" name="limits-enabled" id="limits-on" value="on">
                                <span>On</span>
                            </label>
                        </div>
                        <div class="edit-modal-limits-values">
                            <div class="edit-modal-limits-chip">
                                <div class="edit-modal-limits-chip-header">
                                    <div class="edit-modal-limits-chip-value-row">
                                        <span class="edit-modal-limits-label">Min</span>
                                        <strong id="limits-min-display">Unset</strong>
                                    </div>
                                </div>
                            </div>
                            <div class="edit-modal-limits-chip">
                                <div class="edit-modal-limits-chip-header">
                                    <div class="edit-modal-limits-chip-value-row">
                                        <span class="edit-modal-limits-label">Max</span>
                                        <strong id="limits-max-display">Unset</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="edit-modal-limits-slider" id="limits-slider">
                            <div class="edit-modal-limits-track"></div>
                            <div class="edit-modal-limits-selected-range" id="limits-selected-range"></div>
                            <input type="range" class="edit-modal-limits-range" id="limits-min-range" aria-label="Minimum power limit">
                            <input type="range" class="edit-modal-limits-range" id="limits-max-range" aria-label="Maximum power limit">
                        </div>
                    </div>
                    <div id="power-range-indicator" class="power-range-indicator" hidden></div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-danger" id="btn-delete" style="display:none;">Delete</button>
            <div style="margin-left:auto; display:flex; gap:10px;">
                <button class="btn btn-outline" id="btn-cancel">Cancel</button>
                <button class="btn btn-primary" id="btn-save">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Confirmation Dialog -->
<div class="modal-backdrop" id="confirm-dialog">
    <div class="modal-dialog confirm-dialog">
        <div class="modal-header">
            <div class="modal-title">🗑️ Confirm Delete</div>
        </div>
        <div class="modal-body">
            <p id="confirm-message">Are you sure you want to delete this entry?</p>
        </div>
        <div class="modal-footer">
            <div style="margin-left:auto; display:flex; gap:10px;">
                <button class="btn btn-outline" id="confirm-cancel">Cancel</button>
                <button class="btn btn-danger" id="confirm-delete">Delete</button>
            </div>
        </div>
    </div>
</div>
