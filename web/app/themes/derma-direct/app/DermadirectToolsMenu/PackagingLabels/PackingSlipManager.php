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
     * Production warehouse PrintNode printers. Never printed to from a non-production site.
     * These are printer ids, not credentials.
     */
    public const PRODUCTION_PRINTER_IDS = ['74857961', '71895499', '73994577'];

    /**
     * Order meta recording a successful auto-print (time and printer id)
     */
    public const PRINTED_META = '_dd_packing_slip_printed';

    /**
     * Order meta set on review checkout orders by the review sandbox mu-plugin
     */
    public const REVIEW_ORDER_META = '_dd_review_order';

    /**
     * Initialize hooks
     */
    public static function init(): void
    {
        // Add metabox to order edit page
        add_action('add_meta_boxes', [self::class, 'addMetabox']);

        // Auto-print when the order moves to the store's "Ship" status (slug: ship)
        add_action('woocommerce_order_status_ship', [self::class, 'autoPrintOnShip']);

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
        $html = (string) ob_get_clean();

        if (self::testMarkingEnabled()) {
            $html = self::markTestHtml($html);
        }

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
     * Auto-print packing slip when the order moves to "Ship"
     *
     * Idempotent: a successful print is recorded on the order and never repeated, so setting
     * Ship twice does not print twice.
     *
     * @param int $order_id Order ID
     */
    public static function autoPrintOnShip($order_id): void
    {
        // Check if auto-print is enabled
        if (get_option('packaging_labels_auto_print', '0') !== '1') {
            return;
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        $printed = $order->get_meta(self::PRINTED_META);

        if (self::isPrinted($printed)) {
            $order->add_order_note(sprintf(
                'Packing slip not auto-printed: already printed%s.',
                is_array($printed) && !empty($printed['time']) ? ' on ' . $printed['time'] : ''
            ));
            return;
        }

        // Get API key and printer ID
        $api_key = (string) get_option('packaging_labels_printnode_api_key', '');
        $printer_id = self::normalisePrinterId(get_option('packaging_labels_printer_id', ''));

        if ($api_key === '' || $printer_id === '') {
            $order->add_order_note('Packing slip not auto-printed: PrintNode API key or printer ID is not configured.');
            return;
        }

        $reason = self::autoPrintBlockReason(
            self::currentEnv(),
            $printer_id,
            $order->get_meta(self::REVIEW_ORDER_META),
            self::flagEnabled('DD_PACKING_SLIP_PRINT_ALL_ORDERS'),
            self::allowProductionPrinters()
        );

        if ($reason !== null) {
            $order->add_order_note('Packing slip not auto-printed: ' . $reason);
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
                'title'       => self::printJobTitle($order_id, self::testMarkingEnabled()),
            ]),
        ]);

        // Log errors if any
        if (is_wp_error($response)) {
            error_log('PrintNode API Error for order #' . $order_id . ': ' . $response->get_error_message());
            $order->add_order_note('Packing slip auto-print failed: ' . $response->get_error_message());
            return;
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if ($code < 200 || $code >= 300) {
            error_log('PrintNode API Error: HTTP ' . $code . ' for order #' . $order_id . ' - ' . substr((string) wp_remote_retrieve_body($response), 0, 200));
            $order->add_order_note(sprintf('Packing slip auto-print failed: PrintNode returned HTTP %d. Not marked as printed.', $code));
            return;
        }

        // Only a 2xx response marks the order printed. Saved through the order API so HPOS works.
        $job_id = trim((string) wp_remote_retrieve_body($response));
        $order->update_meta_data(self::PRINTED_META, [
            'time'       => gmdate('c'),
            'printer_id' => $printer_id,
            'job_id'     => ctype_digit($job_id) ? $job_id : '',
        ]);
        $order->save_meta_data();

        $order->add_order_note(sprintf(
            'Packing slip sent to PrintNode printer %s%s.',
            $printer_id,
            ctype_digit($job_id) ? ' (print job ' . $job_id . ')' : ''
        ));
    }

    /**
     * Fallback TEST banner text, used only when the test print marker mu-plugin is missing
     */
    public const TEST_BANNER_TEXT = 'TEST ORDER - DERMADIRECT 2.0 - DO NOT PICK OR SHIP';

    /**
     * PrintNode job title prefix required while TEST marking is on
     */
    public const TEST_PRINTJOB_TITLE_PREFIX = 'TEST DD2.0 - ';

    /**
     * Whether printed packing slips carry the TEST marking. Decided by the test print marker
     * mu-plugin; if that is missing, every non-production site is marked (fail closed).
     */
    public static function testMarkingEnabled(): bool
    {
        if (function_exists('DermaDirect\\TestPrintMarker\\enabled')) {
            return \DermaDirect\TestPrintMarker\enabled();
        }

        return self::guardsApply(self::currentEnv());
    }

    /**
     * Packing slip HTML with the TEST banner and watermark from the mu-plugin, or a plain banner
     * when the mu-plugin is missing
     */
    public static function markTestHtml(string $html): string
    {
        if (function_exists('DermaDirect\\TestPrintMarker\\mark_html')) {
            return \DermaDirect\TestPrintMarker\mark_html($html);
        }

        return self::fallbackMarkHtml($html);
    }

    /**
     * Plain TEST banner inserted after the opening body tag (or prepended), once
     */
    public static function fallbackMarkHtml(string $html): string
    {
        if (strpos($html, 'dd-test-print-fallback') !== false) {
            return $html;
        }

        $banner = '<div id="dd-test-print-fallback" style="background:#000000;color:#ffffff;border:4px solid #d00000;'
            . 'padding:8px;margin:0 0 8px 0;text-align:center;font-size:20px;font-weight:bold;">'
            . self::TEST_BANNER_TEXT . '<br>' . self::TEST_BANNER_TEXT . '</div>';

        if (preg_match('/<body\b[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE)) {
            $at = $match[0][1] + strlen($match[0][0]);

            return substr($html, 0, $at) . $banner . substr($html, $at);
        }

        return $banner . $html;
    }

    /**
     * PrintNode job title for an order's packing slip. Carries the TEST prefix when marked.
     *
     * @param int|string $order_id
     */
    public static function printJobTitle($order_id, bool $marked): string
    {
        $title = 'Packing Slip - Order #' . $order_id;

        return $marked ? self::TEST_PRINTJOB_TITLE_PREFIX . $title : $title;
    }

    /**
     * Current WP_ENV, or null when it is not defined
     */
    public static function currentEnv(): ?string
    {
        return defined('WP_ENV') ? (string) WP_ENV : null;
    }

    /**
     * Whether an opt-in constant is defined and exactly true
     */
    public static function flagEnabled(string $name): bool
    {
        return defined($name) && constant($name) === true;
    }

    /**
     * Whether DD_PACKING_SLIP_ALLOW_PRODUCTION_PRINTERS lets a non-production site print to the
     * production warehouse printers. Off unless the constant is exactly true.
     */
    public static function allowProductionPrinters(): bool
    {
        return self::flagEnabled('DD_PACKING_SLIP_ALLOW_PRODUCTION_PRINTERS');
    }

    /**
     * Whether the non-production print guards apply. Anything that is not production is
     * guarded, including an undefined WP_ENV.
     */
    public static function guardsApply(?string $env): bool
    {
        return $env !== 'production';
    }

    /**
     * Printer id with surrounding whitespace and any "pn_" prefix removed
     *
     * @param mixed $printer_id
     */
    public static function normalisePrinterId($printer_id): string
    {
        $id = trim((string) $printer_id);

        if (stripos($id, 'pn_') === 0) {
            $id = substr($id, 3);
        }

        return trim($id);
    }

    /**
     * Whether a printer id is one of the production warehouse printers
     *
     * @param mixed $printer_id
     */
    public static function isProductionPrinter($printer_id): bool
    {
        return in_array(self::normalisePrinterId($printer_id), self::PRODUCTION_PRINTER_IDS, true);
    }

    /**
     * Why any print (automatic or manual) to this printer is refused, or null if allowed.
     * Applies on non-production only; never blocks anything on production. The production
     * printer deny-list is lifted only when $allow_production_printers is true.
     *
     * @param mixed $printer_id
     */
    public static function printBlockReason(?string $env, $printer_id, bool $allow_production_printers = false): ?string
    {
        if (!self::guardsApply($env)) {
            return null;
        }

        if (!$allow_production_printers && self::isProductionPrinter($printer_id)) {
            return 'the configured printer is a production warehouse printer and this is not the production site.';
        }

        return null;
    }

    /**
     * Why an automatic print is refused, or null if allowed.
     *
     * On non-production only review checkout orders print, unless DD_PACKING_SLIP_PRINT_ALL_ORDERS
     * is true. The production printer deny-list applies regardless of that override, unless
     * $allow_production_printers is true. The two flags are independent.
     *
     * @param mixed $printer_id
     * @param mixed $review_meta Value of the _dd_review_order order meta
     */
    public static function autoPrintBlockReason(?string $env, $printer_id, $review_meta, bool $print_all_orders, bool $allow_production_printers = false): ?string
    {
        $reason = self::printBlockReason($env, $printer_id, $allow_production_printers);

        if ($reason !== null || !self::guardsApply($env)) {
            return $reason;
        }

        if (!$print_all_orders && $review_meta !== '1') {
            return 'not a review order and this is not the production site.';
        }

        return null;
    }

    /**
     * Whether the printed meta records a successful print
     *
     * @param mixed $printed Value of the _dd_packing_slip_printed order meta
     */
    public static function isPrinted($printed): bool
    {
        return is_array($printed) ? !empty($printed['time']) : !empty($printed);
    }
}
