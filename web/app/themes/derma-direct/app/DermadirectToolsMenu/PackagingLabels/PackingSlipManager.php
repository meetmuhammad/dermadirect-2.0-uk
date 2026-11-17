<?php
namespace App\DermadirectToolsMenu\PackagingLabels;

use Dompdf\Dompdf;
use Picqer\Barcode\BarcodeGeneratorPNG;

defined('ABSPATH') || exit;

/**
 * Packing Slip Manager
 *
 * Handles PDF generation and automatic printing for packing slips
 */
class PackingSlipManager
{
    /**
     * Initialize hooks
     */
    public static function init(): void
    {
        // Add metabox to order edit page
        add_action('add_meta_boxes', [self::class, 'addMetabox']);

        // Auto-print on order status change
        add_action('woocommerce_order_status_shipped', [self::class, 'autoPrintOnShipped']);

        // Stream packing slip PDF to authorised admins
        add_action('admin_post_dd_packing_slip', [self::class, 'streamPdf']);
    }

    /**
     * Add packing slip metabox to order page
     */
    public static function addMetabox(): void
    {
        add_meta_box(
            'packing_slip',
            __('Packing Slip', 'derma-direct'),
            [self::class, 'renderMetabox'],
            'shop_order',
            'side',
            'high'
        );

        // HPOS compatibility
        add_meta_box(
            'packing_slip',
            __('Packing Slip', 'derma-direct'),
            [self::class, 'renderMetabox'],
            'woocommerce_page_wc-orders',
            'side',
            'high'
        );
    }

    /**
     * Render metabox content
     *
     * @param \WP_Post|\WC_Order $post_or_order Order object or post
     */
    public static function renderMetabox($post_or_order): void
    {
        // Get order ID
        $order_id = $post_or_order instanceof \WC_Order
            ? $post_or_order->get_id()
            : $post_or_order->ID;

        $order = $order_id ? wc_get_order($order_id) : false;
        $is_auto_draft_post = $post_or_order instanceof \WP_Post && $post_or_order->post_status === 'auto-draft';

        if (!$order || $is_auto_draft_post || self::isDraft($order)) {
            echo '<p>' . esc_html__('Save the order first to view the packing slip.', 'derma-direct') . '</p>';
            return;
        }

        $pdf_url = wp_nonce_url(
            add_query_arg(['action' => 'dd_packing_slip', 'order_id' => $order_id], admin_url('admin-post.php')),
            'dd_packing_slip_' . $order_id
        );
        ?>
        <p>
            <a href="<?php echo esc_url($pdf_url); ?>" class="button button-primary" target="_blank">
                <span class="dashicons dashicons-pdf" style="vertical-align: middle;"></span>
                <?php echo esc_html__('View Packing Slip', 'derma-direct'); ?>
            </a>
        </p>
        <p class="description">
            <?php echo esc_html__('The packing slip is generated fresh from the saved order each time it is opened.', 'derma-direct'); ?>
        </p>
        <?php
    }

