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

        if (!$order_id) {
            echo '<p>' . esc_html__('Create order first', 'derma-direct') . '</p>';
            return;
        }

        // Ensure directory exists
        $upload_dir = wp_upload_dir();
        $directory = $upload_dir['basedir'] . '/packing-slips';

        if (!is_dir($directory)) {
            wp_mkdir_p($directory);
        }

        // Generate PDF if it doesn't exist
        $pdf_file = $directory . '/' . $order_id . '.pdf';
        if (!file_exists($pdf_file)) {
            self::createPdf($order_id, $pdf_file);
        }

        // Display view button
        $pdf_url = $upload_dir['baseurl'] . '/packing-slips/' . $order_id . '.pdf';
        ?>
        <p>
            <a href="<?php echo esc_url($pdf_url); ?>" class="button button-primary" target="_blank">
                <span class="dashicons dashicons-pdf" style="vertical-align: middle;"></span>
                <?php echo esc_html__('View Packing Slip', 'derma-direct'); ?>
            </a>
        </p>
        <p>
            <a href="<?php echo esc_url(add_query_arg('regenerate_packing_slip', $order_id)); ?>" class="button">
                <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
                <?php echo esc_html__('Regenerate', 'derma-direct'); ?>
            </a>
        </p>
        <p class="description">
            <?php echo esc_html__('Use Regenerate to create a fresh PDF after modifying order details, updating the template design, or fixing any PDF issues.', 'derma-direct'); ?>
        </p>
        <?php

        // Handle regeneration
        if (isset($_GET['regenerate_packing_slip']) && $_GET['regenerate_packing_slip'] == $order_id) {
            self::createPdf($order_id, $pdf_file);
            echo '<div class="notice notice-success inline"><p>' . esc_html__('Packing slip regenerated.', 'derma-direct') . '</p></div>';
        }
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

        // Save PDF
        file_put_contents($pdf_file, $pdf->output());
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
                Date: <?php echo esc_html(date('d/m/Y', strtotime($order->get_date_created()))); ?>
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

        // Ensure PDF exists
        $upload_dir = wp_upload_dir();
        $directory = $upload_dir['basedir'] . '/packing-slips';

        if (!is_dir($directory)) {
            wp_mkdir_p($directory);
        }

        $pdf_file = $directory . '/' . $order_id . '.pdf';
        if (!file_exists($pdf_file)) {
            self::createPdf($order_id, $pdf_file);
        }

        // Send to printer via PrintNode API
        $pdf_url = $upload_dir['baseurl'] . '/packing-slips/' . $order_id . '.pdf';

        $response = wp_remote_post('https://api.printnode.com/printjobs', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($api_key . ':'),
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'printerId'   => (int) $printer_id,
                'contentType' => 'pdf_uri',
                'content'     => $pdf_url,
                'title'       => 'Packing Slip - Order #' . $order_id,
            ]),
        ]);

        // Log errors if any
        if (is_wp_error($response)) {
            error_log('PrintNode API Error: ' . $response->get_error_message());
        }
    }
}
