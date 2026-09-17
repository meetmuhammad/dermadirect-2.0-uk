/**
 * Search results — paginated show more / show less per category
 *
 * State: each grid tracks `visibleCount` (multiples of pageSize).
 * Show More
 * Show Less: collapse back to pageSize.
 * Controls: when > pageSize visible, show both buttons; when == pageSize, show only Show More.
*/
function initCategoryShowMore() {
    document.querySelectorAll('.search-category-grid').forEach((grid) => {
        const pageSize = parseInt(grid.dataset.pageSize, 10) || 8;
        const total    = parseInt(grid.dataset.total, 10) || 0;

        if (total <= pageSize) return;

        const controls = grid.nextElementSibling;
        if (!controls || !controls.classList.contains('search-cat-controls')) return;

        const cards = [...grid.querySelectorAll('[data-index]')];
        let visible = pageSize;

        const btnShowMore = document.createElement('button');
        btnShowMore.type = 'button';
        btnShowMore.className =
            'font-quicksand font-bold text-sm text-primary border border-primary py-[11px] px-6 rounded-[6px] leading-none hover:bg-primary hover:text-white transition cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary';

        const btnShowLess = document.createElement('button');
        btnShowLess.type = 'button';
        btnShowLess.className =
            'font-quicksand text-xs text-secondary-grey underline underline-offset-2 cursor-pointer hover:text-primary transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary';
        btnShowLess.textContent = 'Show Less';

        function applyVisibility() {
            cards.forEach((card) => {
                const idx = parseInt(card.dataset.index, 10);
                card.classList.toggle('hidden', idx >= visible);
            });

            const remaining = total - visible;

            btnShowMore.textContent = remaining > 0
                ? 'Show More'
                : '';
            btnShowMore.classList.toggle('hidden', remaining <= 0);
            btnShowLess.classList.toggle('hidden', visible <= pageSize);

            // Rebuild controls DOM
            controls.innerHTML = '';
            if (visible > pageSize) controls.appendChild(btnShowLess);
            if (remaining > 0)     controls.appendChild(btnShowMore);
        }

        btnShowMore.addEventListener('click', () => {
            visible = Math.min(visible + pageSize, total);
            applyVisibility();
        });

        btnShowLess.addEventListener('click', () => {
            visible = pageSize;
            applyVisibility();
            // Scroll grid into view so user isn't stranded below
            grid.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });

        applyVisibility();
    });
}

/**
 * Search results tab switcher
*/
function initSearchTabs() {
    const container = document.querySelector('.search-results-tabs');
    if (!container) return;

    const buttons = container.querySelectorAll('.search-tab-btn');
    const panels  = container.querySelectorAll('[role="tabpanel"]');

    function switchTab(targetTab) {
        buttons.forEach((btn) => {
            const isActive = btn.dataset.tab === targetTab;
            const badge    = btn.querySelector('.search-tab-badge');

            btn.setAttribute('aria-selected', String(isActive));
            btn.classList.toggle('border-primary', isActive);
            btn.classList.toggle('text-primary', isActive);
            btn.classList.toggle('font-bold', isActive);
            btn.classList.toggle('border-transparent', !isActive);
            btn.classList.toggle('text-secondary-grey', !isActive);

            if (badge) {
                badge.classList.toggle('bg-primary', isActive);
                badge.classList.toggle('text-white', isActive);
                badge.classList.toggle('bg-grey-outline', !isActive);
                badge.classList.toggle('text-secondary-grey', !isActive);
            }
        });

        panels.forEach((panel) => {
            const panelTab = panel.id.replace('tab-panel-', '');
            panel.classList.toggle('hidden', panelTab !== targetTab);
        });
    }

    buttons.forEach((btn) => {
        btn.addEventListener('click', () => switchTab(btn.dataset.tab));

        // Keyboard: left/right arrow navigation
        btn.addEventListener('keydown', (e) => {
            const list  = [...buttons];
            const index = list.indexOf(btn);
            if (e.key === 'ArrowRight') {
                list[(index + 1) % list.length].focus();
            } else if (e.key === 'ArrowLeft') {
                list[(index - 1 + list.length) % list.length].focus();
            }
        });
    });

    initCategoryShowMore();
}


document.addEventListener('DOMContentLoaded', () => {
    const searchForm = document.querySelector('.dgwt-wcas-search-form');

    if (!searchForm) return;

    searchForm.addEventListener('submit', function (e) {
        const input = this.querySelector('.dgwt-wcas-search-input');

        if (!input || !input.value) return;

        e.preventDefault();

        window.location.href = `${window.location.origin}/?s=${encodeURIComponent(input.value)}`;
    });
});


//Added brand link to searches as Fibosearch doesnt support custom taxonomies. We injected custom links to searches.
function initFiboSearchBrandLinks() {

    if (typeof jQuery === 'undefined' || typeof ddBrandSearch === 'undefined' || !ddBrandSearch.ajax_url) return;

    jQuery(document).on('fibosearch/show-suggestions', function () {

        document.querySelectorAll('.dgwt-wcas-suggestion-tag').forEach((tag) => {

            const brandName = tag.textContent.trim();

            fetch(`${ddBrandSearch.ajax_url}?action=search_product_brands&term=${encodeURIComponent(brandName)}`)
                .then(response => response.json())
                .then(brands => {
                    if (brands.length && brands[0].url) {
                        tag.dataset.brandUrl = brands[0].url;
                        tag.href = brands[0].url;
                    }
                })
                .catch(() => {});

        });

    });

    const hijack = (e) => {
        const tag = e.target.closest('.dgwt-wcas-suggestion-tag');
        if (!tag || !tag.dataset.brandUrl) return;
        e.preventDefault();
        e.stopImmediatePropagation();
    };

    document.addEventListener('mousedown', hijack, true);
    document.addEventListener('click', function (e) {
        const tag = e.target.closest('.dgwt-wcas-suggestion-tag');
        if (!tag || !tag.dataset.brandUrl) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        window.location.href = tag.dataset.brandUrl;
    }, true);

}

document.addEventListener('DOMContentLoaded', () => {
    initSearchTabs();
    initFiboSearchBrandLinks();
});