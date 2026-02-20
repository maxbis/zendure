<div class="modal-backdrop" id="edit-modal">
    <div class="modal-dialog">
        <div class="modal-header">
            <div class="modal-title" id="modal-title">✏️ Edit Entry</div>
            <button class="modal-close" id="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div class="edit-modal-grid">
                <div class="form-group">
                    <label>Date Pattern (YYYYMMDD)</label>
                    <input type="text" id="inp-date" maxlength="8" placeholder="20251222">
                </div>
                <div class="form-group">
                    <label>Time Pattern (HHmm)</label>
                    <input type="text" id="inp-time" maxlength="4" placeholder="0800">
                </div>
            </div>
            <div class="helper-text edit-modal-helper">Use <code>*</code> for wildcards.</div>

            <div class="form-group">
                <label>Value Mode</label>
                <div class="edit-modal-mode-options">
                    <label class="edit-modal-toggle edit-modal-toggle-radio">
                        <input type="radio" name="val-mode" value="fixed" checked>
                        <span class="edit-modal-toggle-label">Watts&nbsp;(W)</span>
                    </label>
                    <label class="edit-modal-toggle edit-modal-toggle-radio">
                        <input type="radio" name="val-mode" value="netzero" label="Net Zero">
                        <span class="edit-modal-toggle-label">NetZero</span>
                    </label>
                    <label class="edit-modal-toggle edit-modal-toggle-radio">
                        <input type="radio" name="val-mode" value="netzero+" label="☀️ Only">
                        <span class="edit-modal-toggle-label">NetZero+</span>
                    </label>
                </div>
            </div>
            <div class="form-group" id="group-watts">
                <label>Watts (Positive = Charge, Negative = Discharge)</label>
                <div class="watts-limit-row">
                    <input type="number" id="inp-watts" placeholder="0">
                    <label for="inp-limit-1-hour" class="edit-modal-toggle edit-modal-toggle-checkbox limit-1h-row">
                        <span class="edit-modal-toggle-label">Limit 1 hour</span>
                        <input type="checkbox" id="inp-limit-1-hour" name="limit1hour" checked>
                        <span class="edit-modal-toggle-check" aria-hidden="true">✓</span>
                    </label>
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
