<?php
/**
 * Independent record-level parity check of a migrated candidate (read-only on source, candidate and
 * baseline; scratch tables live in a separate --work schema). Does not reuse sync.php's hashing and
 * applies NO URL normalization: values must match byte for byte.
 *   php verify-parity.php --src=<db> --src-prefix=lgcy_ --dest=<candidate> --before=<baseline> --work=<scratch db>
 * Canonical graphs (meta row IDs excluded, meta compared as an exact multiset of key+value):
 *   customers  wp_users row (all columns) + usermeta (minus sessions/2FA/persistent carts)
 *   orders     posts row + postmeta + every order item row + item meta + every note row + note meta
 *   coupons    posts row + postmeta
 *   keyed plugin/analytics tables: every column of every row, by primary key
 * Protected (candidate vs baseline): every product/variation row, all their meta and term links,
 * every other destination-owned post and its meta, terms, term meta, staff accounts, options
 * (volatile rows excluded; the permitted customer-group role addition is checked exactly).
 * Every difference is listed by ID (no personal data) in migration-reports/parity-*.csv and the JSON.
 */
declare(strict_types=1);

namespace DDMig;

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/sync.php';

$a = args($argv);
$S = src_ref($a);
$dest = $a['dest'] ?? fail('--dest required');
$before = $a['before'] ?? fail('--before required (pre-sync baseline)');
$work = $a['work'] ?? fail('--work required (scratch schema)');
guard_dbs($dest, $S, $a);
guard_dbs($work, null, $a);
$db = connect($dest);
$D = dest_ref($dest);
$B = dest_ref($before);
q($db, "CREATE DATABASE IF NOT EXISTS `$work`");
$rep = report_dir();
$res = ['at' => gmdate('c'), 'source' => source_identity($db, $S, false), 'candidate' => $dest, 'baseline' => $before, 'checks' => []];
$fail = 0;
$check = function (string $name, bool $ok, $detail = null) use (&$res, &$fail) {
    $res['checks'][$name] = ['ok' => $ok, 'detail' => $detail];
    $fail += $ok ? 0 : 1;
    out(($ok ? 'PASS ' : 'FAIL ') . $name . ($detail !== null ? '  ' . json_encode($detail) : ''));
};
$W = fn (string $t) => "`$work`.`$t`";
$H = fn (string $expr) => "CAST(CONV(SUBSTRING(MD5($expr),1,15),16,10) AS DECIMAL(40))";
$rowExpr = function (string $schema, string $table, string $alias, array $skip = []) use ($db): string {
    $cols = array_column(rows($db, 'SELECT column_name c FROM information_schema.columns WHERE table_schema=' . e($db, $schema) . ' AND table_name=' . e($db, $table) . ' ORDER BY ordinal_position'), 'c');
    return 'CONCAT_WS(0x1F,' . implode(',', array_map(fn ($c) => "COALESCE(HEX($alias.`$c`),'~')", array_diff($cols, $skip))) . ')';
};
$deny = implode(' AND ', array_map(fn ($k) => "um.meta_key NOT LIKE " . e($db, $k), USERMETA_DENY));

/** Builds work table <name>(id, h, n) from a SELECT id, h, n and returns its full name. */
$build = function (string $name, string $select) use ($db, $W): string {
    q($db, "DROP TABLE IF EXISTS {$W($name)}");
    q($db, "CREATE TABLE {$W($name)} (id BIGINT UNSIGNED NOT NULL, h DECIMAL(40) NOT NULL, n BIGINT NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB");
    q($db, "INSERT INTO {$W($name)} (id, h, n) $select");
    return $W($name);
};
/** Compares two fingerprint tables restricted to IDs in $scope (a work table of ids). */
$compare = function (string $x, string $y, string $scope, string $label) use ($db, $W, $rep): array {
    $missing = array_column(rows($db, "SELECT s.id FROM $scope s LEFT JOIN $y d ON d.id=s.id WHERE d.id IS NULL ORDER BY s.id"), 'id');
    $diff = array_column(rows($db, "SELECT s.id FROM $scope s JOIN $x a ON a.id=s.id JOIN $y b ON b.id=s.id WHERE NOT (a.h<=>b.h AND a.n<=>b.n) ORDER BY s.id"), 'id');
    $srcMissing = array_column(rows($db, "SELECT s.id FROM $scope s LEFT JOIN $x a ON a.id=s.id WHERE a.id IS NULL ORDER BY s.id"), 'id');
    write_csv("$rep/parity-$label.csv", ['id', 'problem'], array_merge(array_map(fn ($i) => [$i, 'missing on candidate'], $missing), array_map(fn ($i) => [$i, 'differs'], $diff)));
    return ['in_scope' => (int) one($db, "SELECT COUNT(*) FROM $scope"), 'identical' => (int) one($db, "SELECT COUNT(*) FROM $scope s JOIN $x a ON a.id=s.id JOIN $y b ON b.id=s.id WHERE a.h<=>b.h AND a.n<=>b.n"),
        'differs' => count($diff), 'missing' => count($missing), 'missing_in_source_fingerprint' => count($srcMissing), 'sample' => array_slice(array_merge($missing, $diff), 0, 10)];
};
/** Sum of row fingerprints per owner id (row content + count). */
$sumBy = fn (string $ownerExpr, string $rowHash, string $from) => "SELECT $ownerExpr id, SUM({$rowHash}) h, COUNT(*) n FROM $from GROUP BY $ownerExpr";

