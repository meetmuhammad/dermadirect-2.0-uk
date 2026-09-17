<?php
namespace App\DermadirectToolsMenu\PackagingLabels;

defined('ABSPATH') || exit;

/**
 * Packaging Labels Admin Page
 *
 * Manages settings for automatic packing slip generation and printing
 */
class AdminPage
{
    /**
     * Initialize admin page and hooks
     */
    public static function init(): void
    {
        // Initialize the packing slip manager
        PackingSlipManager::init();

        // Add admin menu
        add_action('admin_menu', [self::class, 'addAdminMenu']);

        // Register settings
        add_action('admin_init', [self::class, 'registerSettings']);
    }

    /**
     * Add admin menu item
     */
    public static function addAdminMenu(): void
    {
        add_submenu_page(
            'dermadirect-tools',
            __('Packaging Labels', 'derma-direct'),
            __('Packaging Labels', 'derma-direct'),
            'manage_woocommerce',
            'packaging-labels',
            [self::class, 'renderAdminPage']
        );
    }

    /**
     * Register settings
     */
    public static function registerSettings(): void
    {
        register_setting('packaging_labels_settings', 'packaging_labels_printnode_api_key');
        register_setting('packaging_labels_settings', 'packaging_labels_printer_id');
        register_setting('packaging_labels_settings', 'packaging_labels_auto_print');
    }

    /**
     * Render admin settings page
     */
    public static function renderAdminPage(): void
    {
        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // Handle form submission
        if (isset($_POST['packaging_labels_submit'])) {
            check_admin_referer('packaging_labels_settings_action', 'packaging_labels_settings_nonce');

            update_option('packaging_labels_printnode_api_key', sanitize_text_field($_POST['printnode_api_key'] ?? ''));
            update_option('packaging_labels_printer_id', sanitize_text_field($_POST['printer_id'] ?? ''));
            update_option('packaging_labels_auto_print', isset($_POST['auto_print']) ? '1' : '0');

            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved successfully.', 'derma-direct') . '</p></div>';
        }

        // Get current settings
        $api_key = get_option('packaging_labels_printnode_api_key', '');
        $printer_id = get_option('packaging_labels_printer_id', '');
        $auto_print = get_option('packaging_labels_auto_print', '0');

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Packaging Labels Settings', 'derma-direct'); ?></h1>

            <form method="post" action="">
                <?php wp_nonce_field('packaging_labels_settings_action', 'packaging_labels_settings_nonce'); ?>

                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="printnode_api_key">
                                    <?php echo esc_html__('PrintNode API Key', 'derma-direct'); ?>
                                </label>
                            </th>
                            <td>
                                <input
                                    type="password"
                                    name="printnode_api_key"
                                    id="printnode_api_key"
                                    value="<?php echo esc_attr($api_key); ?>"
                                    class="regular-text"
                                    autocomplete="off"
                                />
                                <p class="description">
                                    <?php echo esc_html__('Enter your PrintNode API key for automatic printing.', 'derma-direct'); ?>
                                    <a href="https://app.printnode.com/app/apikeys" target="_blank">
                                        <?php echo esc_html__('Get API Key', 'derma-direct'); ?>
                                    </a>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="printer_id">
                                    <?php echo esc_html__('Printer ID', 'derma-direct'); ?>
                                </label>
                            </th>
                            <td>
                                <input
                                    type="text"
                                    name="printer_id"
                                    id="printer_id"
                                    value="<?php echo esc_attr($printer_id); ?>"
                                    class="regular-text"
                                />
                                <p class="description">
                                    <?php echo esc_html__('Enter your PrintNode printer ID.', 'derma-direct'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <?php echo esc_html__('Auto Print', 'derma-direct'); ?>
                            </th>
                            <td>
                                <label>
                                    <input
                                        type="checkbox"
                                        name="auto_print"
                                        id="auto_print"
                                        value="1"
                                        <?php checked($auto_print, '1'); ?>
                                    />
                                    <?php echo esc_html__('Automatically print packing slips when order status changes to "Shipped"', 'derma-direct'); ?>
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button(__('Save Settings', 'derma-direct'), 'primary', 'packaging_labels_submit'); ?>
            </form>

            <hr>

            <h2><?php echo esc_html__('Information', 'derma-direct'); ?></h2>
            <p><?php echo esc_html__('Packing slips are automatically generated when viewing orders and stored in:', 'derma-direct'); ?></p>
            <code><?php echo esc_html(wp_upload_dir()['basedir'] . '/packing-slips/'); ?></code>
        </div>
        <?php
    }
}
