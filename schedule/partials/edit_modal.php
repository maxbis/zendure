<!-- Modal -->
<div class="modal-backdrop" id="edit-modal">
    <div class="modal-dialog">
        <div class="modal-header">
            <div class="modal-title" id="modal-title">Edit Entry</div>
            <button class="modal-close" id="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                <div class="form-group">
                    <label>Date Pattern (YYYYMMDD)</label>
                    <input type="text" id="inp-date" maxlength="8" placeholder="20251222">
                </div>
                <div class="form-group">
                    <label>Time Pattern (HHmm)</label>
                    <input type="text" id="inp-time" maxlength="4" placeholder="0800">
                </div>
            </div>
            <div class="helper-text" style="margin-bottom:15px;">Use <code>*</code> for wildcards.</div>

            <div class="form-group">
                <label>Value Mode</label>
                <div style="display:flex; gap:15px; margin-top:5px;">
                    <label><input type="radio" name="val-mode" value="fixed" checked> Fixed Watts (W)</label>
                    <label><input type="radio" name="val-mode" value="netzero" label="Net Zero"> Net Zero</label>
                    <label><input type="radio" name="val-mode" value="netzero+" label="☀️ Only">Net Zero+/Solar Only</label>
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

