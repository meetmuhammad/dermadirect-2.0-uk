<?php
/**
 * Moves destination-owned records out of legacy's ID space and rewrites every reference to them.
 *   php renumber.php audit  --dest=dd_rh_dest --src=dd_rh_src   classify all hits, write reports, no writes
 *   php renumber.php apply  --dest=dd_rh_dest --src=dd_rh_src   one transaction; refuses if anything is unresolved
 *   php renumber.php verify --dest=dd_rh_dest --before=<db copy taken before apply>
 * Rerunnable: after a successful apply there are no candidates left, so apply is a no-op.
 * Plain PHP/SQL — WordPress is never bootstrapped.
 */
declare(strict_types=1);

namespace DDMig;

use function DDMig\Refs\{rewrite_int, rewrite_csv, rewrite_serialized, rewrite_patterns, rewrite_blocks, equivalent_under_map, tokens};

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/refs.php';
require_once __DIR__ . '/scan.php';

const COMMERCE_POST_TYPES = ['shop_order', 'shop_order_refund', 'shop_coupon', 'shop_order_placehold'];

/** Integer columns that hold a post ID / user ID (foreign keys into the namespace). */
const FK_COLUMNS = [
    'posts' => ['wp_posts.post_parent', 'wp_postmeta.post_id', 'wp_term_relationships.object_id', 'wp_comments.comment_post_ID'],
    'users' => ['wp_usermeta.user_id', 'wp_posts.post_author', 'wp_comments.user_id'],
];
/** Integer columns proven to belong to another ID space (own keys, or FKs to non-post/user tables). */
const OTHER_NS_COLUMNS = [
    'wp_woocommerce_order_items.order_item_id', 'wp_woocommerce_order_itemmeta.order_item_id', 'wp_wc_order_product_lookup.order_item_id',
    'wp_wdr_order_item_discounts.order_item_id', 'wp_comments.comment_ID', 'wp_automatewoo_log_meta.log_id', 'wp_gens_rafmeta.gens_raf_id',
    'wp_wpmailsmtp_email_tracking_links.email_log_id', // FK to wp_wpmailsmtp_emails_log.id
];
/** Integer columns holding a product/variation post ID outside the core FK set (moved only with --product-map). */
const PRODUCT_FK_COLUMNS = [
    'wp_wc_product_meta_lookup.product_id', 'wp_wc_product_attributes_lookup.product_id', 'wp_wc_product_attributes_lookup.product_or_parent_id',
    'wp_wc_order_product_lookup.product_id', 'wp_wc_order_product_lookup.variation_id', 'wp_wc_mnm_child_items.product_id', 'wp_wc_mnm_child_items.container_id',
    'wp_wc_reserved_stock.product_id', 'wp_wc_product_download_directories.product_id', 'wp_stock_log.product_id',
];

/**
 * Text rules for destination-owned rows. Keyed "table.column|context"; context is meta_key,
 * option_name or post_type. Value: [handler, arg]. Handlers: int, csv, ser(paths), pat(names),
 * blocks, derived (cache cleared instead of rewritten), nonref (reason).
 */
function text_rules(): array
{
    return [
        'posts' => [
            'wp_posts.guid|*' => ['pat', ['query_id', 'path_id']],
            'wp_posts.post_name|revision' => ['pat', ['revision_name']],
            'wp_posts.post_name|nav_menu_item' => ['pat', ['whole']],
            'wp_posts.post_content|*' => ['blocks', null],
            'wp_postmeta.meta_value|_thumbnail_id' => ['int', null],
            'wp_postmeta.meta_value|_menu_item_menu_item_parent' => ['int', null],
            'wp_postmeta.meta_value|_menu_item_object_id' => ['menu_object', null],
            'wp_postmeta.meta_value|_product_image_gallery' => ['csv', null],
            'wp_postmeta.meta_value|_megamenu' => ['ser', ['items/*/id', 'grid/*/columns/*/items/*/id', 'image_swap/id']],
            'wp_postmeta.meta_value|_wp_attachment_metadata' => ['nonref', 'attachment metadata holds sizes/dimensions/filenames, not IDs'],
            'wp_postmeta.meta_value|_dd_migration_eu_id' => ['nonref', 'EU-site post ID from the earlier EU import (foreign namespace)'],
            'wp_postmeta.meta_value|_elementor_data' => ['nonref', 'legacy Elementor element ids (hex) — not rendered by Sage'],
            'wp_options.option_value|page_on_front' => ['int', null],
            'wp_options.option_value|page_for_posts' => ['int', null],
            'wp_options.option_value|site_icon' => ['int', null],
            'wp_options.option_value|site_logo' => ['int', null],
            'wp_options.option_value|woocommerce_placeholder_image' => ['int', null],
            'wp_options.option_value|theme_mods_derma-direct' => ['ser', ['custom_css_post_id', 'custom_logo']],
            'wp_options.option_value|widget_maxmegamenu_image_swap' => ['ser', ['*/media_file_id']],
            'wp_options.option_value|generateblocks_dynamic_css_posts' => ['ser', ['@keys']],
            'wp_options.option_value|dd_migration_id_map' => ['ser', ['@values']],
            'wp_options.option_value|rewrite_rules' => ['derived', 'rewrite rules cache; emptied so WordPress regenerates it'],
            'wp_usermeta.meta_value|wp_dashboard_quick_press_last_post_id' => ['int', null],
        ],
        'users' => [
            'wp_postmeta.meta_value|_edit_last' => ['int', null],
            'wp_postmeta.meta_value|_edit_lock' => ['edit_lock', null],
        ],
    ];
}

