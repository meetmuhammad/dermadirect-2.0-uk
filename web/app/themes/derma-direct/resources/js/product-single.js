document.addEventListener('DOMContentLoaded', function () {

    function renderSaleEndsTimer() {
        const el = document.getElementById('sale_ends_time_remaining');
        if (!el) return;

        const from = el.dataset.saleStarsFrom ? new Date(el.dataset.saleStarsFrom).getTime() : null;
        const to = el.dataset.saleStarsTo ? new Date(el.dataset.saleStarsTo).getTime() : null;
        const now = new Date().getTime();

        // If no end date or sale hasn't started yet, do nothing
        if (!to || !from || now < from) {
            el.style.display = 'none';
            return;
        }

        function updateCountdown() {
            const now = new Date().getTime();
            let diff = to - now;

            if (diff <= 0) {
                el.innerText = "Sale has ended";
                clearInterval(timer);
                return;
            }

            const days = Math.floor(diff / (1000 * 60 * 60 * 24));
            const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000*60*60));
            const minutes = Math.floor((diff % (1000*60*60)) / (1000*60));
            const seconds = Math.floor((diff % (1000*60)) / 1000);

            let display = '';
            if (days > 0) {
                display = `${days}d: ${hours}h: ${minutes}m: ${seconds}s`;
            } else if (hours > 0) {
                display = `${hours}h: ${minutes}m: ${seconds}s`;
            } else {
                display = `${minutes}m: ${seconds}s`;
            }

            el.innerText = ` - offer ends in ${display}`;
        }

        // Initial render
        updateCountdown();

        // Update every second
        const timer = setInterval(updateCountdown, 1000);
    }

    function handleProductImagesSlider() {
        const thumbs_slider__el = document.querySelector('.product-single-page-content-wrapper .product-gallery-slider .swiper-thumbs');
        const main_slider__el = document.querySelector('.product-single-page-content-wrapper .product-gallery-slider .swiper-main');

        if (!thumbs_slider__el || !main_slider__el) return;

        // Thumbnails slider.
        const thumbsSwiper = new Swiper(thumbs_slider__el, {
            spaceBetween: 13,
            slidesPerView: 4,
            freeMode: true,
            watchSlidesProgress: true,
        });

        // Main slider.
        const mainSwiper = new Swiper(main_slider__el, {
            spaceBetween: 13,
            effect: "slide",
            thumbs: {
                swiper: thumbsSwiper,
            },
        });
    }

    function reviewsWithPagination() {
        const wrapper = document.querySelector('#product-reviews-wrapper');
        if (!wrapper) return;

        const ajaxUrl = sp_localized_data.ajax_url;
        const productId = sp_localized_data.product_id;

        const loadReviews = (page = 1) => {
            const formData = new FormData();
            formData.append('action', 'load_product_reviews');
            formData.append('product_id', productId);
            formData.append('paged', page);

            fetch(ajaxUrl, { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success && (data.data ?? '') !== '') {
                        wrapper.innerHTML = data.data
                    } else {
                        wrapper.innerHTML = '<p class="mx-auto">No reviews yet. Be the first one to leave a review</p>'
                    }
                });
        };

        // initial load
        loadReviews();

        // pagination
        wrapper.addEventListener('click', e => {
            const link = e.target.closest('[data-page]');
            if (!link) return;
            e.preventDefault();
            const page = link.dataset.page;
            loadReviews(page);
            wrapper.scrollIntoView({ behavior: 'smooth' });
        });
    }


    var minus = document.getElementById('dd-qty-minus');
    var plus  = document.getElementById('dd-qty-plus');
    var input = document.getElementById('dd-qty-input');
    var atcBtn = document.getElementById('dd-atc-btn');
    var qtyWrapper = document.getElementById('dd-qty-wrapper');

    if (!minus || !plus || !input) return;

    var cap = parseInt(input.getAttribute('max'), 10) || 50;
    var min = parseInt(input.getAttribute('min'), 10) || 1;
    var alreadyInCart = parseInt((window.sp_localized_data && sp_localized_data.already_in_cart_qty) || 0, 10);
    var remaining = Math.max(0, cap - alreadyInCart);

    function syncQty() {
        if (atcBtn) atcBtn.setAttribute('data-quantity', parseInt(input.value, 10));
    }

    function clearQtyNotice() {
        var existing = qtyWrapper && qtyWrapper.parentNode && qtyWrapper.parentNode.querySelector('.dd-qty-notice');
        if (existing) existing.parentNode.removeChild(existing);
    }

    // Mirrors cart-page.js's showCartError() pattern — a dismissible .woocommerce-error notice
    // inserted next to the control it applies to, since this page never renders one otherwise.
    function showQtyNotice(message, persistent) {
        clearQtyNotice();
        if (!qtyWrapper || !qtyWrapper.parentNode) return;

        var notice = document.createElement('div');
        notice.className = 'dd-qty-notice woocommerce-error';
        notice.setAttribute('role', 'alert');
        notice.textContent = message;
        qtyWrapper.parentNode.insertBefore(notice, qtyWrapper);

        if (!persistent) {
            setTimeout(function () {
                if (notice.parentNode) notice.parentNode.removeChild(notice);
            }, 5000);
        }
    }

    function setControlsDisabled(disabled) {
        plus.disabled = disabled;
        input.disabled = disabled;
        if (atcBtn) {
            atcBtn.classList.toggle('pointer-events-none', disabled);
            atcBtn.classList.toggle('opacity-70', disabled);
            atcBtn.classList.toggle('cursor-not-allowed', disabled);
            atcBtn.setAttribute('aria-disabled', disabled ? 'true' : 'false');
        }
    }

    // Reconciles the stepper/Add to Cart button with the live "remaining" allowance, which
    // accounts for quantity already sitting in the cart (mini-cart, cart page) — not just the
    // quantity being newly added here.
    function applyLimitState() {
        if (remaining <= 0) {
            setControlsDisabled(true);
            showQtyNotice(
                'You already have the maximum quantity (' + cap + ') of this product in your cart.',
                true
            );
            return;
        }

        setControlsDisabled(false);
        var qty = parseInt(input.value, 10) || min;
        if (qty > remaining) {
            input.value = remaining;
            syncQty();
        }
    }

    minus.addEventListener('click', function () {
        var qty = parseInt(input.value, 10);
        if (qty > min) { input.value = qty - 1; syncQty(); }
    });

    plus.addEventListener('click', function () {
        var qty = parseInt(input.value, 10);
        if (qty >= remaining) {
            showQtyNotice(
                'You can only add up to ' + remaining + ' more of this item (maximum ' + cap + ' per product).',
                false
            );
            return;
        }
        input.value = qty + 1;
        syncQty();
    });

    // Digits only — blocks letters/symbols as they're typed, but lets navigation/editing keys
    // (arrows, backspace, tab, etc.) and the native number spinner's own key handling through.
    var allowedControlKeys = ['Backspace', 'Delete', 'Tab', 'Escape', 'Enter', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'];
    input.addEventListener('keydown', function (e) {
        if (allowedControlKeys.indexOf(e.key) !== -1 || e.ctrlKey || e.metaKey) return;
        if (!/^[0-9]$/.test(e.key)) e.preventDefault();
    });

    // Fires for keyboard typing, paste, and clicks on the input's native spinner arrows alike —
    // clamps live as the value changes, without spamming a notice on every keystroke.
    input.addEventListener('input', function () {
        var digits = input.value.replace(/[^0-9]/g, '');
        if (digits === '') return; // let them clear the field while editing; finalized on blur
        var qty = parseInt(digits, 10);
        if (qty < min) qty = min;
        if (qty > remaining) qty = remaining;
        if (String(qty) !== input.value) input.value = qty;
        syncQty();
    });

    // Finalizes on blur (typing away, clicking elsewhere, or tabbing out) — falls back to the
    // minimum on an empty/invalid value and surfaces the cap notice if they'd typed over it.
    // Skipped when the field was just programmatically disabled (setControlsDisabled toggling
    // input.disabled forces a synchronous blur) — that's not a user edit to finalize, and
    // running this here would flash a stale "up to N more" notice right before applyLimitState's
    // own "already have the maximum" notice replaces it.
    input.addEventListener('blur', function () {
        if (input.disabled) return;

        var digits = input.value.replace(/[^0-9]/g, '');
        var typedQty = digits === '' ? NaN : parseInt(digits, 10);
        var qty = isNaN(typedQty) || typedQty < min ? min : typedQty;
        var wasOverCap = qty > remaining;
        if (wasOverCap) qty = Math.max(min, remaining);

        input.value = qty;
        syncQty();

        if (wasOverCap) {
            showQtyNotice(
                'You can only add up to ' + remaining + ' more of this item (maximum ' + cap + ' per product).',
                false
            );
        }
    });

    if (atcBtn) {
        // Listener is on the button itself, so it runs in the "target phase" before the click
        // bubbles up to WooCommerce core's delegated handler on document.body — stopping it here
        // reliably blocks the native ajax_add_to_cart request when over the allowance.
        atcBtn.addEventListener('click', function (e) {
            var qty = parseInt(input.value, 10) || min;
            if (remaining <= 0 || qty > remaining) {
                e.preventDefault();
                e.stopImmediatePropagation();
                showQtyNotice(
                    remaining <= 0
                        ? 'You already have the maximum quantity (' + cap + ') of this product in your cart.'
                        : 'You can only add up to ' + remaining + ' more of this item (maximum ' + cap + ' per product).',
                    false
                );
            }
        });
    }

    jQuery(document.body).on('wc_fragments_refreshed wc_fragments_loaded', syncQty);

    // Mini-cart quantity changes (+/-, delete) happen outside this page's own AJAX calls, so the
    // in-memory "remaining" allowance would otherwise go stale until a full reload.
    jQuery(document.body).on('dd_cart_quantity_changed', function (event, productId, newQty) {
        if (!window.sp_localized_data || String(productId) !== String(sp_localized_data.product_id)) return;

        alreadyInCart = parseInt(newQty, 10) || 0;
        remaining = Math.max(0, cap - alreadyInCart);
        clearQtyNotice();
        applyLimitState();
    });

    // Reset the stepper back to 1 after a successful add, and re-check the allowance against the
    // quantity that just landed in the cart.
    jQuery(document.body).on('added_to_cart', function (event, fragments, cart_hash, $button) {
        if (!$button || $button.attr('id') !== 'dd-atc-btn') return;

        var addedQty = parseInt(input.value, 10) || min;
        alreadyInCart += addedQty;
        remaining = Math.max(0, cap - alreadyInCart);

        input.value = min;
        syncQty();
        clearQtyNotice();
        applyLimitState();
    });

    applyLimitState();

    /**
     * ===============================================================================================
     * Main.
    */
    renderSaleEndsTimer();
    handleProductImagesSlider();
    reviewsWithPagination();
});


document.addEventListener('DOMContentLoaded', () => {
    const tabs = document.querySelectorAll('.product-tab');
    const contents = document.querySelectorAll('.product-tab-content');
    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const target = tab.dataset.tab;
            tabs.forEach(item => {
                item.classList.remove('bg-primary', 'text-white');
            });
            contents.forEach(content => {
                content.classList.add('hidden');
            });
            tab.classList.add('bg-primary', 'text-white');
            document
                .getElementById(target)
                .classList.remove('hidden');
        });
    });
});