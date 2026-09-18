window.addEventListener('pageshow', (event) => {
    if (event.persisted && typeof ajax_obj !== 'undefined') {
        fetch(ajax_obj.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'refresh_ajax_nonce' }),
        })
            .then(res => res.json())
            .then(data => {
                if (data?.data?.nonce) ajax_obj.nonce = data.data.nonce;
            })
            .catch(() => {});
    }
});

document.addEventListener('DOMContentLoaded', () => {

    /**
     * ===========================================================
     * Load More / AJAX
     * ===========================================================
    */

    function updateSortText(sort) {
        const sortLabels = {
            best_selling: 'Best Selling',
            featured: 'Featured',
            newest: 'Newest',
            price_asc: 'Price: Low to High',
            price_desc: 'Price: High to Low',
            name_asc: 'A-Z'
        };

        const countEl = document.querySelector('.product-count-text');

        if (countEl) {
            const currentText = countEl.innerHTML.split('<span>')[0];

            countEl.innerHTML = `
                ${currentText}
                <span>|</span>
                Sorted by ${sortLabels[sort] ?? 'Newest'}
            `;
        }
    }

    function updateProductCount(total, page, sort) {
        const countEl = document.querySelector('.product-count-text');

        if (!countEl) return;

        const perPage = 12;

        const from = total > 0 ? ((page - 1) * perPage) + 1 : 0;
        const to = Math.min(page * perPage, total);

        const sortLabels = {
            best_selling: 'Best Selling',
            featured: 'Featured',
            newest: 'Newest',
            price_asc: 'Price: Low to High',
            price_desc: 'Price: High to Low',
            name_asc: 'A-Z'
        };

        countEl.innerHTML = `
            Showing ${from}–${to} of ${total} products
            <span>|</span>
            Sorted by ${sortLabels[sort] ?? 'Newest'}
        `;
    }

    function initLoadMore() {
        const btn = document.querySelector('#load-more');
        const grid = document.querySelector('#product-grid');
        if (!btn || !grid) return;

        let currentPage = parseInt(btn.dataset.page || 1);
        let maxPage = parseInt(btn.dataset.max || 1);

        let currentSort = '';
        let currentCategories = [];      // Multiple categories
        let currentBrands = [];
        let currentTreatmentAreas = [];
        let currentIngredients = [];
        let currentProductGauge = [];
        let currentProductLength = [];
        let currentProductType = [];
        let currentProductProtocols = [];

        async function fetchWithNonceRetry(params) {
            let response = await fetch(ajax_obj.ajax_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params,
            });

            if (response.status === 403) {
                try {
                    const refreshRes = await fetch(ajax_obj.ajax_url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ action: 'refresh_ajax_nonce' }),
                    });
                    const refreshData = await refreshRes.json();
                    const freshNonce = refreshData?.data?.nonce;

                    if (freshNonce) {
                        ajax_obj.nonce = freshNonce;
                        params.set('nonce', freshNonce);
                        response = await fetch(ajax_obj.ajax_url, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: params,
                        });
                    }
                } catch (refreshErr) {
                    console.error('Nonce refresh failed:', refreshErr);
                }
            }

            return response;
        }

        async function loadProducts(page = 1, sort = '', categories = [], brands = [], treatmentAreas = [], ingredients = [], productGauge = [], productLength = [], productType = [], productProtocols = []) {

            btn.disabled = true;
            btn.textContent = "Loading...";

            try {
                const params = new URLSearchParams();
                params.append('action', 'load_more_products');
                params.append('page', page);
                params.append('sort', sort);
                params.append('nonce', ajax_obj.nonce);

                // Add category context if on category page
                if (ajax_obj.category_id) {
                    params.append('category_id', ajax_obj.category_id);
                }

                // Append multi-categories
                categories.forEach(c => params.append('categories[]', c));

                brands.forEach(b => params.append('brands[]', b));

                treatmentAreas.forEach(t => params.append('treatment_areas[]', t));

                ingredients.forEach(i => params.append('ingredients[]', i));

                productGauge.forEach(g => params.append('product_gauge[]', g));

                productLength.forEach(l => params.append('product_length[]', l));

                productType.forEach(t => params.append('product_type[]', t));

                productProtocols.forEach(p => params.append('product_protocols[]', p));

                const response = await fetchWithNonceRetry(params);

                const data = await response.json();

                if (data.html) {
                    if (page === 1) {
                        grid.innerHTML = data.html;
                    } else {
                        grid.insertAdjacentHTML('beforeend', data.html);
                    }
                    currentPage = parseInt(data.page);
                    maxPage = parseInt(data.max_page);
                    updateProductCount(data.total_products, page, sort);
                    currentSort = sort;
                    updateSortText(sort);
                    currentCategories = categories;
                    currentBrands = brands;
                    currentTreatmentAreas = treatmentAreas;
                    currentIngredients = ingredients;
                    currentProductGauge = productGauge;
                    currentProductLength = productLength;
                    currentProductType = productType;
                    currentProductProtocols = productProtocols;
                    btn.dataset.page = currentPage;
                    btn.dataset.max = maxPage;

                    // Count currently loaded products
                    const loadedProducts = grid.children.length;

                    // Hide button if all products have been loaded
                    if (loadedProducts >= parseInt(data.total_products) || currentPage >= maxPage) {
                        btn.style.display = 'none';
                    } else {
                        btn.style.display = 'inline-block';
                        btn.disabled = false;
                        btn.textContent = 'View More';
                    }

                } else {
                    grid.innerHTML = '<span>No products found</span>';
                    btn.style.display = 'none';
                }
                
            } catch (err) {
                console.error('Load products failed:', err);
                btn.disabled = false;
                btn.textContent = "View More";
            }
        }

        btn.addEventListener('click', () =>
            loadProducts(currentPage + 1, currentSort, currentCategories, currentBrands, currentTreatmentAreas, currentIngredients, currentProductGauge, currentProductLength, currentProductType, currentProductProtocols)
        );

        return {
            setSort: (sort) => currentSort = sort,
            setCategories: (cats) => currentCategories = cats,
            setBrands: (brands) => currentBrands = brands,
            setTreatmentAreas: (areas) => currentTreatmentAreas = areas,
            setIngredients: (ingredients) => currentIngredients = ingredients,
            setProductGauge: (gauge) => currentProductGauge = gauge,
            setProductLength: (length) => currentProductLength = length,
            setProductType: (type) => currentProductType = type,
            setProductProtocols: (protocols) => currentProductProtocols = protocols,
            loadFirstPage: () => loadProducts(1, currentSort, currentCategories, currentBrands, currentTreatmentAreas, currentIngredients, currentProductGauge, currentProductLength, currentProductType, currentProductProtocols)
        };
    }

    /**
     * ===========================================================
     * Sort Filter
     * ===========================================================
    */
    function initSortFilter(callbacks) {
        const sortSelect = document.querySelector('#short_by_filter');
        const btn = document.querySelector('#load-more');
        if (!sortSelect || !btn) return;

        // Browsers restore <select> values from session history on back/forward
        // navigation independently of the actual page content. Since the shop page
        // always re-renders with the default "Newest" sort on a fresh load, force
        // the dropdown back in sync so it doesn't show a stale selection.
        sortSelect.value = 'newest';

        sortSelect.addEventListener('change', (e) => {
            const selectedSort = e.target.value;

            // Reset page
            btn.dataset.page = 1;
            btn.style.display = 'inline-block';
            btn.disabled = true;
            btn.textContent = 'Loading...';

            if (callbacks && callbacks.setSort) callbacks.setSort(selectedSort);
            if (callbacks && callbacks.loadFirstPage) callbacks.loadFirstPage();
        });
    }

    function initBrandFilter(callbacks) {

        const brandCheckboxes = document.querySelectorAll('.brand-filter-option');
        const btn = document.querySelector('#load-more');

        if (!brandCheckboxes.length || !btn) return;

        brandCheckboxes.forEach(checkbox => {

            checkbox.addEventListener('change', () => {

                let selectedBrands = [];

                // 👉 SINGLE SELECT behavior (checkbox UI but radio logic)
                if (checkbox.checked) {

                    brandCheckboxes.forEach(cb => {
                        if (cb !== checkbox) cb.checked = false;
                    });

                    selectedBrands = [checkbox.value];

                } else {
                    selectedBrands = [];
                }

                // reset pagination
                btn.dataset.page = 1;
                btn.style.display = 'inline-block';
                btn.disabled = true;
                btn.textContent = 'Loading...';

                // update state
                if (callbacks?.setBrands) {
                    callbacks.setBrands(selectedBrands);
                }

                // reload products
                if (callbacks?.loadFirstPage) {
                    callbacks.loadFirstPage();
                }

                // update UI count
                const countEl = document.getElementById('selected_brands_count');
                if (countEl) {
                    countEl.innerHTML = selectedBrands.length
                        ? `(${selectedBrands.length})`
                        : '';
                }

                // Move the newly selected brand to the top of the list
                refreshBrandFilterOrder();
            });
        });
    }

    function initTreatmentAreasFilter(callbacks) {
        const checkboxes = document.querySelectorAll('.treatment-area-filter-option');
        const btn = document.querySelector('#load-more');
        if (!checkboxes.length || !btn) return;

        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                const selected = Array.from(checkboxes)
                    .filter(input => input.checked)
                    .map(input => input.value);

                btn.dataset.page = 1;
                btn.style.display = 'inline-block';
                btn.disabled = true;
                btn.textContent = 'Loading...';

                if (callbacks && callbacks.setTreatmentAreas) callbacks.setTreatmentAreas(selected);
                if (callbacks && callbacks.loadFirstPage) callbacks.loadFirstPage();

                const count = selected.length ?? 0;
                const countEl = document.getElementById('selected_treatment_areas_count');
                if (countEl) countEl.innerHTML = (count > 0) ? `(${count})` : '';
            });
        });
    }

    function initIngredientsFilter(callbacks) {
        const checkboxes = document.querySelectorAll('.ingredient-filter-option');
        const btn = document.querySelector('#load-more');
        if (!checkboxes.length || !btn) return;

        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                const selected = Array.from(checkboxes)
                    .filter(input => input.checked)
                    .map(input => input.value);

                btn.dataset.page = 1;
                btn.style.display = 'inline-block';
                btn.disabled = true;
                btn.textContent = 'Loading...';

                if (callbacks && callbacks.setIngredients) callbacks.setIngredients(selected);
                if (callbacks && callbacks.loadFirstPage) callbacks.loadFirstPage();

                const count = selected.length ?? 0;
                const countEl = document.getElementById('selected_ingredients_count');
                if (countEl) countEl.innerHTML = (count > 0) ? `(${count})` : '';
            });
        });
    }

    function initProductGaugeFilter(callbacks) {
        const checkboxes = document.querySelectorAll('.product-gauge-filter-option');
        const btn = document.querySelector('#load-more');
        if (!checkboxes.length || !btn) return;

        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                const selected = Array.from(checkboxes)
                    .filter(input => input.checked)
                    .map(input => input.value);

                btn.dataset.page = 1;
                btn.style.display = 'inline-block';
                btn.disabled = true;
                btn.textContent = 'Loading...';

                if (callbacks && callbacks.setProductGauge) callbacks.setProductGauge(selected);
                if (callbacks && callbacks.loadFirstPage) callbacks.loadFirstPage();

                const count = selected.length ?? 0;
                const countEl = document.getElementById('selected_product_gauge_count');
                if (countEl) countEl.innerHTML = (count > 0) ? `(${count})` : '';
            });
        });
    }

    function initProductLengthFilter(callbacks) {
        const checkboxes = document.querySelectorAll('.product-length-filter-option');
        const btn = document.querySelector('#load-more');
        if (!checkboxes.length || !btn) return;

        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                const selected = Array.from(checkboxes)
                    .filter(input => input.checked)
                    .map(input => input.value);

                btn.dataset.page = 1;
                btn.style.display = 'inline-block';
                btn.disabled = true;
                btn.textContent = 'Loading...';

                if (callbacks && callbacks.setProductLength) callbacks.setProductLength(selected);
                if (callbacks && callbacks.loadFirstPage) callbacks.loadFirstPage();

                const count = selected.length ?? 0;
                const countEl = document.getElementById('selected_product_length_count');
                if (countEl) countEl.innerHTML = (count > 0) ? `(${count})` : '';
            });
        });
    }

    function initProductTypeFilter(callbacks) {
        const checkboxes = document.querySelectorAll('.product-type-filter-option');
        const btn = document.querySelector('#load-more');
        if (!checkboxes.length || !btn) return;

        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                const selected = Array.from(checkboxes)
                    .filter(input => input.checked)
                    .map(input => input.value);

                btn.dataset.page = 1;
                btn.style.display = 'inline-block';
                btn.disabled = true;
                btn.textContent = 'Loading...';

                if (callbacks && callbacks.setProductType) callbacks.setProductType(selected);
                if (callbacks && callbacks.loadFirstPage) callbacks.loadFirstPage();

                const count = selected.length ?? 0;
                const countEl = document.getElementById('selected_product_type_count');
                if (countEl) countEl.innerHTML = (count > 0) ? `(${count})` : '';
            });
        });
    }

    function initProductProtocolsFilter(callbacks) {
        const checkboxes = document.querySelectorAll('.product-protocols-filter-option');
        const btn = document.querySelector('#load-more');
        if (!checkboxes.length || !btn) return;

        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                const selected = Array.from(checkboxes)
                    .filter(input => input.checked)
                    .map(input => input.value);

                btn.dataset.page = 1;
                btn.style.display = 'inline-block';
                btn.disabled = true;
                btn.textContent = 'Loading...';

                if (callbacks && callbacks.setProductProtocols) callbacks.setProductProtocols(selected);
                if (callbacks && callbacks.loadFirstPage) callbacks.loadFirstPage();

                const count = selected.length ?? 0;
                const countEl = document.getElementById('selected_product_protocols_count');
                if (countEl) countEl.innerHTML = (count > 0) ? `(${count})` : '';
            });
        });
    }

    function syncBrandFilterFromCheckedState(callbacks) {
        const checkedBrands = Array.from(document.querySelectorAll('.brand-filter-option'))
            .filter(input => input.checked)
            .map(input => input.value);

        if (!checkedBrands.length) return;

        if (callbacks && callbacks.setBrands) callbacks.setBrands(checkedBrands);

        const countEl = document.getElementById('selected_brands_count');
        if (countEl) countEl.innerHTML = `(${checkedBrands.length})`;
    }

    function applyCategoryVisibility(expanded = false) {
        const button = document.querySelector('#see_more_categories');
        const items = Array.from(document.querySelectorAll('.category-filter-option'));
        const defaultQty = parseInt(button?.dataset.defaultQty ?? 6);

        items.forEach((el, index) => {
            if (expanded) {
                el.classList.remove('!hidden');
            } else {
                el.classList.toggle('!hidden', index >= defaultQty);
            }
        });

        if (button) {
            button.innerText = expanded ? 'See less' : 'See more';
        }
    }

    function seeMoreCategoriesFilterList() {
        const button = document.querySelector('#see_more_categories');
        if (!button) return;

        const defaultExpanded = false;

        applyCategoryVisibility(defaultExpanded);

        button.addEventListener('click', () => {
            const isExpanded = button.innerText === 'See more';
            applyCategoryVisibility(isExpanded);
        });
    }


    function getBrandFilterList() {
        const button = document.querySelector('#see_more_brands');
        const list = button ? button.previousElementSibling : document.querySelector('.brand-filter-option-item')?.parentElement;
        return { button, list };
    }

    function reorderBrandFilterList() {
        const { list } = getBrandFilterList();
        if (!list) return;

        const items = Array.from(list.querySelectorAll('.brand-filter-option-item'));
        const active = items.filter(el => el.querySelector('.brand-filter-option')?.checked);
        const inactive = items.filter(el => !el.querySelector('.brand-filter-option')?.checked);

        [...active, ...inactive].forEach(el => list.appendChild(el));
    }

    function applyBrandFilterVisibility(expanded = false) {
        const { button, list } = getBrandFilterList();
        if (!button || !list) return;

        const defaultQty = parseInt(button.getAttribute('data-default-qty') ?? 4);
        const items = Array.from(list.querySelectorAll('.brand-filter-option-item'));

        items.forEach((el, index) => {
            if (expanded) {
                el.classList.remove('!hidden');
            } else {
                el.classList.toggle('!hidden', index >= defaultQty);
            }
        });

        button.innerText = expanded ? 'See less' : 'See more';
    }

    // Re-runs the active-brand-first reorder, keeping the current expanded/collapsed state.
    function refreshBrandFilterOrder() {
        const { button } = getBrandFilterList();
        const expanded = button ? button.innerText === 'See less' : true;

        reorderBrandFilterList();
        applyBrandFilterVisibility(expanded);
    }

    function seeMoreBrandFilterList() {
        const { button, list } = getBrandFilterList();
        if (!button || !list) return;

        reorderBrandFilterList();
        applyBrandFilterVisibility(false);

        button.addEventListener('click', () => {
            const isExpand = button.innerText === 'See more';
            applyBrandFilterVisibility(isExpand);
        });
    }

    /**
     * Generic "See more/less" handler for filter lists
     * @param {string} buttonSelector - CSS selector for the button
     * @param {string} itemSelector - CSS selector for the items to show/hide
     */
    function initSeeMoreToggle(buttonSelector, itemSelector) {
        const button = document.querySelector(buttonSelector);
        if (!button) return;

        const defaultQty = parseInt(button.getAttribute('data-default-qty') ?? 4);

        button.addEventListener('click', (e) => {
            const isExpanded = e.target.innerText === 'See less';

            e.target.innerText = isExpanded ? 'See more' : 'See less';

            document.querySelectorAll(itemSelector).forEach((el, index) => {
                if (isExpanded && index > (defaultQty - 1)) {
                    el.classList.add('!hidden');
                } else {
                    el.classList.remove('!hidden');
                }
            });
        });
    }

    /**
     * ===========================================================
     * Clear All Filters
     * ===========================================================
    */
    function initClearAllFilters(callbacks) {
        const clearBtn = document.querySelector('#clear_all_filters');
        if (!clearBtn) return;

        clearBtn.addEventListener('click', () => {

            /* -------------------------
                RESET CATEGORY FILTERS
            ------------------------- */
            document.querySelectorAll('.category-filter-option').forEach(el => {
                el.classList.remove('active');
            });

            // UI count reset
            const selected_categories_count = document.getElementById('selected_categories_count');
            if (selected_categories_count) selected_categories_count.innerText = '';

            /* -------------------------
                RESET BRAND FILTERS
            ------------------------- */
            document.querySelectorAll('.brand-filter-option').forEach(input => {
                input.checked = false;
            });

            const selected_brands_count = document.getElementById('selected_brands_count');
            if (selected_brands_count) selected_brands_count.innerHTML = '';


            /* -------------------------
                RESET TREATMENT AREAS FILTERS
            ------------------------- */
            document.querySelectorAll('.treatment-area-filter-option').forEach(input => {
                input.checked = false;
            });

            const selected_treatment_areas_count = document.getElementById('selected_treatment_areas_count');
            if (selected_treatment_areas_count) selected_treatment_areas_count.innerHTML = '';

            /* -------------------------
                RESET INGREDIENTS FILTERS
            ------------------------- */
            document.querySelectorAll('.ingredient-filter-option').forEach(input => {
                input.checked = false;
            });

            const selected_ingredients_count = document.getElementById('selected_ingredients_count');
            if (selected_ingredients_count) selected_ingredients_count.innerHTML = '';

            /* -------------------------
                RESET PRODUCT GAUGE FILTERS
            ------------------------- */
            document.querySelectorAll('.product-gauge-filter-option').forEach(input => {
                input.checked = false;
            });

            const selected_product_gauge_count = document.getElementById('selected_product_gauge_count');
            if (selected_product_gauge_count) selected_product_gauge_count.innerHTML = '';

            /* -------------------------
                RESET PRODUCT LENGTH FILTERS
            ------------------------- */
            document.querySelectorAll('.product-length-filter-option').forEach(input => {
                input.checked = false;
            });

            const selected_product_length_count = document.getElementById('selected_product_length_count');
            if (selected_product_length_count) selected_product_length_count.innerHTML = '';

            /* -------------------------
                RESET PRODUCT TYPE FILTERS
            ------------------------- */
            document.querySelectorAll('.product-type-filter-option').forEach(input => {
                input.checked = false;
            });

            const selected_product_type_count = document.getElementById('selected_product_type_count');
            if (selected_product_type_count) selected_product_type_count.innerHTML = '';

            /* -------------------------
                RESET PRODUCT PROTOCOLS FILTERS
            ------------------------- */
            document.querySelectorAll('.product-protocols-filter-option').forEach(input => {
                input.checked = false;
            });

            const selected_product_protocols_count = document.getElementById('selected_product_protocols_count');
            if (selected_product_protocols_count) selected_product_protocols_count.innerHTML = '';

            /* -------------------------
                RESET SORT
            ------------------------- */
            const sortSelect = document.querySelector('#short_by_filter');
            if (sortSelect) sortSelect.value = '';
            if (callbacks && callbacks.setSort) callbacks.setSort('');

            /* -------------------------
                Update JS state via callbacks
            ------------------------- */
            if (callbacks && callbacks.setCategories) callbacks.setCategories([]);
            if (callbacks && callbacks.setBrands) callbacks.setBrands([]);
            if (callbacks && callbacks.setTreatmentAreas) callbacks.setTreatmentAreas([]);
            if (callbacks && callbacks.setIngredients) callbacks.setIngredients([]);
            if (callbacks && callbacks.setSort) callbacks.setSort('');

            /* -------------------------
                Reload first page
            ------------------------- */
            if (callbacks && callbacks.loadFirstPage) callbacks.loadFirstPage();

            /* Reset load-more button */
            const btn = document.querySelector('#load-more');
            if (btn) {
                btn.dataset.page = 1;
                btn.style.display = 'inline-block';
                btn.textContent = 'Loading...';
                btn.disabled = true;
            }
        });
    }

    function handleFilterSectionToggle() {
        /**
         * This toggle appears on tablet + mobile screen sizes.
        */
        const toggleBtn = document.querySelector('.product-toggle');
        const menu = document.querySelector('.product-menu');

        if (toggleBtn && menu) {
            toggleBtn.addEventListener('click', () => {
                toggleBtn.classList.toggle('btn-open');
                menu.classList.toggle('open');
            });
        }
    }

    /** ===============================================================
     * Main
    */
    // handleAddToCartButtonTextChange() is already invoked once from
    // woocommerce-common.js's own DOMContentLoaded listener; calling it again
    // here just re-attached the same click handlers to the same buttons a
    // second time, so that redundant call (and its import) has been removed.
    const callbacks = initLoadMore();

    initSortFilter(callbacks);
    initBrandFilter(callbacks);

    // Also check if we're on a brand taxonomy page
    const brandSlug = ajax_obj.brand_slug ?? '';
    const activeBrands = brandSlug ? [brandSlug] : [];

    if (activeBrands.length > 0) {
        // Pre-check boxes
        document.querySelectorAll('.brand-filter-option').forEach(checkbox => {
            if (activeBrands.includes(checkbox.value)) {
                checkbox.checked = true;
            }
        });

        // Update JS state only. The initial page load is already server-filtered by
        // WP's own taxonomy query, so calling loadFirstPage() here would just
        // re-fetch/replace the whole #product-grid via AJAX for no reason (and that
        // AJAX re-render is what produced broken "Add to cart" hrefs on brand pages).
        // The count UI + state sync happens right below via syncBrandFilterFromCheckedState().
        if (callbacks && callbacks.setBrands) callbacks.setBrands(activeBrands);
    }

    initTreatmentAreasFilter(callbacks);
    initIngredientsFilter(callbacks);
    initProductGaugeFilter(callbacks);
    initProductLengthFilter(callbacks);
    initProductTypeFilter(callbacks);
    initProductProtocolsFilter(callbacks);
    initClearAllFilters(callbacks);

    syncBrandFilterFromCheckedState(callbacks);

    seeMoreBrandFilterList();
    seeMoreCategoriesFilterList();

    // Initialize "See more" toggles for all filter types
    initSeeMoreToggle('#see_more_treatment_areas', '.treatment-area-filter-option-label');
    initSeeMoreToggle('#see_more_ingredients', '.ingredient-filter-option-label');
    initSeeMoreToggle('#see_more_product_gauge', '.product-gauge-filter-option-label');
    initSeeMoreToggle('#see_more_product_length', '.product-length-filter-option-label');
    initSeeMoreToggle('#see_more_product_type', '.product-type-filter-option-label');
    initSeeMoreToggle('#see_more_product_protocols', '.product-protocols-filter-option-label');

    handleFilterSectionToggle();
});
