<?php
/**
 * DermaDirect UK migration settings. Evidence for each value: docs/migration/PLAN.md §2 and §9.
 * Everything here is data, not secrets. Database credentials come from the environment (run.sh).
 */
return [
    'prefix' => 'wp_',

    // Databases that must never be written by any tool here.
    'protected_dbs' => ['dermadirect_local', 'legacy_snapshot_20260927', 'httpsdermadirectcom', 'dermadirect_UK_20', 'dermadirect_rehearsal'],
    // Destination (write target) must match this; source is opened read-only (DB user has SELECT only).
    'dest_pattern' => '/^dd_rh_(dest|test)[a-z0-9_]*$/',

    // Last IDs shared by the 2026-09-08 ID-preserving clone (verified: every legacy record <= these
    // exists on the destination with identical identity). Destination-created rows sit above them.
    'cutoff' => ['posts' => 695563, 'users' => 17552],

    // Destination-owned records found inside legacy's ID space are moved to old_id + offset[ns].
    // Layout per namespace: legacy IDs < relocated band < future_start (InnoDB cannot set
    // AUTO_INCREMENT below an existing ID, so future-generated IDs must sit above everything).
    // posts: 695564..696749 -> 995564..996749; users: 17553 -> 47553.
    'relocate_offset' => ['posts' => 300000, 'users' => 30000],

    // Future destination-generated IDs start here (AUTO_INCREMENT). Must stay above legacy's maximum
    // at launch and above the relocated band. Preflight (headroom.php) fails when legacy approaches.
    // Growth measured 2026-09-08..27: posts ~190/day, comments ~2,300/day, order items ~1,000/day.
    'future_start' => [
        'wp_posts' => 1000000,
        'wp_users' => 50000,
        'wp_comments' => 3000000,
        'wp_woocommerce_order_items' => 1500000,
        'wp_woocommerce_payment_tokens' => 20000,
        'wp_webis_pbet' => 20000,
        'wp_webis_pbet_order' => 600000,
        'wp_webis_pbet_order_item' => 200000,
        'wp_woocommerce_bis_notifications' => 10000,
        'wp_gens_raf' => 60000,
        'wp_wc_customer_lookup' => 100000,
    ],
    // Legacy-owned product fields (decision 05 + launch scope): prices, sale schedule, stock, tax,
    // total sales, and warehouse barcode data. The rendered barcode image and the legacy server file
    // path are excluded (derived / environment-specific). Everything else on a product is protected.
    'product_commercial' => ['_regular_price', '_sale_price', '_sale_price_dates_from', '_sale_price_dates_to', '_price', '_stock',
        '_stock_status', '_manage_stock', '_backorders', '_low_stock_amount', '_tax_status', '_tax_class', 'total_sales',
        '_ywbc_barcode_value', '_ywbc_barcode_display_value', '_ywbc_barcode_protocol', 'ywbc_barcode_display_value_custom_field'],

    // Approved SKU changes for products whose identity is proven by other evidence. Applied only when
    // the destination SKU equals 'from' and legacy's equals 'to' exactly; otherwise reported as ambiguous.
    // 689012: same ID/type/title; at the 2026-09-08 clone legacy had it as a draft with the 'from' SKU
    // (verified in DevKinsta dermadirect_UK_20); legacy re-SKU'd, repriced and published it on
    // 2026-09-15. No other product uses either SKU; no order items reference it. Status stays on the
    // review list (destination draft kept pending Paul).
    'approved_sku_changes' => [
        689012 => ['from' => '00919944-919945', 'to' => '06890124'],
    ],

    // URL/path rewrite applied to every legacy value the sync copies (serialization-safe), and used
    // to compare records modulo that rewrite. 'common' mirrors the original import's Bedrock layout
    // change; the profile maps the legacy base URL to the destination's for that environment.
    'url_rewrite' => [
        // 'common' applies to every source. The second pair reverses DevKinsta's local-domain rewrite:
        // a DevKinsta pull replaces dermadirect.co.uk with httpsdermadirectcom.local everywhere, including
        // inside email addresses (e.g. order-note author woocommerce@dermadirect.co.uk). Verified on legacy
        // production 2026-09-28 (read-only): 1,553,901 notes use woocommerce@dermadirect.co.uk, 0 use the
        // local host. Full site URLs are matched first (longer key) by the profile pair; this pair only
        // restores the remaining bare host. A no-op for authentic sources (--from-legacy-kinsta).
        'common' => [['/wp-content/uploads/', '/app/uploads/'], ['httpsdermadirectcom.local', 'dermadirect.co.uk']],
        'profiles' => [
            'rehearsal' => [['https://httpsdermadirectcom.local:8443', 'http://localhost:8088']],
            // Final sync on Kinsta runs after the destination takes the production domain: legacy URLs
            // are already correct, so only the uploads path changes.
            'kinsta-final' => [],
            // 'auto': legacy base URL recorded in the source snapshot -> the destination's current home.
        ],
    ],
    // Preflight: legacy max must stay below the next reserved band by max(25% of legacy max, 500).
    'headroom_margin_ratio' => 0.25, 'headroom_margin_min' => 500,
];