/** Tables whose content is derived/transient/foreign — hits there are never references to preserve. */
const NONREF_TABLES = [
    'wp_actionscheduler_actions' => 'stale Action Scheduler queue (never executed; purged before launch per runbook)',
    'wp_actionscheduler_logs' => 'Action Scheduler log',
    'wp_woocommerce_sessions' => 'customer sessions (not migrated, expire)',
    'wp_wpmailsmtp_emails_log' => 'email log message ids/headers (hashes)',
    'wp_omnisend_contact_cache' => 'Omnisend contact ids (foreign namespace)',
    'wp_simple_history' => 'history occasion hashes',
    'wp_yith_wcwl_lists' => 'wishlist session ids (hashes)',
];

final class Classifier
{
    public array $acf;
    private array $srcCache = [];
    private array $postType = [];
    private array $metaCache = [];
    private array $userRoles = [];

    public function __construct(private \mysqli $db, private string $dest, private TableRef $src, private array $cands)
    {
        $f = report_dir() . '/acf-fields.json';
        $this->acf = is_file($f) ? json_decode(file_get_contents($f), true)['fields'] : fail("missing $f (run acf-export first)");
    }

    private function postType(int $id): ?string
    {
        return $this->postType[$id] ??= one($this->db, 'SELECT post_type FROM ' . t($this->dest, 'posts') . " WHERE ID=$id");
    }

    private function sibling(int $postId, string $key): ?string
    {
        $k = "$postId|$key";
        if (!array_key_exists($k, $this->metaCache)) {
            $this->metaCache[$k] = one($this->db, 'SELECT meta_value FROM ' . t($this->dest, 'postmeta') . " WHERE post_id=$postId AND meta_key=" . e($this->db, $key) . ' LIMIT 1');
        }
        return $this->metaCache[$k];
    }

    private function option(string $name): ?string
    {
        return one($this->db, 'SELECT option_value FROM ' . t($this->dest, 'options') . ' WHERE option_name=' . e($this->db, $name));
    }

    private function isStaffOrDestUser(int $uid): bool
    {
        $caps = $this->userRoles[$uid] ??= (string) one($this->db, 'SELECT meta_value FROM ' . t($this->dest, 'usermeta') . " WHERE user_id=$uid AND meta_key='wp_capabilities'");
        return !preg_match('/^a:1:\{s:(8:"customer"|16:"blocked_customer")/', $caps);
    }