// ---------------------------------------------------------------- customers
$customers = [];
$staffPolicy = [];
$res2 = q($db, "SELECT u.ID id, m.meta_value caps FROM {$S['users']} u LEFT JOIN {$S['usermeta']} m ON m.user_id=u.ID AND m.meta_key=" . e($db, cfg()['prefix'] . 'capabilities'));
while ($r = $res2->fetch_assoc()) {
    is_customer_caps($r['caps']) ? $customers[] = (int) $r['id'] : $staffPolicy[] = ['id' => (int) $r['id'], 'roles' => implode('+', caps_roles($r['caps']))];
}
q($db, "DROP TABLE IF EXISTS {$W('scope_users')}");
q($db, "CREATE TABLE {$W('scope_users')} (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB");
foreach (array_chunk($customers, 5000) as $ch) {
    q($db, "INSERT INTO {$W('scope_users')} VALUES (" . implode('),(', $ch) . ')');
}
write_csv("$rep/parity-staff-policy-users.csv", ['legacy_user_id', 'roles'], array_map(fn ($x) => [$x['id'], $x['roles']], $staffPolicy));
$uRow = fn ($T, $schema, $tbl) => $build("u_row_$schema", "SELECT u.ID, {$H($rowExpr($schema, $tbl, 'u'))}, 1 FROM {$T['users']} u JOIN {$W('scope_users')} s ON s.id=u.ID");
$uMeta = fn ($T, $tag) => $build("u_meta_$tag", $sumBy('um.user_id', $H("CONCAT(um.meta_key,0x1F,COALESCE(HEX(um.meta_value),'~'))"), "{$T['usermeta']} um JOIN {$W('scope_users')} s ON s.id=um.user_id WHERE $deny"));
$a1 = $uRow($S, $S->schema, $S->name('wp_users'));
$a2 = $uRow($D, $dest, 'wp_users');
$check('customers: user rows identical (login, email, password hash, registration, every column)', ($c = $compare($a1, $a2, $W('scope_users'), 'customers-rows'))['differs'] + $c['missing'] === 0, $c);
$m1 = $uMeta($S, 'src');
$m2 = $uMeta($D, 'dst');
$check('customers: all customer meta identical (addresses, roles, consent, plugin keys; sessions/2FA/carts excluded)', ($c = $compare($m1, $m2, $W('scope_users'), 'customers-meta'))['differs'] + $c['missing'] === 0, $c);
$groups = cfg()['customer_group_roles'];
foreach ($groups as $g) {
    $like = e($db, '%"' . $g . '"%');
    $n = [(int) one($db, "SELECT COUNT(*) FROM {$S['usermeta']} WHERE meta_key='wp_capabilities' AND meta_value LIKE $like"),
        (int) one($db, "SELECT COUNT(*) FROM {$D['usermeta']} WHERE meta_key='wp_capabilities' AND meta_value LIKE $like")];
    $srcRoles = @unserialize((string) one($db, "SELECT option_value FROM {$S['options']} WHERE option_name='wp_user_roles'"), ['allowed_classes' => false]);
    $dstRoles = @unserialize((string) one($db, "SELECT option_value FROM {$D['options']} WHERE option_name='wp_user_roles'"), ['allowed_classes' => false]);
    $check("customer group '$g': same members on both sides and role definition identical to the source", $n[0] === $n[1] && ($srcRoles[$g] ?? null) === ($dstRoles[$g] ?? null) && isset($dstRoles[$g]),
        ['members_source' => $n[0], 'members_candidate' => $n[1], 'definition' => $dstRoles[$g] ?? null]);
}
$res['staff_policy_users_not_migrated'] = count($staffPolicy);

