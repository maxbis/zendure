(() => {
    'use strict';

    const search = document.querySelector('[data-role="docs-search"]');
    const cards = Array.from(document.querySelectorAll('[data-doc-card]'));
    const groups = Array.from(document.querySelectorAll('[data-doc-group]'));
    const results = document.querySelector('[data-role="docs-results"]');
    const empty = document.querySelector('[data-role="docs-empty"]');

    if (!search || !cards.length) return;

    const filter = () => {
        const query = search.value.trim().toLocaleLowerCase();
        let visibleCount = 0;

        cards.forEach((card) => {
            const visible = !query || (card.dataset.searchText || '').includes(query);
            card.hidden = !visible;
            if (visible) visibleCount += 1;
        });

        groups.forEach((group) => {
            group.hidden = !group.querySelector('[data-doc-card]:not([hidden])');
        });

        if (results) results.textContent = `${visibleCount} ${visibleCount === 1 ? 'document' : 'documents'}`;
        if (empty) empty.hidden = visibleCount !== 0;
    };

    search.addEventListener('input', filter);
})();
