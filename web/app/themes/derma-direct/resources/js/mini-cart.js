/**
 * Open mini-cart when a product is added to the cart.
 */
jQuery(document.body).on('added_to_cart', function(event, fragments, cart_hash, button) {
    const minicart_slide__el = jQuery('#minicart_slide');
    const overlay_el = jQuery('.minicart-overlay');

    if (!minicart_slide__el.length || !overlay_el.length) return;
    // Content swap is handled by WooCommerce core's own updateFragments() (bound to this
    // same 'added_to_cart' event on document.body), which now correctly matches and replaces
    // #minicart_content since the fragment key is a real selector. Doing it again here would
    // just re-replace it with identical markup right after core's own fade/block animation.

    minicart_slide__el.addClass('open');   // Slide open
    overlay_el.addClass('overlay-open');   // Overlay visible
});

/**
 * Handle mini-cart close button
 */
jQuery(function() {
    jQuery('#open_minicart').on('click', function() {
        const minicart_slide__el = jQuery('#minicart_slide');
        const overlay_el = jQuery('.minicart-overlay');
        if (!minicart_slide__el.length || !overlay_el.length) return;

        minicart_slide__el.addClass('open');   // Slide open
        overlay_el.addClass('overlay-open');   // Overlay visible
    });

    function handleMiniCartClose() {
        const minicart_slide__el = jQuery('#minicart_slide');
        const close_btn__el = jQuery('#close_minicart_slide');
        const overlay_el = jQuery('.minicart-overlay');

        if (!minicart_slide__el.length || !close_btn__el.length) return;

        close_btn__el.on('click', function() {
            minicart_slide__el.removeClass('open');      // Slide close
            overlay_el.removeClass('overlay-open');      // Overlay hide
        });

        overlay_el.on('click', function() {
            minicart_slide__el.removeClass('open');
            overlay_el.removeClass('overlay-open');
        });
    }

    // Mirrors cart-page.js's showCartError() pattern — a dismissible .woocommerce-error notice,
    // since the mini-cart drawer previously had no way to surface a cap/stock error at all.
    function showMiniCartError(message) {
        jQuery('.mini-cart-notice').remove();

        const $notice = jQuery(
            '<div class="mini-cart-notice woocommerce-error" role="alert"></div>'
        ).text(message);

        jQuery('#minicart_content').first().before($notice);

        setTimeout(function () {
            $notice.fadeOut(200, function () {
                jQuery(this).remove();
            });
        }, 5000);
    }

    function updateMiniCartQty(productId, newQty, callback) {
        jQuery.ajax({
            url: minicart_vars.ajax_url,
            method: "POST",
            data: {
                action: "update_minicart_qty",
                product_id: productId,
                quantity: newQty,
                nonce: minicart_vars.nonce,
            },
            success: function(response) {
                let success = false;

                if (response && response.fragments) {
                    if (response.fragments['updated_minicart_content']) {
                        jQuery('#minicart_content').html(response.fragments['updated_minicart_content']);
                    }
                    if (response.fragments['cart_count'] !== undefined) {
                        jQuery('#woo_cart_count').text(response.fragments['cart_count']);
                    }
                    success = true;
                    // Lets other surfaces (e.g. the single product page's cap tracking) react to
                    // a quantity change made from the mini-cart, since they can't see it otherwise.
                    jQuery(document.body).trigger('dd_cart_quantity_changed', [productId, newQty]);
                } else if (response && response.data) {
                    if (response.data.message) {
                        showMiniCartError(response.data.message);
                    }
                    if (typeof callback === "function") {
                        callback(false, response.data.reset_quantity);
                    }
                    return;
                }

                if (typeof callback === "function") {
                    callback(success);
                }
            },
            error: function() {
                if (typeof callback === "function") {
                    callback(false);
                }
            }
        });
    }

    const hardCap = 50;

    function clampMiniCartQty(rawValue) {
        const digits = String(rawValue).replace(/[^0-9]/g, '');
        if (digits === '') return null; // still being typed/cleared — not finalized yet

        let qty = parseInt(digits, 10);
        if (qty < 1) qty = 1;
        if (qty > hardCap) qty = hardCap;

        return qty;
    }

    function handleQtyChange() {
        // PLUS button
        jQuery(document).on("click", ".mini-cart-quantity-plus", function () {
            const productId = jQuery(this).data("product-id");
            const input = jQuery(this).siblings("input");
            let qty = parseInt(input.val(), 10) || 1;

            if (qty >= hardCap) {
                showMiniCartError('Maximum order quantity per product is 50.');
                return;
            }

            const newQty = qty + 1;

            updateMiniCartQty(productId, newQty, function(success, resetQuantity) {
                if (success) {
                    input.val(newQty); // only update after success
                } else if (resetQuantity !== undefined) {
                    input.val(resetQuantity);
                }
            });
        });

        // MINUS button
        jQuery(document).on("click", ".mini-cart-quantity-minus", function () {
            const productId = jQuery(this).data("product-id");
            const input = jQuery(this).siblings("input");
            let qty = parseInt(input.val(), 10) || 1;

            if (qty <= 1) return; // min limit

            const newQty = qty - 1;

            updateMiniCartQty(productId, newQty, function(success, resetQuantity) {
                if (success) {
                    input.val(newQty); // only update after success
                } else if (resetQuantity !== undefined) {
                    input.val(resetQuantity);
                }
            });
        });
    }

    // Lets the mini-cart quantity field be typed into directly (previously pointer-events-none,
    // +/- buttons only). Keyboard typing and clicks on the input's native spinner arrows both
    // fire the 'input' event, so a single debounced handler covers keyboard and mouse alike.
    function handleQtyTyping() {
        const allowedControlKeys = ['Backspace', 'Delete', 'Tab', 'Escape', 'Enter', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'];
        const debounceTimers = new WeakMap();

        jQuery(document).on('keydown', '.mini-cart-quantity', function (e) {
            if (allowedControlKeys.indexOf(e.key) !== -1 || e.ctrlKey || e.metaKey) return;
            if (!/^[0-9]$/.test(e.key)) e.preventDefault();
        });

        // Tracked so blur can tell "clicked in and back out without changing anything" apart from
        // a real edit — otherwise every stray focus/blur re-sends the quantity and re-renders the
        // whole mini-cart from the server response for no reason.
        jQuery(document).on('focus', '.mini-cart-quantity', function () {
            jQuery(this).data('ddQtyFocusValue', jQuery(this).val());
        });

        jQuery(document).on('input', '.mini-cart-quantity', function () {
            const $input = jQuery(this);
            const el = this;
            const productId = $input.data('product-id');
            const qty = clampMiniCartQty($input.val());

            if (qty === null) return; // let them clear the field while editing
            if (String(qty) !== $input.val()) $input.val(qty);

            clearTimeout(debounceTimers.get(el));
            debounceTimers.set(el, setTimeout(function () {
                updateMiniCartQty(productId, qty, function (success, resetQuantity) {
                    if (!success && resetQuantity !== undefined) {
                        $input.val(resetQuantity);
                    }
                });
            }, 500));
        });

        // Finalize immediately on blur (clicking/tabbing away) instead of waiting on the debounce.
        jQuery(document).on('blur', '.mini-cart-quantity', function () {
            const $input = jQuery(this);
            const el = this;
            const productId = $input.data('product-id');
            const focusValue = parseInt($input.data('ddQtyFocusValue'), 10);
            const typedDigits = String($input.val()).replace(/[^0-9]/g, '');
            const rawQty = clampMiniCartQty($input.val());
            const qty = rawQty === null ? 1 : rawQty;

            clearTimeout(debounceTimers.get(el));
            if (String(qty) !== $input.val()) $input.val(qty);

            if (typedDigits !== '' && parseInt(typedDigits, 10) > hardCap) {
                showMiniCartError('Maximum order quantity per product is 50.');
            }

            if (qty === focusValue) return; // nothing actually changed — skip the no-op request

            updateMiniCartQty(productId, qty, function (success, resetQuantity) {
                if (success) {
                    $input.val(qty);
                } else if (resetQuantity !== undefined) {
                    $input.val(resetQuantity);
                }
            });
        });
    }

    // Fallback for when a product was added via a plain page navigation
    // (?add-to-cart=ID → redirected to ?dd_added=ID by dd_clean_up_fallback_add_to_cart() in
    // filters.php) instead of WooCommerce's own ajax_add_to_cart click handler — refreshes and
    // opens the drawer the same way the AJAX flow's `added_to_cart` event would have, then
    // cleans the URL so a page refresh doesn't leave a stray query param behind.
    function handleFallbackAddToCart() {
        const params = new URLSearchParams(window.location.search);
        const addedProductId = params.get('dd_added');

        if (!addedProductId) return;

        params.delete('dd_added');
        const cleanedSearch = params.toString();
        const cleanedUrl = window.location.pathname + (cleanedSearch ? '?' + cleanedSearch : '') + window.location.hash;
        window.history.replaceState(null, '', cleanedUrl);

        jQuery.ajax({
            url: minicart_vars.ajax_url,
            method: 'POST',
            data: {
                action: 'refresh_minicart',
                nonce: minicart_vars.nonce,
            },
            success: function (response) {
                if (!response || !response.fragments) return;

                const minicart_slide__el = jQuery('#minicart_slide');
                const overlay_el = jQuery('.minicart-overlay');

                if (response.fragments['updated_minicart_content']) {
                    jQuery('#minicart_content').html(response.fragments['updated_minicart_content']);
                }
                if (response.fragments['cart_count'] !== undefined) {
                    jQuery('#woo_cart_count').text(response.fragments['cart_count']);
                }

                if (minicart_slide__el.length && overlay_el.length) {
                    minicart_slide__el.addClass('open');
                    overlay_el.addClass('overlay-open');
                }
            },
        });
    }

    function handleCartItemDelete() {
        jQuery(document).on("click", ".delete-product-item", function () {
            const button = jQuery(this);
            const productId = button.data("product-id");

            if (!productId) return;

            jQuery.ajax({
                url: minicart_vars.ajax_url,
                method: "POST",
                data: {
                    action: "delete_minicart_item",
                    product_id: productId,
                    nonce: minicart_vars.nonce
                },
                success: function (response) {
                    if (response && response.fragments && response.fragments['updated_minicart_content']) {
                        jQuery('#minicart_content').html(response.fragments['updated_minicart_content']);
                    }
                    jQuery(document.body).trigger('dd_cart_quantity_changed', [productId, 0]);
                },
                error: function () {
                    console.error("Failed to remove product from cart.");
                }
            });
        });
    }

    function handlePromoCode() {
        /**
         * --------------------------------------------------------------------
         * Toggling promo code input field.
        */
        jQuery(document).on('click', '.add-promo-code', function(e) {
            e.preventDefault();
            jQuery('.promo-enter').toggleClass('hidden');
        });

        /**
         * --------------------------------------------------------------------
         * Applying promocode to the cart items.
        */
        jQuery(document).on('click', '.apply-coupon-code', function(e) {
            e.preventDefault();

            const container = jQuery(this).closest('.promo-enter');
            const couponCode = container.find('.coupon-code-input').val().trim();

            if (!couponCode) return;

            jQuery.ajax({
                url: minicart_vars.ajax_url,
                method: 'POST',
                data: {
                    action: 'apply_minicart_coupon',
                    coupon_code: couponCode,
                    nonce: minicart_vars.nonce
                },
                success: function(response) {
                    if (!response) return;

                    // Coupon applied successfully
                    if (response.success) {
                        // Update mini-cart content (subtotal, total, etc.)
                        if (response.fragments && response.fragments['updated_minicart_content']) {
                            jQuery('#minicart_content').html(response.fragments['updated_minicart_content']);
                        }

                        // Show success message
                        jQuery('.success-coupon-msg')
                            .text(response.data.message)
                            .removeClass('hidden');

                        // Hide error message
                        jQuery('.failed-coupon-error').addClass('hidden');

                        // Clear input field
                        container.find('.coupon-code-input').val('');
                    }
                    // Coupon invalid
                    else {
                        jQuery('.failed-coupon-error')
                            .text(response.data.message)
                            .removeClass('hidden');
                    }
                },
                error: function() {
                    jQuery('.failed-coupon-error')
                        .text('Failed to apply coupon. Please try again.')
                        .removeClass('hidden');
                }
            });
        });

        /**
         * --------------------------------------------------------------------
         * Removing coupon
        */
        jQuery(document).on("click", ".remove-coupon", function () {
            const parent = jQuery(this).closest('.remove-coupon-div');
            const couponCode = parent.data("coupon");

            if (!couponCode) return;

            jQuery.ajax({
                url: minicart_vars.ajax_url,
                method: "POST",
                data: {
                    action: "remove_minicart_coupon",
                    coupon_code: couponCode,
                    nonce: minicart_vars.nonce
                },
                success: function (response) {
                   if (response.fragments && response.fragments['updated_minicart_content']) {
                        jQuery('#minicart_content').html(response.fragments['updated_minicart_content']);
                    }
                },
                error: function () {
                    console.error("Failed to remove coupon.");
                }
            });
        });
    }

    /**
     * ====================================================================
     * Main.
     * ====================================================================
    */
    handleMiniCartClose();
    handleQtyChange();
    handleQtyTyping();
    handleCartItemDelete();
    handlePromoCode();
    handleFallbackAddToCart();
});
