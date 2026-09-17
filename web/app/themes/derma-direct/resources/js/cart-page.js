// This script is for the cart page for quantity stepper, free delivery bar, and coupon toggle.
jQuery(function ($) {
    function wrapQtyInputs() {
        $('.woocommerce-cart-form .qty').each(function () {
            const $input = $(this);
            if ($input.data('ddce-wrapped')) {
                return;
            }
            $input.data('ddce-wrapped', true);
            $input.wrap('<span class="ddce-qty-stepper"></span>');
            $input.before('<button type="button" class="ddce-qty-minus">&minus;</button>');
            $input.after('<button type="button" class="ddce-qty-plus">+</button>');
        });
    }

    wrapQtyInputs();

    $('.woocommerce-cart-form button[name="update_cart"]')
        .hide();

    const hardCap = 50;

    // Returns a clamped integer, or null while the field is empty/being edited (not finalized).
    function clampCartQty(rawValue) {
        const digits = String(rawValue).replace(/[^0-9]/g, '');
        if (digits === '') return null;

        let qty = parseInt(digits, 10);
        if (qty < 1) qty = 1;
        if (qty > hardCap) qty = hardCap;

        return qty;
    }

    function getCartItemKey($input) {
        const name = $input.attr('name') || '';
        const match = name.match(/cart\[([^\]]+)\]/);

        return match ? match[1] : null;
    }

    let debounceTimer;
    function debounceUpdate($input) {
        clearTimeout(debounceTimer);

        debounceTimer = setTimeout(function () {
            sendQtyUpdate($input);
        }, 450);
    }
    // Send the quantity update to the server via AJAX
    function sendQtyUpdate($input) {
        const $row = $input.closest('tr.cart_item');
        const cartItemKey = getCartItemKey($input);
        const quantity = parseInt($input.val(), 10) || 0;
        if (!cartItemKey) {
            return;
        }
        $row.addClass('ddce-updating');
        $.post(ddceParams.ajax_url, {
            action: 'ddce_update_qty',
            nonce: ddceParams.nonce,
            cart_item_key: cartItemKey,
            quantity: quantity
        })
        .done(function (response) {
            if (!response || !response.success) {
                $row.removeClass('ddce-updating');
                const errorData = response && response.data ? response.data : {};
                if (errorData.reset_quantity !== undefined) {
                    $input.val(errorData.reset_quantity);
                }
                if (errorData.message) {
                    showCartError(errorData.message);
                }
                return;
            }
            const data = response.data;
            if (data.cart_is_empty) {
                location.reload();
                return;
            }
            if (quantity === 0) {
                $row.fadeOut(200, function () {
                    $(this).remove();
                });
            } else if (data.line_subtotal) {
                $row.find('.product-subtotal')
                    .html(data.line_subtotal);
            }

            $('.cart-subtotal td')
                .last()
                .html(data.cart_subtotal);

            $('.order-total td')
                .last()
                .html(data.cart_total);

            renderFreeDeliveryBar(data.cart_total_raw);
            $(document.body).trigger('wc_fragment_refresh');
            $row.removeClass('ddce-updating');

        })

        .fail(function () {
            $row.removeClass('ddce-updating');
        });
    }

    function showCartError(message) {
        // Remove any existing error notice
        $('.ddce-cart-notice').remove();
        const $notice = $(
            '<div class="ddce-cart-notice woocommerce-error" role="alert">' +
            $('<div>').text(message).html() +
            '</div>'
        );
        // Insert at the very top of the cart form
        $('.woocommerce-cart-form').first().before($notice);
        // Scroll into view so user notices it, especially on longer carts
        $('html, body').animate({
            scrollTop: $notice.offset().top - 100
        }, 300);
        // Auto-dismiss after a few seconds
        setTimeout(function () {
            $notice.fadeOut(200, function () {
                $(this).remove();
            });
        }, 5000);
    }
    $(document).on('click', '.ddce-qty-plus', function () {
        const $input = $(this).siblings('.qty');
        const current = parseInt($input.val(), 10) || 0;

        if (current >= hardCap) {
            showCartError('Maximum order quantity per product is 50.');
            return;
        }

        $input
            .val(current + 1)
            .trigger('change');
    });

    $(document).on('click', '.ddce-qty-minus', function () {
        const $input = $(this).siblings('.qty');
        $input
            .val(Math.max(0, (parseInt($input.val(), 10) || 0) - 1))
            .trigger('change');
    });

    $(document).on('change', '.woocommerce-cart-form .qty', function () {
        debounceUpdate($(this));
    });

    // Digits only — blocks letters/symbols as they're typed, but lets navigation/editing keys
    // (arrows, backspace, tab, etc.) through.
    const allowedQtyControlKeys = ['Backspace', 'Delete', 'Tab', 'Escape', 'Enter', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'];
    $(document).on('keydown', '.woocommerce-cart-form .qty', function (e) {
        if (allowedQtyControlKeys.indexOf(e.key) !== -1 || e.ctrlKey || e.metaKey) return;
        if (!/^[0-9]$/.test(e.key)) e.preventDefault();
    });

    // Tracked so blur can tell "clicked in and back out without changing anything" apart from a
    // real edit — otherwise every stray focus/blur re-sends the quantity for no reason.
    $(document).on('focus', '.woocommerce-cart-form .qty', function () {
        $(this).data('ddQtyFocusValue', $(this).val());
    });

    // Fires for keyboard typing, paste, and clicks on the input's native spinner arrows alike —
    // clamps live and debounce-updates the cart as the user types, same as the +/- buttons do.
    $(document).on('input', '.woocommerce-cart-form .qty', function () {
        const $input = $(this);
        const qty = clampCartQty($input.val());

        if (qty === null) return; // let them clear the field while editing; finalized on blur
        if (String(qty) !== $input.val()) $input.val(qty);

        debounceUpdate($input);
    });

    // Finalizes immediately on blur (clicking/tabbing away) instead of waiting on the debounce —
    // falls back to 1 on an empty/invalid value and surfaces the cap notice if they typed over it.
    $(document).on('blur', '.woocommerce-cart-form .qty', function () {
        const $input = $(this);
        const focusValue = parseInt($input.data('ddQtyFocusValue'), 10);
        const typedDigits = String($input.val()).replace(/[^0-9]/g, '');
        const qty = clampCartQty($input.val()) || 1;

        clearTimeout(debounceTimer);
        if (String(qty) !== $input.val()) $input.val(qty);

        if (typedDigits !== '' && parseInt(typedDigits, 10) > hardCap) {
            showCartError('Maximum order quantity per product is 50.');
        }

        if (qty === focusValue) return; // nothing actually changed — skip the no-op request

        sendQtyUpdate($input);
    });
// Render the countdown timer for next day delivery
    function renderCountdown() {
        const $el = $('#ddce-delivery-countdown');
        if (!$el.length) {
            return;
        }
        const now = new Date();
        const cutoff = new Date();
        cutoff.setHours(
            ddceParams.cutoff_hour,
            ddceParams.cutoff_minute,
            0,
            0
        );

        if (now < cutoff) {
            const diff = cutoff - now;
            const hours = Math.floor(diff / 3600000);
            const minutes = Math.floor((diff % 3600000) / 60000);
            $el.html(
                'Order within <strong>' +
                hours +
                'h ' +
                minutes +
                'm</strong> for Next Day Delivery'
            );
        } else {
            $el.html(
                'Today’s cut-off has passed — order now for delivery the day after tomorrow'
            );
        }

    }

    renderCountdown();
    setInterval(renderCountdown, 60000);
    const $couponForm = $('.woocommerce-cart-form .coupon').first();
    if ($couponForm.length && !$couponForm.closest('.ddce-coupon-wrap').length) {
        $couponForm.wrap('<div class="ddce-coupon-wrap"></div>');
        const $wrap = $couponForm.closest('.ddce-coupon-wrap');
        $wrap.hide();
        $wrap.before(
            '<a href="#" class="ddce-coupon-toggle">Have a discount code?</a>'
        );
    }

    $(document).on('click', '.ddce-coupon-toggle', function (e) {
        e.preventDefault();
        const $link = $(this);
        $link.next('.ddce-coupon-wrap').slideDown(200, function () {
            $(this)
                .find('input[type="text"]')
                .first()
                .focus();
        });
        $link.hide();
    });

    // Render the free delivery bar based on the cart total
    function renderFreeDeliveryBar(total) {
        const $bar = $('#ddce-free-delivery-bar');
        if (!$bar.length) {
            return;
        }
        const threshold = parseFloat(ddceParams.free_threshold);
        if (total >= threshold) {
            $bar.html(
                '<div class="ddce-free-delivery-success">You’ve unlocked Free Next Day Delivery!</div>'
            );
            return;
        }
        const remaining = (threshold - total).toFixed(2);
        const percentage = Math.min(100, Math.max(0, (total / threshold) * 100));
        $bar.html(
            '<div class="ddce-progress-label">Spend ' +
            ddceParams.currency_symbol +
            remaining +
            ' more for FREE Next Day Delivery</div>' +
            '<div class="ddce-progress-track">' +
            '<div class="ddce-progress-fill" style="width:' +
            percentage +
            '%;"></div>' +
            '</div>'
        );
    }

    const $bar = $('#ddce-free-delivery-bar');
    if ($bar.length) {
        renderFreeDeliveryBar(parseFloat($bar.data('total')));
    }
});