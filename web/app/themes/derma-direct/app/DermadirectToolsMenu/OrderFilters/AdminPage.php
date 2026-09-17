<?php
namespace App\DermadirectToolsMenu\OrderFilters;

use WC_Order_Query;

defined('ABSPATH') || exit;

class AdminPage
{
    /**
     * Initialize the Order Filters functionality
     */
    public static function init(): void
    {
        // Initialize the main order filters manager
        OrderFiltersManager::init();

        // Add admin menu
        add_action('admin_menu', [self::class, 'addAdminMenu']);
    }

    /**
     * Add admin submenu under Dermadirect Tools
     */
    public static function addAdminMenu(): void
    {
        add_submenu_page(
            'dermadirect-tools',
            'Order Filters',
            'Order Filters',
            'manage_options',
            'order-filters',
            [self::class, 'renderAdminPage']
        );
    }

    /**
     * Render the admin page
     */
    public static function renderAdminPage(): void
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Order Filters', 'derma-direct'); ?></h1>

            <div class="notice notice-success">
                <p>
                    <strong><?php esc_html_e('Order Filters are now active!', 'derma-direct'); ?></strong>
                </p>
                <p>
                    <?php esc_html_e('Additional filtering options have been added to the WooCommerce Orders page.', 'derma-direct'); ?>
                    <?php esc_html_e('You can filter orders by status, payment method, customer group, shipping method, customer details, billing country, track number, SKU, date range, and order total.', 'derma-direct'); ?>
                </p>
            </div>

            <div class="card">
                <h2><?php esc_html_e('Available Filters', 'derma-direct'); ?></h2>
                <ul>
                    <li><?php esc_html_e('Order Statuses - Filter by one or multiple order statuses', 'derma-direct'); ?></li>
                    <li><?php esc_html_e('Payment Method - Filter by payment gateway used', 'derma-direct'); ?></li>
                    <li><?php esc_html_e('Customer Group - Filter by registered vs non-registered customers', 'derma-direct'); ?></li>
                    <li><?php esc_html_e('Shipping Method - Filter by shipping method name', 'derma-direct'); ?></li>
                    <li><?php esc_html_e('Customer Details - Filter by email, first name, last name, or phone', 'derma-direct'); ?></li>
                    <li><?php esc_html_e('Billing Country - Filter by customer\'s billing country', 'derma-direct'); ?></li>
                    <li><?php esc_html_e('Track Number - Filter by shipment tracking number', 'derma-direct'); ?></li>
                    <li><?php esc_html_e('SKU Number - Filter by product SKU in order items', 'derma-direct'); ?></li>
                    <li><?php esc_html_e('Date Range - Filter by order date within specific range', 'derma-direct'); ?></li>
                    <li><?php esc_html_e('Order Total - Filter by order total amount range', 'derma-direct'); ?></li>
                </ul>
            </div>

            <div class="card">
                <h2><?php esc_html_e('How to Use', 'derma-direct'); ?></h2>
                <p>
                    <?php esc_html_e('Go to the WooCommerce Orders page and click the "Additional Filters" button to reveal all available filter options.', 'derma-direct'); ?>
                    <?php esc_html_e('All filters use AND logic, so results will match all selected criteria.', 'derma-direct'); ?>
                </p>
                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-orders')); ?>" class="button button-primary button-large">
                        <?php esc_html_e('Go to Orders Page', 'derma-direct'); ?>
                    </a>
                </p>
            </div>
        </div>
        <?php
    }
}
