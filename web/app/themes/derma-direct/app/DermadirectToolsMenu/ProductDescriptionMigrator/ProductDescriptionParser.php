<?php

namespace App\DermadirectToolsMenu\ProductDescriptionMigrator;

/**
 * Splits a WooCommerce product's long description (post_content) into the
 * pieces that belong in dedicated ACF fields versus the pieces that should
 * stay in the description.
 *
 * Full rationale lives in plan.md ("Parsing algorithm" + "What the real data
 * actually looks like"), derived from inspecting ~600 real product
 * descriptions. The short version:
 *
 *   1. Every "heading-like" block in the HTML is located first — an
 *      <h1>-<h6>, or a bold run that ends in a colon — whatever it says.
 *      This deliberately also finds headings we don't care about
 *      ("Recommended Usage:", "Specifications:", ...): they need to be found
 *      so their content isn't accidentally swallowed by a neighbouring
 *      target section, even though they end up left alone.
 *   2. Each heading is classified against a small, extensible alias table
 *      (different wording, same meaning — e.g. "Key Features" / "Key
 *      benefits" are the same thing).
 *   3. A heading's content is everything from right after it to right before
 *      the *next* heading of any kind (known or not), or end-of-string if
 *      it's the last heading present. This is intentionally uniform — every
 *      heading is a "decision point": known heading -> move its content to
 *      the matching ACF field and remove it from the description; unknown
 *      heading, or the Treatment Areas heading -> leave it exactly where it
 *      is. One accepted tradeoff of this simplicity: if a product's last
 *      heading is (say) Composition with no following Storage heading, any
 *      unheaded trailing prose after Composition's list attaches to
 *      Composition rather than staying as free-floating description text.
 *      That case is real (see plan.md, product 408757) and is intentionally
 *      not special-cased further — it's the kind of thing the Preview report
 *      exists to catch.
 *   4. Everything written into an ACF field is reduced to plain text — no
 *      HTML tags are ever saved into an ACF value.
 *
 * This class has no WordPress dependencies on purpose (pure string in,
 * value-object out) so it can be unit tested directly against HTML fixtures
 * pulled from real products, without needing a WordPress bootstrap.
 */
class ProductDescriptionParser
{
    /** Internal classification keys used while parsing. */
    private const TARGET_KEY_BENEFITS = 'key_benefits';
    private const TARGET_TREATMENT_AREAS = 'treatment_areas';
    private const TARGET_COMPOSITION = 'composition';
    private const TARGET_STORAGE = 'storage_information';

    /**
     * Known wording variants for each heading, lowercase, no trailing colon.
     * Add a new phrase here if a future product uses different wording for
     * one of these four sections — no other change is needed.
     */
    private const HEADING_ALIASES = [
        self::TARGET_KEY_BENEFITS => ['key features', 'key benefits'],
        self::TARGET_TREATMENT_AREAS => ['treatment areas', 'treatment area'],
        self::TARGET_COMPOSITION => ['composition'],
        self::TARGET_STORAGE => ['storage'],
    ];

    /** Longest plausible heading label, in characters. Guards against a bold run of ordinary prose being mistaken for a heading. */
    private const MAX_LABEL_LENGTH = 60;

