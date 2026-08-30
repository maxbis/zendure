(function () {
    "use strict";

    const dialog = document.querySelector('[data-component="automation-control-dialog"]');
    const trigger = document.querySelector('[data-gsd-dialog-target="app-automation-control-dialog"]');
    if (!(dialog instanceof HTMLDialogElement) || !(trigger instanceof HTMLButtonElement)) return;

    const frame = dialog.querySelector('[data-role="automation-control-frame"]');
    const loading = dialog.querySelector('[data-role="automation-control-loading"]');
    if (!(frame instanceof HTMLIFrameElement) || !(loading instanceof HTMLElement)) return;

    function ensureLoaded() {
        if (frame.getAttribute("src")) return;
        const source = frame.dataset.src;
        if (!source) return;
        dialog.setAttribute("aria-busy", "true");
        frame.setAttribute("src", source);
    }

    function openDialog() {
        const menu = trigger.closest("[data-gsd-footer-more]");
        const returnTarget = menu?.querySelector('[data-role="gsd-footer-more-toggle"]') || trigger;
        if (menu && window.GraphiteFooterMore) window.GraphiteFooterMore.close(menu);
        window.GraphiteDialog.open(dialog, { trigger: returnTarget });
        ensureLoaded();
    }

    frame.addEventListener("load", () => {
        loading.hidden = true;
        frame.hidden = false;
        dialog.removeAttribute("aria-busy");
    });
    trigger.addEventListener("click", openDialog);
})();
