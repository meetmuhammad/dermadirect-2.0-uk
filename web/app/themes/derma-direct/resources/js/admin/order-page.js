/**
 * Order Filters Admin JavaScript
 * Handles modal popup and applied filters pills
 */

(function($) {
    'use strict';

    /**
     * Order Filters Manager
     */
    const OrderFilters = {

        /**
         * Initialize the order filters functionality
         */
        init: function() {
            this.repositionFilters();
            this.bindEvents();
        },

        /**
         * Reposition applied filters container below tablenav
         */
        repositionFilters: function() {
            const $filtersContainer = $('.applied-filters-container');
            const $tablenav = $('.tablenav.top');

            if ($filtersContainer.length && $tablenav.length) {
                // Move the filters container after tablenav
                $filtersContainer.insertAfter($tablenav);
            }
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Show modal when Additional Filters button is clicked
            $(document).on('click', '#show_order_filters', this.showModal);

            // Close modal events
            $(document).on('click', '#order-filters-close', this.hideModal);
            $(document).on('click', '.order-filters-modal-overlay', this.overlayClick);

            // Clear all filters buttons
            $(document).on('click', '#clear-all-filters, #clear-all-filters-btn', this.clearAllFilters);

            // Remove individual filter pills
            $(document).on('click', '.remove-filter', this.removeFilter);

            // Form submission from modal
            $(document).on('submit', '#order-filters-form', this.submitFilters);

            // ESC key to close modal
            $(document).on('keydown', this.handleKeyDown);

            // Handle multiselect functionality for older browsers
            this.enhanceMultiselects();
        },

        /**
         * Show the filters modal
         */
        showModal: function(e) {
            e.preventDefault();
            console.log('Show modal clicked'); // Debug

            const $modal = $('#order-filters-modal');
            console.log('Modal found:', $modal.length); // Debug

            $modal.addClass('show');
            $('body').addClass('modal-open');

            // Focus first input
            setTimeout(function() {
                $modal.find('input, select').first().focus();
            }, 100);
        },

        /**
         * Hide the filters modal
         */
        hideModal: function(e) {
            if (e) e.preventDefault();

            const $modal = $('#order-filters-modal');
            $modal.removeClass('show');
            $('body').removeClass('modal-open');
        },

        /**
         * Handle overlay click
         */
        overlayClick: function(e) {
            if (e.target === e.currentTarget) {
                OrderFilters.hideModal();
            }
        },

        /**
         * Handle keyboard events
         */
        handleKeyDown: function(e) {
            if (e.keyCode === 27) { // ESC key
                OrderFilters.hideModal();
            }
        },

        /**
         * Clear all filters
         */
        clearAllFilters: function(e) {
            e.preventDefault();

            if (!confirm('Are you sure you want to clear all filters?')) {
                return;
            }

            // Build URL without filter parameters
            const baseUrl = window.location.href.split('?')[0];
            const urlParams = new URLSearchParams(window.location.search);

            // Keep only essential parameters
            const keepParams = ['post_type', 'page'];
            const newParams = new URLSearchParams();

            keepParams.forEach(param => {
                if (urlParams.has(param)) {
                    newParams.set(param, urlParams.get(param));
                }
            });

            const newUrl = baseUrl + (newParams.toString() ? '?' + newParams.toString() : '');
            window.location.href = newUrl;
        },

        /**
         * Remove individual filter
         */
        removeFilter: function(e) {
            e.preventDefault();

            const filterType = $(this).data('filter');
            const currentUrl = new URL(window.location);
            const params = currentUrl.searchParams;

            // Remove specific filter parameters
            switch (filterType) {
                case 'post_status':
                    params.delete('post_status');
                    // Also delete array parameters
                    const keysToDelete = [];
                    for (let key of params.keys()) {
                        if (key === 'post_status[]' || key.startsWith('post_status[')) {
                            keysToDelete.push(key);
                        }
                    }
                    keysToDelete.forEach(key => params.delete(key));
                    break;
                case 'payment_customer_filter':
                    params.delete('payment_customer_filter');
                    break;
                case 'nonregistered_users_filter':
                    params.delete('nonregistered_users_filter');
                    break;
                case 'user_email_search':
                    params.delete('user_email_search');
                    break;
                case 'user_billing_first_name':
                    params.delete('user_billing_first_name');
                    break;
                case 'user_billing_last_name':
                    params.delete('user_billing_last_name');
                    break;
                case 'user_phone':
                    params.delete('user_phone');
                    break;
                case 'user_billing_country':
                    params.delete('user_billing_country');
                    // Also delete array parameters
                    const countryKeysToDelete = [];
                    for (let key of params.keys()) {
                        if (key === 'user_billing_country[]' || key.startsWith('user_billing_country[')) {
                            countryKeysToDelete.push(key);
                        }
                    }
                    countryKeysToDelete.forEach(key => params.delete(key));
                    break;
                case 'shipping_method_filter':
                    params.delete('shipping_method_filter');
                    break;
                case 'shipping_track_number':
                    params.delete('shipping_track_number');
                    break;
                case 'filter_search_sku':
                    params.delete('filter_search_sku');
                    break;
                case 'date_range':
                    params.delete('filter_start_date');
                    params.delete('filter_end_date');
                    break;
                case 'order_total':
                    params.delete('order_total_start');
                    params.delete('order_total_end');
                    break;
            }

            // Redirect to updated URL
            window.location.href = currentUrl.toString();
        },

        /**
         * Submit filters from modal
         */
        submitFilters: function(e) {
            e.preventDefault();

            // Validate filters before submitting
            const isValid = OrderFilters.validateFilters();
            if (!isValid) {
                return false;
            }

            // Collect form data
            const $form = $('#order-filters-form');
            const baseUrl = window.location.href.split('?')[0];
            const currentParams = new URLSearchParams(window.location.search);

            // Build new URL with filter parameters
            const newParams = new URLSearchParams();

            // Keep important parameters
            if (currentParams.has('post_type')) {
                newParams.set('post_type', currentParams.get('post_type'));
            }
            if (currentParams.has('page')) {
                newParams.set('page', currentParams.get('page'));
            }

            // Add all form values
            $form.find('input, select').each(function() {
                const $el = $(this);
                const name = $el.attr('name');
                const value = $el.val();

                // Skip empty values and buttons
                if (!name || !value || $el.attr('type') === 'button' || $el.attr('type') === 'submit') {
                    return;
                }

                // Handle multiselect
                if ($el.is('select[multiple]') && Array.isArray(value)) {
                    value.forEach((v, index) => {
                        newParams.append(name + '[]', v);
                    });
                } else if (value) {
                    newParams.set(name, value);
                }
            });

            // Navigate to filtered URL
            const newUrl = baseUrl + (newParams.toString() ? '?' + newParams.toString() : '');
            window.location.href = newUrl;
        },

        /**
         * Validate filter inputs
         */
        validateFilters: function() {
            let isValid = true;

            // Clear previous error states
            $('.order-filters-modal input, .order-filters-modal select')
                .removeClass('error success');

            // Validate date range
            const startDate = $('#filter_start_date').val();
            const endDate = $('#filter_end_date').val();

            if ((startDate && !endDate) || (!startDate && endDate)) {
                $('#filter_start_date, #filter_end_date').addClass('error');
                alert('Please provide both start and end dates for date range filtering.');
                isValid = false;
            } else if (startDate && endDate && startDate > endDate) {
                $('#filter_start_date, #filter_end_date').addClass('error');
                alert('Start date cannot be later than end date.');
                isValid = false;
            } else if (startDate && endDate) {
                $('#filter_start_date, #filter_end_date').addClass('success');
            }

            // Validate order total range
            const totalStart = parseFloat($('#order_total_start').val()) || 0;
            const totalEnd = parseFloat($('#order_total_end').val()) || 0;

            if (totalStart > 0 && totalEnd > 0 && totalStart > totalEnd) {
                $('#order_total_start, #order_total_end').addClass('error');
                alert('Starting total cannot be greater than ending total.');
                isValid = false;
            } else if (totalStart > 0 || totalEnd > 0) {
                $('#order_total_start, #order_total_end').addClass('success');
            }

            return isValid;
        },

        /**
         * Enhance multiselect dropdowns
         */
        enhanceMultiselects: function() {
            // Add Select All / Deselect All functionality for multiselects
            $('.order_statuses_select').each(function() {
                const $select = $(this);
                const $wrapper = $select.closest('.order_block_wrapper');

                // Add control buttons
                if (!$wrapper.find('.multiselect-controls').length) {
                    const $controls = $('<div class="multiselect-controls" style="margin-top: 5px; font-size: 11px;"></div>');
                    const $selectAll = $('<a href="#" style="margin-right: 10px;">Select All</a>');
                    const $deselectAll = $('<a href="#">Deselect All</a>');

                    $selectAll.on('click', function(e) {
                        e.preventDefault();
                        $select.find('option').prop('selected', true);
                    });

                    $deselectAll.on('click', function(e) {
                        e.preventDefault();
                        $select.find('option').prop('selected', false);
                    });

                    $controls.append($selectAll, ' | ', $deselectAll);
                    $wrapper.append($controls);
                }
            });
        }
    };

    /**
     * Initialize when document is ready
     */
    $(document).ready(function() {
        // Only initialize on orders pages
        if ($('body').hasClass('edit-php') || $('body').hasClass('woocommerce_page_wc-orders')) {
            OrderFilters.init();
        }
    });

})(jQuery);