    public function parse(string $postContent): ParsedProductDescription
    {
        // All patterns below use the `u` (UTF-8) modifier so `\s` and friends
        // correctly recognise multi-byte whitespace such as a non-breaking
        // space (some products have `<strong>Key Features:&nbsp;</strong>` —
        // without /u, \s only matches ASCII whitespace and that heading is
        // silently missed). preg_* silently fails to match on invalid UTF-8
        // under /u rather than throwing, so guard for that explicitly —
        // otherwise a malformed-encoding product would look identical to a
        // product that legitimately has no matching headings.
        if (!mb_check_encoding($postContent, 'UTF-8')) {
            return new ParsedProductDescription(
                remainingContent: $postContent,
                keyBenefits: null,
                composition: null,
                storageInformation: null,
                treatmentAreasFound: false,
                anomalies: ['post_content is not valid UTF-8 — skipped entirely rather than risk a bad parse.'],
            );
        }

        $headings = $this->findHeadings($postContent);

        if ($headings === []) {
            // Nothing recognisable at all — leave this product completely untouched.
            return new ParsedProductDescription(
                remainingContent: $postContent,
                keyBenefits: null,
                composition: null,
                storageInformation: null,
                treatmentAreasFound: false,
                anomalies: [],
            );
        }

        $keyBenefits = null;
        $composition = null;
        $storageInformation = null;
        $treatmentAreasFound = false;
        $anomalies = [];
        $removedSpans = [];

        foreach ($headings as $index => $heading) {
            $target = $this->classify($heading['label']);

            // Content span: from just after this heading's own markup to just
            // before the next heading's markup, or end of string if this is
            // the last heading found.
            $contentStart = $heading['end'];
            $contentEnd = $headings[$index + 1]['start'] ?? strlen($postContent);
            $sectionHtml = substr($postContent, $contentStart, $contentEnd - $contentStart);

            if ($target === self::TARGET_TREATMENT_AREAS) {
                $treatmentAreasFound = true;
                continue; // Always stays in the description — never extracted.
            }

            if ($target === null) {
                continue; // Unrecognised heading: leave it and its content exactly where they are.
            }

            if ($target === self::TARGET_KEY_BENEFITS) {
                $items = $this->splitIntoListItems($sectionHtml);
                if ($items === []) {
                    $anomalies[] = 'Found a "Key Features" heading but no usable bullet content after it — field left unchanged.';
                    continue;
                }
                $keyBenefits = $items;
            } else {
                $plainText = $this->htmlToPlainText($sectionHtml);
                if ($plainText === '') {
                    $anomalies[] = sprintf(
                        'Found a "%s" heading but no usable content after it — field left unchanged.',
                        $target === self::TARGET_COMPOSITION ? 'Composition' : 'Storage'
                    );
                    continue;
                }

                if ($target === self::TARGET_COMPOSITION) {
                    $composition = $plainText;
                } else {
                    $storageInformation = $plainText;
                }
            }

            // Remove the heading's own markup together with its content —
            // the label text is moved to the ACF field's context (its label),
            // not saved as text inside the field, and shouldn't linger in
            // post_content either.
            $removedSpans[] = [$heading['start'], $contentEnd];
        }

        $remainingContent = $this->removeSpans($postContent, $removedSpans);

        return new ParsedProductDescription(
            remainingContent: $remainingContent,
            keyBenefits: $keyBenefits,
            composition: $composition,
            storageInformation: $storageInformation,
            treatmentAreasFound: $treatmentAreasFound,
            anomalies: $anomalies,
        );
    }

