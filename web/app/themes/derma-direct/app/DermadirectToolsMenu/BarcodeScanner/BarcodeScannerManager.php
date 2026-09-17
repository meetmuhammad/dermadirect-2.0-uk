<?php

namespace App\DermadirectToolsMenu\BarcodeScanner;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\View;

class BarcodeScannerManager
{
    private static ?S3Client $s3Client = null;

    /**
     * Initialize the barcode scanner functionality
     */
    /**
     * Capability required to scan an order and mark it completed. Warehouse staff already
     * work in wp-admin on the order list, so they hold this; anonymous visitors do not.
     */
    public const CAPABILITY = 'edit_shop_orders';

    public static function init(): void
    {
        // Register shortcode
        add_shortcode('barcode-scanner', [self::class, 'renderShortcode']);

        // Register AJAX handlers.
        //
        // SECURITY: wp_ajax_nopriv_bs_upload is deliberately NOT registered. handleUpload()
        // completes an arbitrary order id taken from POST, so exposing it to logged-out
        // visitors let anyone mark any order as completed - this is audit finding F-01,
        // carried over from the legacy barcode-scanner plugin. A nonce is not an
        // authorisation check: for logged-out users a nonce is derived from user 0, so the
        // one printed in the public shortcode was valid for every anonymous visitor.
        add_action('wp_ajax_bs_upload', [self::class, 'handleUpload']);

        // Add admin meta box
        add_action('add_meta_boxes', [self::class, 'registerMetaBox']);

        // Schedule cleanup cron
        if (!wp_next_scheduled('barcode_scanner_delete_images')) {
            wp_schedule_event(time(), 'daily', 'barcode_scanner_delete_images');
        }
        add_action('barcode_scanner_delete_images', [self::class, 'cleanupOldImages']);

        // Enqueue assets
        add_action('wp_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('wp_head', [self::class, 'addInlineStyles']);
        add_action('wp_footer', [self::class, 'addInlineScripts']);
    }

    /**
     * Get S3 client instance
     */
    /**
     * Is S3 actually configured?
     *
     * `env('AWS_S3_BUCKET', 'dermadirect-images')` only falls back when the key is ABSENT - an
     * empty value is returned as-is. With AWS deliberately unconfigured (T15: the legacy key is
     * treated as compromised and not carried forward), the SDK threw
     * "The GetObject operation requires non-empty parameter: Bucket" from the order meta box and
     * took down the entire order-edit screen with a 500.
     *
     * An unconfigured optional integration must degrade, not break order administration.
     */
    private static function s3Configured(): bool
    {
        foreach (['AWS_S3_KEY', 'AWS_S3_SECRET', 'AWS_S3_BUCKET'] as $key) {
            if (trim((string) env($key)) === '') {
                return false;
            }
        }

        return true;
    }

    private static function getS3Client(): S3Client
    {
        if (self::$s3Client === null) {
            self::$s3Client = new S3Client([
                'version' => 'latest',
                'region' => env('AWS_S3_REGION', 'eu-west-2'),
                'credentials' => [
                    'key' => env('AWS_S3_KEY'),
                    'secret' => env('AWS_S3_SECRET'),
                ],
            ]);
        }

        return self::$s3Client;
    }

    /**
     * Enqueue necessary assets
     */
    public static function enqueueAssets(): void
    {
        // CSS
        wp_enqueue_style(
            'filepond-css',
            get_theme_file_uri('resources/css/vendor/filepond.min.css'),
            [],
            '4.30.3'
        );
        wp_enqueue_style(
            'filepond-image-preview-css',
            get_theme_file_uri('resources/css/vendor/filepond-plugin-image-preview.min.css'),
            ['filepond-css'],
            '4.6.10'
        );

        // JavaScript
        wp_enqueue_script(
            'quagga',
            get_theme_file_uri('resources/js/vendor/quagga.min.js'),
            ['jquery'],
            '0.12.1',
            true
        );
        wp_enqueue_script(
            'filepond-js',
            get_theme_file_uri('resources/js/vendor/filepond.min.js'),
            ['jquery'],
            '4.30.3',
            true
        );
        wp_enqueue_script(
            'filepond-image-preview-js',
            get_theme_file_uri('resources/js/vendor/filepond-plugin-image-preview.min.js'),
            ['filepond-js'],
            '4.6.10',
            true
        );
        wp_enqueue_script(
            'filepond-image-resize',
            get_theme_file_uri('resources/js/vendor/filepond-plugin-image-resize.js'),
            ['filepond-js'],
            '2.0.10',
            true
        );
        wp_enqueue_script(
            'filepond-image-transform',
            get_theme_file_uri('resources/js/vendor/filepond-plugin-image-transform.js'),
            ['filepond-js'],
            '3.8.7',
            true
        );
        wp_enqueue_script(
            'compressor',
            get_theme_file_uri('resources/js/vendor/compressor.min.js'),
            ['jquery'],
            '1.1.1',
            true
        );
    }

    /**
     * Add inline styles
     */
    public static function addInlineStyles(): void
    {
        ?>
        <style>
            .barcode-scanner-wrapper {
                max-width: 800px;
                margin: 40px auto;
                padding: 30px;
                background: #fff;
                border-radius: 12px;
                box-shadow: 0 2px 12px rgba(0, 0, 0, 0.1);
            }

            .barcode-scanner-alert {
                display: none;
                position: relative;
                padding: 16px 20px;
                margin-bottom: 24px;
                border-radius: 8px;
                font-size: 15px;
                font-weight: 500;
                animation: slideIn 0.3s ease;
            }

            @keyframes slideIn {
                from {
                    opacity: 0;
                    transform: translateY(-10px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            .alert-danger {
                color: #842029;
                background-color: #f8d7da;
                border: 1px solid #f5c2c7;
            }

            .alert-success {
                color: #0f5132;
                background-color: #d1e7dd;
                border: 1px solid #badbcc;
            }

            .barcode-scanner-button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 14px 32px;
                margin-bottom: 24px;
                background: #000;
                color: #fff;
                border: none;
                border-radius: 8px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                min-width: 200px;
            }

            .barcode-scanner-button:hover {
                background: #333;
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            }

            .barcode-scanner-button:active {
                transform: translateY(0);
            }

            .barcode-scanner-image {
                display: none;
                margin-bottom: 24px;
                max-width: 300px;
                width: 100%;
                border-radius: 8px;
                border: 2px solid #000;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            }

            .barcode-scanner-preview {
                display: none;
                margin-bottom: 24px;
                border-radius: 12px;
                overflow: hidden;
                background: #000;
                position: relative;
            }

            #barcode-scanner-preview video {
                width: 100%;
                height: auto;
                min-height: 400px;
                max-height: 600px;
                object-fit: cover;
                display: block;
            }

            #barcode-scanner-preview canvas {
                display: none;
            }

            #barcode-scanner-form {
                margin-bottom: 24px;
            }

            #barcode-scanner-form label {
                display: block;
                margin-bottom: 12px;
                margin-top: 24px;
                font-size: 16px;
                font-weight: 600;
                color: #000;
            }

            #barcode-scanner-upload {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 14px 32px;
                margin-top: 16px;
                background: #000;
                color: #fff;
                border: none;
                border-radius: 8px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                min-width: 200px;
            }

