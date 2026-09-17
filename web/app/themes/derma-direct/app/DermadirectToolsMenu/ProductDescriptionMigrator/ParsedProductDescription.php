<?php

namespace App\DermadirectToolsMenu\ProductDescriptionMigrator;

/**
 * Immutable result of parsing one product's description HTML.
 *
 * `keyBenefits` / `composition` / `storageInformation` are `null` when the
 * corresponding heading wasn't found (or was found but had no usable content
 * after it) — the caller must treat `null` as "leave this ACF field alone",
 * never as "clear it". This is what keeps the migration from ever fabricating
 * content for a product whose description doesn't follow the expected shape.
 */
final class ParsedProductDescription
{
    /**
     * @param string        $remainingContent   The post_content to save (original minus the
     *                                           sections that were moved into ACF fields).
     * @param string[]|null $keyBenefits         Plain-text bullet lines for the `key_benefits`
     *                                           repeater, or null if no "Key Features" heading
     *                                           was found.
     * @param string|null   $composition         Plain text for the `composition` field, or null.
     * @param string|null   $storageInformation  Plain text for the `storage_information` field,
     *                                           or null.
     * @param bool          $treatmentAreasFound Whether a Treatment Areas heading was seen
     *                                           (informational only — that section is always
     *                                           left in the description, never extracted).
     * @param string[]      $anomalies           Human-readable notes about anything that looked
     *                                           unusual while parsing (surfaced in the report so
     *                                           it can be spot-checked, not hidden).
     */
    public function __construct(
        public readonly string $remainingContent,
        public readonly ?array $keyBenefits,
        public readonly ?string $composition,
        public readonly ?string $storageInformation,
        public readonly bool $treatmentAreasFound,
        public readonly array $anomalies = [],
    ) {
    }

    /**
     * True if at least one ACF field has content to write. When false, the
     * only possible change is to `post_content` staying exactly as it was —
     * i.e. this product has nothing for the migration to do.
     */
    public function hasExtractedContent(): bool
    {
        return $this->keyBenefits !== null
            || $this->composition !== null
            || $this->storageInformation !== null;
    }
}