    /**
     * Finds every heading-like block in the HTML, sorted by position.
     *
     * @return array<int, array{start:int,end:int,label:string}> `start`/`end` are
     *         byte offsets bounding the heading's *entire* markup (wrapper tags
     *         included); `label` is the normalised (lowercase, trimmed, no
     *         trailing colon) heading text.
     */
    private function findHeadings(string $html): array
    {
        $matches = [];

        // 1) <h1>-<h6> blocks. A heading tag is inherently a heading regardless
        //    of whether its text ends in a colon (e.g. `<h4><b>Storage</b></h4>`
        //    appears in real data with no colon at all).
        if (preg_match_all('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/isu', $html, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $i => $fullMatch) {
                if ($this->isInsideListItem($html, $fullMatch[1])) {
                    continue;
                }
                $label = $this->normalizeLabel(strip_tags($m[1][$i][0]));
                if ($label === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
                    continue;
                }
                $matches[] = [
                    'start' => $fullMatch[1],
                    'end' => $fullMatch[1] + strlen($fullMatch[0]),
                    'label' => $label,
                ];
            }
        }

        // Bold runs already claimed by an <h1>-<h6> match above must not be
        // matched again below (an <h3><strong>Key Features:</strong></h3>
        // would otherwise be detected twice, at overlapping offsets).
        $claimed = array_map(static fn (array $h) => [$h['start'], $h['end']], $matches);

        // 2) A <strong>/<b> run — optionally wrapped in one <p>...</p> — whose
        //    text ends in a colon, found anywhere in the content. The colon is
        //    required here (unlike the h-tag case) because bold text is also
        //    used for plain emphasis inside the description's intro
        //    ("<strong>Product Name</strong> is a..."), which must NOT be
        //    mistaken for a heading. A second guard — applied uniformly in
        //    matchPattern() — rejects any match sitting inside an open <li>:
        //    real section headings are never nested inside a bullet, but a
        //    bolded ingredient sub-label inside one is common in real data,
        //    e.g. `<li><b>Niacinamide (Vitamin B3):</b> Improves...</li>`,
        //    and would otherwise be mistaken for a heading and wrongly split
        //    that bullet list in the middle.
        $this->matchPattern(
            pattern: '/(?:<p[^>]*>\s*)?<(strong|b)[^>]*>\s*([^<>]{1,' . self::MAX_LABEL_LENGTH . '}?):?\s*<\/\1>\s*:?\s*(?:<\/p>)?/iu',
            labelGroup: 2,
            html: $html,
            matches: $matches,
            claimed: $claimed,
            requireColonInMatch: true,
        );

        // 3) A <p> whose *entire* content is a short label ending in a colon,
        //    with no bold wrapper at all (e.g. `<p>Storage:</p>`). A paragraph
        //    containing nothing but a short colon-terminated phrase is a safe
        //    heading signal on its own — the strict "nothing else in the <p>"
        //    requirement (no nested tags allowed inside the label capture) is
        //    what keeps this from matching ordinary paragraph text.
        $this->matchPattern(
            pattern: '/<p[^>]*>\s*([A-Z][^<>]{1,' . (self::MAX_LABEL_LENGTH - 2) . '}?)\s*:\s*<\/p>/iu',
            labelGroup: 1,
            html: $html,
            matches: $matches,
            claimed: $claimed,
        );

        // 4) A <strong>/<b> run (optionally wrapped in one <p>) with NO colon,
        //    accepted only when it sits directly before a <ul>/<ol> — some
        //    products have e.g. `<strong>Storage</strong><ul>...</ul>` with no
        //    punctuation at all. Tolerates a stray empty inline tag pair
        //    (`<b></b>`) and a closing </p> in between, both seen in real
        //    data from copy/pasted source documents.
        $this->matchPattern(
            pattern: '/(?:<p[^>]*>\s*)?<(strong|b)[^>]*>\s*([^<>]{1,' . self::MAX_LABEL_LENGTH . '}?):?\s*<\/\1>\s*'
                . '(?:<[a-zA-Z][^>]*>\s*<\/[a-zA-Z][^>]*>\s*)*(?:<\/p>)?\s*(?=<(?:ul|ol)[\s>])/iu',
            labelGroup: 2,
            html: $html,
            matches: $matches,
            claimed: $claimed,
        );

        // 5) A bare, untagged label immediately followed by a list — the rare
        //    (but real) case where a product's copy has no wrapping tag at
        //    all around the heading. Deliberately narrow: only recognised
        //    when it sits directly before a <ul>/<ol>, and a colon is
        //    required (there's no tag boundary here to lean on instead), to
        //    avoid mistaking ordinary sentences for headings. Handled by hand
        //    rather than via matchPattern() because the match itself starts
        //    at a leading `>`/newline delimiter that isn't part of the
        //    heading and must not be included in the span that gets removed.
        $barePattern = '/(?:^|>|\n)\s*([A-Z][A-Za-z0-9 &\'\-\/]{1,' . (self::MAX_LABEL_LENGTH - 2) . '}?)\s*:\s*(?=<(?:ul|ol)[\s>])/u';
        if (preg_match_all($barePattern, $html, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $i => $fullMatch) {
                $labelStart = $m[1][$i][1];
                $colonPos = strpos($html, ':', $labelStart + strlen($m[1][$i][0]));
                $end = $colonPos + 1;

                if ($this->overlapsAny($labelStart, $end, $claimed) || $this->isInsideListItem($html, $labelStart)) {
                    continue;
                }

                $label = $this->normalizeLabel($m[1][$i][0]);
                if ($label === '') {
                    continue;
                }

                $matches[] = ['start' => $labelStart, 'end' => $end, 'label' => $label];
                $claimed[] = [$labelStart, $end];
            }
        }

        usort($matches, static fn (array $a, array $b) => $a['start'] <=> $b['start']);

        return $matches;
    }

