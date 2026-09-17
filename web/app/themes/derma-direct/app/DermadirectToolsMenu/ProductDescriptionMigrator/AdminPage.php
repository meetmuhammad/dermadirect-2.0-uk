<?php
// Admin UI for the Product Description Migrator tool.
namespace App\DermadirectToolsMenu\ProductDescriptionMigrator;

/**
 * Moves the "Key Features" / "Composition" / "Storage" sections out of a
 * product's long description and into their dedicated ACF fields (leaving
 * "Treatment Areas" and everything else in the description). See plan.md at
 * the repo root and PRODUCT_DESCRIPTION_MIGRATOR_NOTES.md in this directory
 * for the full rationale.
 *
 * Follows this theme's existing DermadirectToolsMenu convention: this class
 * is auto-discovered by app/setup.php (any `AdminPage.php` under
 * app/DermadirectToolsMenu/*\/ with a static init() gets booted automatically
 * — no manual registration needed) and the admin_post_{action} + nonce +
 * current_user_can('manage_options') pattern mirrors BarcodeScanner/AdminPage.php.
 *
 * Everything here is manually triggered from wp-admin by an administrator —
 * nothing in this tool runs automatically. The actual parsing/writing logic
 * lives in ProductDescriptionParser (pure HTML parsing) and
 * ProductDescriptionMigrator (WordPress/ACF orchestration); this class is
 * just the UI + request handling layer.
 */
class AdminPage
{
    private const PAGE_SLUG = 'product-description-migrator';

