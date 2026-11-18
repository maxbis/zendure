(function () {
    "use strict";

    const root = document.querySelector('[data-component="configuration-catalog"]');
    if (!root) return;

    const filter = root.querySelector('[data-role="configuration-filter"]');
    const visibleCount = root.querySelector('[data-role="visible-count"]');
    const emptyState = root.querySelector('[data-role="empty-state"]');
    const groups = Array.from(root.querySelectorAll('[data-configuration-group]'));
    const fields = Array.from(root.querySelectorAll('[data-configuration-field]'));
    const initialOpenGroups = new Set(groups.filter((group) => group.open));
    let filterWasActive = false;

    function applyFilter() {
        const query = String(filter?.value || "").trim().toLocaleLowerCase();
        const filterIsActive = query !== "";
        let visibleTotal = 0;

        groups.forEach((group) => {
            const groupFields = Array.from(group.querySelectorAll('[data-configuration-field]'));
            let groupVisible = 0;

            groupFields.forEach((field) => {
                const matches = query === "" || String(field.dataset.searchText || "").includes(query);
                field.hidden = !matches;
                if (matches) groupVisible += 1;
            });

            group.hidden = groupVisible === 0;
            const count = group.querySelector('[data-role="group-count"]');
            if (count) count.textContent = String(groupVisible);
            if (filterIsActive && groupVisible > 0) {
                group.open = true;
            } else if (!filterIsActive && filterWasActive) {
                group.open = initialOpenGroups.has(group);
            }
            visibleTotal += groupVisible;
        });

        if (visibleCount) visibleCount.textContent = String(visibleTotal);
        if (emptyState) emptyState.hidden = visibleTotal !== 0;
        filterWasActive = filterIsActive;
    }

    filter?.addEventListener("input", applyFilter);

    root.addEventListener("click", (event) => {
        const trigger = event.target.closest('.configuration-tooltip__trigger');
        if (!trigger) return;
        const wasExpanded = trigger.getAttribute("aria-expanded") === "true";
        root.querySelectorAll('.configuration-tooltip__trigger[aria-expanded="true"]').forEach((button) => {
            button.setAttribute("aria-expanded", "false");
        });
        trigger.setAttribute("aria-expanded", wasExpanded ? "false" : "true");
    });

    document.addEventListener("click", (event) => {
        if (event.target.closest('.configuration-tooltip')) return;
        root.querySelectorAll('.configuration-tooltip__trigger[aria-expanded="true"]').forEach((button) => {
            button.setAttribute("aria-expanded", "false");
        });
    });

    document.addEventListener("keydown", (event) => {
        if (event.key !== "Escape") return;
        root.querySelectorAll('.configuration-tooltip__trigger[aria-expanded="true"]').forEach((button) => {
            button.setAttribute("aria-expanded", "false");
        });
    });

    applyFilter();
}());

