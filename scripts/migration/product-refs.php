<?php
/**
 * Read-only report of every reference from migrated (legacy-owned) commerce data to products and
 * product categories, classified against the destination catalogue. Nothing is rewritten: historical
 * references stay exactly as legacy stored them (scope 2026-10-19: destination products are never
 * written, no placeholders are created).
 *   php product-refs.php --src=<db> [--src-prefix=lgcy_] --dest=<db>
 * Product classification (by ID, never by SKU alone):
 *   verified      same ID on the destination, same post type, same non-empty SKU
 *   same-id-no-sku   same ID and type, SKU empty on either side (title compared, reported)
 *   ambiguous     same ID and type but SKU differs
 *   type-conflict same ID holds a different post type on the destination
 *   missing       no destination post with that ID
 * Category classification: same term ID in product_cat with the same slug = verified; same ID, other
 * slug/taxonomy = ambiguous; absent = missing.
 * Writes migration-reports/product-refs-*.csv and product-refs-<ts>.json.
 */
declare(strict_types=1);

namespace DDMig;

require_once __DIR__ . '/lib.php';

$a = args($argv);
$S = src_ref($a);
$dest = $a['dest'] ?? fail('--dest required');
guard_dbs($dest, $S, $a);
$db = connect($dest);
$D = dest_ref($dest);
$rep = report_dir();

/** [id => count] of referenced IDs per source kind. */
$refs = [];
$add = function (string $kind, array $pairs) use (&$refs) {
    foreach ($pairs as [$id, $n]) {
        $id = (int) $id;
        if ($id > 0) {
            $refs[$kind][$id] = ($refs[$kind][$id] ?? 0) + (int) $n;
        }
    }
};
$pairs = fn (string $sql) => array_map(fn ($r) => [$r['id'], $r['n']], rows($db, $sql));
$orders = "SELECT ID FROM {$S['posts']} WHERE post_type IN ('shop_order','shop_order_refund')";