    /** Nonce action shared by this tool's two AJAX endpoints (batch preview/apply is one continuous workflow). */
    private const AJAX_NONCE_ACTION = 'pdm_ajax';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'addAdminMenu']);

        add_action('wp_ajax_pdm_get_product_ids', [self::class, 'handleGetProductIds']);
        add_action('wp_ajax_pdm_process_batch', [self::class, 'handleProcessBatch']);

        add_action('admin_post_pdm_rollback', [self::class, 'handleRollback']);
        add_action('admin_post_pdm_download_report', [self::class, 'handleDownloadReport']);
    }

    public static function addAdminMenu(): void
    {
        add_submenu_page(
            'dermadirect-tools',
            'Product Description Migrator',
            'Description Migrator',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'renderAdminPage']
        );
    }

    /**
     * AJAX: returns the list of product IDs a Preview or Apply run should
     * process. Fetched fresh on every "Run" click (rather than once at page
     * load) so the ID list reflects the catalog at the moment the button is
     * pressed, not whenever the admin happened to open the page.
     */
    public static function handleGetProductIds(): void
    {
        check_ajax_referer(self::AJAX_NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $mode = sanitize_text_field(wp_unslash($_POST['mode'] ?? 'preview'));
        $force = !empty($_POST['force']);
        $migrator = new ProductDescriptionMigrator();

        // Preview always looks at every product, so the report reflects the
        // full catalog. Apply defaults to only the not-yet-migrated ones —
        // that's what makes it safe to click again after an earlier run.
        $ids = ($mode === 'apply' && !$force)
            ? $migrator->getUnmigratedProductIds()
            : $migrator->getEligibleProductIds();

        wp_send_json_success(['ids' => array_map('intval', $ids)]);
    }

    /**
     * AJAX: processes one batch of product IDs in Preview (read-only) or
     * Apply (writes) mode. Called repeatedly by the page's JS, once per
     * batch, so a slow host can't time out mid-run the way one giant request
     * over 600 products could.
     */
    public static function handleProcessBatch(): void
    {
        check_ajax_referer(self::AJAX_NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $mode = sanitize_text_field(wp_unslash($_POST['mode'] ?? 'preview'));
        $force = !empty($_POST['force']);
        $productIds = array_filter(array_map('intval', (array) ($_POST['product_ids'] ?? [])));

        $migrator = new ProductDescriptionMigrator();

        $rows = $mode === 'apply'
            ? $migrator->applyBatch($productIds, $force)
            : $migrator->previewBatch($productIds);

        // Handing the raw rows back (rather than a pre-aggregated summary)
        // lets the page's JS accumulate running totals across every batch
        // without this endpoint needing to know about the whole run.
        wp_send_json_success(['rows' => array_values($rows)]);
    }

    /** admin_post: reverts every product touched by the most recent Apply run. */
    public static function handleRollback(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('pdm_rollback');

        $migrator = new ProductDescriptionMigrator();
        $reverted = $migrator->rollbackLastRun();

        wp_safe_redirect(add_query_arg([
            'page' => self::PAGE_SLUG,
            'rolled_back' => $reverted,
        ], admin_url('admin.php')));
        exit;
    }

    /** admin_post: streams the most recent Preview/Apply report as a CSV download. */
    public static function handleDownloadReport(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('pdm_download_report');

        $migrator = new ProductDescriptionMigrator();
        $csv = $migrator->buildReportCsv();

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="product-description-migration-report-' . gmdate('Y-m-d-His') . '.csv"');
        echo $csv;
        exit;
    }

    public static function renderAdminPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $migrator = new ProductDescriptionMigrator();
        $status = $migrator->getStatusSummary();

        self::renderStyles();
        ?>
        <div class="wrap pdm-wrap">
            <h1>Product Description Migrator</h1>
            <p>
                Moves the <strong>Key Features</strong>, <strong>Composition</strong> and <strong>Storage</strong>
                sections out of each product's description and into their ACF fields. The <strong>Treatment
                Areas</strong> section (and anything else the description contains) is always left in place.
                A heading that isn't found on a given product is simply skipped for that product — nothing is
                ever invented.
            </p>

            <?php if (isset($_GET['rolled_back'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Rolled back <?php echo esc_html((int) $_GET['rolled_back']); ?> product(s) to their original description.</p>
                </div>
            <?php endif; ?>

            <div class="card pdm-card">
                <h2>Status</h2>
                <table class="widefat striped" style="max-width: 640px;">
                    <tbody>
                        <tr><td>Total products</td><td id="pdm-stat-total"><?php echo esc_html($status['total_products']); ?></td></tr>
                        <tr><td>Already migrated</td><td id="pdm-stat-migrated"><?php echo esc_html($status['migrated_count']); ?></td></tr>
                        <tr><td>Not yet migrated</td><td id="pdm-stat-unmigrated"><?php echo esc_html($status['unmigrated_count']); ?></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="card pdm-card">
                <h2>1. Preview (read-only)</h2>
                <p>Parses every product and reports what <em>would</em> change. Nothing is written to the database.</p>
                <button type="button" class="button button-secondary" id="pdm-run-preview">Run Preview</button>
            </div>

            <div class="card pdm-card">
                <h2>2. Apply Migration</h2>
                <p>
                    Writes the changes for real: fills in the ACF fields and trims the description. Only products
                    that haven't been migrated yet are processed, unless "force" is checked below. The original
                    description is always backed up first, so this can be undone with Rollback.
                </p>
                <p>
                    <label>
                        <input type="checkbox" id="pdm-force-checkbox" />
                        Force re-process products that were already migrated
                    </label>
                </p>
                <p>
                    Type <code>MIGRATE</code> below to enable the button:
                    <input type="text" id="pdm-confirm-input" autocomplete="off" style="text-transform: uppercase;" />
                </p>
                <button type="button" class="button button-primary" id="pdm-run-apply" disabled>Apply Migration</button>
            </div>

            <div class="card pdm-card" id="pdm-progress-card" style="display: none;">
                <h2 id="pdm-progress-title">Working…</h2>
                <div class="pdm-progress-bar"><div class="pdm-progress-bar-fill" id="pdm-progress-fill"></div></div>
                <p id="pdm-progress-text">0 / 0 processed</p>
                <table class="widefat striped" style="max-width: 640px;">
                    <tbody>
                        <tr><td>Key Features filled</td><td id="pdm-count-key-benefits">0</td></tr>
                        <tr><td>Composition filled</td><td id="pdm-count-composition">0</td></tr>
                        <tr><td>Storage filled</td><td id="pdm-count-storage">0</td></tr>
                        <tr><td>Treatment Areas found</td><td id="pdm-count-treatment-areas">0</td></tr>
                        <tr><td>Skipped (already migrated)</td><td id="pdm-count-skipped">0</td></tr>
                        <tr><td>Products with a note to review</td><td id="pdm-count-anomalies">0</td></tr>
                    </tbody>
                </table>
                <p id="pdm-progress-actions" style="display: none;">
                    <a href="<?php echo esc_url(self::downloadReportUrl()); ?>" class="button">Download report (CSV)</a>
                </p>
            </div>

            <?php if ($status['can_rollback']): ?>
                <div class="card pdm-card">
                    <h2>Rollback</h2>
                    <p>Restores the original description and clears the ACF fields for every product touched by the most recent Apply run.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                          onsubmit="return confirm('Restore the original description for every product from the last Apply run? This cannot be undone.');">
                        <input type="hidden" name="action" value="pdm_rollback">
                        <?php wp_nonce_field('pdm_rollback'); ?>
                        <button type="submit" class="button button-secondary">Rollback last Apply run</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <?php self::renderScript(); ?>
        <?php
    }

    private static function downloadReportUrl(): string
    {
        return wp_nonce_url(
            admin_url('admin-post.php?action=pdm_download_report'),
            'pdm_download_report'
        );
    }

    private static function renderStyles(): void
    {
        ?>
        <style>
            .pdm-card { max-width: 760px; margin-top: 16px; padding: 16px 20px; }
            .pdm-progress-bar { background: #e5e5e5; border-radius: 4px; height: 20px; overflow: hidden; margin: 12px 0 4px; }
            .pdm-progress-bar-fill { background: #2271b1; height: 100%; width: 0%; transition: width 0.2s ease; }
        </style>
        <?php
    }

    /**
     * Inline admin JS driving the whole Preview/Apply workflow: fetch the ID
     * list for the chosen mode, then walk it in fixed-size batches (posting
     * one AJAX request per batch to pdm_process_batch), updating the
     * progress bar and running totals as each batch's response comes back.
     * Kept as one self-contained inline script to match this theme's
     * existing admin-tool convention (see BarcodeScanner) rather than adding
     * a separate enqueued asset file for a single settings page.
     */
    private static function renderScript(): void
    {
        $config = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::AJAX_NONCE_ACTION),
            'batchSize' => ProductDescriptionMigrator::RECOMMENDED_BATCH_SIZE,
        ];
        ?>
        <script>
        jQuery(function ($) {
            var PDM = <?php echo wp_json_encode($config); ?>;

            var totals = {};
            function resetTotals() {
                totals = { processed: 0, total: 0, keyBenefits: 0, composition: 0, storage: 0, treatmentAreas: 0, skipped: 0, anomalies: 0 };
            }

            function renderProgress() {
                var pct = totals.total > 0 ? Math.round((totals.processed / totals.total) * 100) : 0;
                $('#pdm-progress-fill').css('width', pct + '%');
                $('#pdm-progress-text').text(totals.processed + ' / ' + totals.total + ' processed');
                $('#pdm-count-key-benefits').text(totals.keyBenefits);
                $('#pdm-count-composition').text(totals.composition);
                $('#pdm-count-storage').text(totals.storage);
                $('#pdm-count-treatment-areas').text(totals.treatmentAreas);
                $('#pdm-count-skipped').text(totals.skipped);
                $('#pdm-count-anomalies').text(totals.anomalies);
            }

            function applyRowToTotals(row) {
                if (row.status === 'skipped') { totals.skipped++; return; }
                if (row.key_benefits_count) { totals.keyBenefits++; }
                if (row.composition_filled) { totals.composition++; }
                if (row.storage_filled) { totals.storage++; }
                if (row.treatment_areas_found) { totals.treatmentAreas++; }
                if (row.anomalies && row.anomalies.length > 0) { totals.anomalies++; }
            }

            /**
             * Walks `ids` in fixed-size batches, POSTing each one to
             * pdm_process_batch in sequence (never in parallel — keeps load
             * on the database predictable and batches strictly ordered).
             */
            function runBatches(mode, force, ids, onDone) {
                totals.total = ids.length;
                var index = 0;

                function nextBatch() {
                    if (index >= ids.length) { onDone(); return; }

                    var batch = ids.slice(index, index + PDM.batchSize);
                    index += batch.length;

                    $.post(PDM.ajaxUrl, {
                        action: 'pdm_process_batch',
                        nonce: PDM.nonce,
                        mode: mode,
                        force: force ? 1 : 0,
                        product_ids: batch
                    }).done(function (response) {
                        if (!response || !response.success) {
                            onDone('Server error while processing a batch. Stopped — already-processed products are safe; you can run again to continue.');
                            return;
                        }
                        (response.data.rows || []).forEach(applyRowToTotals);
                        totals.processed += batch.length;
                        renderProgress();
                        nextBatch();
                    }).fail(function () {
                        onDone('Network error while processing a batch. Stopped — already-processed products are safe; you can run again to continue.');
                    });
                }

                nextBatch();
            }

            function startRun(mode, force) {
                resetTotals();
                $('#pdm-progress-card').show();
                $('#pdm-progress-title').text(mode === 'apply' ? 'Applying migration…' : 'Running preview…');
                $('#pdm-progress-actions').hide();
                $('#pdm-run-preview, #pdm-run-apply').prop('disabled', true);

                $.post(PDM.ajaxUrl, { action: 'pdm_get_product_ids', nonce: PDM.nonce, mode: mode, force: force ? 1 : 0 })
                    .done(function (response) {
                        if (!response || !response.success) {
                            alert('Could not fetch the product list.');
                            $('#pdm-run-preview').prop('disabled', false);
                            return;
                        }
                        var ids = response.data.ids || [];
                        if (ids.length === 0) {
                            $('#pdm-progress-title').text('Nothing to do — no matching products.');
                            $('#pdm-run-preview').prop('disabled', false);
                            $('#pdm-run-apply').prop('disabled', !pdmApplyEnabled());
                            return;
                        }
                        runBatches(mode, force, ids, function (errorMessage) {
                            $('#pdm-progress-title').text(errorMessage ? 'Stopped: ' + errorMessage : (mode === 'apply' ? 'Apply complete.' : 'Preview complete.'));
                            $('#pdm-progress-actions').show();
                            $('#pdm-run-preview').prop('disabled', false);
                            $('#pdm-run-apply').prop('disabled', !pdmApplyEnabled());
                            if (mode === 'apply' && !errorMessage) {
                                // Reflect newly-migrated products without a full page reload.
                                $('#pdm-stat-migrated').text(totals.processed - totals.skipped + parseInt($('#pdm-stat-migrated').text(), 10));
                                $('#pdm-stat-unmigrated').text(Math.max(0, parseInt($('#pdm-stat-unmigrated').text(), 10) - (totals.processed - totals.skipped)));
                            }
                        });
                    })
                    .fail(function () {
                        alert('Could not fetch the product list.');
                        $('#pdm-run-preview').prop('disabled', false);
                    });
            }

            function pdmApplyEnabled() {
                return $('#pdm-confirm-input').val().trim().toUpperCase() === 'MIGRATE';
            }

            $('#pdm-confirm-input').on('input', function () {
                $('#pdm-run-apply').prop('disabled', !pdmApplyEnabled());
            });

            $('#pdm-run-preview').on('click', function () {
                startRun('preview', false);
            });

            $('#pdm-run-apply').on('click', function () {
                if (!pdmApplyEnabled()) { return; }
                var force = $('#pdm-force-checkbox').is(':checked');
                var confirmMessage = force
                    ? 'This will re-process ALL eligible products, including ones already migrated. Continue?'
                    : 'This will write ACF fields and update descriptions for every not-yet-migrated product. Continue?';
                if (!confirm(confirmMessage)) { return; }
                startRun('apply', force);
            });
        });
        </script>
        <?php
    }
}