    /**
     * Runs one heading-detection regex over $html and appends any
     * non-overlapping match to $matches/$claimed (both passed by reference
     * and shared across every pattern findHeadings() runs, so earlier/more-
     * reliable patterns always win a given span over later/more-permissive
     * ones — a later pattern simply skips any match that overlaps a span an
     * earlier one already claimed).
     *
     * Assumes the match's own start offset is the true start of the heading
     * (true for every pattern here except the bare-label-before-a-list one,
     * which is applied separately in findHeadings() for that reason).
     *
     * @param string                                              $pattern             PCRE pattern; must be built
     *                                                                                  with the `u` modifier.
     * @param int                                                 $labelGroup          Index of the capture group
     *                                                                                  holding the heading's label text.
     * @param string                                              $html
     * @param array<int, array{start:int,end:int,label:string}>   $matches             Accumulator, by reference.
     * @param array<int, array{0:int,1:int}>                      $claimed             Accumulator, by reference.
     * @param bool                                                $requireColonInMatch When true, a match without a
     *                                                                                  literal `:` anywhere in it is
     *                                                                                  discarded (used where the colon
     *                                                                                  is optional in the pattern
     *                                                                                  itself but still required for
     *                                                                                  this particular signal to count).
     */
    private function matchPattern(
        string $pattern,
        int $labelGroup,
        string $html,
        array &$matches,
        array &$claimed,
        bool $requireColonInMatch = false,
    ): void {
        if (!preg_match_all($pattern, $html, $m, PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($m[0] as $i => $fullMatch) {
            if ($requireColonInMatch && !str_contains($fullMatch[0], ':')) {
                continue;
            }

            $start = $fullMatch[1];
            $end = $start + strlen($fullMatch[0]);

            if ($this->overlapsAny($start, $end, $claimed) || $this->isInsideListItem($html, $start)) {
                continue;
            }

            $label = $this->normalizeLabel($m[$labelGroup][$i][0]);
            if ($label === '') {
                continue;
            }

            $matches[] = ['start' => $start, 'end' => $end, 'label' => $label];
            $claimed[] = [$start, $end];
        }
    }

    /**
     * True if $position falls inside an <li> that hasn't been closed yet.
     *
     * Real section headings are never nested inside a bullet point; a bolded
     * sub-label *is* sometimes used inside one (e.g. an ingredient name
     * before its description). This is what actually distinguishes a real
     * heading from that case — simply requiring the text to "end in a colon"
     * isn't enough, since the sub-label pattern does too.
     */
    private function isInsideListItem(string $html, int $position): bool
    {
        $before = substr($html, 0, $position);
        $lastOpen = strripos($before, '<li');
        $lastClose = strripos($before, '</li>');

        return $lastOpen !== false && ($lastClose === false || $lastOpen > $lastClose);
    }

    /** True if [$start, $end) overlaps any of the given [start, end) ranges. */
    private function overlapsAny(int $start, int $end, array $ranges): bool
    {
        foreach ($ranges as [$rangeStart, $rangeEnd]) {
            if ($start < $rangeEnd && $end > $rangeStart) {
                return true;
            }
        }

        return false;
    }

    /** Lowercase, trim, strip a trailing colon, collapse internal whitespace. */
    private function normalizeLabel(string $label): string
    {
        $label = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $label = trim($label);
        // rtrim()'s charlist is byte-based and won't strip a multi-byte space
        // (e.g. a non-breaking space), so collapse whitespace with a Unicode-
        // aware regex first, then trim a plain ASCII trailing colon/space.
        $label = preg_replace('/\s+/u', ' ', $label);
        $label = rtrim($label, ": \t\n\r\0\x0B");

        return mb_strtolower(trim($label));
    }

    /**
     * Maps a normalised heading label to one of our four targets, or null if
     * unrecognised.
     *
     * Matches an alias exactly, or as its leading word(s) — e.g. the alias
     * "storage" also matches the real-world heading "Storage & Handling",
     * which is the same section under a slightly longer name. The word-
     * boundary requirement (space right after the alias) stops this from
     * matching an unrelated word that merely starts the same way.
     */
    private function classify(string $normalizedLabel): ?string
    {
        foreach (self::HEADING_ALIASES as $target => $aliases) {
            foreach ($aliases as $alias) {
                if ($normalizedLabel === $alias || str_starts_with($normalizedLabel, $alias . ' ')) {
                    return $target;
                }
            }
        }

        return null;
    }

    /**
     * Extracts each `<li>` in the given HTML as a plain-text string, for use
     * as one `key_benefits` repeater row per bullet.
     *
     * @return string[]
     */
    private function splitIntoListItems(string $html): array
    {
        $items = [];

        if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $html, $m)) {
            foreach ($m[1] as $itemHtml) {
                $text = $this->htmlToPlainText($itemHtml, singleLine: true);
                if ($text !== '') {
                    $items[] = $text;
                }
            }
        }

        if ($items !== []) {
            return $items;
        }

        // Fallback for malformed content with no <li> markup at all: treat
        // each non-empty line of the plain-text version as one bullet.
        $plainText = $this->htmlToPlainText($html);
        foreach (preg_split('/\R+/', $plainText) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $items[] = $line;
            }
        }