// 1. Order line items (_product_id / _variation_id), legacy orders and refunds
foreach (['_product_id' => 'order-item-product', '_variation_id' => 'order-item-variation'] as $k => $kind) {
    $add($kind, $pairs("SELECT im.meta_value id, COUNT(*) n FROM {$S['woocommerce_order_itemmeta']} im JOIN {$S['woocommerce_order_items']} oi
        ON oi.order_item_id=im.order_item_id WHERE im.meta_key='$k' AND im.meta_value REGEXP '^[0-9]+$' AND im.meta_value<>'0' GROUP BY im.meta_value"));
}
// 2. Coupons: product restrictions (comma lists)
$couponLists = [];
foreach (['product_ids', 'exclude_product_ids'] as $k) {
    foreach (rows($db, "SELECT pm.meta_value v FROM {$S['postmeta']} pm JOIN {$S['posts']} p ON p.ID=pm.post_id AND p.post_type='shop_coupon' WHERE pm.meta_key='$k' AND pm.meta_value<>''") as $r) {
        foreach (preg_split('/\s*,\s*/', $r['v'], -1, PREG_SPLIT_NO_EMPTY) as $id) {
            if (ctype_digit($id)) {
                $couponLists["coupon-$k"][(int) $id] = ($couponLists["coupon-$k"][(int) $id] ?? 0) + 1;
            }
        }
    }
}
foreach ($couponLists as $kind => $ids) {
    $add($kind, array_map(fn ($id, $n) => [$id, $n], array_keys($ids), $ids));
}
// 3. Coupons: category restrictions (serialized arrays of term IDs)
$catRefs = [];
foreach (['product_categories', 'exclude_product_categories'] as $k) {
    foreach (rows($db, "SELECT pm.meta_value v FROM {$S['postmeta']} pm JOIN {$S['posts']} p ON p.ID=pm.post_id AND p.post_type='shop_coupon' WHERE pm.meta_key='$k' AND pm.meta_value NOT IN ('','a:0:{}')") as $r) {
        $v = @unserialize($r['v'], ['allowed_classes' => false]);
        foreach (is_array($v) ? $v : [] as $tid) {
            if (is_numeric($tid) && (int) $tid > 0) { // 0 = empty placeholder in WooCommerce's array, not a term
                $catRefs["coupon-$k"][(int) $tid] = ($catRefs["coupon-$k"][(int) $tid] ?? 0) + 1;
            }
        }
    }
}
// WebToffee Smart Coupons restrictions: serialized arrays keyed by product / category ID ({id: {min,max}}).
foreach (['_wt_sc_coupon_products' => 'product', '_wt_sc_coupon_categories' => 'category'] as $k => $what) {
    foreach (rows($db, "SELECT pm.meta_value v FROM {$S['postmeta']} pm JOIN {$S['posts']} p ON p.ID=pm.post_id AND p.post_type='shop_coupon' WHERE pm.meta_key='$k' AND pm.meta_value NOT IN ('','a:0:{}')") as $r) {
        $v = @unserialize($r['v'], ['allowed_classes' => false]);
        foreach (is_array($v) ? array_keys($v) : [] as $id) {
            if ((int) $id > 0) {
                if ($what === 'product') {
                    $couponLists["coupon-$k"][(int) $id] = ($couponLists["coupon-$k"][(int) $id] ?? 0) + 1;
                } else {
                    $catRefs["coupon-$k"][(int) $id] = ($catRefs["coupon-$k"][(int) $id] ?? 0) + 1;
                }
            }
        }
    }
}
foreach (array_filter($couponLists, fn ($k) => str_starts_with($k, 'coupon-_wt'), ARRAY_FILTER_USE_KEY) as $kind => $ids) {
    $add($kind, array_map(fn ($id, $n) => [$id, $n], array_keys($ids), $ids));
}
// Other coupon meta keys that look like product/category restrictions: listed for review, not parsed
// (WebToffee *_condition keys are and/or operators, not references).
$otherCouponKeys = rows($db, "SELECT pm.meta_key k, COUNT(*) n FROM {$S['postmeta']} pm JOIN {$S['posts']} p ON p.ID=pm.post_id AND p.post_type='shop_coupon'
    WHERE (pm.meta_key LIKE '%product%' OR pm.meta_key LIKE '%categor%') AND pm.meta_key NOT IN ('product_ids','exclude_product_ids','product_categories','exclude_product_categories')
    AND pm.meta_key NOT IN ('_wt_sc_coupon_products','_wt_sc_coupon_categories','_wt_category_condition','_wt_product_condition','_wt_sc_bogo_product_condition','_wt_enable_product_category_restriction')
    AND pm.meta_value NOT IN ('','a:0:{}','0','no') GROUP BY pm.meta_key ORDER BY n DESC");
// 4. Plugin rows and analytics that point at products
$plugin = [
    'back-in-stock' => ['woocommerce_bis_notifications', 'product_id'],
    'download-permission' => ['woocommerce_downloadable_product_permissions', 'product_id'],
    'analytics-product' => ['wc_order_product_lookup', 'product_id'],
    'analytics-variation' => ['wc_order_product_lookup', 'variation_id'],
];
foreach ($plugin as $kind => [$t, $col]) {
    if ((int) one($db, 'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=' . e($db, $S->schema) . ' AND table_name=' . e($db, $S->name("wp_$t")) . ' AND column_name=' . e($db, $col))) {
        $add($kind, $pairs("SELECT `$col` id, COUNT(*) n FROM {$S["wp_$t"]} WHERE `$col`>0 GROUP BY `$col`"));
    }
}

// Classify every referenced product ID once
$all = [];
foreach ($refs as $ids) {
    $all += array_fill_keys(array_keys($ids), true);
}
$cls = [];
foreach (array_chunk(array_keys($all), 2000) as $ch) {
    $in = implode(',', $ch);
    $src = [];
    foreach (rows($db, "SELECT p.ID id, p.post_type t, p.post_title title, (SELECT meta_value FROM {$S['postmeta']} WHERE post_id=p.ID AND meta_key='_sku' LIMIT 1) sku FROM {$S['posts']} p WHERE p.ID IN ($in)") as $r) {
        $src[(int) $r['id']] = $r;
    }
    $dst = [];
    foreach (rows($db, "SELECT p.ID id, p.post_type t, p.post_title title, p.post_status st, (SELECT meta_value FROM {$D['postmeta']} WHERE post_id=p.ID AND meta_key='_sku' LIMIT 1) sku FROM {$D['posts']} p WHERE p.ID IN ($in)") as $r) {
        $dst[(int) $r['id']] = $r;
    }
    foreach ($ch as $id) {
        $s = $src[$id] ?? null;
        $d = $dst[$id] ?? null;
        if (!$d) {
            $c = 'missing';
        } elseif (!in_array($d['t'], ['product', 'product_variation'], true) || ($s && $s['t'] !== $d['t'])) {
            $c = 'type-conflict';
        } elseif ((string) ($s['sku'] ?? '') === '' || (string) $d['sku'] === '') {
            $c = 'same-id-no-sku';
        } elseif ($s && (string) $s['sku'] === (string) $d['sku']) {
            $c = 'verified';
        } else {
            $c = 'ambiguous';
        }
        $cls[$id] = ['class' => $c, 'legacy_type' => $s['t'] ?? '(deleted on legacy)', 'legacy_sku' => $s['sku'] ?? '', 'dest_type' => $d['t'] ?? '', 'dest_sku' => $d['sku'] ?? '',
            'dest_status' => $d['st'] ?? '', 'title_equal' => $s && $d ? (int) ($s['title'] === $d['title']) : 0];
    }
}
$summary = [];
$csv = [];
foreach ($refs as $kind => $ids) {
    foreach ($ids as $id => $n) {
        $c = $cls[$id];
        $summary[$kind][$c['class']]['ids'] = ($summary[$kind][$c['class']]['ids'] ?? 0) + 1;
        $summary[$kind][$c['class']]['references'] = ($summary[$kind][$c['class']]['references'] ?? 0) + $n;
        if ($c['class'] !== 'verified') {
            $csv[] = [$kind, $id, $n, $c['class'], $c['legacy_type'], $c['legacy_sku'], $c['dest_type'], $c['dest_sku'], $c['dest_status'], $c['title_equal']];
        }
    }
}
write_csv("$rep/product-refs-exceptions.csv", ['source', 'product_id', 'references', 'class', 'legacy_type', 'legacy_sku', 'destination_type', 'destination_sku', 'destination_status', 'title_equal'], $csv);

// Categories
$catCsv = [];
foreach ($catRefs as $kind => $ids) {
    foreach ($ids as $tid => $n) {
        $s = rows($db, "SELECT t.slug, tt.taxonomy FROM {$S['terms']} t JOIN {$S['term_taxonomy']} tt USING(term_id) WHERE t.term_id=$tid")[0] ?? null;
        $d = rows($db, "SELECT t.slug, tt.taxonomy FROM {$D['terms']} t JOIN {$D['term_taxonomy']} tt USING(term_id) WHERE t.term_id=$tid")[0] ?? null;
        $c = !$d ? 'missing' : (($s && $d['slug'] === $s['slug'] && $d['taxonomy'] === 'product_cat') ? 'verified' : 'ambiguous');
        $summary[$kind][$c]['ids'] = ($summary[$kind][$c]['ids'] ?? 0) + 1;
        $summary[$kind][$c]['references'] = ($summary[$kind][$c]['references'] ?? 0) + $n;
        if ($c !== 'verified') {
            $catCsv[] = [$kind, $tid, $n, $c, $s['slug'] ?? '(deleted on legacy)', $s['taxonomy'] ?? '', $d['slug'] ?? '', $d['taxonomy'] ?? ''];
        }
    }
}
write_csv("$rep/product-refs-category-exceptions.csv", ['source', 'term_id', 'coupons', 'class', 'legacy_slug', 'legacy_taxonomy', 'destination_slug', 'destination_taxonomy'], $catCsv);

// Catalogue differences (report only; products are not migrated): legacy vs destination by ID.
$cat = rows($db, "SELECT COALESCE(s.ID, d.ID) id, s.post_type st, d.post_type dt, s.post_status ss, d.post_status ds,
    (SELECT meta_value FROM {$S['postmeta']} WHERE post_id=s.ID AND meta_key='_sku' LIMIT 1) ssku, (SELECT meta_value FROM {$D['postmeta']} WHERE post_id=d.ID AND meta_key='_sku' LIMIT 1) dsku,
    (SELECT meta_value FROM {$S['postmeta']} WHERE post_id=s.ID AND meta_key='_price' LIMIT 1) sp, (SELECT meta_value FROM {$D['postmeta']} WHERE post_id=d.ID AND meta_key='_price' LIMIT 1) dp,
    (SELECT meta_value FROM {$S['postmeta']} WHERE post_id=s.ID AND meta_key='_stock' LIMIT 1) sk, (SELECT meta_value FROM {$D['postmeta']} WHERE post_id=d.ID AND meta_key='_stock' LIMIT 1) dk
    FROM {$S['posts']} s LEFT JOIN {$D['posts']} d ON d.ID=s.ID WHERE s.post_type IN ('product','product_variation')
    UNION SELECT d.ID, NULL, d.post_type, NULL, d.post_status, NULL, (SELECT meta_value FROM {$D['postmeta']} WHERE post_id=d.ID AND meta_key='_sku' LIMIT 1), NULL, NULL, NULL, NULL
    FROM {$D['posts']} d LEFT JOIN {$S['posts']} s ON s.ID=d.ID WHERE d.post_type IN ('product','product_variation') AND s.ID IS NULL");
$catSum = [];
$catCsv = [];
foreach ($cat as $r) {
    if ($r['st'] === null) {
        $k = 'destination-only';
    } elseif ($r['dt'] === null) {
        $k = 'legacy-only (missing on destination)';
    } elseif ($r['st'] !== $r['dt']) {
        $k = 'same ID, different type';
    } else {
        $k = 'on both';
    }
    $f = [];
    if ($k === 'on both') {
        foreach (['sku' => ['ssku', 'dsku'], 'status' => ['ss', 'ds'], 'price' => ['sp', 'dp'], 'stock' => ['sk', 'dk']] as $name => [$x, $y]) {
            if ((string) $r[$x] !== (string) $r[$y]) {
                $f[] = $name;
                $catSum['field differs: ' . $name] = ($catSum['field differs: ' . $name] ?? 0) + 1;
            }
        }
    }
    $catSum[$k] = ($catSum[$k] ?? 0) + 1;
    if ($k !== 'on both' || $f) {
        $catCsv[] = [$r['id'], $k, $r['st'] ?? '', $r['dt'] ?? '', $r['ss'] ?? '', $r['ds'] ?? '', implode('+', $f)];
    }
}
write_csv("$rep/product-catalogue-differences.csv", ['product_id', 'class', 'legacy_type', 'destination_type', 'legacy_status', 'destination_status', 'differing_fields'], $catCsv);
$summary['catalogue (report only)'] = $catSum;

$out = ['at' => gmdate('c'), 'source' => source_identity($db, $S, false), 'destination' => $dest, 'transformations' => 'none (references copied verbatim)',
    'summary' => $summary, 'other_coupon_restriction_keys_not_parsed' => $otherCouponKeys];
file_put_contents("$rep/product-refs-" . gmdate('Ymd\THis') . '.json', json_encode($out, JSON_PRETTY_PRINT));
out(json_encode(['summary' => $summary, 'other_coupon_restriction_keys' => $otherCouponKeys]));
