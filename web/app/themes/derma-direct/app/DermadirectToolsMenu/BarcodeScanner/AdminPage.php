<?php
// Ensure the Dermadirect Tools menu is registered before any submenu
namespace App\DermadirectToolsMenu\BarcodeScanner;

class AdminPage
{
    /**
     * Bootstraps the Barcode Scanner tool
     */
    public static function init(): void
    {
        // Initialize all hooks and shortcodes for the barcode scanner
        BarcodeScannerManager::init();

        // Admin UI
        add_action('admin_menu', [self::class, 'addAdminMenu']);
        add_action('admin_post_setup_barcode_scanner', [self::class, 'handleSetup']);

        // Run once on theme activation
        add_action('after_switch_theme', [self::class, 'onThemeActivation']);
    }

    /**
     * Runs once when theme is activated
     */
    public static function onThemeActivation(): void
    {
        BarcodeScannerManager::createDatabaseTable();
    }

    /**
     * Add admin menu
     */
    public static function addAdminMenu(): void
    {
        // Add Barcode Scanner as a submenu under Dermadirect Tools
        add_submenu_page(
            'dermadirect-tools',
            'Barcode Scanner Setup',
            'Barcode Scanner',
            'manage_options',
            'barcode-scanner-setup',
            [self::class, 'renderAdminPage']
        );
    }

    /**
     * Render admin page
     */
    public static function renderAdminPage(): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'barcode_scanner_images';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

        ?>
        <div class="wrap">
            <h1>Barcode Scanner Setup</h1>

            <?php if (isset($_GET['setup']) && $_GET['setup'] === 'success'): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Database table created successfully!</p>
                </div>
            <?php endif; ?>

            <div class="card" style="max-width: 800px;">
                <h2>Database Status</h2>
                <p>
                    <strong>Table Name:</strong> <code><?php echo esc_html($table_name); ?></code><br>
                    <strong>Status:</strong>
                    <?php if ($table_exists): ?>
                        <span style="color: green;">✓ Table exists</span>
                    <?php else: ?>
                        <span style="color: red;">✗ Table does not exist</span>
                    <?php endif; ?>
                </p>

                <?php if (!$table_exists): ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="setup_barcode_scanner">
                        <?php wp_nonce_field('setup_barcode_scanner'); ?>
                        <button type="submit" class="button button-primary">Create Database Table</button>
                    </form>
                <?php else: ?>
                    <p style="color: green;">The barcode scanner is ready to use!</p>
                <?php endif; ?>
            </div>

            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>AWS S3 Configuration</h2>
                <table class="form-table">
                    <tr>
                        <th>AWS Key:</th>
                        <td>
                            <?php if (env('AWS_S3_KEY')): ?>
                                <span style="color: green;">✓ Configured</span>
                            <?php else: ?>
                                <span style="color: red;">✗ Not configured</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>AWS Secret:</th>
                        <td>
                            <?php if (env('AWS_S3_SECRET')): ?>
                                <span style="color: green;">✓ Configured</span>
                            <?php else: ?>
                                <span style="color: red;">✗ Not configured</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>AWS Region:</th>
                        <td><code><?php echo esc_html(env('AWS_S3_REGION', 'Not set')); ?></code></td>
                    </tr>
                    <tr>
                        <th>S3 Bucket:</th>
                        <td><code><?php echo esc_html(env('AWS_S3_BUCKET', 'Not set')); ?></code></td>
                    </tr>
                </table>
                    <li>Point camera at a CODE_128 barcode (WooCommerce order ID)</li>
                    <li>Upload photos of the order</li>
                    <li>Photos are compressed and uploaded to S3</li>
                    <li>Order is automatically marked as completed</li>
                    <li>Photos appear in the order admin sidebar</li>
                    <li>Photos are automatically deleted after 120 days</li>
                </ol>
            </div>
        </div>
        <?php
    }

    /**
     * Handle setup form submission
     */
    public static function handleSetup(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('setup_barcode_scanner');

        BarcodeScannerManager::createDatabaseTable();

        wp_redirect(add_query_arg([
            'page' => 'barcode-scanner-setup',
            'setup' => 'success'
        ], admin_url('tools.php')));
        exit;
    }
}
