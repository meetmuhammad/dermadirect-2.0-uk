<?php

namespace App\DermadirectToolsMenu\ProductDescriptionMigrator;

use WP_Post;

/**
 * Orchestrates the migration: pulls products from the database, runs each
 * one through ProductDescriptionParser, and (in "apply" mode) writes the
 * results back — with a backup, an idempotency guard, and a per-run report
 * that AdminPage.php renders and exposes as a CSV download.
 *
 * Design choices worth knowing before touching this class (see plan.md for
 * the full write-up):
 *   - "Preview" (previewBatch) never writes anything. It always re-parses
 *     every product fresh, so it reflects the current content even if it was
 *     edited since the last run.
 *   - "Apply" (applyBatch) is idempotent: a product that already has a
 *     `_pdm_migrated_at` timestamp is skipped unless $force is passed, so
 *     re-running after a host timeout (or just clicking the button twice)
 *     can't double-apply or duplicate repeater rows.
 *   - The very first time a product is touched, its original post_content is
 *     backed up to `_pdm_original_description` and never overwritten again —
 *     that's what makes rollback possible even after a --force re-run.
 *   - Only fields the parser actually found content for are written. A field
 *     the parser returned null for is left completely alone, on every run.
 */
class ProductDescriptionMigrator
{
    /** Postmeta key holding a product's original post_content, captured the first time it's touched. */
    private const META_ORIGINAL_CONTENT = '_pdm_original_description';

    /** Postmeta key: unix timestamp of the last successful Apply for this product. Presence = "already migrated". */
    private const META_MIGRATED_AT = '_pdm_migrated_at';

    /** Postmeta key: which ACF field names were actually written on the last Apply, for precise rollback. */
    private const META_FIELDS_APPLIED = '_pdm_fields_applied';

    /** Option holding the most recent Preview/Apply report, keyed by product ID. */
    private const OPTION_REPORT = 'pdm_report';

    /** Option holding the product IDs touched by the most recent Apply run — what "Rollback" reverts. */
    private const OPTION_LAST_APPLY_IDS = 'pdm_last_apply_product_ids';

    /** Suggested batch size for the admin page's JS to chunk requests by. Kept here so it's defined once. */
    public const RECOMMENDED_BATCH_SIZE = 40;

    private ProductDescriptionParser $parser;

    public function __construct()
    {
        $this->parser = new ProductDescriptionParser();
    }

