jQuery(function ($) {

    function setPanelHeight() {
        const $panel = $('#buy-again-panel');

        if ($panel.data('open')) {
            $panel.css('max-height', $panel[0].scrollHeight + 'px');
        }
    }

    // Accordion
    $('#buy-again-toggle').on('click', function () {

        const $panel = $('#buy-again-panel');
        const $chevron = $('#buy-again-chevron');

        if ($panel.data('open')) {

            $panel.data('open', false);
            $panel.css('max-height', '0px');
            $chevron.css('transform', 'rotate(0deg)');
            $(this).attr('aria-expanded', 'false');

        } else {

            $panel.data('open', true);
            $panel.css('max-height', $panel[0].scrollHeight + 'px');
            $chevron.css('transform', 'rotate(180deg)');
            $(this).attr('aria-expanded', 'true');

        }

    });

    // View More
    $('#buy-again-view-more').on('click', function () {

        const $extras = $('[data-buy-again-extra="true"]');
        const $text = $('#buy-again-view-more-text');
        const $icon = $('#buy-again-view-more-icon');

        if (!$extras.length) {
            return;
        }

        const isHidden = $extras.first().hasClass('hidden');

        if (isHidden) {
            $extras.removeClass('hidden');
            $text.text('View less');
            $icon.css('transform', 'rotate(180deg)');
        } else {
            $extras.addClass('hidden');

            const count = $extras.length;
            $text.text(`View ${count} more ${count === 1 ? 'item' : 'items'}`);
            $icon.css('transform', 'rotate(0deg)');
        }

        setPanelHeight();

    });

    // Favourite Toggle
    $(document).on('click', '.favourite-toggle-btn', function (e) {

        e.preventDefault();
        e.stopPropagation();

        const $btn = $(this);

        $btn.prop('disabled', true);

        $.ajax({
            url: AccountDashboardConfig.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'toggle_favourite_product',
                nonce: AccountDashboardConfig.nonce,
                product_id: $btn.data('product-id')
            },
            success: function (response) {

                $btn.prop('disabled', false);

                if (!response.success) {
                    return;
                }

                const isFavourite = response.data.is_favourite;
                const $svg = $btn.find('svg');
                const $card = $btn.closest('.buy-again-item');

                if (isFavourite) {

                    $btn
                        .removeClass('border-gray-300 bg-white text-gray-400')
                        .addClass('border-primary bg-primary text-white')
                        .attr({
                            'data-favourite': 'true',
                            'aria-label': 'Remove from favourites',
                            'title': 'Remove from favourites'
                        });

                    $svg.attr('fill', 'currentColor');

                    $card
                        .removeClass('border-gray-200')
                        .addClass('border-primary/40 bg-primary/5');

                } else {

                    $btn
                        .removeClass('border-primary bg-primary text-white')
                        .addClass('border-gray-300 bg-white text-gray-400')
                        .attr({
                            'data-favourite': 'false',
                            'aria-label': 'Add to favourites',
                            'title': 'Pin as favourite'
                        });

                    $svg.attr('fill', 'none');

                    $card
                        .removeClass('border-primary/40 bg-primary/5')
                        .addClass('border-gray-200');

                }

                setPanelHeight();

            },
            error: function () {
                $btn.prop('disabled', false);
            }
        });

    });

    // Auto open if URL hash exists
    if (window.location.hash === '#buy-again') {

        $('#buy-again-toggle').trigger('click');

        $('html, body').animate({
            scrollTop: $('#buy-again').offset().top
        }, 500);

    }

});