    /**
     * Stream packing slip PDF for an order (admin-post handler)
     */
    public static function streamPdf(): void
    {
        if (!current_user_can('edit_shop_orders') && !current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to view packing slips.', 'derma-direct'), '', ['response' => 403]);
        }

        $order_id = absint($_GET['order_id'] ?? 0);

        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'dd_packing_slip_' . $order_id)) {
            wp_die(esc_html__('Invalid or expired link.', 'derma-direct'), '', ['response' => 403]);
        }

        $order = $order_id ? wc_get_order($order_id) : false;

        if (!$order) {
            wp_die(esc_html__('Order not found.', 'derma-direct'), '', ['response' => 404]);
        }

        if (self::isDraft($order)) {
            wp_die(esc_html__('Save the order first to view the packing slip.', 'derma-direct'), '', ['response' => 400]);
        }

        $bytes = self::renderPdf($order);

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="packing-slip-' . $order_id . '.pdf"');
        header('X-Robots-Tag: noindex, nofollow');
        echo $bytes;
        exit;
    }

    /**
     * Whether the order is an unsaved or draft order
     *
     * @param \WC_Order $order Order object
     */
    private static function isDraft($order): bool
    {
        return in_array($order->get_status(), ['auto-draft', 'draft', 'checkout-draft'], true);
    }

    /**
     * Render packing slip PDF bytes
     *
     * @param \WC_Order $order Order object
     */
    private static function renderPdf(\WC_Order $order): string
    {
        // Initialize Dompdf
        $pdf = new Dompdf(['enable_remote' => true]);

        // Generate HTML
        ob_start();
        self::renderPdfHtml($order);
        $html = ob_get_clean();

        // Create PDF
        $pdf->loadHtml($html);
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        return (string) $pdf->output();
    }

    /**
     * Create PDF packing slip
     *
     * @param int    $order_id Order ID
     * @param string $pdf_file Path to save PDF file
     */
    public static function createPdf($order_id, $pdf_file): void
    {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        file_put_contents($pdf_file, self::renderPdf($order));
    }

    /**
     * Render PDF HTML content
     *
     * @param \WC_Order $order Order object
     */
    private static function renderPdfHtml($order): void
    {
        ?>
        <html>
        <head>
            <meta charset="UTF-8">
            <style type="text/css">
                body {
                    font-family: 'Helvetica', Arial, sans-serif;
                    font-size: 12px;
                    color: #333;
                }
                h3 {
                    margin: 0 0 10px;
                    font-size: 20px;
                }
                .order-info {
                    margin: 10px 0 20px;
                }
                .address-table {
                    width: 100%;
                    margin: 20px 0;
                    border-spacing: 0;
                }
                .address-table td {
                    padding: 10px;
                    vertical-align: top;
                    width: 33.33%;
                }
                .address-table strong {
                    display: block;
                    margin-bottom: 5px;
                    font-size: 13px;
                }
                .product-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 20px 0;
                    text-align: center;
                }
                .product-table th {
                    background: #f5f5f5;
                    padding: 10px;
                    border: 1px solid #ddd;
                    font-weight: bold;
                }
                .product-table td {
                    border: 1px solid #ddd;
                    padding: 10px;
                }
                .barcode-container {
                    margin: 20px 0;
                    text-align: center;
                }
            </style>
        </head>
        <body>
            <h3>DERMA DIRECT</h3>

            <div class="order-info">
                <strong>Order No: <?php echo esc_html($order->get_order_number()); ?></strong><br>
                Date: <?php echo esc_html($order->get_date_created() ? $order->get_date_created()->date_i18n('d/m/Y') : ''); ?>
            </div>

            <table class="address-table">
                <tr>
                    <td>
                        <strong>Billing Address</strong>
                        <?php echo esc_html(trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name())); ?><br>
                        <?php echo esc_html($order->get_billing_address_1()); ?><br>
                        <?php if ($order->get_billing_address_2()) : ?>
                            <?php echo esc_html($order->get_billing_address_2()); ?><br>
                        <?php endif; ?>
                        <?php echo esc_html($order->get_billing_city() . ', ' . $order->get_billing_state() . ' ' . $order->get_billing_postcode()); ?><br>
                        <?php echo esc_html(WC()->countries->countries[$order->get_billing_country()] ?? ''); ?>
                    </td>
                    <td>
                        <strong>Shipping Address</strong>
                        <?php echo esc_html(trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name())); ?><br>
                        <?php echo esc_html($order->get_shipping_address_1()); ?><br>
                        <?php if ($order->get_shipping_address_2()) : ?>
                            <?php echo esc_html($order->get_shipping_address_2()); ?><br>
                        <?php endif; ?>
                        <?php echo esc_html($order->get_shipping_city() . ', ' . $order->get_shipping_state() . ' ' . $order->get_shipping_postcode()); ?><br>
                        <?php echo esc_html(WC()->countries->countries[$order->get_shipping_country()] ?? ''); ?>
                    </td>
                    <td>
                        <strong>Shipping Method</strong>
                        <?php echo esc_html($order->get_shipping_method()); ?>
                    </td>
                </tr>
            </table>

            <table class="product-table">
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Product</th>
                        <th>Quantity</th>
                        <th>Total Price</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($order->get_items() as $item) : ?>
                        <?php
                        $product_id = $item->get_product_id();
                        $image = wp_get_attachment_image_src(get_post_thumbnail_id($product_id), 'thumbnail');
                        ?>
                        <tr>
                            <td>
                                <?php if ($image) : ?>
                                    <img src="<?php echo esc_url($image[0]); ?>" style="max-width: 50px; height: auto;">
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($item->get_name()); ?></td>
                            <td><?php echo esc_html($item->get_quantity()); ?></td>
                            <td><?php echo wp_kses_post(wc_price($item->get_total() + $item->get_subtotal_tax())); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="barcode-container">
                <?php
                $barcode = new BarcodeGeneratorPNG();
                $barcode_image = $barcode->getBarcode($order->get_order_number(), $barcode::TYPE_CODE_128);
                ?>
                <img src="data:image/png;base64,<?php echo base64_encode($barcode_image); ?>" style="width: 250px; height: 30px;">
            </div>
        </body>
        </html>
        <?php
    }

    /**
     * Auto-print packing slip when order is shipped
     *
     * @param int $order_id Order ID
     */
    public static function autoPrintOnShipped($order_id): void
    {
        // Check if auto-print is enabled
        if (get_option('packaging_labels_auto_print', '0') !== '1') {
            return;
        }

        // Get API key and printer ID
        $api_key = get_option('packaging_labels_printnode_api_key', '');
        $printer_id = get_option('packaging_labels_printer_id', '');

        if (empty($api_key) || empty($printer_id)) {
            return;
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        // Render a fresh PDF from the current order data
        $bytes = self::renderPdf($order);

        // Send to printer via PrintNode API
        $response = wp_remote_post('https://api.printnode.com/printjobs', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($api_key . ':'),
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'printerId'   => (int) $printer_id,
                'contentType' => 'pdf_base64',
                'content'     => base64_encode($bytes),
                'title'       => 'Packing Slip - Order #' . $order_id,
            ]),
        ]);

        // Log errors if any
        if (is_wp_error($response)) {
            error_log('PrintNode API Error: ' . $response->get_error_message());
            return;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            error_log('PrintNode API Error: HTTP ' . $code . ' for order #' . $order_id . ' - ' . substr((string) wp_remote_retrieve_body($response), 0, 200));
        }
    }
}