        return $items;
    }

    /**
     * Strips all HTML from a fragment, leaving plain text. Block-level
     * boundaries are converted to newlines first so that separate list items
     * or paragraphs don't get glued together into one unreadable line.
     *
     * @param bool $singleLine When true (used for a single `<li>`'s content,
     *             which feeds a single-line ACF text field), all newlines are
     *             collapsed to spaces instead of being preserved.
     */
    private function htmlToPlainText(string $html, bool $singleLine = false): string
    {
        // Turn block-level boundaries into explicit newlines before stripping tags.
        $withBreaks = preg_replace(
            '/<\s*(br|\/li|\/p|\/div|\/h[1-6])\s*\/?>/i',
            "\n",
            $html
        );

        $text = strip_tags($withBreaks);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse horizontal whitespace (Unicode-aware — decoded &nbsp;
        // becomes a real non-breaking-space byte sequence, not an ASCII
        // space), then collapse blank-line runs.
        $lines = preg_split('/\R/u', $text);
        $lines = array_map(static function (string $line): string {
            return trim(preg_replace('/[^\S\n]+/u', ' ', $line));
        }, $lines);
        $lines = array_values(array_filter($lines, static fn (string $line) => $line !== ''));

        return $singleLine ? implode(' ', $lines) : trim(implode("\n", $lines));
    }

    /**
     * Removes the given [start, end) byte spans from the HTML (spans need not
     * be sorted or non-adjacent) and tidies up whitespace left behind so
     * removing a whole section doesn't leave a run of blank lines.
     *
     * @param array<int, array{0:int,1:int}> $spans
     */
    private function removeSpans(string $html, array $spans): string
    {
        if ($spans === []) {
            return $html;
        }

        usort($spans, static fn (array $a, array $b) => $a[0] <=> $b[0]);

        $result = '';
        $cursor = 0;
        foreach ($spans as [$start, $end]) {
            if ($start < $cursor) {
                continue; // Defensive: skip an overlapping/out-of-order span rather than corrupting output.
            }
            $result .= substr($html, $cursor, $start - $cursor);
            $cursor = $end;
        }
        $result .= substr($html, $cursor);

        // Collapse whitespace left behind by removed sections (3+ newlines -> 2).
        $result = preg_replace('/\n{3,}/', "\n\n", $result);
        $result = preg_replace('/(\r?\n[ \t]*){3,}/', "\n\n", $result);

        return trim($result);
    }
}
