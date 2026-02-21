<?php
/**
 * No Back-end Dialog Partial
 * Shown when the back-end (Pi control / automation service) is not running (502).
 * Stops auto-refresh; user can retry by reloading the page.
 */
?>
<!-- No Back-end Dialog (shown when proxy returns 502) -->
<div class="modal-backdrop" id="no-backend-dialog" aria-hidden="true">
    <div class="modal-dialog no-backend-dialog">
        <div class="modal-header">
            <div class="modal-title">Back-end not running</div>
        </div>
        <div class="modal-body">
            <p>The automation back-end service is not reachable. Auto-update has been stopped.</p>
            <p>Start the back-end service, then click <strong>Retry</strong> to reload the page.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-primary" id="no-backend-retry-btn">Retry</button>
        </div>
    </div>
</div>
