(function () {
    "use strict";

    const ICON_PATH = document.currentScript
        ? new URL("../icons/sprite.svg", document.currentScript.src).href
        : "assets/icons/sprite.svg";
    const openDialogs = [];
    const flashItems = [];
    const MAX_FLASHES = 4;

    function icon(name, className = "gsd-icon") {
        return `<svg class="${className}" aria-hidden="true"><use href="${ICON_PATH}#${name}"></use></svg>`;
    }

    function getFocusable(container) {
        return Array.from(
            container.querySelectorAll(
                'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), ' +
                'textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
            )
        ).filter((element) => !element.hidden && element.getClientRects().length > 0);
    }

    function trapFocus(event) {
        const dialog = event.currentTarget;

        if (event.key === "Escape") {
            if (dialog.dataset.gsdBlocking !== "true") {
                event.preventDefault();
                GraphiteDialog.close(dialog, "escape");
            }
            return;
        }

        if (event.key !== "Tab") return;

        const focusable = getFocusable(dialog);
        if (!focusable.length) {
            event.preventDefault();
            dialog.focus();
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function prepareDialog(dialog) {
        if (!dialog || dialog.dataset.gsdReady === "true") return;

        dialog.dataset.gsdReady = "true";
        dialog.addEventListener("keydown", trapFocus);
        dialog.addEventListener("cancel", (event) => {
            if (dialog.dataset.gsdBlocking === "true") {
                event.preventDefault();
            }
        });
        dialog.addEventListener("click", (event) => {
            if (event.target === dialog && dialog.dataset.gsdBlocking !== "true") {
                GraphiteDialog.close(dialog, "backdrop");
            }
        });
        dialog.addEventListener("close", () => {
            const index = openDialogs.indexOf(dialog);
            if (index >= 0) openDialogs.splice(index, 1);

            const trigger = dialog._gsdTrigger;
            dialog._gsdTrigger = null;
            if (trigger && document.contains(trigger)) trigger.focus();
        });

        dialog.querySelectorAll("[data-gsd-dialog-close]").forEach((button) => {
            button.addEventListener("click", () => {
                GraphiteDialog.close(dialog, button.dataset.gsdDialogClose || "close");
            });
        });
    }

    const GraphiteDialog = {
        open(id, options = {}) {
            const dialog = typeof id === "string" ? document.getElementById(id) : id;
            if (!(dialog instanceof HTMLDialogElement)) {
                throw new Error(`Graphite dialog "${id}" was not found.`);
            }

            prepareDialog(dialog);
            dialog._gsdTrigger = options.trigger || document.activeElement;
            dialog.dataset.gsdBlocking = options.blocking ? "true" : "false";

            if (!dialog.open) {
                dialog.showModal();
                openDialogs.push(dialog);
            }

            const initialFocus = dialog.querySelector("[data-gsd-initial-focus]");
            (initialFocus || getFocusable(dialog)[0] || dialog).focus();
            return dialog;
        },

        close(dialogOrId, returnValue = "close") {
            const dialog = typeof dialogOrId === "string"
                ? document.getElementById(dialogOrId)
                : dialogOrId;
            if (dialog instanceof HTMLDialogElement && dialog.open) {
                dialog.close(returnValue);
            }
        },

        top() {
            return openDialogs[openDialogs.length - 1] || null;
        }
    };

    function ensureFlashRegion() {
        let region = document.querySelector("[data-gsd-flash-region]");
        if (!region) {
            region = document.createElement("div");
            region.className = "gsd-flash-region";
            region.dataset.gsdFlashRegion = "";
            region.setAttribute("aria-live", "polite");
            region.setAttribute("aria-relevant", "additions");
            document.body.appendChild(region);
        }
        return region;
    }

    function removeFlash(item) {
        if (!item || item.dataset.state === "leaving") return;
        item.dataset.state = "leaving";
        window.clearTimeout(item._gsdTimer);

        const remove = () => {
            const index = flashItems.indexOf(item);
            if (index >= 0) flashItems.splice(index, 1);
            item.remove();
        };

        item.addEventListener("animationend", remove, { once: true });
        window.setTimeout(remove, 280);
    }

    function flashTitle(type) {
        return {
            success: "Success",
            error: "Something went wrong",
            warning: "Attention needed",
            info: "Information"
        }[type] || "Information";
    }

    function flashIcon(type) {
        return {
            success: "check",
            error: "error",
            warning: "warning",
            info: "info"
        }[type] || "info";
    }

    const GraphiteFlash = {
        show({ type = "info", title, message, duration } = {}) {
            if (!message) throw new Error("A flash message is required.");

            const normalizedType = ["success", "error", "warning", "info"].includes(type)
                ? type
                : "info";
            const visibleDuration = duration ?? (normalizedType === "error" ? 0 : 5000);
            const item = document.createElement("article");
            item.className = `gsd-flash gsd-flash--${normalizedType}`;
            item.setAttribute("role", normalizedType === "error" ? "alert" : "status");
            item.innerHTML = `
                <div class="gsd-flash__icon">${icon(flashIcon(normalizedType))}</div>
                <div class="gsd-flash__content">
                    <strong class="gsd-flash__title"></strong>
                    <p class="gsd-flash__message"></p>
                </div>
                <button class="gsd-flash__close" type="button" aria-label="Dismiss message">
                    ${icon("close")}
                </button>
                ${visibleDuration > 0 ? '<span class="gsd-flash__timer" aria-hidden="true"></span>' : ""}
            `;
            item.querySelector(".gsd-flash__title").textContent = title || flashTitle(normalizedType);
            item.querySelector(".gsd-flash__message").textContent = message;
            item.querySelector(".gsd-flash__close").addEventListener("click", () => removeFlash(item));

            if (visibleDuration > 0) {
                item.style.setProperty("--gsd-flash-duration", `${visibleDuration}ms`);
                item._gsdTimer = window.setTimeout(() => removeFlash(item), visibleDuration);
            }

            ensureFlashRegion().appendChild(item);
            flashItems.push(item);

            while (flashItems.length > MAX_FLASHES) {
                removeFlash(flashItems[0]);
            }

            return item;
        },

        success(message, options = {}) {
            return this.show({ ...options, type: "success", message });
        },

        error(message, options = {}) {
            return this.show({ ...options, type: "error", message });
        },

        warning(message, options = {}) {
            return this.show({ ...options, type: "warning", message });
        },

        info(message, options = {}) {
            return this.show({ ...options, type: "info", message });
        },

        dismissAll() {
            [...flashItems].forEach(removeFlash);
        }
    };

    function prepareFooterMore(root) {
        if (!(root instanceof HTMLElement) || root.dataset.gsdFooterReady === "true") {
            return;
        }

        const toggle = root.querySelector('[data-role="gsd-footer-more-toggle"]');
        const panel = root.querySelector('[data-role="gsd-footer-more-panel"]');
        if (!(toggle instanceof HTMLButtonElement) || !(panel instanceof HTMLElement)) {
            return;
        }

        root.dataset.gsdFooterReady = "true";

        let backdrop = null;

        function setOpen(open) {
            const nextOpen = Boolean(open);
            root.classList.toggle("is-open", nextOpen);
            toggle.setAttribute("aria-expanded", nextOpen ? "true" : "false");
            panel.hidden = !nextOpen;
            panel.setAttribute("aria-hidden", nextOpen ? "false" : "true");

            if (nextOpen) {
                if (!backdrop) {
                    backdrop = document.createElement("button");
                    backdrop.type = "button";
                    backdrop.className = "gsd-footer-more__backdrop";
                    backdrop.setAttribute("aria-label", "Close more menu");
                    backdrop.addEventListener("click", () => setOpen(false));
                    root.insertBefore(backdrop, root.firstChild);
                }
            } else if (backdrop) {
                backdrop.remove();
                backdrop = null;
            }
        }

        root._gsdFooterMoreSetOpen = setOpen;

        toggle.addEventListener("click", () => {
            setOpen(!root.classList.contains("is-open"));
        });

        document.addEventListener("keydown", (event) => {
            if (event.key === "Escape" && root.classList.contains("is-open")) {
                setOpen(false);
                toggle.focus();
            }
        });
    }

    const GraphiteFooterMore = {
        init(scope = document) {
            if (scope instanceof HTMLElement && scope.matches("[data-gsd-footer-more]")) {
                prepareFooterMore(scope);
            }
            if (scope && typeof scope.querySelectorAll === "function") {
                scope.querySelectorAll("[data-gsd-footer-more]").forEach(prepareFooterMore);
            }
        },

        open(root) {
            const target = typeof root === "string" ? document.querySelector(root) : root;
            if (!(target instanceof HTMLElement)) return;
            prepareFooterMore(target);
            if (typeof target._gsdFooterMoreSetOpen === "function") {
                target._gsdFooterMoreSetOpen(true);
            }
        },

        close(root) {
            const target = typeof root === "string" ? document.querySelector(root) : root;
            if (!(target instanceof HTMLElement)) return;
            if (typeof target._gsdFooterMoreSetOpen === "function") {
                target._gsdFooterMoreSetOpen(false);
            }
        }
    };

    document.addEventListener("DOMContentLoaded", () => {
        document.querySelectorAll("dialog.gsd-dialog").forEach(prepareDialog);
        ensureFlashRegion();
        GraphiteFooterMore.init();
    });

    window.GraphiteDialog = GraphiteDialog;
    window.GraphiteFlash = GraphiteFlash;
    window.GraphiteFooterMore = GraphiteFooterMore;
})();