    /**
     * Every product this tool is willing to touch: all non-trashed products,
     * regardless of publish status (a draft product's description is just as
     * real as a published one's).
     *
     * @return int[]
     */
    public function getEligibleProductIds(): array
    {
        return get_posts([
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
    }

    /**
     * The subset of getEligibleProductIds() that Apply hasn't successfully
     * processed yet. This is what "Apply Migration" runs over by default.
     *
     * @return int[]
     */
    public function getUnmigratedProductIds(): array
    {
        return get_posts([
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'meta_query' => [
                ['key' => self::META_MIGRATED_AT, 'compare' => 'NOT EXISTS'],
            ],
        ]);
    }

    /** Counts + last-report info shown in the admin page's status panel. */
    public function getStatusSummary(): array
    {
        $total = count($this->getEligibleProductIds());
        $unmigrated = count($this->getUnmigratedProductIds());
        $report = get_option(self::OPTION_REPORT, null);

        return [
            'total_products' => $total,
            'migrated_count' => $total - $unmigrated,
            'unmigrated_count' => $unmigrated,
            'last_report_mode' => $report['mode'] ?? null,
            'last_report_generated_at' => $report['generated_at'] ?? null,
            'last_report_row_count' => isset($report['rows']) ? count($report['rows']) : 0,
            'can_rollback' => !empty(get_option(self::OPTION_LAST_APPLY_IDS, [])),
        ];
    }

    /**
     * Parses a batch of products and records what *would* change, without
     * writing anything to the database.
     *
     * @param int[] $productIds
     * @return array<int, array> One report row per product ID processed.
     */
    public function previewBatch(array $productIds): array
    {
        $rows = [];

        foreach ($productIds as $postId) {
            $post = get_post($postId);
            if (!$post instanceof WP_Post) {
                continue;
            }

            $result = $this->parser->parse($post->post_content);
            $rows[$postId] = $this->buildReportRow($postId, $post, $result, 'would_apply');
        }

        $this->mergeIntoStoredReport($rows, 'preview');

        return $rows;
    }

    /**
     * Parses a batch of products and writes the results: ACF fields,
     * post_content, and this tool's own tracking meta.
     *
     * @param int[] $productIds
     * @param bool  $force Re-process products that already have a
     *              `_pdm_migrated_at` timestamp instead of skipping them.
     * @return array<int, array> One report row per product ID processed.
     */
    public function applyBatch(array $productIds, bool $force = false): array
    {
        $rows = [];
        $touchedIds = get_option(self::OPTION_LAST_APPLY_IDS, []);

        foreach ($productIds as $postId) {
            $post = get_post($postId);
            if (!$post instanceof WP_Post || $post->post_type !== 'product') {
                continue;
            }

            $alreadyMigrated = (bool) get_post_meta($postId, self::META_MIGRATED_AT, true);
            if ($alreadyMigrated && !$force) {
                $rows[$postId] = $this->buildSkippedRow($postId, $post, 'Already migrated — re-run with "force" to reprocess.');
                continue;
            }

            $result = $this->parser->parse($post->post_content);
            $fieldsApplied = [];

            // Back up the true original exactly once. On a --force re-run this
            // is a no-op (the backup from the *first* run is what rollback
            // needs to stay accurate), which is why we check for an existing
            // value rather than always writing.
            if (get_post_meta($postId, self::META_ORIGINAL_CONTENT, true) === '') {
                update_post_meta($postId, self::META_ORIGINAL_CONTENT, $post->post_content);
            }

            if ($result->keyBenefits !== null) {
                $repeaterRows = array_map(
                    static fn (string $benefit): array => ['benefit' => $benefit],
                    $result->keyBenefits
                );
                update_field('key_benefits', $repeaterRows, $postId);
                $fieldsApplied[] = 'key_benefits';
            }

            if ($result->composition !== null) {
                update_field('composition', $result->composition, $postId);
                $fieldsApplied[] = 'composition';
            }

            if ($result->storageInformation !== null) {
                update_field('storage_information', $result->storageInformation, $postId);
                $fieldsApplied[] = 'storage_information';
            }

            if ($result->remainingContent !== $post->post_content) {
                wp_update_post([
                    'ID' => $postId,
                    'post_content' => $result->remainingContent,
                ]);
            }

            update_post_meta($postId, self::META_MIGRATED_AT, time());
            update_post_meta($postId, self::META_FIELDS_APPLIED, $fieldsApplied);

            if (!in_array($postId, $touchedIds, true)) {
                $touchedIds[] = $postId;
            }

            $rows[$postId] = $this->buildReportRow($postId, $post, $result, 'applied', $fieldsApplied);
        }

        update_option(self::OPTION_LAST_APPLY_IDS, $touchedIds, false);
        $this->mergeIntoStoredReport($rows, 'apply');

        return $rows;
    }

    /**
     * Reverts every product touched by the most recent Apply run: restores
     * the original post_content and clears whichever ACF fields this tool
     * wrote, using each product's own tracking meta (so it's exact, not a
     * blanket "clear everything").
     *
     * @return int Number of products actually reverted.
     */
    public function rollbackLastRun(): int
    {
        $touchedIds = get_option(self::OPTION_LAST_APPLY_IDS, []);
        $reverted = 0;

        foreach ($touchedIds as $postId) {
            $original = get_post_meta($postId, self::META_ORIGINAL_CONTENT, true);
            if ($original === '') {
                // No backup on file for this ID — nothing safe to restore to,
                // so leave the product exactly as it is rather than guess.
                continue;
            }

            wp_update_post([
                'ID' => $postId,
                'post_content' => $original,
            ]);

            $fieldsApplied = get_post_meta($postId, self::META_FIELDS_APPLIED, true);
            foreach ((array) $fieldsApplied as $field) {
                delete_field($field, $postId);
            }

            delete_post_meta($postId, self::META_ORIGINAL_CONTENT);
            delete_post_meta($postId, self::META_MIGRATED_AT);
            delete_post_meta($postId, self::META_FIELDS_APPLIED);

            $reverted++;
        }

        delete_option(self::OPTION_LAST_APPLY_IDS);
        delete_option(self::OPTION_REPORT);

        return $reverted;
    }

    /** Renders the currently stored report (from the last Preview or Apply run) as CSV text. */
    public function buildReportCsv(): string
    {
        $report = get_option(self::OPTION_REPORT, ['rows' => []]);

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, [
            'Product ID', 'Title', 'SKU', 'Status',
            'Key Features rows', 'Composition filled', 'Storage filled', 'Treatment Areas found',
            'Fields applied', 'Notes',
        ]);

        foreach ($report['rows'] as $row) {
            fputcsv($handle, [
                $row['id'],
                $row['title'],
                $row['sku'],
                $row['status'],
                $row['key_benefits_count'] ?? '',
                $this->yesNoOrBlank($row['composition_filled']),
                $this->yesNoOrBlank($row['storage_filled']),
                $this->yesNoOrBlank($row['treatment_areas_found']),
                implode('; ', $row['fields_applied']),
                implode('; ', $row['anomalies']),
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function yesNoOrBlank(?bool $value): string
    {
        return match ($value) {
            true => 'yes',
            false => 'no',
            null => '',
        };
    }

    private function buildReportRow(
        int $postId,
        WP_Post $post,
        ParsedProductDescription $result,
        string $status,
        array $fieldsApplied = [],
    ): array {
        return [
            'id' => $postId,
            'title' => $post->post_title,
            'sku' => (string) get_post_meta($postId, '_sku', true),
            'status' => $status,
            'key_benefits_count' => $result->keyBenefits !== null ? count($result->keyBenefits) : null,
            'composition_filled' => $result->composition !== null,
            'storage_filled' => $result->storageInformation !== null,
            'treatment_areas_found' => $result->treatmentAreasFound,
            'fields_applied' => $fieldsApplied,
            'anomalies' => $result->anomalies,
            'has_changes' => $result->hasExtractedContent(),
        ];
    }

    private function buildSkippedRow(int $postId, WP_Post $post, string $reason): array
    {
        return [
            'id' => $postId,
            'title' => $post->post_title,
            'sku' => (string) get_post_meta($postId, '_sku', true),
            'status' => 'skipped',
            'key_benefits_count' => null,
            'composition_filled' => null,
            'storage_filled' => null,
            'treatment_areas_found' => null,
            'fields_applied' => [],
            'anomalies' => [$reason],
            'has_changes' => false,
        ];
    }

    /**
     * Merges new rows into the stored report option. Switching mode (Preview
     * <-> Apply) starts a fresh report rather than mixing the two together.
     *
     * @param array<int, array> $rows
     */
    private function mergeIntoStoredReport(array $rows, string $mode): void
    {
        $report = get_option(self::OPTION_REPORT, null);

        if (!is_array($report) || ($report['mode'] ?? null) !== $mode) {
            $report = ['mode' => $mode, 'generated_at' => time(), 'rows' => []];
        }

        foreach ($rows as $id => $row) {
            $report['rows'][$id] = $row;
        }

        // autoload=false: this option can grow to hundreds of rows and has no
        // business being loaded on every front-end page request.
        update_option(self::OPTION_REPORT, $report, false);
    }
}
