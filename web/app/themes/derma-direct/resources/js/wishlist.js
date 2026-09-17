/**
 * Dermadirect Wishlist
 *
 * Vanilla JS – no jQuery dependency.
 * Works on shop, archive, single product, related products, and wishlist page.
 *
 * Globals injected via wp_localize_script:
 *   ddWishlist.ajax_url  {string}
 *   ddWishlist.nonce     {string}
 *   ddWishlist.items     {number[]}  — initial product IDs in wishlist
 */

(function () {
    'use strict';

    if (typeof ddWishlist === 'undefined') return;

    const { ajax_url, nonce } = ddWishlist;

    /** Local in-memory set of wishlisted product IDs. */
    let wishlistItems = new Set((ddWishlist.items || []).map(Number));

    // ─── Init ─────────────────────────────────────────────────────────────────

    function init() {
        syncAllButtons();
        bindEvents();
    }

    // ─── DOM Helpers ──────────────────────────────────────────────────────────

    /** Apply active/inactive visual state to a single button. */
    function setButtonState(btn, inWishlist) {
        const heart = btn.querySelector('.wishlist-heart');
        if (!heart) return;

        if (inWishlist) {
            heart.classList.remove('fill-white', 'stroke-current');
            heart.classList.add('fill-red-500', 'stroke-red-500');
            btn.setAttribute('aria-pressed', 'true');
            btn.setAttribute('aria-label', btn.dataset.labelRemove || 'Remove from wishlist');
        } else {
            heart.classList.remove('fill-red-500', 'stroke-red-500');
            heart.classList.add('fill-white', 'stroke-current');
            btn.setAttribute('aria-pressed', 'false');
            btn.setAttribute('aria-label', btn.dataset.labelAdd || 'Add to wishlist');
        }
    }

    /** Sync ALL wishlist buttons on the page against the current local state. */
    function syncAllButtons() {
        document.querySelectorAll('.wishlist-btn[data-product-id]').forEach(btn => {
            const id = parseInt(btn.dataset.productId, 10);
            if (id) setButtonState(btn, wishlistItems.has(id));
        });

        updateBadge(wishlistItems.size);
    }

    /** Update the header wishlist badge count(s). */
    function updateBadge(count) {
        document.querySelectorAll('#woo_wishlist_count').forEach(badge => {
            badge.textContent = String(count);

            // The parent <span> is the notification-badge wrapper — toggle visibility.
            const wrapper = badge.closest('.notification-badge');
            if (wrapper) {
                wrapper.style.display = count > 0 ? '' : 'none';
            }
        });
    }

    /** Set loading state on a button to prevent duplicate clicks. */
    function setLoading(btn, loading) {
        btn.dataset.loading = loading ? 'true' : 'false';
        btn.classList.toggle('opacity-60', loading);
        btn.classList.toggle('cursor-wait', loading);
        btn.disabled = loading;
    }

    // ─── Events ───────────────────────────────────────────────────────────────

    function bindEvents() {
        // Delegated click on any wishlist button anywhere on the page.
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.wishlist-btn[data-product-id]');
            if (!btn) return;
            e.preventDefault();
            handleToggle(btn);
        });

        // Re-sync after WooCommerce replaces product HTML (e.g. variation swap).
        document.body.addEventListener('wc_fragments_refreshed', syncAllButtons);
        document.body.addEventListener('added_to_cart', syncAllButtons);
    }

    // ─── AJAX ─────────────────────────────────────────────────────────────────

    function handleToggle(btn) {
        if (btn.dataset.loading === 'true') return;

        const productId = parseInt(btn.dataset.productId, 10);
        if (!productId) return;

        setLoading(btn, true);

        const body = new URLSearchParams({
            action:     'dd_toggle_wishlist',
            nonce,
            product_id: productId,
        });

        fetch(ajax_url, {
            method:      'POST',
            credentials: 'same-origin',
            headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:        body.toString(),
        })
            .then(r => {
                if (!r.ok) throw new Error('Network error');
                return r.json();
            })
            .then(response => {
                if (!response || !response.success) return;

                const { in_wishlist, count, product_id } = response.data;

                // Update local state.
                if (in_wishlist) {
                    wishlistItems.add(product_id);
                } else {
                    wishlistItems.delete(product_id);
                }

                // Sync every button for this product across the whole page.
                document.querySelectorAll(`.wishlist-btn[data-product-id="${product_id}"]`).forEach(b => {
                    setButtonState(b, in_wishlist);
                });

                // Update header badge.
                updateBadge(count);

                // On the wishlist page, remove the card when de-wishlisted.
                if (!in_wishlist) {
                    removeWishlistCard(product_id);
                }
            })
            .catch(() => {
                // Silently fail – state is unchanged.
            })
            .finally(() => {
                setLoading(btn, false);
            });
    }

    // ─── Wishlist page ────────────────────────────────────────────────────────

    /** Animate and remove a product card from the wishlist page. */
    function removeWishlistCard(productId) {
        const card = document.querySelector(`.wishlist-product-item[data-product-id="${productId}"]`);
        if (!card) return;

        card.style.transition = 'opacity 0.25s ease, transform 0.25s ease';
        card.style.opacity    = '0';
        card.style.transform  = 'scale(0.95)';

        setTimeout(() => {
            card.remove();
            checkEmptyState();
        }, 260);
    }

    /** Show the empty state template if the grid is now empty. */
    function checkEmptyState() {
        const grid = document.querySelector('.wishlist-products-grid');
        if (!grid || grid.children.length > 0) return;

        // Hide the grid.
        grid.style.display = 'none';

        // Reveal the hidden empty-state block (the second one, hidden by default).
        const emptyState = document.querySelector('.wishlist-empty-state.hidden');
        if (emptyState) {
            emptyState.classList.remove('hidden');
            emptyState.style.display = 'flex';
        }
    }

    // ─── Boot ─────────────────────────────────────────────────────────────────

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
