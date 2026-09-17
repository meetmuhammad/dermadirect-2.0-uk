<?php
namespace App\DermadirectToolsMenu\OrderFilters;

use Illuminate\Support\Facades\Vite;

defined('ABSPATH') || exit;

/**
 * Order Filters Manager
 *
 * Provides comprehensive filtering options for WooCommerce orders page.
 * Filters include: order status, payment method, customer group, shipping method,
 * customer details, billing country, track number, SKU, date range, and order total.
 *
 * All filters use AND logic with each other and with default WooCommerce filters.
 */
class OrderFiltersManager
{
    /**
     * Filter search patterns
     */
    private static $filter_search = array('*', ', ', ',');
    private static $filter_replace = array('%', '|', '|');

    /**
     * Initialize the order filters functionality
     */
    public static function init(): void
    {
        // Only load on admin and for orders page
        if (!is_admin()) {
            return;
        }

        // Hook into admin actions
        add_action('admin_enqueue_scripts', [self::class, 'enqueueScripts']);
        add_action('views_edit-shop_order', [self::class, 'showFiltersButton'], 10);
        add_action('views_woocommerce_page_wc-orders', [self::class, 'showFiltersButton'], 10);
        add_action('restrict_manage_posts', [self::class, 'showFilters'], 15);

        // Add hooks for HPOS orders page
        add_action('woocommerce_order_list_table_restrict_manage_orders', [self::class, 'showFiltersHPOS']);

        // Hook into WooCommerce HPOS (High-Performance Order Storage) if available
        if (self::isHPOSEnabled()) {
            add_filter('woocommerce_orders_table_query_clauses', [self::class, 'modifyHPOSQuery'], 10, 2);
        } else {
            // Legacy post-based orders
            add_filter('posts_where', [self::class, 'modifyPostsWhere']);
            add_filter('pre_get_posts', [self::class, 'filterDateRange']);
        }
    }

    /**
     * Check if HPOS (High-Performance Order Storage) is enabled
     *
     * @return bool
     */
    private static function isHPOSEnabled(): bool
    {
        // Simple check - HPOS is enabled if the option is set to yes
        return get_option('woocommerce_custom_orders_table_enabled') === 'yes';
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook_suffix Current admin page
     */
    public static function enqueueScripts($hook_suffix): void
    {
        global $typenow;

        // Only load on orders pages
        if ($typenow !== 'shop_order' && $hook_suffix !== 'woocommerce_page_wc-orders') {
            return;
        }

        // Enqueue Vite assets for order page
        self::enqueueViteAsset('resources/js/admin/order-page.js', 'order-page-js');
        self::enqueueViteAsset('resources/css/admin/order-page.css', 'order-page-css');

        // Localize script for translations
        wp_localize_script('order-page-js', 'orderFilters', [
            'showFilters' => __('Additional Filters', 'derma-direct'),
            'applyFilters' => __('Apply Filters', 'derma-direct'),
            'clearFilters' => __('Clear', 'derma-direct'),
        ]);
    }

    /**
     * Enqueue Vite assets
     *
     * @param string $entry Entry point path relative to theme root
     * @param string $handle Script/style handle
     */
    private static function enqueueViteAsset($entry, $handle): void
    {
        $vite_uri = Vite::asset($entry);

        if (strpos($entry, '.js') !== false) {
            wp_enqueue_script(
                $handle,
                $vite_uri,
                ['jquery'],
                null,
                true
            );
        } elseif (strpos($entry, '.css') !== false) {
            wp_enqueue_style(
                $handle,
                $vite_uri,
                [],
                null
            );
        }
    }

    /**
     * Show the Additional Filters button
     *
     * @param array $views Current view links
     * @return array
     */
    public static function showFiltersButton($views): array
    {
        echo '<a href="" onclick="event.preventDefault()" id="show_order_filters" class="button action">' .
             esc_html__('Additional Filters', 'derma-direct') . '</a>';

        return $views;
    }

    /**
     * Display all filter form elements
     */
    public static function showFilters(): void
    {
        // Check if we're on the legacy orders page only
        $post_type = sanitize_text_field($_GET['post_type'] ?? '');

        if ($post_type !== 'shop_order') {
            return;
        }

        $output = self::buildFiltersHTML();
        echo $output;
    }

    /**
     * Show filters on HPOS orders page
     */
    public static function showFiltersHPOS(): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'woocommerce_page_wc-orders') {
            return;
        }