            #barcode-scanner-upload:hover {
                background: #333;
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            }

            #barcode-scanner-upload:disabled {
                background: #ccc;
                cursor: not-allowed;
                transform: none;
            }

            /* FilePond overrides */
            .filepond--root {
                font-family: inherit;
                margin-bottom: 0;
            }

            .filepond--drop-label {
                min-height: 200px;
                border: 2px dashed #ddd;
                border-radius: 8px;
                background: #fafafa;
                transition: all 0.3s ease;
            }

            .filepond--drop-label:hover {
                border-color: #000;
                background: #f5f5f5;
            }

            .filepond--panel-root {
                border-radius: 8px;
                background: #fff;
            }

            .filepond--item {
                margin-bottom: 8px;
            }

            /* Scanning indicator */
            .barcode-scanner-preview::after {
                content: 'Position barcode in center of camera';
                position: absolute;
                bottom: 20px;
                left: 50%;
                transform: translateX(-50%);
                background: rgba(0, 0, 0, 0.8);
                color: #fff;
                padding: 12px 24px;
                border-radius: 8px;
                font-size: 14px;
                font-weight: 500;
                animation: pulse 2s infinite;
            }

            @keyframes pulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.7; }
            }

            /* Scanned barcode indicator */
            .barcode-scanned {
                padding: 16px;
                background: #d1e7dd;
                border: 1px solid #badbcc;
                border-radius: 8px;
                margin-bottom: 16px;
                display: none;
            }

            .barcode-scanned.active {
                display: block;
            }

            .barcode-scanned strong {
                color: #0f5132;
                font-size: 18px;
            }

            /* Responsive */
            @media (max-width: 768px) {
                .barcode-scanner-wrapper {
                    margin: 20px;
                    padding: 20px;
                }

                .barcode-scanner-button,
                #barcode-scanner-upload {
                    width: 100%;
                }

                #barcode-scanner-preview video {
                    min-height: 300px;
                }
            }
        </style>
        <?php
    }

    /**
     * Render the barcode scanner shortcode
     */
    public static function renderShortcode(): string
    {
        // Never emit the scanner - or its nonce - to a visitor who could not use it anyway.
        if (!current_user_can(self::CAPABILITY)) {
            return '';
        }

        ob_start();
        ?>
        <div class="barcode-scanner-wrapper">
            <div class="barcode-scanner-alert" id="barcode-scanner-alert"></div>

            <div class="barcode-scanned" id="barcode-scanned">
                ✓ Barcode Scanned: <strong id="barcode-value"></strong>
            </div>

            <button class="barcode-scanner-button" id="barcode-scanner-button">
                📷 Scan Barcode
            </button>

            <div class="barcode-scanner-preview" id="barcode-scanner-preview"></div>
            <img class="barcode-scanner-image" id="barcode-scanner-image" />

            <form id="barcode-scanner-form" data-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
                <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('bs_upload')); ?>" />
                <input type="hidden" name="action" value="bs_upload" />
                <input type="hidden" name="order_id" id="barcode-scanner-order-id" />

                <label>📸 Upload Order Photos (Max 2)</label>
                <input type="file" name="photos[]" class="filepond" id="barcode-scanner-photo" multiple data-allow-reorder="true" data-max-files="2" />

                <button type="submit" id="barcode-scanner-upload">📤 Upload Photos</button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Add inline JavaScript for barcode scanner
     */
    public static function addInlineScripts(): void
    {
        ?>
        <script>
            jQuery(document).ready(function ($) {
                if ($('#barcode-scanner-form').length > 0) {
                    $('#barcode-scanner-button').click(function () {
                        $('#barcode-scanner-preview').slideDown();
                        Quagga.init({
                            inputStream: {
                                name: 'Live',
                                type : 'LiveStream',
                                target: '#barcode-scanner-preview'
                            },
                            decoder : {
                                readers : ['code_128_reader']
                            }
                        }, function (error) {
                            if (error) {
                                return
                            }
                            Quagga.start();
                        });
                    });

                    Quagga.onDetected(function (response) {
                        var canvas = Quagga.canvas.dom.image;
                        var barcodeValue = response.codeResult.code;

                        $('#barcode-scanner-image').attr('src', canvas.toDataURL()).slideDown();
                        $('#barcode-scanner-order-id').val(barcodeValue);
                        $('#barcode-value').text(barcodeValue);
                        $('#barcode-scanned').addClass('active').slideDown();
                        $('#barcode-scanner-preview').slideUp();
                        $('#barcode-scanner-button').text('✓ Scanned - Click to Rescan');
                        Quagga.stop();
                    });

                    FilePond.registerPlugin(FilePondPluginImagePreview);

                    var filePond = FilePond.create($('#barcode-scanner-photo')[0], {
                        storeAsFile: true
                    });

                    $('#barcode-scanner-form').submit(function (e) {
                        e.preventDefault();
                        $('#barcode-scanner-alert').hide();
                        $('#barcode-scanner-upload').text('Uploading...');
                        var url = $(this).data('url');
                        var data = new FormData($(this)[0]);
                        var pondFiles = filePond.getFiles();
                        if (pondFiles.length == 0) {
                            $.ajax({
                                type: 'POST',
                                url: url,
                                data: data,
                                dataType: 'json',
                                contentType: false,
                                processData: false,
                                success: function (response) {
                                    if (response.status == 'success') {
                                        $('#barcode-scanner-alert').removeClass('alert-danger').addClass('alert-success').text(response.message).show();
                                        setTimeout(function () {
                                            window.location.reload();
                                        }, 3000);
                                    } else {
                                        $('#barcode-scanner-alert').removeClass('alert-success').addClass('alert-danger').text(response.error).show();
                                    }

                                    $('#barcode-scanner-upload').text('Upload');
                                }
                            });
                        } else {
                            for (var i = 0; i < pondFiles.length; i++) {
                                new Compressor(pondFiles[i].file, {
                                    quality: 0.3,
                                    success: function (result) {
                                        data.append('photos[]', result, result.name);
                                        if (i == pondFiles.length) {
                                            $.ajax({
                                                type: 'POST',
                                                url: url,
                                                data: data,
                                                dataType: 'json',
                                                contentType: false,
                                                processData: false,
                                                success: function (response) {
                                                    if (response.status == 'success') {
                                                        $('#barcode-scanner-alert').removeClass('alert-danger').addClass('alert-success').text(response.message).show();
                                                        setTimeout(function () {
                                                            window.location.reload();
                                                        }, 3000);
                                                    } else {
                                                        $('#barcode-scanner-alert').removeClass('alert-success').addClass('alert-danger').text(response.error).show();
                                                    }

                                                    $('#barcode-scanner-upload').text('Upload');
                                                }
                                            });
                                        }
                                    }
                                });
                            }
                        }
                    });
                }
            });
        </script>
        <?php
    }

    /**
     * Handle the upload AJAX request
     */
    public static function handleUpload(): void
    {
        // Authorisation first: this endpoint completes orders. See F-01 in the security audit.
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json(['status' => 'error', 'error' => 'Not permitted'], 403);
        }

        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'bs_upload')) {
            wp_send_json(['status' => 'error', 'error' => 'Invalid or expired session'], 403);
        }

        $orderId = sanitize_text_field($_POST['order_id'] ?? '');
        $photos = $_FILES['photos'] ?? null;
        $errors = [];

        if ($orderId == '') {
            array_push($errors, 'You must scan the barcode first');
        } else {
            $order = wc_get_order($orderId);
            if (!$order) {
                array_push($errors, 'Order not found');
            }
        }

        if (!$photos || $photos['name'][0] == '') {
            array_push($errors, 'Photo is required');
        } else {
            for ($index = 0; $index < count($photos['name']); $index++) {
                $fileType = wp_check_filetype($photos['name'][$index]);
                if (!in_array(strtolower($fileType['ext']), ['jpg', 'jpeg', 'png'])) {
                    array_push($errors, 'You must upload image');
                }
            }
        }

        if (count($errors) == 0 && !self::s3Configured()) {
            wp_send_json([
                'status' => 'error',
                'error'  => 'Photo storage is not configured in this environment.',
            ]);
        }

        if (count($errors) == 0) {
            global $wpdb;
            $s3 = self::getS3Client();
            $bucket = env('AWS_S3_BUCKET', 'dermadirect-images');

            $attachments = [];
            $uploadDirectory = wp_upload_dir();

            for ($index = 0; $index < count($photos['name']); $index++) {
                $key = uniqid();
                $newPhotoPath = $uploadDirectory['path'] . '/' . $key . '-' . $photos['name'][$index];
                move_uploaded_file($photos['tmp_name'][$index], $newPhotoPath);

                $s3->putObject([
                    'Bucket' => $bucket,
                    'Key' => $key,
                    'Body' => fopen($newPhotoPath, 'r'),
                    'ACL' => 'public-read'
                ]);

                $wpdb->insert($wpdb->prefix . 'barcode_scanner_images', [
                    'image_key' => $key,
                    'delete_date' => date('Y-m-d', strtotime('+120 days')),
                    'created' => date('Y-m-d H:i:s')
                ]);

                array_push($attachments, $key);

                if (file_exists($newPhotoPath)) {
                    unlink($newPhotoPath);
                }
            }

            update_post_meta($orderId, 'barcode_photos_s3', $attachments);
            $order->update_status('completed');

            wp_send_json([
                'status' => 'success',
                'message' => 'Your photos have been uploaded'
            ]);
        } else {
            wp_send_json([
                'status' => 'error',
                'error' => $errors[0]
            ]);
        }

        wp_die();
    }

    /**
     * Register admin meta box
     */
    public static function registerMetaBox(): void
    {
        add_meta_box(
            'barcode_scanner_photo',
            'Order Photos',
            [self::class, 'renderMetaBox'],
            'shop_order',
            'side'
        );
    }

    /**
     * Render the admin meta box
     */
    public static function renderMetaBox($post): void
    {
        $orderId = $post->ID;
        $photosS3 = get_post_meta($orderId, 'barcode_photos_s3', true);

        if ($photosS3 && !self::s3Configured()) {
            printf(
                '<p>%s</p>',
                esc_html(sprintf(
                    /* translators: %d: number of stored photos */
                    _n('%d warehouse photo is stored, but AWS S3 is not configured in this environment.',
                       '%d warehouse photos are stored, but AWS S3 is not configured in this environment.',
                       count((array) $photosS3),
                       'sage'),
                    count((array) $photosS3)
                ))
            );

            return;
        }

        if ($photosS3) {
            $s3 = self::getS3Client();
            $bucket = env('AWS_S3_BUCKET', 'dermadirect-images');

            foreach ($photosS3 as $key) {
                $photoUrl = $s3->getObjectUrl($bucket, $key);
                echo '<a href="' . esc_url($photoUrl) . '" target="_blank" style="margin-bottom: 10px"><img src="' . esc_url($photoUrl) . '" style="width: 100%" /></a>';
            }
        } else {
            echo 'There is no photo';
        }
    }

    /**
     * Cleanup old images from S3
     */
    public static function cleanupOldImages(): void
    {
        if (!self::s3Configured()) {
            return;
        }

        $s3 = self::getS3Client();
        $bucket = env('AWS_S3_BUCKET', 'dermadirect-images');

        global $wpdb;
        $images = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . $wpdb->prefix . 'barcode_scanner_images WHERE delete_date = %s',
                date('Y-m-d')
            )
        );

        foreach ($images as $image) {
            $s3->deleteObject([
                'Bucket' => $bucket,
                'Key' => $image->image_key
            ]);

            $wpdb->delete($wpdb->prefix . 'barcode_scanner_images', [
                'image_key' => $image->image_key
            ]);
        }
    }

    /**
     * Create database table
     */
    public static function createDatabaseTable(): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'barcode_scanner_images';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            image_key varchar(255) NOT NULL,
            delete_date date NOT NULL,
            created datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY image_key (image_key)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}
