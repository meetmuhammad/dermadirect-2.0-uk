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

    /**
     * WordPress option production stores the S3 credentials in (key, secret, region, bucket).
     * DD2.0 writes the same shape plus an optional folder whenever Theme Settings are saved.
     */
    public const STORAGE_OPTION = 'barcode_scanner_aws';

    /**
     * Theme Settings (ACF Composer options page) slug and the storage fields on it.
     */
    public const SETTINGS_PAGE_SLUG = 'theme-settings';

    public const DEFAULT_REGION = 'eu-west-2';

    /**
     * Most photos stored per upload. Matches data-max-files on the FilePond input.
     */
    public const MAX_PHOTOS = 2;

    private const SETTINGS_FIELDS = [
        'key'    => 'aws_s3_key',
        'secret' => 'aws_s3_secret',
        'region' => 'aws_s3_region',
        'bucket' => 'aws_s3_bucket',
        'folder' => 'aws_s3_folder',
    ];

    /**
     * Fields on the Barcode Scanner tab that only manage_options users may see or change.
     */
    private const RESTRICTED_FIELDS = [
        'barcode_scanner',
        'barcode_scanner_storage_message',
        'aws_s3_key',
        'aws_s3_secret',
        'aws_s3_region',
        'aws_s3_bucket',
        'aws_s3_folder',
    ];

    private static ?array $storageConfig = null;

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

        // Theme Settings: restrict the storage fields and mirror them into the production option.
        foreach (self::RESTRICTED_FIELDS as $name) {
            add_filter("acf/prepare_field/name={$name}", [self::class, 'restrictSettingsField']);
        }
        foreach (self::SETTINGS_FIELDS as $name) {
            add_filter("acf/update_value/name={$name}", [self::class, 'guardSettingsValue'], 10, 3);
        }
        add_action('acf/save_post', [self::class, 'syncStorageOption'], 20);
    }

    /**
     * Hide the storage fields from anyone who cannot manage_options. Hidden fields are not
     * submitted, so their stored values are left alone.
     */
    public static function restrictSettingsField($field)
    {
        return current_user_can('manage_options') ? $field : false;
    }

    /**
     * Belt and braces for the hidden fields: if a value arrives from a user without
     * manage_options, keep what is already stored.
     */
    public static function guardSettingsValue($value, $postId, $field)
    {
        if (!self::isOptionsPost($postId) || current_user_can('manage_options')) {
            return $value;
        }

        return get_option('options_' . $field['name'], '');
    }

    /**
     * After Theme Settings are saved, write the barcode_scanner_aws option in the same shape
     * production uses (key, secret, region, bucket) plus folder.
     *
     * Skipped when key, secret and bucket are all blank, so saving an unrelated Theme
     * Settings tab never wipes an option that was set some other way (for example carried
     * over in a database import).
     */
    public static function syncStorageOption($postId): void
    {
        if (!self::isOptionsPost($postId) || !current_user_can('manage_options')) {
            return;
        }

        $settings = self::readThemeSettings();
        if ($settings['key'] === '' && $settings['secret'] === '' && $settings['bucket'] === '') {
            return;
        }

        update_option(self::STORAGE_OPTION, [
            'key'    => $settings['key'],
            'secret' => $settings['secret'],
            'region' => $settings['region'] !== '' ? $settings['region'] : self::DEFAULT_REGION,
            'bucket' => $settings['bucket'],
            'folder' => $settings['folder'],
        ], false);

        self::$storageConfig = null;
        self::$s3Client = null;
    }

    /**
     * Storage details that are safe to display. Never includes the key or secret values.
     *
     * @return array{configured: bool, source: string, key_set: bool, secret_set: bool, region: string, bucket: string, folder: string}
     */
    public static function storageStatus(): array
    {
        $config = self::storageConfig();

        return [
            'configured' => $config['source'] !== '',
            'source'     => $config['source'],
            'key_set'    => $config['key'] !== '',
            'secret_set' => $config['secret'] !== '',
            'region'     => $config['region'],
            'bucket'     => $config['bucket'],
            'folder'     => $config['folder'],
        ];
    }

    /**
     * Pick the first complete storage source. A source is complete when key, secret and
     * bucket are all non-empty after trimming. Region falls back to eu-west-2 and folder is
     * normalised with normalizeFolder().
     *
     * Pure function so it can be tested without WordPress.
     *
     * @param array<string, array> $sources Ordered map of source name => raw settings array.
     * @return array{source: string, key: string, secret: string, region: string, bucket: string, folder: string}
     */
    public static function pickStorageConfig(array $sources): array
    {
        foreach ($sources as $name => $raw) {
            $raw = is_array($raw) ? $raw : [];
            $config = [
                'source' => (string) $name,
                'key'    => trim((string) ($raw['key'] ?? '')),
                'secret' => trim((string) ($raw['secret'] ?? '')),
                'region' => trim((string) ($raw['region'] ?? '')),
                'bucket' => trim((string) ($raw['bucket'] ?? '')),
                'folder' => self::normalizeFolder((string) ($raw['folder'] ?? '')),
            ];

            if ($config['key'] !== '' && $config['secret'] !== '' && $config['bucket'] !== '') {
                if ($config['region'] === '') {
                    $config['region'] = self::DEFAULT_REGION;
                }

                return $config;
            }
        }

        return [
            'source' => '',
            'key'    => '',
            'secret' => '',
            'region' => self::DEFAULT_REGION,
            'bucket' => '',
            'folder' => '',
        ];
    }

    /**
     * Trim whitespace and surrounding slashes from a folder prefix.
     */
    public static function normalizeFolder(string $folder): string
    {
        return trim(trim($folder), '/');
    }

    /**
     * S3 object key for a new photo. With no folder this is the bare id at the bucket root,
     * exactly as production stores it; otherwise it is "{folder}/{id}".
     */
    public static function buildObjectKey(string $id, string $folder = ''): string
    {
        $folder = self::normalizeFolder($folder);

        return $folder === '' ? $id : $folder . '/' . $id;
    }

    /**
     * Indexes of the uploaded files to store, in order. A file is skipped when its hash is
     * empty (upload error or unreadable) or was already seen in this request, and at most
     * $max files are kept.
     *
     * Pure function so it can be tested without WordPress.
     *
     * @param array<int, string|false|null> $hashes Upload index => content hash.
     * @return int[]
     */
    public static function uniqueUploadIndexes(array $hashes, int $max = self::MAX_PHOTOS): array
    {
        $keep = [];
        $seen = [];

        foreach ($hashes as $index => $hash) {
            if (count($keep) >= $max) {
                break;
            }

            $hash = (string) $hash;
            if ($hash === '' || isset($seen[$hash])) {
                continue;
            }

            $seen[$hash] = true;
            $keep[] = $index;
        }

        return $keep;
    }

    /**
     * Content hash of one entry in a multi-file $_FILES array, or an empty string when the
     * upload failed or the temporary file cannot be read.
     */
    private static function uploadedFileHash(array $photos, int $index): string
    {
        $error = $photos['error'][$index] ?? UPLOAD_ERR_NO_FILE;
        $tmpName = (string) ($photos['tmp_name'][$index] ?? '');

        if ((int) $error !== UPLOAD_ERR_OK || $tmpName === '' || !is_uploaded_file($tmpName)) {
            return '';
        }

        $hash = md5_file($tmpName);

        return $hash === false ? '' : $hash;
    }

    /**
     * Single resolver for S3 storage settings, in priority order:
     *   1. Theme Settings fields (Barcode Scanner tab)
     *   2. the barcode_scanner_aws option (production compatible)
     *   3. env AWS_S3_KEY, AWS_S3_SECRET, AWS_S3_REGION, AWS_S3_BUCKET, AWS_S3_FOLDER
     */
    private static function storageConfig(): array
    {
        if (self::$storageConfig === null) {
            self::$storageConfig = self::pickStorageConfig([
                'theme_settings' => self::readThemeSettings(),
                'option'         => get_option(self::STORAGE_OPTION, []),
                'env'            => [
                    'key'    => env('AWS_S3_KEY'),
                    'secret' => env('AWS_S3_SECRET'),
                    'region' => env('AWS_S3_REGION'),
                    'bucket' => env('AWS_S3_BUCKET'),
                    'folder' => env('AWS_S3_FOLDER'),
                ],
            ]);
        }

        return self::$storageConfig;
    }

    /**
     * Raw storage values from Theme Settings, trimmed. Empty strings when ACF is unavailable.
     */
    private static function readThemeSettings(): array
    {
        $settings = [];
        foreach (self::SETTINGS_FIELDS as $key => $field) {
            $value = function_exists('get_field') ? get_field($field, 'option') : '';
            $settings[$key] = is_scalar($value) ? trim((string) $value) : '';
        }
        $settings['folder'] = self::normalizeFolder($settings['folder']);

        return $settings;
    }

    private static function isOptionsPost($postId): bool
    {
        return in_array($postId, ['options', 'option'], true);
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
     *
     * True when any source in storageConfig() (Theme Settings, the barcode_scanner_aws option,
     * or env) has a non-empty key, secret and bucket.
     */
    private static function s3Configured(): bool
    {
        return self::storageConfig()['source'] !== '';
    }

    private static function getS3Client(): S3Client
    {
        if (self::$s3Client === null) {
            $config = self::storageConfig();
            self::$s3Client = new S3Client([
                'version' => 'latest',
                'region' => $config['region'],
                'credentials' => [
                    'key' => $config['key'],
                    'secret' => $config['secret'],
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

                    var uploadButton = $('#barcode-scanner-upload');
                    var uploadButtonLabel = uploadButton.text();
                    var uploading = false;

                    function finishUpload() {
                        uploading = false;
                        uploadButton.prop('disabled', false).text(uploadButtonLabel);
                    }

                    function showUploadError(message) {
                        $('#barcode-scanner-alert').removeClass('alert-success').addClass('alert-danger').text(message).show();
                    }

                    // Resolves with the compressed file, or with the original if compression fails.
                    function compressPhoto(file) {
                        return new Promise(function (resolve) {
                            try {
                                new Compressor(file, {
                                    quality: 0.3,
                                    success: function (result) {
                                        resolve(result);
                                    },
                                    error: function () {
                                        resolve(file);
                                    }
                                });
                            } catch (error) {
                                resolve(file);
                            }
                        });
                    }

                    function sendUpload(url, data) {
                        $.ajax({
                            type: 'POST',
                            url: url,
                            data: data,
                            dataType: 'json',
                            contentType: false,
                            processData: false,
                            success: function (response) {
                                if (response && response.status == 'success') {
                                    $('#barcode-scanner-alert').removeClass('alert-danger').addClass('alert-success').text(response.message).show();
                                    setTimeout(function () {
                                        window.location.reload();
                                    }, 3000);
                                } else {
                                    showUploadError(response && response.error ? response.error : 'Something went wrong. Please try again.');
                                }

                                finishUpload();
                            },
                            error: function () {
                                showUploadError('The upload failed. Please try again.');
                                finishUpload();
                            }
                        });
                    }

                    $('#barcode-scanner-form').submit(function (e) {
                        e.preventDefault();

                        if (uploading) {
                            return;
                        }
                        uploading = true;

                        $('#barcode-scanner-alert').hide();
                        uploadButton.prop('disabled', true).text('Uploading...');

                        var url = $(this).data('url');
                        var data = new FormData($(this)[0]);

                        // FilePond (storeAsFile) keeps the original photos in a native input
                        // named photos[] inside this form. Drop them so only the compressed
                        // copies below are sent.
                        data.delete('photos[]');

                        var pondFiles = filePond.getFiles();
                        if (pondFiles.length == 0) {
                            sendUpload(url, data);
                            return;
                        }

                        var originals = pondFiles.map(function (pondFile) {
                            return pondFile.file;
                        });

                        Promise.all(originals.map(compressPhoto)).then(function (results) {
                            results.forEach(function (result, index) {
                                data.append('photos[]', result, result.name || originals[index].name);
                            });
                            sendUpload(url, data);
                        }).catch(function () {
                            showUploadError('The upload failed. Please try again.');
                            finishUpload();
                        });
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
        $keepIndexes = [];

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

            // Store each distinct photo once per request, even if the browser sends the same
            // file twice. Files that failed to upload or cannot be read are skipped.
            $hashes = [];
            for ($index = 0; $index < count($photos['name']); $index++) {
                $hashes[$index] = self::uploadedFileHash($photos, $index);
            }
            $keepIndexes = self::uniqueUploadIndexes($hashes, self::MAX_PHOTOS);

            if (count($errors) == 0 && count($keepIndexes) == 0) {
                array_push($errors, 'Photo is required');
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
            $storage = self::storageConfig();
            $bucket = $storage['bucket'];

            $attachments = [];
            $uploadDirectory = wp_upload_dir();

            foreach ($keepIndexes as $index) {
                $id = uniqid();
                $key = self::buildObjectKey($id, $storage['folder']);
                $newPhotoPath = $uploadDirectory['path'] . '/' . $id . '-' . $photos['name'][$index];
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
            $bucket = self::storageConfig()['bucket'];

            // Stored keys already include any folder prefix that was active at upload time.
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
        $bucket = self::storageConfig()['bucket'];

        global $wpdb;
        $images = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . $wpdb->prefix . 'barcode_scanner_images WHERE delete_date = %s',
                date('Y-m-d')
            )
        );

        // image_key is the full object key as uploaded (folder prefix included), so delete by it.
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