        $output = self::buildFiltersHTML();
        echo $output;
    }

    /**
     * Build the complete filters HTML
     *
     * @return string
     */
    private static function buildFiltersHTML(): string
    {
        $output = '';

        // Applied filters pills container
        $output .= '<div class="applied-filters-container">';
        $output .= self::buildAppliedFiltersPills();
        $output .= '</div>';

        // Modal overlay and popup
        $output .= '<div class="order-filters-modal-overlay" id="order-filters-modal">';
        $output .= '<form id="order-filters-form" method="GET" action="">';
        $output .= '<div class="order-filters-modal">';
        $output .= '<div class="order-filters-modal-header">';
        $output .= '<h3>' . esc_html__('Filter Orders', 'derma-direct') . '</h3>';
        $output .= '<button type="button" class="order-filters-close" id="order-filters-close">&times;</button>';
        $output .= '</div>';
        $output .= '<div class="order-filters-modal-body">';

        // Create filter columns
        $filters = self::getFilterDefinitions();
        $per_column = 3; // Reduce to 3 filters per column for better popup layout

        $output .= '<div class="order-filters-grid">';
        foreach (array_chunk($filters, $per_column, true) as $filter_column) {
            $output .= '<div class="filter_column">';
            foreach ($filter_column as $filter) {
                $output .= self::renderFilter($filter);
            }
            $output .= '</div>';
        }
        $output .= '</div>'; // .order-filters-grid

        $output .= '</div>'; // .order-filters-modal-body

        // Modal footer with buttons
        $output .= '<div class="order-filters-modal-footer">';
        $output .= '<button type="button" class="button" id="clear-all-filters">' . esc_attr__('Clear All', 'derma-direct') . '</button>';
        $output .= '<button type="submit" class="button button-primary">' . esc_attr__('Apply Filters', 'derma-direct') . '</button>';
        $output .= '</div>';

        $output .= '</div>'; // .order-filters-modal
        $output .= '</form>'; // #order-filters-form
        $output .= '</div>'; // .order-filters-modal-overlay

        return $output;
    }

    /**
     * Build applied filters pills
     *
     * @return string
     */
    private static function buildAppliedFiltersPills(): string
    {
        $pills = [];

        // Check for applied filters and create pills
        if (!empty($_GET['post_status']) && is_array($_GET['post_status'])) {
            $statuses = wc_get_order_statuses();
            $selected_statuses = array_map('sanitize_text_field', $_GET['post_status']);
            $status_names = array_intersect_key($statuses, array_flip($selected_statuses));
            if (!empty($status_names)) {
                $pills[] = '<span class="filter-pill">Status: ' . esc_html(implode(', ', $status_names)) . ' <button type="button" class="remove-filter" data-filter="post_status">&times;</button></span>';
            }
        }

        if (!empty($_GET['payment_customer_filter'])) {
            $gateways = WC()->payment_gateways->payment_gateways();
            $selected_gateway = sanitize_text_field($_GET['payment_customer_filter']);
            $gateway_title = isset($gateways[$selected_gateway]) ? $gateways[$selected_gateway]->title : $selected_gateway;
            $pills[] = '<span class="filter-pill">Payment: ' . esc_html($gateway_title) . ' <button type="button" class="remove-filter" data-filter="payment_customer_filter">&times;</button></span>';
        }

        if (!empty($_GET['nonregistered_users_filter'])) {
            $filter_value = sanitize_text_field($_GET['nonregistered_users_filter']);
            $label = $filter_value === 'nonregistered_users' ? 'Non-registered Users' : 'Registered Users';
            $pills[] = '<span class="filter-pill">Customer: ' . esc_html($label) . ' <button type="button" class="remove-filter" data-filter="nonregistered_users_filter">&times;</button></span>';
        }

        if (!empty($_GET['user_email_search'])) {
            $pills[] = '<span class="filter-pill">Email: ' . esc_html($_GET['user_email_search']) . ' <button type="button" class="remove-filter" data-filter="user_email_search">&times;</button></span>';
        }

        if (!empty($_GET['user_billing_first_name'])) {
            $pills[] = '<span class="filter-pill">First Name: ' . esc_html($_GET['user_billing_first_name']) . ' <button type="button" class="remove-filter" data-filter="user_billing_first_name">&times;</button></span>';
        }

        if (!empty($_GET['user_billing_last_name'])) {
            $pills[] = '<span class="filter-pill">Last Name: ' . esc_html($_GET['user_billing_last_name']) . ' <button type="button" class="remove-filter" data-filter="user_billing_last_name">&times;</button></span>';
        }

        if (!empty($_GET['user_phone'])) {
            $pills[] = '<span class="filter-pill">Phone: ' . esc_html($_GET['user_phone']) . ' <button type="button" class="remove-filter" data-filter="user_phone">&times;</button></span>';
        }

        if (!empty($_GET['user_billing_country']) && is_array($_GET['user_billing_country'])) {
            $countries = (new \WC_Countries())->get_countries();
            $selected_countries = array_map('sanitize_text_field', $_GET['user_billing_country']);
            $country_names = array_intersect_key($countries, array_flip($selected_countries));
            if (!empty($country_names)) {
                $pills[] = '<span class="filter-pill">Country: ' . esc_html(implode(', ', $country_names)) . ' <button type="button" class="remove-filter" data-filter="user_billing_country">&times;</button></span>';
            }
        }

        if (!empty($_GET['shipping_method_filter'])) {
            $pills[] = '<span class="filter-pill">Shipping: ' . esc_html($_GET['shipping_method_filter']) . ' <button type="button" class="remove-filter" data-filter="shipping_method_filter">&times;</button></span>';
        }

        if (!empty($_GET['shipping_track_number'])) {
            $pills[] = '<span class="filter-pill">Track #: ' . esc_html($_GET['shipping_track_number']) . ' <button type="button" class="remove-filter" data-filter="shipping_track_number">&times;</button></span>';
        }

        if (!empty($_GET['filter_search_sku'])) {
            $pills[] = '<span class="filter-pill">SKU: ' . esc_html($_GET['filter_search_sku']) . ' <button type="button" class="remove-filter" data-filter="filter_search_sku">&times;</button></span>';
        }

        if (!empty($_GET['filter_start_date']) && !empty($_GET['filter_end_date'])) {
            $pills[] = '<span class="filter-pill">Date: ' . esc_html($_GET['filter_start_date']) . ' to ' . esc_html($_GET['filter_end_date']) . ' <button type="button" class="remove-filter" data-filter="date_range">&times;</button></span>';
        }

        if (!empty($_GET['order_total_start']) || !empty($_GET['order_total_end'])) {
            $start = !empty($_GET['order_total_start']) ? $_GET['order_total_start'] : '0';
            $end = !empty($_GET['order_total_end']) ? $_GET['order_total_end'] : '∞';
            $pills[] = '<span class="filter-pill">Total: ' . esc_html($start) . ' - ' . esc_html($end) . ' <button type="button" class="remove-filter" data-filter="order_total">&times;</button></span>';
        }

        if (!empty($pills)) {
            $output = '<div class="applied-filters-pills">';
            $output .= '<span class="applied-filters-label">' . esc_html__('Applied Filters:', 'derma-direct') . '</span>';
            $output .= implode('', $pills);
            $output .= '<button type="button" class="button clear-all-filters-btn" id="clear-all-filters-btn">' . esc_html__('Clear All', 'derma-direct') . '</button>';
            $output .= '</div>';
            return $output;
        }

        return '';
    }

    /**
     * Get filter definitions
     *
     * @return array
     */
    private static function getFilterDefinitions(): array
    {
        return [
            'order_statuses' => [
                'id' => 'order_statuses',
                'name' => __('Order Status', 'derma-direct'),
                'type' => 'multiselect'
            ],
            'payment_method' => [
                'id' => 'payment_method',
                'name' => __('Payment Method', 'derma-direct'),
                'type' => 'select'
            ],
            'customer_group' => [
                'id' => 'customer_group',
                'name' => __('Customer Group', 'derma-direct'),
                'type' => 'select'
            ],
            'shipping_method' => [
                'id' => 'shipping_method',
                'name' => __('Shipping Method', 'derma-direct'),
                'type' => 'text'
            ],
            'customer_email' => [
                'id' => 'customer_email',
                'name' => __('Customer Email', 'derma-direct'),
                'type' => 'text'
            ],
            'customer_first_name' => [
                'id' => 'customer_first_name',
                'name' => __('First Name', 'derma-direct'),
                'type' => 'text'
            ],
            'customer_last_name' => [
                'id' => 'customer_last_name',
                'name' => __('Last Name', 'derma-direct'),
                'type' => 'text'
            ],
            'customer_phone' => [
                'id' => 'customer_phone',
                'name' => __('Phone Number', 'derma-direct'),
                'type' => 'text'
            ],
            'billing_country' => [
                'id' => 'billing_country',
                'name' => __('Billing Country', 'derma-direct'),
                'type' => 'multiselect'
            ],
            'track_number' => [
                'id' => 'track_number',
                'name' => __('Track Number', 'derma-direct'),
                'type' => 'text'
            ],
            'search_by_sku' => [
                'id' => 'search_by_sku',
                'name' => __('SKU Number', 'derma-direct'),
                'type' => 'text'
            ],
            'orders_by_date_range' => [
                'id' => 'orders_by_date_range',
                'name' => __('Date Range', 'derma-direct'),
                'type' => 'date_range'
            ],
            'filter_order_total' => [
                'id' => 'filter_order_total',
                'name' => __('Order Total', 'derma-direct'),
                'type' => 'number_range'
            ]
        ];
    }

    /**
     * Render individual filter
     *
     * @param array $filter
     * @return string
     */
    private static function renderFilter($filter): string
    {
        $output = '<div class="order_block_wrapper">';

        switch ($filter['id']) {
            case 'order_statuses':
                $output .= self::renderOrderStatusesFilter($filter);
                break;
            case 'payment_method':
                $output .= self::renderPaymentMethodFilter($filter);
                break;
            case 'customer_group':
                $output .= self::renderCustomerGroupFilter($filter);
                break;
            case 'shipping_method':
                $output .= self::renderTextFilter($filter, 'shipping_method_filter');
                break;
            case 'customer_email':
                $output .= self::renderTextFilter($filter, 'user_email_search');
                break;
            case 'customer_first_name':
                $output .= self::renderTextFilter($filter, 'user_billing_first_name');
                break;
            case 'customer_last_name':
                $output .= self::renderTextFilter($filter, 'user_billing_last_name');
                break;
            case 'customer_phone':
                $output .= self::renderTextFilter($filter, 'user_phone');
                break;
            case 'billing_country':
                $output .= self::renderBillingCountryFilter($filter);
                break;
            case 'track_number':
                $output .= self::renderTextFilter($filter, 'shipping_track_number');
                break;
            case 'search_by_sku':
                $output .= self::renderTextFilter($filter, 'filter_search_sku');
                break;
            case 'orders_by_date_range':
                $output .= self::renderDateRangeFilter($filter);
                break;
            case 'filter_order_total':
                $output .= self::renderOrderTotalFilter($filter);
                break;
        }

        $output .= '</div>';
        return $output;
    }

    /**
     * Render order statuses multiselect filter
     */
    private static function renderOrderStatusesFilter($filter): string
    {
        $selected = (array) ($_GET['post_status'] ?? []);
        $statuses = wc_get_order_statuses();

        $output = '<label for="order_statuses">' . esc_html($filter['name']) . '</label>';
        $output .= '<select id="order_statuses" class="order_statuses_select" name="post_status[]" multiple="multiple">';

        foreach ($statuses as $key => $label) {
            $is_selected = in_array($key, $selected) ? ' selected' : '';
            $output .= '<option value="' . esc_attr($key) . '"' . $is_selected . '>' . esc_html($label) . '</option>';
        }

        $output .= '</select>';
        return $output;
    }

    /**
     * Render payment method filter
     */
    private static function renderPaymentMethodFilter($filter): string
    {
        $selected = sanitize_text_field($_GET['payment_customer_filter'] ?? '');
        $gateways = WC()->payment_gateways->payment_gateways();

        $output = '<label for="payment_customer_filter">' . esc_html($filter['name']) . '</label>';
        $output .= '<select name="payment_customer_filter" id="payment_customer_filter">';
        $output .= '<option value=""></option>';

        foreach ($gateways as $gateway) {
            $is_selected = ($selected === $gateway->id) ? ' selected' : '';
            $output .= '<option value="' . esc_attr($gateway->id) . '"' . $is_selected . '>' . esc_html($gateway->title) . '</option>';
        }

        $output .= '</select>';
        return $output;
    }

    /**
     * Render customer group filter
     */
    private static function renderCustomerGroupFilter($filter): string
    {
        $selected = sanitize_text_field($_GET['nonregistered_users_filter'] ?? '');

        $output = '<label for="nonregistered_users_filter">' . esc_html($filter['name']) . '</label>';
        $output .= '<select name="nonregistered_users_filter" id="nonregistered_users_filter">';
        $output .= '<option value=""></option>';
        $output .= '<option value="nonregistered_users"' . (($selected === 'nonregistered_users') ? ' selected' : '') . '>' . esc_html__('Non-registered Users', 'derma-direct') . '</option>';
        $output .= '<option value="registered_users"' . (($selected === 'registered_users') ? ' selected' : '') . '>' . esc_html__('Registered Users', 'derma-direct') . '</option>';
        $output .= '</select>';

        return $output;
    }

    /**
     * Render billing country multiselect filter
     */
    private static function renderBillingCountryFilter($filter): string
    {
        $selected = (array) ($_GET['user_billing_country'] ?? []);
        $countries = (new \WC_Countries())->get_countries();

        $output = '<label for="user_billing_country">' . esc_html($filter['name']) . '</label>';
        $output .= '<select id="user_billing_country" class="order_statuses_select" name="user_billing_country[]" multiple="multiple">';

        foreach ($countries as $code => $name) {
            $is_selected = in_array($code, $selected) ? ' selected' : '';
            $output .= '<option value="' . esc_attr($code) . '"' . $is_selected . '>' . esc_html($name) . '</option>';
        }

        $output .= '</select>';
        return $output;
    }

    /**
     * Render text input filter
     */
    private static function renderTextFilter($filter, $field_name): string
    {
        $value = sanitize_text_field($_GET[$field_name] ?? '');

        $output = '<label for="' . esc_attr($field_name) . '">' . esc_html($filter['name']) . '</label>';
        $output .= '<input type="text" value="' . esc_attr($value) . '" name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '">';

        return $output;
    }

    /**
     * Render date range filter
     */
    private static function renderDateRangeFilter($filter): string
    {
        $from = sanitize_text_field($_GET['filter_start_date'] ?? '');
        $to = sanitize_text_field($_GET['filter_end_date'] ?? '');

        $output = '<label for="filter_start_date">' . esc_html($filter['name']) . '</label>';
        $output .= '<div class="date_range">';
        $output .= '<input type="date" id="filter_start_date" name="filter_start_date" value="' . esc_attr($from) . '" placeholder="' . esc_attr__('Start date', 'derma-direct') . '">';
        $output .= '<input type="date" id="filter_end_date" value="' . esc_attr($to) . '" name="filter_end_date" placeholder="' . esc_attr__('End date', 'derma-direct') . '">';
        $output .= '</div>';

        return $output;
    }

    /**
     * Render order total range filter
     */
    private static function renderOrderTotalFilter($filter): string
    {
        $start = sanitize_text_field($_GET['order_total_start'] ?? '');
        $end = sanitize_text_field($_GET['order_total_end'] ?? '');

        $output = '<label>' . esc_html($filter['name']) . '</label>';
        $output .= '<div class="order_total_range">';
        $output .= '<label for="order_total_start">' . esc_html__('From:', 'derma-direct') . '</label>';
        $output .= '<input type="number" min="0" step="0.01" value="' . esc_attr($start) . '" id="order_total_start" name="order_total_start">';
        $output .= '<label for="order_total_end">' . esc_html__('To:', 'derma-direct') . '</label>';
        $output .= '<input type="number" min="0" step="0.01" value="' . esc_attr($end) . '" id="order_total_end" name="order_total_end">';
        $output .= '</div>';

        return $output;
    }

    /**
     * Modify HPOS query clauses
     *
     * @param array $clauses
     * @param \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableQuery $query
     * @return array
     */
    public static function modifyHPOSQuery($clauses, $query): array
    {
        global $wpdb;

        // Get the orders table name
        $orders_table = $wpdb->prefix . 'wc_orders';
        $orders_meta_table = $wpdb->prefix . 'wc_orders_meta';

        $where_conditions = [];

        // Apply all filters using AND logic
        $where_conditions = array_merge($where_conditions, self::getHPOSWhereConditions($orders_table, $orders_meta_table));

        if (!empty($where_conditions)) {
            $clauses['where'] .= ' AND ' . implode(' AND ', $where_conditions);
        }

        return $clauses;
    }

    /**
     * Get HPOS WHERE conditions
     */
    private static function getHPOSWhereConditions($orders_table, $orders_meta_table): array
    {
        global $wpdb;
        $conditions = [];

        // Order status filter
        if (!empty($_GET['post_status']) && is_array($_GET['post_status'])) {
            $statuses = array_map('sanitize_text_field', $_GET['post_status']);
            // Remove 'wc-' prefix if present and ensure it's added
            $statuses = array_map(function($status) {
                $status = str_replace('wc-', '', $status);
                return 'wc-' . $status;
            }, $statuses);
            $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));
            $conditions[] = $wpdb->prepare("$orders_table.status IN ($status_placeholders)", $statuses);
        }

        // Customer group filter
        if (!empty($_GET['nonregistered_users_filter'])) {
            $filter = sanitize_text_field($_GET['nonregistered_users_filter']);
            if ($filter === 'nonregistered_users') {
                $conditions[] = "$orders_table.customer_id = 0";
            } elseif ($filter === 'registered_users') {
                $conditions[] = "$orders_table.customer_id > 0";
            }
        }

        // Payment method filter
        if (!empty($_GET['payment_customer_filter'])) {
            $payment_method = sanitize_text_field($_GET['payment_customer_filter']);
            $conditions[] = $wpdb->prepare(
                "$orders_table.id IN (SELECT order_id FROM $orders_meta_table WHERE meta_key = '_payment_method' AND meta_value = %s)",
                $payment_method
            );
        }

        // Customer email filter
        if (!empty($_GET['user_email_search'])) {
            $email = sanitize_text_field($_GET['user_email_search']);
            $email = str_replace(self::$filter_search, self::$filter_replace, $email);
            $conditions[] = $wpdb->prepare("$orders_table.billing_email REGEXP %s", $email);
        }

        // First name filter
        if (!empty($_GET['user_billing_first_name'])) {
            $name = sanitize_text_field($_GET['user_billing_first_name']);
            $name = str_replace(self::$filter_search, self::$filter_replace, $name);
            $conditions[] = $wpdb->prepare(
                "$orders_table.id IN (SELECT order_id FROM $orders_meta_table WHERE meta_key = '_billing_first_name' AND meta_value REGEXP %s)",
                $name
            );
        }

        // Last name filter
        if (!empty($_GET['user_billing_last_name'])) {
            $name = sanitize_text_field($_GET['user_billing_last_name']);
            $name = str_replace(self::$filter_search, self::$filter_replace, $name);
            $conditions[] = $wpdb->prepare(
                "$orders_table.id IN (SELECT order_id FROM $orders_meta_table WHERE meta_key = '_billing_last_name' AND meta_value REGEXP %s)",
                $name
            );
        }

        // Phone filter
        if (!empty($_GET['user_phone'])) {
            $phone = sanitize_text_field($_GET['user_phone']);
            $phone = str_replace(self::$filter_search, self::$filter_replace, $phone);
            $conditions[] = $wpdb->prepare(
                "$orders_table.id IN (SELECT order_id FROM $orders_meta_table WHERE (meta_key = '_billing_phone' OR meta_key = '_shipping_phone') AND meta_value REGEXP %s)",
                $phone
            );
        }

        // Billing country filter
        if (!empty($_GET['user_billing_country']) && is_array($_GET['user_billing_country'])) {
            $countries = array_map('sanitize_text_field', $_GET['user_billing_country']);
            $country_placeholders = implode(',', array_fill(0, count($countries), '%s'));
            $conditions[] = $wpdb->prepare(
                "$orders_table.id IN (SELECT order_id FROM $orders_meta_table WHERE meta_key = '_billing_country' AND meta_value IN ($country_placeholders))",
                $countries
            );
        }

        // Shipping method filter
        if (!empty($_GET['shipping_method_filter'])) {
            $shipping = sanitize_text_field($_GET['shipping_method_filter']);
            $shipping = str_replace(self::$filter_search, self::$filter_replace, $shipping);
            $conditions[] = $wpdb->prepare(
                "$orders_table.id IN (SELECT order_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_type = 'shipping' AND order_item_name REGEXP %s)",
                $shipping
            );
        }

        // Track number filter
        if (!empty($_GET['shipping_track_number'])) {
            $track = sanitize_text_field($_GET['shipping_track_number']);
            $track = str_replace(self::$filter_search, self::$filter_replace, $track);

            if (is_plugin_active('woocommerce-shipment-tracking/shipment-tracking.php')) {
                $conditions[] = $wpdb->prepare(
                    "$orders_table.id IN (SELECT order_id FROM $orders_meta_table WHERE meta_key = '_wc_shipment_tracking_items' AND meta_value REGEXP %s)",
                    "tracking_number.*$track"
                );
            }
        }

        // SKU filter
        if (!empty($_GET['filter_search_sku'])) {
            $sku = sanitize_text_field($_GET['filter_search_sku']);
            $sku = str_replace(self::$filter_search, self::$filter_replace, $sku);

            $conditions[] = $wpdb->prepare("
                $orders_table.id IN (
                    SELECT oi.order_id
                    FROM {$wpdb->prefix}woocommerce_order_items oi
                    INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
                    INNER JOIN $wpdb->postmeta pm ON oim.meta_value = pm.post_id
                    WHERE oi.order_item_type = 'line_item'
                    AND oim.meta_key = '_product_id'
                    AND pm.meta_key = '_sku'
                    AND pm.meta_value REGEXP %s
                )
            ", $sku);
        }

        // Order total filter
        if (!empty($_GET['order_total_start']) || !empty($_GET['order_total_end'])) {
            $start = !empty($_GET['order_total_start']) ? floatval($_GET['order_total_start']) : null;
            $end = !empty($_GET['order_total_end']) ? floatval($_GET['order_total_end']) : null;

            if ($start !== null && $end !== null) {
                $conditions[] = $wpdb->prepare(
                    "$orders_table.id IN (SELECT order_id FROM $orders_meta_table WHERE meta_key = '_order_total' AND CAST(meta_value AS DECIMAL(10,2)) BETWEEN %f AND %f)",
                    $start,
                    $end
                );
            } elseif ($start !== null) {
                $conditions[] = $wpdb->prepare(
                    "$orders_table.id IN (SELECT order_id FROM $orders_meta_table WHERE meta_key = '_order_total' AND CAST(meta_value AS DECIMAL(10,2)) >= %f)",
                    $start
                );
            } elseif ($end !== null) {
                $conditions[] = $wpdb->prepare(
                    "$orders_table.id IN (SELECT order_id FROM $orders_meta_table WHERE meta_key = '_order_total' AND CAST(meta_value AS DECIMAL(10,2)) <= %f)",
                    $end
                );
            }
        }

        return $conditions;
    }

    /**
     * Modify posts WHERE clause (legacy orders)
     *
     * @param string $where
     * @return string
     */
    public static function modifyPostsWhere($where): string
    {
        global $typenow, $wpdb;

        if ('shop_order' !== $typenow) {
            return $where;
        }

        // Customer group filter
        if (!empty($_GET['nonregistered_users_filter'])) {
            $filter = sanitize_text_field($_GET['nonregistered_users_filter']);
            if ($filter === 'nonregistered_users') {
                $where .= " AND $wpdb->posts.ID IN (SELECT $wpdb->postmeta.post_id FROM $wpdb->postmeta WHERE meta_key = '_customer_user' AND (meta_value = '0' OR meta_value = ''))";
            } elseif ($filter === 'registered_users') {
                $where .= " AND $wpdb->posts.ID IN (SELECT $wpdb->postmeta.post_id FROM $wpdb->postmeta WHERE meta_key = '_customer_user' AND meta_value > '0')";
            }
        }

        // Order statuses filter
        if (!empty($_GET['post_status']) && is_array($_GET['post_status'])) {
            $statuses = array_map('sanitize_text_field', $_GET['post_status']);
            $status_placeholders = implode(',', array_fill(0, count($statuses), '%s'));
            $where .= $wpdb->prepare(" AND $wpdb->posts.post_status IN ($status_placeholders)", $statuses);
        }

        // Customer email filter
        if (!empty($_GET['user_email_search'])) {
            $email = sanitize_text_field($_GET['user_email_search']);
            $email = str_replace(self::$filter_search, self::$filter_replace, $email);
            $where .= $wpdb->prepare(" AND $wpdb->posts.ID IN (SELECT $wpdb->postmeta.post_id FROM $wpdb->postmeta WHERE meta_key = '_billing_email' AND meta_value REGEXP %s)", $email);
        }

        // First name filter
        if (!empty($_GET['user_billing_first_name'])) {
            $name = sanitize_text_field($_GET['user_billing_first_name']);
            $name = str_replace(self::$filter_search, self::$filter_replace, $name);
            $where .= $wpdb->prepare(" AND $wpdb->posts.ID IN (SELECT $wpdb->postmeta.post_id FROM $wpdb->postmeta WHERE meta_key = '_billing_first_name' AND meta_value REGEXP %s)", $name);
        }

        // Last name filter
        if (!empty($_GET['user_billing_last_name'])) {
            $name = sanitize_text_field($_GET['user_billing_last_name']);
            $name = str_replace(self::$filter_search, self::$filter_replace, $name);
            $where .= $wpdb->prepare(" AND $wpdb->posts.ID IN (SELECT $wpdb->postmeta.post_id FROM $wpdb->postmeta WHERE meta_key = '_billing_last_name' AND meta_value REGEXP %s)", $name);
        }

        // Phone filter
        if (!empty($_GET['user_phone'])) {
            $phone = sanitize_text_field($_GET['user_phone']);
            $phone = str_replace(self::$filter_search, self::$filter_replace, $phone);
            $where .= $wpdb->prepare(" AND $wpdb->posts.ID IN (SELECT $wpdb->postmeta.post_id FROM $wpdb->postmeta WHERE (meta_key = '_billing_phone' OR meta_key = '_shipping_phone') AND meta_value REGEXP %s)", $phone);
        }

        // Billing country filter
        if (!empty($_GET['user_billing_country']) && is_array($_GET['user_billing_country'])) {
            $countries = array_map('sanitize_text_field', $_GET['user_billing_country']);
            $country_placeholders = implode(',', array_fill(0, count($countries), '%s'));
            $where .= $wpdb->prepare(" AND $wpdb->posts.ID IN (SELECT $wpdb->postmeta.post_id FROM $wpdb->postmeta WHERE meta_key = '_billing_country' AND meta_value IN ($country_placeholders))", $countries);
        }

        // Payment method filter
        if (!empty($_GET['payment_customer_filter'])) {
            $payment_method = sanitize_text_field($_GET['payment_customer_filter']);
            $where .= $wpdb->prepare(" AND $wpdb->posts.ID IN (SELECT $wpdb->postmeta.post_id FROM $wpdb->postmeta WHERE meta_key = '_payment_method' AND meta_value = %s)", $payment_method);
        }

        // Shipping method filter
        if (!empty($_GET['shipping_method_filter'])) {
            $shipping = sanitize_text_field($_GET['shipping_method_filter']);
            $shipping = str_replace(self::$filter_search, self::$filter_replace, $shipping);
            $where .= $wpdb->prepare(" AND $wpdb->posts.ID IN (SELECT order_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_type = 'shipping' AND order_item_name REGEXP %s)", $shipping);
        }

        // Track number filter
        if (!empty($_GET['shipping_track_number'])) {
            $track = sanitize_text_field($_GET['shipping_track_number']);
            $track = str_replace(self::$filter_search, self::$filter_replace, $track);

            // Support multiple tracking plugins
            if (is_plugin_active('woocommerce-shipment-tracking/shipment-tracking.php')) {
                $where .= $wpdb->prepare(" AND $wpdb->posts.ID IN (SELECT $wpdb->postmeta.post_id FROM $wpdb->postmeta WHERE meta_key = '_wc_shipment_tracking_items' AND meta_value REGEXP %s)", "tracking_number.*$track");
            }
        }

        // SKU filter
        if (!empty($_GET['filter_search_sku'])) {
            $sku = sanitize_text_field($_GET['filter_search_sku']);
            $sku = str_replace(self::$filter_search, self::$filter_replace, $sku);

            $where .= $wpdb->prepare("
                AND $wpdb->posts.ID IN (
                    SELECT $wpdb->posts.ID
                    FROM $wpdb->posts
                    INNER JOIN {$wpdb->prefix}woocommerce_order_items ON $wpdb->posts.ID = {$wpdb->prefix}woocommerce_order_items.order_id
                    INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta ON {$wpdb->prefix}woocommerce_order_items.order_item_id = {$wpdb->prefix}woocommerce_order_itemmeta.order_item_id
                    INNER JOIN $wpdb->postmeta ON {$wpdb->prefix}woocommerce_order_itemmeta.meta_value = $wpdb->postmeta.post_id
                    WHERE $wpdb->posts.post_type = 'shop_order'
                    AND {$wpdb->prefix}woocommerce_order_items.order_item_type = 'line_item'
                    AND {$wpdb->prefix}woocommerce_order_itemmeta.meta_key = '_product_id'
                    AND $wpdb->postmeta.meta_key = '_sku'
                    AND $wpdb->postmeta.meta_value REGEXP %s
                )
            ", $sku);
        }

        // Order total filter
        if (!empty($_GET['order_total_start']) || !empty($_GET['order_total_end'])) {
            $start = !empty($_GET['order_total_start']) ? floatval($_GET['order_total_start']) : null;
            $end = !empty($_GET['order_total_end']) ? floatval($_GET['order_total_end']) : null;

            if ($start !== null || $end !== null) {
                $total_where = " AND $wpdb->posts.ID IN (SELECT $wpdb->postmeta.post_id FROM $wpdb->postmeta WHERE meta_key = '_order_total'";

                if ($start !== null) {
                    $total_where .= $wpdb->prepare(" AND meta_value >= %f", $start);
                }

                if ($end !== null) {
                    $total_where .= $wpdb->prepare(" AND meta_value <= %f", $end);
                }

                $total_where .= ")";
                $where .= $total_where;
            }
        }

        return $where;
    }

    /**
     * Filter date range for legacy orders
     *
     * @param \WP_Query $wp_query
     * @return \WP_Query
     */
    public static function filterDateRange($wp_query): \WP_Query
    {
        if (
            is_admin() &&
            $wp_query->is_main_query() &&
            isset($_GET['post_type']) &&
            $_GET['post_type'] === 'shop_order' &&
            !empty($_GET['filter_start_date']) &&
            !empty($_GET['filter_end_date'])
        ) {
            $start_date = sanitize_text_field($_GET['filter_start_date']);
            $end_date = sanitize_text_field($_GET['filter_end_date']);

            if (self::isValidDate($start_date) && self::isValidDate($end_date)) {
                $wp_query->set('date_query', [
                    'after' => $start_date,
                    'before' => $end_date,
                    'inclusive' => true,
                    'column' => 'post_date',
                ]);
            }
        }

        return $wp_query;
    }

    /**
     * Validate date format
     *
     * @param string $date
     * @return bool
     */
    private static function isValidDate($date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}
