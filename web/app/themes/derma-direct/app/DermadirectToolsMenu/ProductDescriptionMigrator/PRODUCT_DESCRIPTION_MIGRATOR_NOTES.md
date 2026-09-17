# Product Description Migrator

**Where:** wp-admin → Dermadirect Tools → Description Migrator
**Full design rationale:** `plan.md` at the repo root — read that first if you're changing the parsing rules.

## What it does

Every product's long description (`post_content`) historically bundled four
sections together as plain HTML: **Key Features**, **Treatment Areas**,
**Composition**, **Storage**. This tool splits that apart:

| Section | Goes to | Format |
|---|---|---|
| Key Features | `key_benefits` ACF repeater | one plain-text `benefit` row per bullet |
| Treatment Areas | *(stays in the description, untouched)* | — |
| Composition | `composition` ACF field | plain text, heading removed |
| Storage | `storage_information` ACF field | plain text, heading removed |
| Anything else (e.g. "Recommended Usage:", "Specifications:") | *(stays in the description, untouched)* | — |

A heading missing on a given product means that field is simply left alone —
nothing is ever guessed or fabricated. Every value written into an ACF field
is plain text; any HTML in the source (`<ul>`, `<strong>`, pasted-from-Word
cruft, etc.) is stripped before saving.

## Files

- **`ProductDescriptionParser.php`** — the actual parsing logic. Pure PHP, no
  WordPress dependency, so it can be tested directly against raw HTML strings
  (see "How to sanity-check a change" below). This is where the heading
  detection and text-extraction rules live.
- **`ProductDescriptionMigrator.php`** — WordPress/ACF orchestration: queries
  products, calls the parser, writes ACF fields + `post_content`, tracks
  backup/idempotency meta, and builds the CSV report.
- **`AdminPage.php`** — the wp-admin page, AJAX batch-processing endpoints,
  and the rollback/CSV-download `admin_post_*` handlers.

## Why the parsing is more involved than it looks

Real product descriptions (~600 of them, inspected directly in the database
while building this) are much messier than a single clean example suggests:
the same heading appears as `<strong>`, `<b>`, `<h3><strong>`, wrapped in a
`<p>`, or with no wrapping tag at all; "Key Features" and "Treatment Areas"
have wording variants ("Key benefits", "Treatment Area"); some products
interleave unrelated headings ("Recommended Usage:", "Specifications:",
40+ variants found) between the ones we care about; and a bolded ingredient
name inside a bullet (`<li><b>Niacinamide (Vitamin B3):</b> Improves...`) can
look exactly like a heading unless you check whether it's nested inside a
`<li>`. Every one of these is handled deliberately — see the doc comments in
`ProductDescriptionParser::findHeadings()` for the reasoning behind each
detection rule, and plan.md for the full investigation.

## Safety model

- **Preview is always read-only.** It re-parses every product fresh and
  reports what *would* happen — nothing is written.
- **Apply backs up before writing.** The very first time a product is
  touched, its original `post_content` is saved to postmeta
  (`_pdm_original_description`) and never overwritten again, even on a
  forced re-run.
- **Idempotent.** A product with a `_pdm_migrated_at` timestamp is skipped on
  the next Apply run unless "force" is checked — so re-running after a
  timeout, or just clicking the button twice, can't duplicate repeater rows.
- **Batched.** The admin page processes products in batches (see
  `ProductDescriptionMigrator::RECOMMENDED_BATCH_SIZE`) via repeated AJAX
  calls, not one request over the whole catalog, so a slow host can't time
  out mid-run.
- **Rollback.** Restores `post_content` from the backup and clears exactly
  the ACF fields that were written (tracked per-product in
  `_pdm_fields_applied`), for every product touched by the most recent Apply
  run.
- **CSV report.** Every Preview/Apply run produces a downloadable report —
  which fields were filled per product, and any anomaly notes (e.g. "heading
  found but no usable content after it") worth a manual look.

Before running Apply on production: run Preview first, spot-check the
report, and take a database backup (this repo already keeps one under
`db-backups/`).

## Postmeta / options this tool uses

- `_pdm_original_description` (postmeta) — the product's `post_content`
  exactly as it was before the first migration touch.
- `_pdm_migrated_at` (postmeta) — unix timestamp of the last successful
  Apply for that product; presence means "already migrated".
- `_pdm_fields_applied` (postmeta) — which of `key_benefits` / `composition`
  / `storage_information` were actually written on the last Apply, so
  Rollback only clears what this tool actually set.
- `pdm_report` (option, autoload off) — the most recent Preview/Apply run's
  report, what the CSV download is built from.
- `pdm_last_apply_product_ids` (option, autoload off) — product IDs touched
  by the most recent Apply run; what Rollback reverts.

## How to sanity-check a change to the parser

`ProductDescriptionParser` has no WordPress dependency, so you can test it
against real content directly, e.g.:

```php
require 'ProductDescriptionParser.php';
require 'ParsedProductDescription.php';

use App\DermadirectToolsMenu\ProductDescriptionMigrator\ProductDescriptionParser;

$parser = new ProductDescriptionParser();
$result = $parser->parse($someProductsPostContent);

var_dump($result->keyBenefits, $result->composition, $result->storageInformation, $result->treatmentAreasFound, $result->anomalies);
echo $result->remainingContent;
```

When validating against the live database during development, the most
useful check isn't reading individual outputs — it's a bulk sweep across
every product's real `post_content` that flags anything structurally wrong
(a leftover `<` in a value that's supposed to be plain text, a PHP warning,
an unexpected drop in how many products get a given field filled compared to
a plain `LIKE '%Storage%'` count). That's how each of the edge cases
documented in `findHeadings()` was actually found.
