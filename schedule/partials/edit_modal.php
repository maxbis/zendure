<!-- Modal -->
<style>
    .mode-buttons {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        margin-top: 5px;
    }
    .mode-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 6px 10px;
        background:#868686;
        border: 1px solid #bbb;
        border-radius: 6px;
        cursor: pointer;
        color: #202020;
    }
    .mode-btn input {
        position: absolute;
        opacity: 0;
        width: 0;
        height: 0;
    }
    .mode-btn:has(input:checked) {
        background:rgb(245, 255, 155);
        box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.15);
    }
    /* Override .form-group label color so button text is dark on grey */
    .form-group .mode-btn,
    .form-group .mode-btn span {
        color: #202020;
    }
    /* Readable selection in date/time/number inputs (white on blue) */
    #edit-modal input::selection {
        background: #1976d2;
        color: #fff !important;
    }
    #edit-modal input::-moz-selection {
        background: #1976d2;
        color: #fff !important;
    }
</style>

<div class="modal-backdrop" id="edit-modal">
    <div class="modal-dialog">
        <div class="modal-header">
            <div class="modal-title" id="modal-title">Edit Entry</div>
            <button class="modal-close" id="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                <div class="form-group">
                    <label>Date (YYYYMMDD)</label>
                    <input type="text" id="inp-date" maxlength="8" placeholder="20251222">
                </div>
                <div class="form-group">
                    <label>Time (HHmm)</label>
                    <input type="text" id="inp-time" maxlength="4" placeholder="0800">
                </div>
            </div>
            <div class="helper-text" style="margin-bottom:15px;">Use <code>*</code> for wildcards.</div>

            <div class="form-group">
                <label>Mode</label>
                <div class="mode-buttons">
                    <label class="mode-btn"><input type="radio" name="val-mode" value="fixed" checked><span>Watts (W)</span></label>
                    <label class="mode-btn"><input type="radio" name="val-mode" value="netzero" label="Net Zero"><span>NetZero</span></label>
                    <label class="mode-btn"><input type="radio" name="val-mode" value="netzero+" label="☀️ Only"><span>NetZero+</span></label>
                </div>
            </div>
            <div class="form-group" id="group-watts">
                <label>Watts (Positive = Charge, Negative = Discharge)</label>
                <input type="number" id="inp-watts" placeholder="0">
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
            <div class="modal-title">Confirm Delete</div>
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