    /** Same PK row in the source with the identical value => legacy data that predates the moved records. */
    private function identicalInSource(string $table, array $pk, string $col, string $value): bool
    {
        if (!isset($this->srcCache[$table])) {
            $this->srcCache[$table] = (bool) one($this->db, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=' . e($this->db, $this->src->schema) . ' AND table_name=' . e($this->db, $this->src->name($table)));
        }
        if (!$this->srcCache[$table]) {
            return false;
        }
        $w = implode(' AND ', array_map(fn ($k, $v) => "`$k`=" . e($this->db, $v), array_keys($pk), $pk));
        $sv = q($this->db, "SELECT `$col` FROM {$this->src[$table]} WHERE $w LIMIT 1")->fetch_row();
        return $sv !== null && (string) $sv[0] === $value;
    }

    /** ACF: meta/option K whose "_K" sibling names a field of a reference type. */
    private function acfType(?string $fieldKey): ?string
    {
        return $fieldKey ? ($this->acf[$fieldKey]['type'] ?? null) : null;
    }

    /**
     * Returns [class, handler, arg, reason]. class ∈ entity|fk|rewrite|nonref|legacy|derived|unresolved.
     */
    public function classify(string $ns, string $table, string $col, array $pk, array $ctx, string $kind): array
    {
        $tc = "$table.$col";
        if ($kind === 'int') {
            if (($ns === 'posts' && $tc === 'wp_posts.ID') || ($ns === 'users' && $tc === 'wp_users.ID')) {
                return ['entity', 'pk', null, 'the moved record itself'];
            }
            if ($ns === 'posts' && in_array($tc, PRODUCT_FK_COLUMNS, true)) {
                return ['fk', 'int', null, 'product ID column'];
            }
            if (in_array($tc, FK_COLUMNS[$ns], true)) {
                if ($tc === 'wp_term_relationships.object_id') {
                    return ['fk', 'int', null, 'term relationship of moved post'];
                }
                return ['fk', 'int', null, 'foreign key into ' . $ns];
            }
            $pkc = array_keys($pk);
            if (in_array($tc, OTHER_NS_COLUMNS, true) || (count($pkc) === 1 && $pkc[0] === $col) || str_ends_with($col, 'meta_id') || $col === 'umeta_id') {
                return ['nonref', null, null, 'different ID namespace (' . $tc . ')'];
            }
            return ['unresolved', null, null, "integer column $tc not classified"];
        }
        if (isset(NONREF_TABLES[$table])) {
            return ['nonref', null, null, NONREF_TABLES[$table]];
        }
        $value = (string) one($this->db, "SELECT `$col` FROM `{$this->dest}`.`$table` WHERE " . implode(' AND ', array_map(fn ($k, $v) => "`$k`=" . e($this->db, $v), array_keys($pk), $pk)));
        if ($this->identicalInSource($table, $pk, $col, $value)) {
            return ['legacy', null, null, 'identical to the source row (legacy data from before the destination records existed)'];
        }
        // Rows owned by legacy commerce entities are replaced by the sync; their numbers are legacy IDs.
        $ownerPost = match ($table) {
            'wp_postmeta', 'wp_wc_orders_meta' => (int) ($ctx['post_id'] ?? $ctx['order_id'] ?? 0),
            'wp_posts' => (int) $pk['ID'],
            'wp_comments' => (int) one($this->db, 'SELECT comment_post_ID FROM ' . t($this->dest, 'comments') . ' WHERE comment_ID=' . (int) $pk['comment_ID']),
            default => 0,
        };
        if (in_array($table, ['wp_wc_orders_meta', 'wp_wc_order_addresses', 'wp_wc_order_operational_data', 'wp_pp_order_actions_log', 'wp_woocommerce_bis_notificationsmeta'], true)) {
            return ['legacy', null, null, 'legacy-owned commerce/plugin record (replaced by the sync)'];
        }
        if ($ownerPost && in_array($this->postType($ownerPost), COMMERCE_POST_TYPES, true)) {
            return ['legacy', null, null, 'belongs to legacy ' . $this->postType($ownerPost) . " $ownerPost (replaced by the sync)"];
        }
        if ($table === 'wp_usermeta' && !$this->isStaffOrDestUser((int) $ctx['user_id'])) {
            return ['legacy', null, null, 'customer user meta (legacy-owned, replaced by the sync)'];
        }
        $key = $ctx['meta_key'] ?? $ctx['option_name'] ?? $ctx['post_type'] ?? '';
        if ($table === 'wp_options' && preg_match('/^_(site_)?transient_/', $key)) {
            return ['derived', 'transient', null, 'transient cache naming/holding a moved ID; deleted (with its timeout) so WordPress rebuilds it'];
        }
        $rules = text_rules()[$ns];
        $rule = $rules["$tc|$key"] ?? $rules["$tc|*"] ?? null;
        if ($rule === null && $table === 'wp_postmeta' && $ownerPost) {
            $type = $this->acfType($this->sibling($ownerPost, '_' . $key));
            if ($type !== null) {
                $rule = in_array($type, \DDMig\Refs\ACF_REF_TYPES, true) ? ['acf', $type] : ['unresolved', "ACF $type field '$key' contains a moved ID as text"];
            }
        }
        if ($rule === null && $table === 'wp_options' && str_starts_with($key, 'options_')) {
            $type = $this->acfType($this->option('_' . $key));
            if ($type !== null) {
                $rule = in_array($type, \DDMig\Refs\ACF_REF_TYPES, true) ? ['acf', $type] : ['unresolved', "ACF option $type '$key' contains a moved ID as text"];
            }
        }
        if ($rule === null) {
            return ['unresolved', null, null, "no rule for $tc|$key"];
        }
        [$h, $arg] = $rule;
        return match ($h) {
            'nonref' => ['nonref', null, null, $arg],
            'derived' => ['derived', 'derived', null, $arg],
            'unresolved' => ['unresolved', null, null, $arg],
            default => ['rewrite', $h, $arg, "rule $tc|$key"],
        };
    }

    /** Computes the rewritten value for a 'rewrite' hit, or returns unresolved reasons. */
    public function rewrite(string $handler, mixed $arg, string $value, array $map, array $ctx): array
    {
        switch ($handler) {
            case 'int':
                $nv = rewrite_int($value, $map);
                return ['value' => $nv, 'unresolved' => $nv === $value ? ['not a whole-integer value'] : []];
            case 'csv':
                return ['value' => rewrite_csv($value, $map), 'unresolved' => []];
            case 'menu_object':
                $type = $this->sibling((int) $ctx['post_id'], '_menu_item_type');
                // Custom links store their own item ID as object id (WordPress core behaviour).
                if ($type === 'custom' && trim($value) === (string) $ctx['post_id']) {
                    return ['value' => rewrite_int($value, $map), 'unresolved' => []];
                }
                if ($type !== 'post_type') {
                    return ['value' => $value, 'unresolved' => ["_menu_item_object_id with _menu_item_type=$type is not a post ID"]];
                }
                return ['value' => rewrite_int($value, $map), 'unresolved' => []];
            case 'edit_lock':
                return preg_match('/^([0-9]+):([0-9]+)$/', $value, $m) && isset($map[(int) $m[2]])
                    ? ['value' => $m[1] . ':' . $map[(int) $m[2]], 'unresolved' => []] : ['value' => $value, 'unresolved' => ['unexpected _edit_lock shape']];
            case 'ser':
                return rewrite_serialized($value, $map, $arg);
            case 'pat':
                return rewrite_patterns($value, $map, $arg);
            case 'blocks':
                return rewrite_blocks($value, $map, $this->acf);
            case 'acf':
                if (ctype_digit(trim($value))) {
                    return ['value' => rewrite_int($value, $map), 'unresolved' => []];
                }
                return rewrite_serialized($value, $map, ['@values']);
            case 'derived':
            case 'transient':
                return ['value' => '', 'unresolved' => []];
        }
        return ['value' => $value, 'unresolved' => ["unknown handler $handler"]];
    }
}

/** Explicit post targets (--product-map: destination product -> verified legacy product ID); others use the offset. */
function product_map(?array $set = null): array
{
    static $m = [];
    return $set === null ? $m : $m = $set;
}

function build_map(array $cands): array
{
    $m = [];
    foreach ($cands as $ns => $ids) {
        foreach ($ids as $id) {
            $m[$ns][$id] = ($ns === 'posts' ? product_map()[$id] ?? null : null) ?? $id + (int) cfg()['relocate_offset'][$ns];
        }
    }
    return $m;
}

/** Runs the scan and classification; returns [hits, rewrites, unresolved]. */
function audit(\mysqli $db, string $dest, TableRef $src, array $cands): array
{
    $maps = build_map($cands);
    $cl = new Classifier($db, $dest, $src, $cands);
    $hits = [];
    $rewrites = [];
    $unresolved = [];
    foreach (scan($db, $dest, $cands) as [$ns, $table, $col, $pkJson, $id, $ctxJson, $kind]) {
        $pk = json_decode($pkJson, true);
        $ctx = json_decode($ctxJson, true);
        [$class, $handler, $arg, $reason] = $cl->classify($ns, $table, $col, $pk, $ctx, $kind);
        $hits[] = [$ns, $table, $col, $pkJson, $id, $class, $reason, $ctx['meta_key'] ?? $ctx['option_name'] ?? $ctx['post_type'] ?? ''];
        if ($class === 'unresolved') {
            $unresolved[] = end($hits);
            continue;
        }
        if ($class === 'rewrite' || $class === 'derived') {
            $rk = "$table|$col|$pkJson";
            if (!isset($rewrites[$rk])) {
                $where = implode(' AND ', array_map(fn ($k, $v) => "`$k`=" . e($db, $v), array_keys($pk), $pk));
                $value = (string) one($db, "SELECT `$col` FROM `$dest`.`$table` WHERE $where");
                // A value may hold IDs of both namespaces; apply every namespace's map in turn.
                $r = ['value' => $value, 'unresolved' => []];
                foreach ($maps as $mns => $map) {
                    if (!tokens($r['value'], $map) && $mns !== $ns) {
                        continue;
                    }
                    [$c2, $h2, $a2] = $mns === $ns ? [$class, $handler, $arg] : $cl->classify($mns, $table, $col, $pk, $ctx, $kind);
                    if ($c2 !== 'rewrite' && $c2 !== 'derived') {
                        continue;
                    }
                    $step = $cl->rewrite($h2, $a2, $r['value'], $map, $ctx);
                    $r = ['value' => $step['value'], 'unresolved' => array_merge($r['unresolved'], $step['unresolved'])];
                }
                if ($r['unresolved']) {
                    foreach ($r['unresolved'] as $u) {
                        $unresolved[] = [$ns, $table, $col, $pkJson, $id, 'unresolved', "rewrite: $u", end($hits)[7]];
                    }
                    continue;
                }
                $rewrites[$rk] = [$table, $col, $pk, $value, $r['value'], $handler];
            }
        }
    }
    return [$hits, $rewrites, $unresolved, $maps];
}

function write_audit_reports(array $hits, array $rewrites, array $unresolved, array $maps): array
{
    $d = report_dir();
    write_csv("$d/renumber-hits.csv", ['ns', 'table', 'column', 'pk', 'id', 'class', 'reason', 'key'], $hits);
    write_csv("$d/renumber-unresolved.csv", ['ns', 'table', 'column', 'pk', 'id', 'class', 'reason', 'key'], $unresolved);
    write_csv("$d/renumber-rewrites.csv", ['table', 'column', 'pk', 'handler', 'before_len', 'after_len'], array_map(fn ($r) => [$r[0], $r[1], json_encode($r[2]), $r[5], strlen($r[3]), strlen($r[4])], $rewrites));
    $by = [];
    foreach ($hits as $h) {
        $by[$h[5]] = ($by[$h[5]] ?? 0) + 1;
    }
    ksort($by);
    return ['hits' => count($hits), 'by_class' => $by, 'rewrites' => count($rewrites), 'unresolved' => count($unresolved),
        'moved_posts' => count($maps['posts'] ?? []), 'moved_users' => count($maps['users'] ?? [])];
}

/** Relocated band must sit above legacy's max (with margin) and below the future range, and be free. */
function preflight_renumber(\mysqli $db, string $dest, TableRef $src, array $maps): void
{
    $c = cfg();
    foreach (['posts' => 'posts', 'users' => 'users'] as $ns => $tbl) {
        // explicit product targets sit inside legacy space by design; they are checked by check_product_map()
        $band = $ns === 'posts' ? array_diff_key($maps[$ns] ?? [], product_map()) : ($maps[$ns] ?? []);
        if (!$band) {
            continue;
        }
        $lo = min($band);
        $hi = max($band);
        $future = (int) $c['future_start']['wp_' . $tbl];
        $maxSrc = (int) one($db, "SELECT MAX(ID) FROM {$src[$tbl]}");
        $margin = max((int) ceil($maxSrc * $c['headroom_margin_ratio']), (int) $c['headroom_margin_min']);
        if ($maxSrc + $margin >= $lo) {
            fail("source $tbl max $maxSrc (+margin $margin) reaches relocation band starting $lo");
        }
        if ($hi >= $future) {
            fail("relocation band for $tbl ends at $hi, not below future_start $future");
        }
        $taken = (int) one($db, 'SELECT COUNT(*) FROM ' . t($dest, $tbl) . " WHERE ID BETWEEN $lo AND $hi");
        if ($taken) {
            fail("$taken destination $tbl rows already occupy the relocation band $lo..$hi");
        }
    }
}

function apply(\mysqli $db, string $dest, TableRef $src, string $run, array $cands): array
{
    [$hits, $rewrites, $unresolved, $maps] = audit($db, $dest, $src, $cands);
    $summary = write_audit_reports($hits, $rewrites, $unresolved, $maps);
    if ($unresolved) {
        fail(count($unresolved) . ' unresolved references — see renumber-unresolved.csv; nothing was written');
    }
    if (!$maps) {
        out('nothing to move (already renumbered)');
        return $summary + ['applied' => false];
    }
    preflight_renumber($db, $dest, $src, $maps);
    $P = t($dest, 'posts');
    $PM = t($dest, 'postmeta');
    $U = t($dest, 'users');
    $UM = t($dest, 'usermeta');
    q($db, 'START TRANSACTION');
    try {
        foreach ($maps as $ns => $map) {
            foreach (array_chunk($map, 500, true) as $chunk) {
                $vals = [];
                foreach ($chunk as $old => $new) {
                    $kind = $ns === 'posts' ? (string) one($db, "SELECT post_type FROM $P WHERE ID=$old") : 'user';
                    $vals[] = '(' . e($db, $ns) . ",$old,$new," . e($db, $kind) . ',' . e($db, isset(product_map()[$old]) ? "destination product moved to its verified legacy product ID (--product-map, run $run)" : "destination-owned record inside legacy ID space (run $run)") . ', UTC_TIMESTAMP())';
                }
                q($db, "INSERT INTO `$dest`.dd_mig_idmap (ns, old_id, new_id, kind, reason, created_at) VALUES " . implode(',', $vals));
            }
        }
        // 1) value rewrites, addressed by the row's current (old) primary key, optimistic on old value
        $n = 0;
        foreach ($rewrites as [$table, $col, $pk, $old, $new, $handler]) {
            $where = implode(' AND ', array_map(fn ($k, $v) => "`$k`=" . e($db, $v), array_keys($pk), $pk));
            if ($handler === 'transient') {
                $name = (string) one($db, "SELECT option_name FROM `$dest`.`$table` WHERE $where");
                q($db, "DELETE FROM `$dest`.`$table` WHERE $where AND `$col`=" . e($db, $old));
                $ok = $db->affected_rows === 1;
                q($db, "DELETE FROM `$dest`.`$table` WHERE option_name=" . e($db, preg_replace('/^_(site_)?transient_/', '_$1transient_timeout_', $name)));
                if (!$ok) {
                    throw new \RuntimeException("delete of transient $name affected no row");
                }
                $n++;
                continue;
            }
            q($db, "UPDATE `$dest`.`$table` SET `$col`=" . e($db, $new) . " WHERE $where AND `$col`=" . e($db, $old));
            if ($db->affected_rows !== 1) {
                throw new \RuntimeException("rewrite of $table.$col " . json_encode($pk) . ' affected ' . $db->affected_rows . ' rows');
            }
            $n++;
        }
        // 2) foreign keys and 3) primary keys, set-based through THIS RUN's mappings only.
        // (Joining the whole persisted map would re-apply earlier mappings to legacy records that now
        // legitimately occupy the vacated IDs — found by tests/guards_test.sh on a second run.)
        q($db, 'DROP TEMPORARY TABLE IF EXISTS dd_mig_cur_map');
        q($db, 'CREATE TEMPORARY TABLE dd_mig_cur_map (ns VARCHAR(32) NOT NULL, old_id BIGINT UNSIGNED NOT NULL, new_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY (ns, old_id))');
        foreach ($maps as $ns => $map) {
            foreach (array_chunk($map, 500, true) as $chunk) {
                q($db, 'INSERT INTO dd_mig_cur_map VALUES ' . implode(',', array_map(fn ($o, $n) => '(' . e($db, $ns) . ",$o,$n)", array_keys($chunk), $chunk)));
            }
        }
        $M = 'dd_mig_cur_map';
        // [table, column, namespace] in update order (FKs before the primary keys they point at)
        $cols = [['wp_posts', 'post_parent', 'posts'], ['wp_postmeta', 'post_id', 'posts'], ['wp_term_relationships', 'object_id', 'posts'],
            ['wp_comments', 'comment_post_ID', 'posts'], ['wp_posts', 'ID', 'posts'], ['wp_usermeta', 'user_id', 'users'],
            ['wp_posts', 'post_author', 'users'], ['wp_comments', 'user_id', 'users'], ['wp_users', 'ID', 'users']];
        foreach (product_map() ? PRODUCT_FK_COLUMNS : [] as $tc) {
            [$tb, $col] = explode('.', $tc);
            if (one($db, 'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=' . e($db, $dest) . ' AND table_name=' . e($db, $tb) . ' AND column_name=' . e($db, $col))) {
                $cols[] = [$tb, $col, 'posts'];
            }
        }
        record_refs($db, $dest, $run, $cols, $maps);
        $fkCounts = [];
        foreach ($cols as [$tb, $col, $ns]) {
            q($db, "UPDATE `$dest`.`$tb` x JOIN $M m ON m.ns='$ns' AND m.old_id=x.`$col` SET x.`$col`=m.new_id");
            $fkCounts[] = $db->affected_rows;
        }
        q($db, "INSERT INTO `$dest`.dd_mig_ledger (run_id, entity, entity_id, action, note) VALUES (" . e($db, $run) . ", 'renumber', 0, 'apply', " . e($db, json_encode(['value_rewrites' => $n, 'fk_rows' => $fkCounts])) . ')');
        q($db, 'COMMIT');
    } catch (\Throwable $t) {
        q($db, 'ROLLBACK');
        fail('renumber rolled back: ' . $t->getMessage());
    }
    return $summary + ['applied' => true, 'value_rewrites' => $n, 'fk_rows' => $fkCounts];
}

/** Canonical primary-key string of a row (key order and int/string typing do not matter). */
function pk_canon(array $pk): string
{
    ksort($pk);
    return implode(';', array_map(fn ($k, $v) => "$k=$v", array_keys($pk), $pk));
}

/**
 * Records, before the set-based updates, every key/FK cell this run rewrites: table, column, the row's primary key
 * AFTER the run (moved key columns mapped), old and new ID. Reversible record and the residual check's provenance.
 */
function record_refs(\mysqli $db, string $dest, string $run, array $cols, array $maps): void
{
    $moved = [];
    foreach ($cols as [$tb, $col, $ns]) {
        $moved[$tb][$col] = $ns;
    }
    foreach ($cols as [$tb, $col, $ns]) {
        $pkc = pk($db, $dest, $tb) ?: fail("$tb has no primary key; cannot record provenance");
        $sel = implode(',', array_map(fn ($c) => "x.`$c`", $pkc));
        $vals = [];
        $res = q($db, "SELECT $sel, x.`$col` AS __old, m.new_id AS __new FROM `$dest`.`$tb` x JOIN dd_mig_cur_map m ON m.ns='$ns' AND m.old_id=x.`$col`");
        while ($r = $res->fetch_assoc()) {
            $pk = [];
            foreach ($pkc as $c) {
                $n = $moved[$tb][$c] ?? null;
                $pk[$c] = $n !== null && isset($maps[$n][(int) $r[$c]]) ? (string) $maps[$n][(int) $r[$c]] : (string) $r[$c];
            }
            $vals[] = '(' . implode(',', [e($db, $run), e($db, $ns), e($db, $tb), e($db, $col), e($db, pk_canon($pk)), (int) $r['__old'], (int) $r['__new']]) . ')';
        }
        foreach (array_chunk($vals, 1000) as $ch) {
            q($db, "INSERT INTO `$dest`.dd_mig_refs (run_id, ns, tbl, col, pk, old_id, new_id) VALUES " . implode(',', $ch));
        }
    }
}

/**
 * Mapping-aware comparison of the destination against a copy taken before apply: every row of the
 * compared tables must be identical, or differ only by mapped-ID substitutions (primary keys mapped).
 */
function verify(\mysqli $db, string $dest, string $before): array
{
    $maps = [];
    foreach (rows($db, "SELECT ns, old_id, new_id FROM `$dest`.dd_mig_idmap") as $r) {
        $maps[$r['ns']][(int) $r['old_id']] = (int) $r['new_id'];
    }
    $all = ($maps['posts'] ?? []) + ($maps['users'] ?? []);
    $tables = ['posts' => 'ID', 'postmeta' => 'meta_id', 'options' => 'option_id', 'term_relationships' => null, 'termmeta' => 'meta_id',
        'terms' => 'term_id', 'term_taxonomy' => 'term_taxonomy_id', 'usermeta' => 'umeta_id', 'users' => 'ID', 'comments' => 'comment_ID'];
    $res = [];
    $changes = []; // every row that differs only by mapped IDs: the explicit list of mapping changes
    foreach ($tables as $tb => $pk) {
        $B = t($before, $tb);
        $A = t($dest, $tb);
        $cnt = [(int) one($db, "SELECT COUNT(*) FROM $B"), (int) one($db, "SELECT COUNT(*) FROM $A")];
        if ($pk === null) {
            // composite PK: compare as a set after mapping object_id
            $diff = (int) one($db, "SELECT COUNT(*) FROM $B b LEFT JOIN `$dest`.dd_mig_idmap m ON m.ns='posts' AND m.old_id=b.object_id
                LEFT JOIN $A a ON a.object_id=COALESCE(m.new_id,b.object_id) AND a.term_taxonomy_id=b.term_taxonomy_id AND a.term_order=b.term_order WHERE a.object_id IS NULL");
            $res[$tb] = ['rows' => $cnt, 'mismatched' => $diff];
            continue;
        }
        $pkMapNs = match ($tb) { 'posts' => 'posts', 'users' => 'users', default => null };
        $cols = array_column(rows($db, "SELECT column_name c FROM information_schema.columns WHERE table_schema=" . e($db, $dest) . " AND table_name=" . e($db, cfg()['prefix'] . $tb) . ' ORDER BY ordinal_position'), 'c');
        $sel = implode(',', array_map(fn ($c) => "b.`$c` AS `b_$c`, a.`$c` AS `a_$c`", $cols));
        $join = $pkMapNs ? "LEFT JOIN `$dest`.dd_mig_idmap m ON m.ns='$pkMapNs' AND m.old_id=b.`$pk` LEFT JOIN $A a ON a.`$pk`=COALESCE(m.new_id,b.`$pk`)"
            : "LEFT JOIN $A a ON a.`$pk`=b.`$pk`";
        $diffCond = implode(' OR ', array_map(fn ($c) => "NOT (b.`$c` <=> a.`$c`)", $cols));
        $bad = 0;
        $changed = 0;
        $samples = [];
        $r = q($db, "SELECT $sel FROM $B b $join WHERE a.`$pk` IS NULL OR $diffCond");
        while ($row = $r->fetch_assoc()) {
            // transient caches naming a moved ID are deleted by apply (WordPress rebuilds them)
            $ok = $row["a_$pk"] !== null || ($tb === 'options' && preg_match('/^_(site_)?transient_/', (string) $row['b_option_name']));
            foreach ($row["a_$pk"] === null ? [] : $cols as $c) {
                if ($ok && $row["b_$c"] !== $row["a_$c"] && !equivalent_under_map((string) $row["b_$c"], (string) $row["a_$c"], $all)) {
                    // Derived caches are allowed to be emptied.
                    $ok = ($tb === 'options' && $c === 'option_value' && ($row['b_option_name'] ?? '') === 'rewrite_rules' && $row["a_$c"] === '');
                }
            }
            if ($ok) {
                $diff = array_values(array_filter($cols, fn ($c) => $row["a_$pk"] !== null && $row["b_$c"] !== $row["a_$c"]));
                $changes[] = [$tb, $row["b_$pk"], $row["a_$pk"] ?? '(deleted)', implode('+', $diff ?: ['row']), $row['b_post_type'] ?? $row['b_meta_key'] ?? $row['b_option_name'] ?? '', $row['b_post_id'] ?? ''];
            }
            $ok ? $changed++ : $bad++;
            if (!$ok && count($samples) < 10) {
                $samples[] = [$pk => $row["b_$pk"]];
            }
        }
        $res[$tb] = ['rows' => $cnt, 'changed_only_by_mapping' => $changed, 'mismatched' => $bad, 'samples' => $samples];
    }
    write_csv(report_dir() . '/renumber-verify-changes.csv', ['table', 'pk_before', 'pk_after', 'changed_columns', 'type_or_key', 'post_id_before'], $changes);
    return $res;
}

/**
 * Files named after moved IDs (outside the database). Known: GenerateBlocks per-post CSS
 * uploads/generateblocks/style-{post_id}.css (content has no IDs). Copied, not renamed, because the
 * uploads folder may be shared with another database that still uses the old IDs. Any other upload
 * whose name carries a moved ID is unresolved. Idempotent.
 */
function renumber_files(\mysqli $db, string $dest, string $uploads, bool $apply): array
{
    $map = [];
    foreach (rows($db, "SELECT old_id, new_id FROM `$dest`.dd_mig_idmap WHERE ns='posts'") as $r) {
        $map[(int) $r['old_id']] = (int) $r['new_id'];
    }
    $res = ['copied' => [], 'already' => [], 'unresolved' => []];
    if (!$map || !is_dir($uploads)) {
        return $res + ['note' => $map ? "uploads dir $uploads not found" : 'nothing mapped'];
    }
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($uploads, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($uploads) + 1);
        if (!\DDMig\Refs\tokens(basename($rel), $map)) {
            continue;
        }
        if (preg_match('#^generateblocks/style-([0-9]+)\.css$#', $rel, $m) && isset($map[(int) $m[1]])) {
            $target = "$uploads/generateblocks/style-" . $map[(int) $m[1]] . '.css';
            if (is_file($target) && md5_file($target) === md5_file($f->getPathname())) {
                $res['already'][] = $rel;
            } elseif ($apply) {
                copy($f->getPathname(), $target) && md5_file($target) === md5_file($f->getPathname()) ? $res['copied'][] = "$rel -> " . basename($target) : $res['unresolved'][] = "copy failed: $rel";
            } else {
                $res['copied'][] = "(would copy) $rel -> " . basename($target);
            }
            continue;
        }
        $res['unresolved'][] = $rel;
    }
    return $res;
}

/**
 * --product-map=<csv destination_id,legacy_id>: an approved, explicit identity correction (2026-10-20) for destination
 * products that occupy legacy order IDs. Every row must prove identity and a free target, or nothing runs:
 * destination row is a product candidate; the legacy row at the target is a product of the same type with the same
 * SKU and title; the legacy row at the destination ID is not a product; the target is unused in the destination.
 */
function check_product_map(\mysqli $db, string $dest, TableRef $src, string $file, array $prod): array
{
    $pm = [];
    foreach (array_slice(array_map('str_getcsv', file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)), 1) as $r) {
        [$from, $to] = [(int) $r[0], (int) $r[1]];
        $err = fn ($m) => fail("--product-map $from -> $to: $m");
        if ((int) one($db, "SELECT COUNT(*) FROM `$dest`.dd_mig_idmap WHERE ns='posts' AND old_id=$from AND new_id=$to")) {
            continue; // already applied by an earlier run (rerun is a no-op)
        }
        isset($prod[$from]) || $err('not a destination product candidate');
        (!isset($pm[$from]) && !in_array($to, $pm, true)) || $err('duplicate row or target');
        $l = rows($db, "SELECT post_type, post_title, (SELECT meta_value FROM {$src['postmeta']} WHERE post_id=$to AND meta_key='_sku' LIMIT 1) sku FROM {$src['posts']} WHERE ID=$to")[0] ?? $err('no legacy record at target');
        [$type, , $title, $sku] = $prod[$from];
        ($l['post_type'] === $type && $l['post_title'] === $title && (string) $l['sku'] === $sku && $sku !== '') || $err('legacy type/title/SKU does not match');
        in_array((string) one($db, "SELECT post_type FROM {$src['posts']} WHERE ID=$from"), ['product', 'product_variation'], true) && $err('legacy record at the destination ID is a product');
        // (legacy order lines that already name the legacy product ID, e.g. order_product_lookup.product_id, are the
        // references this correction repairs, so they are not "in use")
        foreach ([['posts', 'ID'], ['posts', 'post_parent'], ['postmeta', 'post_id'], ['term_relationships', 'object_id'], ['comments', 'comment_post_ID'],
            ['wc_orders', 'id'], ['wc_orders', 'parent_order_id'], ['wc_product_meta_lookup', 'product_id'], ['wc_product_attributes_lookup', 'product_id'],
            ['wc_product_attributes_lookup', 'product_or_parent_id'], ['stock_log', 'product_id'], ['wc_reserved_stock', 'product_id']] as [$tb, $col]) {
            if (one($db, 'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=' . e($db, $dest) . ' AND table_name=' . e($db, cfg()['prefix'] . $tb) . ' AND column_name=' . e($db, $col))) {
                (int) one($db, 'SELECT COUNT(*) FROM ' . t($dest, $tb) . " WHERE `$col`=$to") && $err("target in use in $tb.$col");
            }
        }
        (int) one($db, "SELECT COUNT(*) FROM `$dest`.dd_mig_idmap WHERE ns='posts' AND new_id=$to") && $err('target already used by an earlier mapping');
        $pm[$from] = $to;
    }
    return $pm;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $a = args($argv);
    $mode = $a['_'][0] ?? fail('mode required: audit|apply|verify');
    $dest = $a['dest'] ?? fail('--dest required');
    guard_dbs($dest, null, $a);
    $db = connect($dest);
    ensure_state_tables($db, $dest);
    $t0 = microtime(true);
    if ($mode === 'verify') {
        $before = $a['before'] ?? fail('--before required');
        $res = verify($db, $dest, $before);
        file_put_contents(report_dir() . '/renumber-verify.json', json_encode($res, JSON_PRETTY_PRINT));
        $bad = array_sum(array_column($res, 'mismatched'));
        foreach ($res as $tb => $r) {
            out(str_pad($tb, 20) . json_encode(array_diff_key($r, ['samples' => 1])));
        }
        out(($bad ? "VERIFY FAILED: $bad mismatched rows" : 'VERIFY OK') . sprintf(' (%.1fs)', microtime(true) - $t0));
        exit($bad ? 1 : 0);
    }
    if ($mode === 'files') {
        $r = renumber_files($db, $dest, $a['uploads'] ?? '/var/www/html/web/app/uploads', !isset($a['dry-run']));
        file_put_contents(report_dir() . '/renumber-files.json', json_encode($r, JSON_PRETTY_PRINT));
        out('files: ' . json_encode($r));
        exit($r['unresolved'] ? 1 : 0);
    }
    $src = src_ref($a);
    guard_dbs($dest, $src, $a);
    if ((int) one($db, "SELECT GET_LOCK('dd_migration', 0)") !== 1) {
        fail('another migration run holds the dd_migration lock');
    }
    if ($mode === 'residual') {
        // Rescan for the OLD ids of everything that was moved: no FK/PK/reference-rule hit may remain.
        $old = [];
        foreach (rows($db, "SELECT ns, old_id FROM `$dest`.dd_mig_idmap") as $r) {
            $old[$r['ns']][] = (int) $r['old_id'];
        }
        [$hits, , $unresolved] = audit($db, $dest, $src, $old);
        // An old ID that a later mapping legitimately re-occupies (e.g. a vacated attachment ID that is the verified
        // legacy ID of a moved product): its own row and FK rows belong to the new holder; value references still fail.
        // Accepted only when that exact cell (table, column, row key, ID) was written by renumber itself (dd_mig_refs).
        $prov = [];
        foreach (rows($db, "SELECT r.ns, r.tbl, r.col, r.pk, r.new_id FROM `$dest`.dd_mig_refs r JOIN `$dest`.dd_mig_idmap i ON i.ns=r.ns AND i.old_id=r.new_id") as $r) {
            $prov["{$r['ns']}|{$r['tbl']}|{$r['col']}|{$r['pk']}|{$r['new_id']}"] = true;
        }
        // After the sync, a vacated old ID legitimately holds the imported LEGACY record: such a hit passes only when the
        // whole referencing row is identical, column for column by primary key, to the source row (it is the legacy record).
        $cols = [];
        $legacyRow = function (string $table, array $pk) use ($db, $dest, $src, &$cols): bool {
            if (!isset($cols[$table])) {
                $have = (bool) one($db, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=' . e($db, $src->schema) . ' AND table_name=' . e($db, $src->name($table)));
                $cols[$table] = $have ? array_column(rows($db, 'SELECT d.column_name c FROM information_schema.columns d JOIN information_schema.columns s ON s.column_name=d.column_name AND s.table_schema='
                    . e($db, $src->schema) . ' AND s.table_name=' . e($db, $src->name($table)) . ' WHERE d.table_schema=' . e($db, $dest) . ' AND d.table_name=' . e($db, $table)), 'c') : [];
            }
            if (!$cols[$table]) {
                return false;
            }
            $where = implode(' AND ', array_map(fn ($k, $v) => "d.`$k`=" . e($db, $v), array_keys($pk), $pk));
            $same = implode(' AND ', array_map(fn ($c) => "d.`$c` <=> s.`$c`", $cols[$table]));
            $on = implode(' AND ', array_map(fn ($k) => "s.`$k`=d.`$k`", array_keys($pk)));
            return (int) one($db, "SELECT COUNT(*) FROM `$dest`.`$table` d JOIN {$src[$table]} s ON $on WHERE $where AND $same") === 1;
        };
        $res = ['provenance' => 0, 'legacy_identical' => 0];
        $bad = array_filter($hits, function ($h) use ($prov, $legacyRow, &$res) {
            if (in_array($h[5], ['rewrite', 'unresolved'], true)) {
                return true;
            }
            if (!in_array($h[5], ['entity', 'fk'], true)) {
                return false;
            }
            $pk = json_decode($h[3], true);
            if (isset($prov["$h[0]|$h[1]|$h[2]|" . pk_canon($pk) . "|$h[4]"])) {
                $res['provenance']++;
                return false;
            }
            if ($legacyRow($h[1], $pk)) {
                $res['legacy_identical']++;
                return false;
            }
            return true;
        });
        out('residual entity/fk hits accepted: ' . json_encode($res));
        write_csv(report_dir() . '/renumber-residual.csv', ['ns', 'table', 'column', 'pk', 'id', 'class', 'reason', 'key'], $hits);
        $by = array_count_values(array_column($hits, 5));
        out('residual hits by class: ' . json_encode($by) . ' — ' . ($bad ? count($bad) . ' REMAINING REFERENCES (FAIL)' : 'no remaining references (OK)'));
        exit($bad ? 1 : 0);
    }
    $cands = candidates($db, $dest, $src);
    // Product guard: destination products/variations are never relocated. Without --skip-products the run
    // stops; with it they stay at their IDs, are listed, and the legacy records they block are left to the
    // sync's explicit isolation (sync.php --isolate-product-conflicts) pending a decision.
    $prod = product_candidates($db, $dest, $cands['posts']);
    if ($prod) {
        write_csv(report_dir() . '/renumber-product-collisions.csv', ['destination_id', 'type', 'status', 'title', 'sku'],
            array_map(fn ($id, $p) => [$id, ...$p], array_keys($prod), $prod));
        if (!isset($a['skip-products']) && !isset($a['product-map'])) {
            fail(count($prod) . ' destination products/variations sit in legacy ID space (renumber-product-collisions.csv). Products are never relocated: decide, or pass --skip-products to move only non-product records');
        }
        $pm = isset($a['product-map']) ? check_product_map($db, $dest, $src, $a['product-map'], $prod) : [];
        product_map($pm);
        $cands['posts'] = array_values(array_diff($cands['posts'], array_diff(array_keys($prod), array_keys($pm))));
        out('product guard: ' . count($pm) . ' products moved to verified legacy IDs (--product-map), ' . (count($prod) - count($pm)) . ' left in place');
    }
    out('candidates: posts=' . count($cands['posts']) . ' users=' . count($cands['users']));
    $run = start_run($db, $dest, "renumber-$mode", $a);
    if ($mode === 'audit') {
        [$hits, $rewrites, $unresolved, $maps] = audit($db, $dest, $src, $cands);
        $s = write_audit_reports($hits, $rewrites, $unresolved, $maps);
    } elseif ($mode === 'apply') {
        $s = apply($db, $dest, $src, $run, $cands);
    } else {
        fail("unknown mode $mode");
    }
    $s['seconds'] = round(microtime(true) - $t0, 1);
    finish_run($db, $dest, $run, 'ok', $s);
    out(json_encode($s));
}