// ---------------------------------------------------------------- orders, refunds, coupons
foreach (['orders' => "'shop_order','shop_order_refund'", 'coupons' => "'shop_coupon'"] as $entity => $types) {
    q($db, "DROP TABLE IF EXISTS {$W("scope_$entity")}");
    q($db, "CREATE TABLE {$W("scope_$entity")} (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB");
    q($db, "INSERT INTO {$W("scope_$entity")} SELECT ID FROM {$S['posts']} WHERE post_type IN ($types)");
    $scope = $W("scope_$entity");
    $parts = [
        'post' => fn ($T, $schema, $tbl) => "SELECT p.ID, {$H($rowExpr($schema, $tbl, 'p'))}, 1 FROM {$T['posts']} p JOIN $scope s ON s.id=p.ID",
        'meta' => fn ($T) => $sumBy('pm.post_id', $H("CONCAT(pm.meta_key,0x1F,COALESCE(HEX(pm.meta_value),'~'))"), "{$T['postmeta']} pm JOIN $scope s ON s.id=pm.post_id"),
    ];
    if ($entity === 'orders') {
        $parts['items'] = fn ($T, $schema, $tbl) => $sumBy('oi.order_id', $H($rowExpr($schema, $tbl, 'oi')), "{$T['woocommerce_order_items']} oi JOIN $scope s ON s.id=oi.order_id");
        $parts['itemmeta'] = fn ($T) => $sumBy('oi.order_id', $H("CONCAT(im.order_item_id,0x1F,im.meta_key,0x1F,COALESCE(HEX(im.meta_value),'~'))"), "{$T['woocommerce_order_itemmeta']} im JOIN {$T['woocommerce_order_items']} oi ON oi.order_item_id=im.order_item_id JOIN $scope s ON s.id=oi.order_id");
        $parts['notes'] = fn ($T, $schema, $tbl) => $sumBy('c.comment_post_ID', $H($rowExpr($schema, $tbl, 'c')), "{$T['comments']} c JOIN $scope s ON s.id=c.comment_post_ID");
        $parts['notemeta'] = fn ($T) => $sumBy('c.comment_post_ID', $H("CONCAT(cm.comment_id,0x1F,cm.meta_key,0x1F,COALESCE(HEX(cm.meta_value),'~'))"), "{$T['commentmeta']} cm JOIN {$T['comments']} c ON c.comment_ID=cm.comment_id JOIN $scope s ON s.id=c.comment_post_ID");
    }
    $tableOf = ['post' => 'wp_posts', 'items' => 'wp_woocommerce_order_items', 'notes' => 'wp_comments'];
    foreach ($parts as $part => $fn) {
        $t = $tableOf[$part] ?? null;
        $x = $build("{$entity}_{$part}_src", $t ? $fn($S, $S->schema, $S->name($t)) : $fn($S));
        $y = $build("{$entity}_{$part}_dst", $t ? $fn($D, $dest, $t) : $fn($D));
        if ($part === 'post') {
            $c = $compare($x, $y, $scope, "$entity-$part");
        } else {
            // records without meta/items/notes have no fingerprint row on either side: null-safe compare over the scope
            $c = ['differs' => (int) one($db, "SELECT COUNT(*) FROM $scope s LEFT JOIN $x a ON a.id=s.id LEFT JOIN $y b ON b.id=s.id WHERE NOT (a.h<=>b.h AND a.n<=>b.n)"),
                'missing' => 0, 'owners_with_rows_source' => (int) one($db, "SELECT COUNT(*) FROM $x"), 'owners_with_rows_candidate' => (int) one($db, "SELECT COUNT(*) FROM $y JOIN $scope s USING(id)")];
            write_csv("$rep/parity-$entity-$part.csv", ['id', 'problem'], array_map(fn ($r) => [$r['id'], 'differs'], rows($db, "SELECT s.id FROM $scope s LEFT JOIN $x a ON a.id=s.id LEFT JOIN $y b ON b.id=s.id WHERE NOT (a.h<=>b.h AND a.n<=>b.n) LIMIT 1000")));
        }
        $check("$entity: $part identical for every legacy record", $c['differs'] + $c['missing'] === 0, $c);
    }
    $byStatus = fn ($T) => rows($db, "SELECT p.post_type, p.post_status, COUNT(*) n FROM {$T['posts']} p JOIN $scope s ON s.id=p.ID GROUP BY 1,2 ORDER BY 1,2");
    $res["{$entity}_coverage_by_status"] = ['source' => $byStatus($S), 'candidate' => $byStatus($D)];
    $check("$entity: coverage by type and status identical", $byStatus($S) == $byStatus($D), null);
}
// Destination-only commerce records inside the legacy ID range are kept, reported
$destOnly = rows($db, "SELECT p.ID, p.post_type, p.post_status FROM {$D['posts']} p LEFT JOIN {$S['posts']} s ON s.ID=p.ID WHERE p.post_type IN ('shop_order','shop_order_refund','shop_coupon') AND s.ID IS NULL ORDER BY p.ID");
write_csv("$rep/parity-destination-only-commerce.csv", ['id', 'type', 'status'], array_map('array_values', $destOnly));
$res['destination_only_commerce_records_kept'] = count($destOnly);

// Exact money totals (DECIMAL, no floating point) by type/status/currency
$money = fn ($T) => rows($db, "SELECT p.post_type, p.post_status, COALESCE(cur.meta_value,'') cur, COUNT(*) n,
    SUM(CAST(COALESCE(NULLIF(tot.meta_value,''),'0') AS DECIMAL(20,6))) total, SUM(CAST(COALESCE(NULLIF(tax.meta_value,''),'0') AS DECIMAL(20,6))) tax,
    SUM(CAST(COALESCE(NULLIF(sh.meta_value,''),'0') AS DECIMAL(20,6))) shipping, SUM(CAST(COALESCE(NULLIF(st.meta_value,''),'0') AS DECIMAL(20,6))) shipping_tax,
    SUM(CAST(COALESCE(NULLIF(ds.meta_value,''),'0') AS DECIMAL(20,6))) discount, SUM(CAST(COALESCE(NULLIF(rf.meta_value,''),'0') AS DECIMAL(20,6))) refund_amount
    FROM {$T['posts']} p JOIN {$W('scope_orders')} s ON s.id=p.ID
    LEFT JOIN {$T['postmeta']} cur ON cur.post_id=p.ID AND cur.meta_key='_order_currency' LEFT JOIN {$T['postmeta']} tot ON tot.post_id=p.ID AND tot.meta_key='_order_total'
    LEFT JOIN {$T['postmeta']} tax ON tax.post_id=p.ID AND tax.meta_key='_order_tax' LEFT JOIN {$T['postmeta']} sh ON sh.post_id=p.ID AND sh.meta_key='_order_shipping'
    LEFT JOIN {$T['postmeta']} st ON st.post_id=p.ID AND st.meta_key='_order_shipping_tax' LEFT JOIN {$T['postmeta']} ds ON ds.post_id=p.ID AND ds.meta_key='_cart_discount'
    LEFT JOIN {$T['postmeta']} rf ON rf.post_id=p.ID AND rf.meta_key='_refund_amount' GROUP BY 1,2,3 ORDER BY 1,2,3");
$ms = $money($S);
$md = $money($D);
$res['money_by_type_status_currency'] = $md;
$check('orders/refunds: exact decimal totals, tax, shipping, discount and refund amounts by type/status/currency', $ms == $md, ['groups' => count($ms)]);
$lineMoney = fn ($T) => one($db, "SELECT CONCAT(COUNT(*),'|',SUM(CAST(im.meta_value AS DECIMAL(20,6)))) FROM {$T['woocommerce_order_itemmeta']} im JOIN {$T['woocommerce_order_items']} oi ON oi.order_item_id=im.order_item_id JOIN {$W('scope_orders')} s ON s.id=oi.order_id WHERE im.meta_key IN ('_line_total','_line_tax','_line_subtotal','_line_subtotal_tax','cost','total_tax') AND im.meta_value REGEXP '^-?[0-9.]+$'");
$check('order items: exact decimal sum of line totals, taxes, subtotals, shipping and fee costs', $lineMoney($S) === $lineMoney($D), $lineMoney($D));

// ---------------------------------------------------------------- keyed plugin + analytics tables
$keyed = keyed_tables() + ['wp_wc_order_stats' => 'order_id', 'wp_wc_order_tax_lookup' => null, 'wp_wc_order_coupon_lookup' => null, 'wp_wc_order_product_lookup' => 'order_item_id', 'wp_wc_customer_lookup' => 'customer_id'];
$tbl = [];
foreach ($keyed as $t => $pk) {
    if (!(int) one($db, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=' . e($db, $dest) . ' AND table_name=' . e($db, $t))) {
        $tbl[$t] = 'table absent on destination (not migrated)';
        continue;
    }
    // composite-key tables: fingerprint the whole row and compare as multisets restricted to legacy orders
    $sx = $H($rowExpr($S->schema, $S->name($t), 'x'));
    $dx = $H($rowExpr($dest, $t, 'x'));
    if ($pk === null) {
        $q = fn ($T, $expr) => one($db, "SELECT CONCAT(COUNT(*),'|',COALESCE(SUM($expr),0)) FROM {$T[$t]} x JOIN {$W('scope_orders')} s ON s.id=x.order_id");
        $tbl[$t] = ['equal' => $q($S, $sx) === $q($D, $dx), 'source' => $q($S, $sx)];
        continue;
    }
    $x = $build("k_src_$t", "SELECT x.`$pk`, $sx, 1 FROM {$S[$t]} x");
    $y = $build("k_dst_$t", "SELECT x.`$pk`, $dx, 1 FROM {$D[$t]} x");
    $tbl[$t] = ['source_rows' => (int) one($db, "SELECT COUNT(*) FROM $x"), 'missing' => (int) one($db, "SELECT COUNT(*) FROM $x a LEFT JOIN $y b ON b.id=a.id WHERE b.id IS NULL"),
        'differs' => (int) one($db, "SELECT COUNT(*) FROM $x a JOIN $y b ON b.id=a.id WHERE a.h<>b.h"), 'destination_only_rows' => (int) one($db, "SELECT COUNT(*) FROM $y b LEFT JOIN $x a ON a.id=b.id WHERE a.id IS NULL")];
}
$bad = array_filter($tbl, fn ($v) => is_array($v) && (isset($v['equal']) ? !$v['equal'] : ($v['missing'] + $v['differs'] > 0)));
$check('plugin and analytics tables: every legacy row present and identical (destination-only rows reported)', !$bad, $tbl);
$res['plugin_tables'] = $tbl;

// ---------------------------------------------------------------- integrity on the candidate
$orph = [
    'order items whose order is missing' => "SELECT COUNT(*) FROM {$D['woocommerce_order_items']} oi LEFT JOIN {$D['posts']} p ON p.ID=oi.order_id WHERE p.ID IS NULL",
    'item meta whose item is missing' => "SELECT COUNT(*) FROM {$D['woocommerce_order_itemmeta']} im LEFT JOIN {$D['woocommerce_order_items']} oi ON oi.order_item_id=im.order_item_id WHERE oi.order_item_id IS NULL",
    'refunds whose parent order is missing' => "SELECT COUNT(*) FROM {$D['posts']} r LEFT JOIN {$D['posts']} o ON o.ID=r.post_parent WHERE r.post_type='shop_order_refund' AND o.ID IS NULL",
    'orders owned by a missing user' => "SELECT COUNT(*) FROM {$D['postmeta']} pm JOIN {$D['posts']} p ON p.ID=pm.post_id AND p.post_type='shop_order' LEFT JOIN {$D['users']} u ON u.ID=pm.meta_value WHERE pm.meta_key='_customer_user' AND pm.meta_value<>'0' AND u.ID IS NULL",
    'duplicate order item IDs' => "SELECT COUNT(*) - COUNT(DISTINCT order_item_id) FROM {$D['woocommerce_order_items']}",
];
$srcOrph = [
    'order items whose order is missing' => "SELECT COUNT(*) FROM {$S['woocommerce_order_items']} oi LEFT JOIN {$S['posts']} p ON p.ID=oi.order_id WHERE p.ID IS NULL",
    'item meta whose item is missing' => "SELECT COUNT(*) FROM {$S['woocommerce_order_itemmeta']} im LEFT JOIN {$S['woocommerce_order_items']} oi ON oi.order_item_id=im.order_item_id WHERE oi.order_item_id IS NULL",
    'refunds whose parent order is missing' => "SELECT COUNT(*) FROM {$S['posts']} r LEFT JOIN {$S['posts']} o ON o.ID=r.post_parent WHERE r.post_type='shop_order_refund' AND o.ID IS NULL",
    'orders owned by a missing user' => "SELECT COUNT(*) FROM {$S['postmeta']} pm JOIN {$S['posts']} p ON p.ID=pm.post_id AND p.post_type='shop_order' LEFT JOIN {$S['users']} u ON u.ID=pm.meta_value WHERE pm.meta_key='_customer_user' AND pm.meta_value<>'0' AND u.ID IS NULL",
    'duplicate order item IDs' => "SELECT COUNT(*) - COUNT(DISTINCT order_item_id) FROM {$S['woocommerce_order_items']}",
];
$o = [];
foreach ($orph as $k => $sql) {
    $o[$k] = ['candidate' => (int) one($db, $sql), 'source' => (int) one($db, $srcOrph[$k])];
}
$check('no orphan or duplicate introduced (candidate counts equal the source\'s own)', !array_filter($o, fn ($v) => $v['candidate'] !== $v['source']), $o);
$staffOwners = (int) one($db, "SELECT COUNT(DISTINCT pm.meta_value) FROM {$S['postmeta']} pm JOIN {$S['posts']} p ON p.ID=pm.post_id AND p.post_type='shop_order' WHERE pm.meta_key='_customer_user' AND pm.meta_value IN (" . (implode(',', array_column($staffPolicy, 'id')) ?: '0') . ')');
$res['orders_owned_by_staff_policy_accounts'] = $staffOwners;

// HPOS compatibility mirror (posts authoritative; storage mode never changed)
$opt = fn ($T, $k) => one($db, "SELECT option_value FROM {$T['options']} WHERE option_name='$k'");
$hposMode = ['enabled' => [$opt($B, 'woocommerce_custom_orders_table_enabled'), $opt($D, 'woocommerce_custom_orders_table_enabled')], 'sync' => [$opt($B, 'woocommerce_custom_orders_table_data_sync_enabled'), $opt($D, 'woocommerce_custom_orders_table_data_sync_enabled')]];
$check('order storage mode unchanged (posts authoritative, compatibility sync as before)', $hposMode['enabled'][0] === $hposMode['enabled'][1] && $hposMode['sync'][0] === $hposMode['sync'][1], $hposMode);
$hpos = rows($db, "SELECT COUNT(*) n, SUM(o.id IS NULL) missing, SUM(o.id IS NOT NULL AND (BINARY o.status<>BINARY p.post_status OR CAST(COALESCE(NULLIF(t.meta_value,''),'0') AS DECIMAL(26,8))<>COALESCE(o.total_amount,0) OR CAST(COALESCE(NULLIF(c.meta_value,''),'0') AS UNSIGNED)<>COALESCE(o.customer_id,0))) mismatched
    FROM {$D['posts']} p JOIN {$W('scope_orders')} s ON s.id=p.ID LEFT JOIN {$D['wc_orders']} o ON o.id=p.ID
    LEFT JOIN {$D['postmeta']} t ON t.post_id=p.ID AND t.meta_key='_order_total' LEFT JOIN {$D['postmeta']} c ON c.post_id=p.ID AND c.meta_key='_customer_user'
    WHERE p.post_status NOT IN ('auto-draft','trash')")[0];
$check('HPOS mirror rows exist and agree with posts on status, total and customer for every legacy order', (int) $hpos['missing'] === 0 && (int) $hpos['mismatched'] === 0, $hpos);

// ---------------------------------------------------------------- protected: candidate vs baseline
$commerce = "'shop_order','shop_order_refund','shop_coupon'";
$prot = function (string $label, string $where) use ($db, $build, $H, $rowExpr, $B, $D, $before, $dest, $W, $sumBy, $rep): array {
    $pb = $build("p_{$label}_b", "SELECT p.ID, {$H($rowExpr($before, 'wp_posts', 'p'))}, 1 FROM {$B['posts']} p WHERE $where");
    $pd = $build("p_{$label}_d", "SELECT p.ID, {$H($rowExpr($dest, 'wp_posts', 'p'))}, 1 FROM {$D['posts']} p WHERE $where");
    $mb = $build("m_{$label}_b", $sumBy('pm.post_id', $H("CONCAT(pm.meta_key,0x1F,COALESCE(HEX(pm.meta_value),'~'))"), "{$B['postmeta']} pm JOIN {$B['posts']} p ON p.ID=pm.post_id WHERE $where"));
    $md = $build("m_{$label}_d", $sumBy('pm.post_id', $H("CONCAT(pm.meta_key,0x1F,COALESCE(HEX(pm.meta_value),'~'))"), "{$D['postmeta']} pm JOIN {$D['posts']} p ON p.ID=pm.post_id WHERE $where"));
    $rb = $build("r_{$label}_b", $sumBy('r.object_id', $H("CONCAT(r.term_taxonomy_id,0x1F,r.term_order)"), "{$B['term_relationships']} r JOIN {$B['posts']} p ON p.ID=r.object_id WHERE $where"));
    $rd = $build("r_{$label}_d", $sumBy('r.object_id', $H("CONCAT(r.term_taxonomy_id,0x1F,r.term_order)"), "{$D['term_relationships']} r JOIN {$D['posts']} p ON p.ID=r.object_id WHERE $where"));
    $cnt = fn ($x, $y) => ['baseline' => (int) one($db, "SELECT COUNT(*) FROM $x"), 'candidate' => (int) one($db, "SELECT COUNT(*) FROM $y"),
        'changed_or_missing' => (int) one($db, "SELECT COUNT(*) FROM $x a LEFT JOIN $y b ON b.id=a.id WHERE NOT (a.h<=>b.h AND a.n<=>b.n)"),
        'added' => (int) one($db, "SELECT COUNT(*) FROM $y b LEFT JOIN $x a ON a.id=b.id WHERE a.id IS NULL")];
    $ids = array_column(rows($db, "SELECT a.id FROM $pb a LEFT JOIN $pd b ON b.id=a.id WHERE NOT (a.h<=>b.h) UNION SELECT a.id FROM $mb a LEFT JOIN $md b ON b.id=a.id WHERE NOT (a.h<=>b.h AND a.n<=>b.n) LIMIT 1000"), 'id');
    write_csv("$rep/parity-protected-$label.csv", ['id'], array_map(fn ($i) => [$i], $ids));
    return ['rows' => $cnt($pb, $pd), 'meta' => $cnt($mb, $md), 'term_links' => $cnt($rb, $rd)];
};
$zero = fn ($p) => !array_filter($p, fn ($v) => $v['changed_or_missing'] + $v['added'] > 0);
$check('protected: every product and variation row, all product meta (prices, stock, SKU, ACF, images) and term links identical to the baseline',
    $zero($pp = $prot('products', "p.post_type IN ('product','product_variation')")), $pp);
$check('protected: every other destination-owned post (pages, menus, blocks, media, ACF groups, forms) and its meta identical',
    $zero($po = $prot('content', "p.post_type NOT IN ($commerce,'product','product_variation')")), $po);
$termSide = fn ($X, $schema) => one($db, "SELECT CONCAT(COUNT(*),'|',SUM({$H($rowExpr($schema, 'wp_terms', 't'))})) FROM {$X['terms']} t") . '/' . one($db, "SELECT CONCAT(COUNT(*),'|',SUM({$H($rowExpr($schema, 'wp_term_taxonomy', 'x'))})) FROM {$X['term_taxonomy']} x")
    . '/' . one($db, "SELECT CONCAT(COUNT(*),'|',COALESCE(SUM({$H($rowExpr($schema, 'wp_termmeta', 'm'))}),0)) FROM {$X['termmeta']} m");
$check('protected: terms, term taxonomy (incl. counts) and term meta identical', $termSide($B, $before) === $termSide($D, $dest), null);
$staffB = "SELECT u.ID FROM {$B['users']} u JOIN {$B['usermeta']} m ON m.user_id=u.ID AND m.meta_key='wp_capabilities' WHERE m.meta_value NOT LIKE '%\"customer\"%' AND m.meta_value NOT LIKE '%\"blocked_customer\"%'";
$staffSide = fn ($X, $schema) => one($db, "SELECT CONCAT(COUNT(*),'|',SUM({$H($rowExpr($schema, 'wp_users', 'u'))})) FROM {$X['users']} u WHERE u.ID IN ($staffB)") . '/'
    . one($db, "SELECT CONCAT(COUNT(*),'|',SUM({$H("CONCAT(um.user_id,0x1F,um.meta_key,0x1F,COALESCE(HEX(um.meta_value),'~'))")})) FROM {$X['usermeta']} um WHERE um.user_id IN ($staffB)");
$check('protected: destination staff accounts and their meta identical', $staffSide($B, $before) === $staffSide($D, $dest), null);
$volatile = "AND b.option_name NOT LIKE '\\_transient%' AND b.option_name NOT LIKE '\\_site\\_transient%' AND b.option_name NOT IN ('cron','rewrite_rules','dd_safety_blocked_log','action_scheduler_lock_async-request-runner')";
$optDiff = array_column(rows($db, "SELECT b.option_name FROM {$B['options']} b LEFT JOIN {$D['options']} a ON a.option_name=b.option_name AND a.option_value<=>b.option_value WHERE a.option_id IS NULL $volatile"), 'option_name');
$optAdded = array_column(rows($db, "SELECT b.option_name FROM {$D['options']} b LEFT JOIN {$B['options']} a ON a.option_name=b.option_name WHERE a.option_id IS NULL $volatile"), 'option_name');
// the only permitted option change: wp_user_roles gains exactly the verified group roles, nothing else
$rb = @unserialize((string) $opt($B, 'wp_user_roles'), ['allowed_classes' => false]);
$rd = @unserialize((string) $opt($D, 'wp_user_roles'), ['allowed_classes' => false]);
$rolesOk = is_array($rb) && is_array($rd) && array_diff_key($rd, $rb) == array_intersect_key($rd, array_flip($groups)) && array_intersect_key($rd, $rb) == $rb;
$other = array_values(array_diff($optDiff, ['wp_user_roles']));
$runtime = '/^(recovery_keys|external_updates-.*|wc_.*_notice.*|woocommerce_admin_notices|action_scheduler_migration_status|wc_blocks_db_schema_version|woocommerce_.*_hpos_.*|_wc_.*)$/';
// An option re-saved by plugin code with byte-different but identical data (e.g. Freemius re-serializing
// fs_accounts on load) counts as unchanged only if both values unserialize to exactly the same structure.
$flat = function ($x, string $p = '') use (&$flat): array {
    if (is_object($x)) {
        $x = (array) $x;
    }
    if (!is_array($x)) {
        return [$p => $x];
    }
    $o = [];
    foreach ($x as $k => $v) {
        $k = preg_replace('/^\0.*\0/', '', (string) $k);
        $o += $flat($v, $p === '' ? $k : "$p.$k");
    }
    return $o;
};
$reser = [];
foreach ($other as $o) {
    $vb = @unserialize((string) $opt($B, $o), ['allowed_classes' => false]);
    $vd = @unserialize((string) $opt($D, $o), ['allowed_classes' => false]);
    if ($vb === false || $vd === false) {
        continue;
    }
    $fb = $flat($vb);
    $fd = $flat($vd);
    $paths = array_values(array_filter(array_unique(array_merge(array_keys($fb), array_keys($fd))), fn ($k) => ($fb[$k] ?? null) !== ($fd[$k] ?? null)));
    // identical data, or only Freemius' per-plugin load timestamp (rewritten whenever a plugin loads)
    if (!array_filter($paths, fn ($k) => !preg_match('/^plugin_data\.[^.]+\.last_load_timestamp$/', $k))) {
        $reser[] = $paths ? "$o (" . implode(', ', $paths) . ')' : $o;
        $other = array_values(array_diff($other, [$o]));
    }
}
$res['options_reserialized_identical_data'] = $reser;
$res['options_changed_runtime_only'] = array_values(array_filter($other, fn ($o) => preg_match($runtime, $o)));
$other = array_values(array_filter($other, fn ($o) => !preg_match($runtime, $o)));
$check('protected: options identical except the permitted group-role addition (runtime state listed separately)', $rolesOk && !$other && !$optAdded,
    ['unexpected_changed' => $other, 'unexpected_added' => $optAdded, 'roles_added' => array_keys(array_diff_key($rd ?: [], $rb ?: [])), 'runtime_state' => $res['options_changed_runtime_only'], 'reserialized_identical_data' => $reser]);
$lookup = fn ($X, $schema, $t) => one($db, "SELECT CONCAT(COUNT(*),'|',COALESCE(SUM({$H($rowExpr($schema, $t, 'x'))}),0)) FROM `$schema`.`$t` x");
$check('protected: product lookup tables identical', $lookup($B, $before, 'wp_wc_product_meta_lookup') === $lookup($D, $dest, 'wp_wc_product_meta_lookup')
    && $lookup($B, $before, 'wp_wc_product_attributes_lookup') === $lookup($D, $dest, 'wp_wc_product_attributes_lookup'), null);

$res['failed_checks'] = $fail;
file_put_contents("$rep/parity-" . gmdate('Ymd\THis') . '.json', json_encode($res, JSON_PRETTY_PRINT));
out($fail ? "PARITY FAILED: $fail check(s)" : 'PARITY OK');
exit($fail ? 1 : 0);
