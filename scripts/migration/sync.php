<?php
/**
 * Legacy -> destination delta sync (plain SQL; WordPress is never bootstrapped, so no emails,
 * webhooks, payments, stock deductions or recalculated totals can happen).
 *   php sync.php plan  --src=dd_rh_src --dest=dd_rh_dest            classify every record, no writes
 *   php sync.php apply --src=dd_rh_src --dest=dd_rh_dest [--batch=500] [--only=users,orders,...]
 * Change detection hashes each record's full content on both sides (no reliance on timestamps), so
 * metadata-only changes are found and an identical rerun does nothing. Each batch is one
 * transaction: an interrupted run is resumed by running apply again.
 * Must run after renumber.php (legacy IDs are kept; destination-owned records no longer collide).
 */
declare(strict_types=1);

namespace DDMig;

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/headroom.php';
require_once __DIR__ . '/refs.php';
require_once __DIR__ . '/scan.php';

const ORDER_TYPES = ['shop_order', 'shop_order_refund'];
const USERMETA_DENY = ['session_tokens', 'wp_2fa_%', '_woocommerce_persistent_cart_%', 'community-events-location'];
/** Legacy-owned plugin/operational tables synced row by row on their primary key. */
const KEYED_TABLES = [
    'wp_woocommerce_payment_tokens' => 'token_id', 'wp_woocommerce_payment_tokenmeta' => 'meta_id',
    'wp_webis_pbet' => 'id', 'wp_webis_pbet_order' => 'id', 'wp_webis_pbet_order_item' => 'id',
    'wp_woocommerce_bis_notifications' => 'id', 'wp_woocommerce_bis_notificationsmeta' => 'meta_id', 'wp_woocommerce_bis_activity' => 'id',
    'wp_gens_raf' => 'id', 'wp_gens_rafmeta' => 'meta_id', 'wp_gens_raf_guest_referrals' => 'id',
    'wp_wt_sc_coupon_lookup' => 'id', 'wp_wdr_order_item_discounts' => 'id', 'wp_pp_order_actions_log' => 'id',
    'wp_trackship_shipment' => 'id', 'wp_trackship_shipment_meta' => 'meta_id', 'wp_zorem_email_sms_log' => 'id',
    'wp_stock_log' => 'ID', 'wp_woocommerce_downloadable_product_permissions' => 'permission_id',
    'wp_wdr_order_discounts' => 'id',
];
/** Product-owned inventory tables (batch stock, stock history): never written while product_sync is false. */
const PRODUCT_OWNED_TABLES = ['wp_webis_pbet', 'wp_stock_log'];

/** Keyed tables in scope for this run. */
function keyed_tables(): array
{
    return cfg()['product_sync'] ? KEYED_TABLES : array_diff_key(KEYED_TABLES, array_flip(PRODUCT_OWNED_TABLES));
}
const HPOS_TABLES = ['wp_wc_orders' => 'id', 'wp_wc_order_addresses' => 'order_id', 'wp_wc_order_operational_data' => 'order_id', 'wp_wc_orders_meta' => 'order_id'];
const LOOKUP_TABLES = ['wp_wc_order_stats' => 'order_id', 'wp_wc_order_product_lookup' => 'order_id', 'wp_wc_order_tax_lookup' => 'order_id', 'wp_wc_order_coupon_lookup' => 'order_id'];

final class Sync
{
    private array $cols = [];
    private array $types = [];
    public array $report = ['url_rewrite_unresolved' => []];
    /** Legacy record IDs held by destination products (isolated by --isolate-product-conflicts): never written. */
    public array $isolated = [];
    private array $pairs;

    private TableRef $dt;

    public function __construct(private \mysqli $db, private TableRef $st, private string $dest, private string $run, private int $batch, array $pairs)
    {
        $this->dt = dest_ref($dest);
        $this->pairs = \DDMig\Refs\url_pairs($pairs);
    }

    private function cols(string $table): array
    {
        if (!isset($this->cols[$table])) {
            $r = rows($this->db, 'SELECT column_name c, data_type t FROM information_schema.columns WHERE table_schema=' . e($this->db, $this->st->schema) . ' AND table_name=' . e($this->db, $this->st->name($table)) . ' ORDER BY ordinal_position');
            $this->cols[$table] = array_column($r, 'c');
            $this->types[$table] = array_column($r, 't', 'c');
        }
        return $this->cols[$table];
    }

    private function textCols(string $table): array
    {
        $this->cols($table);
        return array_keys(array_filter($this->types[$table], fn ($t) => in_array($t, ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'], true)));
    }

    /** Symmetric SQL normalization: apply the URL rewrite and ignore serialized length prefixes. */
    private function norm(string $expr): string
    {
        if (!$this->pairs) {
            return $expr;
        }
        $needles = [];
        foreach ($this->pairs as $from => $to) {
            $needles[] = "$expr LIKE " . e($this->db, '%' . addcslashes($from, '%_\\') . '%');
            $needles[] = "$expr LIKE " . e($this->db, '%' . addcslashes($to, '%_\\') . '%');
        }
        $rep = $expr;
        foreach ($this->pairs as $from => $to) {
            $rep = "REPLACE($rep, " . e($this->db, $from) . ', ' . e($this->db, $to) . ')';
        }
        return 'CASE WHEN ' . implode(' OR ', $needles) . " THEN REGEXP_REPLACE($rep, 's:[0-9]+:\"', 's:\"') ELSE $expr END";
    }

    /** 60-bit row hash expression over the given columns (NULL-safe, separator-safe, URL-normalized). */
    private function h(string $alias, array $cols, ?string $table = null): string
    {
        $text = $table ? $this->textCols($table) : ['meta_key', 'meta_value'];
        $parts = implode(',', array_map(fn ($c) => 'COALESCE(HEX(' . (in_array($c, $text, true) ? $this->norm("$alias.`$c`") : "$alias.`$c`") . "),'N')", $cols));
        return "CAST(CONV(SUBSTRING(MD5(CONCAT_WS('|',$parts)),1,15),16,10) AS UNSIGNED)";
    }

    /** Serialization-safe URL rewrite of freshly copied rows (same transaction as the copy). */
    private function fixup(string $table, string $where, string $pk): int
    {
        if (!$this->pairs) {
            return 0;
        }
        $n = 0;
        foreach ($this->textCols($table) as $col) {
            $like = implode(' OR ', array_map(fn ($f) => "`$col` LIKE " . e($this->db, '%' . addcslashes($f, '%_\\') . '%'), array_keys($this->pairs)));
            $res = q($this->db, "SELECT `$pk` id, `$col` v FROM `{$this->dest}`.`$table` WHERE ($where) AND ($like)");
            while ($r = $res->fetch_assoc()) {
                $nv = \DDMig\Refs\rewrite_urls((string) $r['v'], $this->pairs);
                if ($nv === null) {
                    $this->report['url_rewrite_unresolved'][] = "$table.$col#{$r['id']}";
                    continue;
                }
                if ($nv !== $r['v']) {
                    q($this->db, "UPDATE `{$this->dest}`.`$table` SET `$col`=" . e($this->db, $nv) . " WHERE `$pk`=" . e($this->db, $r['id']));
                    $n++;
                }
            }
        }
        return $n;
    }

    private function denySql(string $alias): string
    {
        return implode(' AND ', array_map(fn ($k) => "$alias.meta_key NOT LIKE " . e($this->db, $k), USERMETA_DENY));
    }

    /**
     * Builds dd_mig_h_{side}_{entity}(id, h) for a post-type graph: post row, postmeta, and for
     * orders the items, item meta, notes and note meta. Meta row IDs are excluded (not identity).
     */
    private function hashPostGraph(string $side, string $entity, array $types): void
    {
        $ST = $side === 'src' ? $this->st : $this->dt;
        $T = "`{$this->dest}`.`dd_mig_h_{$side}_{$entity}`";
        $in = implode(',', array_map(fn ($t) => e($this->db, $t), $types));
        q($this->db, "DROP TABLE IF EXISTS $T");
        q($this->db, "CREATE TABLE $T (id BIGINT UNSIGNED PRIMARY KEY, post_type VARCHAR(40), p DECIMAL(40), m DECIMAL(40), mc INT, i DECIMAL(40), ic INT, im DECIMAL(40), imc INT, c DECIMAL(40), cc INT, cm DECIMAL(40), cmc INT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci");
        q($this->db, "INSERT INTO $T (id, post_type, p) SELECT x.ID, x.post_type, " . $this->h('x', $this->cols('wp_posts'), 'wp_posts') . " FROM {$ST['posts']} x WHERE x.post_type IN ($in)");
        $upd = function (string $col, string $cnt, string $sql) use ($T) {
            q($this->db, "UPDATE $T t JOIN ($sql) a ON a.id=t.id SET t.$col=a.s, t.$cnt=a.n");
        };
        $upd('m', 'mc', "SELECT pm.post_id id, SUM(" . $this->h('pm', ['meta_key', 'meta_value']) . ") s, COUNT(*) n FROM {$ST['postmeta']} pm JOIN $T k ON k.id=pm.post_id GROUP BY pm.post_id");
        if ($entity === 'orders') {
            $upd('i', 'ic', "SELECT oi.order_id id, SUM(" . $this->h('oi', $this->cols('wp_woocommerce_order_items'), 'wp_woocommerce_order_items') . ") s, COUNT(*) n FROM {$ST['woocommerce_order_items']} oi JOIN $T k ON k.id=oi.order_id GROUP BY oi.order_id");
            $upd('im', 'imc', "SELECT oi.order_id id, SUM(" . $this->h('im', ['order_item_id', 'meta_key', 'meta_value']) . ") s, COUNT(*) n FROM {$ST['woocommerce_order_itemmeta']} im JOIN {$ST['woocommerce_order_items']} oi ON oi.order_item_id=im.order_item_id JOIN $T k ON k.id=oi.order_id GROUP BY oi.order_id");
            $upd('c', 'cc', "SELECT c.comment_post_ID id, SUM(" . $this->h('c', $this->cols('wp_comments'), 'wp_comments') . ") s, COUNT(*) n FROM {$ST['comments']} c JOIN $T k ON k.id=c.comment_post_ID GROUP BY c.comment_post_ID");
            $upd('cm', 'cmc', "SELECT c.comment_post_ID id, SUM(" . $this->h('cm', ['comment_id', 'meta_key', 'meta_value']) . ") s, COUNT(*) n FROM {$ST['commentmeta']} cm JOIN {$ST['comments']} c ON c.comment_ID=cm.comment_id JOIN $T k ON k.id=c.comment_post_ID GROUP BY c.comment_post_ID");
        }
    }

    private function hashUsers(string $side): void
    {
        $ST = $side === 'src' ? $this->st : $this->dt;
        $T = "`{$this->dest}`.`dd_mig_h_{$side}_users`";
        q($this->db, "DROP TABLE IF EXISTS $T");
        q($this->db, "CREATE TABLE $T (id BIGINT UNSIGNED PRIMARY KEY, login VARCHAR(60), email VARCHAR(100), registered DATETIME, caps LONGTEXT, p DECIMAL(40), m DECIMAL(40), mc INT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci");
        q($this->db, "INSERT INTO $T (id, login, email, registered, p) SELECT u.ID, u.user_login, u.user_email, u.user_registered, " . $this->h('u', $this->cols('wp_users'), 'wp_users') . " FROM {$ST['users']} u");
        q($this->db, "UPDATE $T t JOIN {$ST['usermeta']} um ON um.user_id=t.id AND um.meta_key='wp_capabilities' SET t.caps=um.meta_value");
        q($this->db, "UPDATE $T t JOIN (SELECT um.user_id id, SUM(" . $this->h('um', ['meta_key', 'meta_value']) . ") s, COUNT(*) n FROM {$ST['usermeta']} um WHERE " . $this->denySql('um') . " GROUP BY um.user_id) a ON a.id=t.id SET t.m=a.s, t.mc=a.n");
    }

    public static function isCustomerCaps(?string $caps): bool
    {
        return is_customer_caps($caps);
    }

    /** Classifies all records of an entity. Returns ['new'=>[], 'changed'=>[], 'same'=>n, 'dest_only'=>[], 'conflict'=>[]]. */
    public function classifyGraph(string $entity, array $types): array
    {
        $this->hashPostGraph('src', $entity, $types);
        $this->hashPostGraph('dst', $entity, $types);
        $S = "`{$this->dest}`.dd_mig_h_src_$entity";
        $D = "`{$this->dest}`.dd_mig_h_dst_$entity";
        $hashEq = "s.p<=>d.p AND s.m<=>d.m AND s.mc<=>d.mc AND s.i<=>d.i AND s.ic<=>d.ic AND s.im<=>d.im AND s.imc<=>d.imc AND s.c<=>d.c AND s.cc<=>d.cc AND s.cm<=>d.cm AND s.cmc<=>d.cmc";
        $r = [];
        $r['new'] = array_map('intval', array_column(rows($this->db, "SELECT s.id FROM $S s LEFT JOIN `{$this->dest}`.wp_posts dp ON dp.ID=s.id WHERE dp.ID IS NULL ORDER BY s.id"), 'id'));
        $r['conflict'] = rows($this->db, "SELECT s.id, s.post_type src_type, dp.post_type dest_type FROM $S s JOIN `{$this->dest}`.wp_posts dp ON dp.ID=s.id WHERE dp.post_type<>s.post_type ORDER BY s.id");
        $r['changed'] = array_map('intval', array_column(rows($this->db, "SELECT s.id FROM $S s JOIN $D d ON d.id=s.id AND d.post_type=s.post_type WHERE NOT ($hashEq) ORDER BY s.id"), 'id'));
        $r['same'] = (int) one($this->db, "SELECT COUNT(*) FROM $S s JOIN $D d ON d.id=s.id AND d.post_type=s.post_type WHERE $hashEq");
        $r['dest_only'] = array_map('intval', array_column(rows($this->db, "SELECT d.id FROM $D d LEFT JOIN $S s ON s.id=d.id WHERE s.id IS NULL ORDER BY d.id"), 'id'));
        return $r;
    }

    public function classifyUsers(): array
    {
        $this->hashUsers('src');
        $this->hashUsers('dst');
        $S = "`{$this->dest}`.dd_mig_h_src_users";
        $D = "`{$this->dest}`.dd_mig_h_dst_users";
        $r = ['new' => [], 'changed' => [], 'same' => 0, 'dest_only' => [], 'conflict' => [], 'staff' => []];
        $res = q($this->db, "SELECT s.id, s.login, s.email, s.registered, s.caps scaps, d.id did, d.login dlogin, d.email demail, d.registered dreg, d.caps dcaps,
            (s.p<=>d.p AND s.m<=>d.m AND s.mc<=>d.mc) eq FROM $S s LEFT JOIN $D d ON d.id=s.id ORDER BY s.id");
        while ($u = $res->fetch_assoc()) {
            $id = (int) $u['id'];
            $staff = !self::isCustomerCaps($u['scaps']) || ($u['did'] !== null && !self::isCustomerCaps($u['dcaps']));
            if ($staff) {
                $r['staff'][] = ['id' => $id, 'source_roles' => self::roles($u['scaps']), 'dest_roles' => $u['did'] === null ? null : self::roles($u['dcaps']), 'on_dest' => $u['did'] !== null];
                continue;
            }
            if ($u['did'] === null) {
                $clash = one($this->db, "SELECT ID FROM `{$this->dest}`.wp_users WHERE user_login=" . e($this->db, $u['login']) . ' OR user_email=' . e($this->db, $u['email']) . ' LIMIT 1');
                $clash ? $r['conflict'][] = ['id' => $id, 'reason' => "login/email already used by destination user $clash"] : $r['new'][] = $id;
                continue;
            }
            // Identity evidence for the same ID: login or registration timestamp must agree (never email alone).
            if ($u['login'] !== $u['dlogin'] && $u['registered'] !== $u['dreg']) {
                $r['conflict'][] = ['id' => $id, 'reason' => 'same ID but login and registration date both differ'];
                continue;
            }
            $u['eq'] ? $r['same']++ : $r['changed'][] = $id;
        }
        $r['dest_only'] = array_map('intval', array_column(rows($this->db, "SELECT d.id FROM $D d LEFT JOIN $S s ON s.id=d.id WHERE s.id IS NULL"), 'id'));
        return $r;
    }

    private static function roles(?string $caps): array
    {
        $c = @unserialize((string) $caps, ['allowed_classes' => false]);
        return is_array($c) ? array_keys(array_filter($c)) : [];
    }

    /** Keyed-row classification for a legacy-owned table. */
    public function classifyKeyed(string $table, string $pk): array
    {
        if (!one($this->db, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=' . e($this->db, $this->dest) . ' AND table_name=' . e($this->db, $table))) {
            return ['missing_table' => true, 'new' => [], 'changed' => [], 'same' => 0, 'dest_only' => []];
        }
        $cols = $this->cols($table);
        $hs = $this->h('s', $cols, $table);
        $hd = $this->h('d', $cols, $table);
        $S = $this->st[$table];
        $D = "`{$this->dest}`.`$table`";
        // rows belonging to isolated legacy orders are never copied
        $iso = $this->isolated && in_array('order_id', $cols, true) ? ' AND s.order_id NOT IN (' . implode(',', $this->isolated) . ')' : '';
        return [
            'new' => array_map('intval', array_column(rows($this->db, "SELECT s.`$pk` id FROM $S s LEFT JOIN $D d ON d.`$pk`=s.`$pk` WHERE d.`$pk` IS NULL$iso"), 'id')),
            'changed' => array_map('intval', array_column(rows($this->db, "SELECT s.`$pk` id FROM $S s JOIN $D d ON d.`$pk`=s.`$pk` WHERE $hs <> $hd$iso"), 'id')),
            'same' => (int) one($this->db, "SELECT COUNT(*) FROM $S s JOIN $D d ON d.`$pk`=s.`$pk` WHERE $hs = $hd$iso"),
            'isolated_rows' => $iso ? (int) one($this->db, "SELECT COUNT(*) FROM $S s WHERE s.order_id IN (" . implode(',', $this->isolated) . ')') : 0,
            'dest_only' => array_map('intval', array_column(rows($this->db, "SELECT d.`$pk` id FROM $D d LEFT JOIN $S s ON s.`$pk`=d.`$pk` WHERE s.`$pk` IS NULL"), 'id')),
        ];
    }

    private function ledger(string $entity, array $ids, string $action, string $hashTable = ''): void
    {
        foreach (array_chunk($ids, 1000) as $ch) {
            $vals = implode(',', array_map(fn ($id) => '(' . e($this->db, $this->run) . ',' . e($this->db, $entity) . ",$id," . e($this->db, $action) . ')', $ch));
            q($this->db, "INSERT INTO `{$this->dest}`.dd_mig_ledger (run_id, entity, entity_id, action) VALUES $vals");
        }
    }

    /** Replaces the destination graph of the given post IDs with the source graph, one transaction per batch. */
    public function applyGraph(string $entity, array $types, array $ids, string $action): int
    {
        $ST = $this->st;
        $D = $this->dest;
        $in = implode(',', array_map(fn ($t) => e($this->db, $t), $types));
        $done = 0;
        foreach (array_chunk($ids, $this->batch) as $ch) {
            $list = implode(',', $ch);
            q($this->db, 'START TRANSACTION');
            try {
                // Fail closed: every destination row at these IDs must be the same record type.
                $foreign = (int) one($this->db, "SELECT COUNT(*) FROM `$D`.wp_posts WHERE ID IN ($list) AND post_type NOT IN ($in)");
                if ($foreign) {
                    throw new \RuntimeException("$foreign destination rows in batch are not $entity records");
                }
                if ($entity === 'orders') {
                    foreach ([['wp_woocommerce_order_items', 'order_item_id', 'order_id'], ['wp_comments', 'comment_ID', 'comment_post_ID']] as [$t, $pk, $fk]) {
                        $clash = (int) one($this->db, "SELECT COUNT(*) FROM {$ST[$t]} s JOIN `$D`.`$t` d ON d.`$pk`=s.`$pk` AND d.`$fk`<>s.`$fk` WHERE s.`$fk` IN ($list)");
                        if ($clash) {
                            throw new \RuntimeException("$clash $t IDs of this batch belong to other records on the destination");
                        }
                    }
                    q($this->db, "DELETE im FROM `$D`.wp_woocommerce_order_itemmeta im JOIN `$D`.wp_woocommerce_order_items oi ON oi.order_item_id=im.order_item_id WHERE oi.order_id IN ($list)");
                    q($this->db, "DELETE FROM `$D`.wp_woocommerce_order_items WHERE order_id IN ($list)");
                    q($this->db, "DELETE cm FROM `$D`.wp_commentmeta cm JOIN `$D`.wp_comments c ON c.comment_ID=cm.comment_id WHERE c.comment_post_ID IN ($list)");
                    q($this->db, "DELETE FROM `$D`.wp_comments WHERE comment_post_ID IN ($list)");
                    // HPOS mirror and analytics rows are rebuilt by WooCommerce afterwards (postsync.sh).
                    foreach (HPOS_TABLES + LOOKUP_TABLES as $t => $col) {
                        q($this->db, "DELETE FROM `$D`.`$t` WHERE `$col` IN ($list)");
                    }
                }
                q($this->db, "DELETE FROM `$D`.wp_postmeta WHERE post_id IN ($list)");
                q($this->db, "DELETE FROM `$D`.wp_posts WHERE ID IN ($list)");
                q($this->db, "INSERT INTO `$D`.wp_posts SELECT * FROM {$ST['posts']} WHERE ID IN ($list)");
                q($this->db, "INSERT INTO `$D`.wp_postmeta (post_id, meta_key, meta_value) SELECT post_id, meta_key, meta_value FROM {$ST['postmeta']} WHERE post_id IN ($list) ORDER BY meta_id");
                $this->fixup('wp_posts', "ID IN ($list)", 'ID');
                $this->fixup('wp_postmeta', "post_id IN ($list)", 'meta_id');
                if ($entity === 'orders') {
                    q($this->db, "INSERT INTO `$D`.wp_woocommerce_order_items SELECT * FROM {$ST['woocommerce_order_items']} WHERE order_id IN ($list)");
                    q($this->db, "INSERT INTO `$D`.wp_woocommerce_order_itemmeta (order_item_id, meta_key, meta_value) SELECT im.order_item_id, im.meta_key, im.meta_value FROM {$ST['woocommerce_order_itemmeta']} im JOIN {$ST['woocommerce_order_items']} oi ON oi.order_item_id=im.order_item_id WHERE oi.order_id IN ($list) ORDER BY im.meta_id");
                    q($this->db, "INSERT INTO `$D`.wp_comments SELECT * FROM {$ST['comments']} WHERE comment_post_ID IN ($list)");
                    q($this->db, "INSERT INTO `$D`.wp_commentmeta (comment_id, meta_key, meta_value) SELECT cm.comment_id, cm.meta_key, cm.meta_value FROM {$ST['commentmeta']} cm JOIN {$ST['comments']} c ON c.comment_ID=cm.comment_id WHERE c.comment_post_ID IN ($list) ORDER BY cm.meta_id");
                    $this->fixup('wp_woocommerce_order_items', "order_id IN ($list)", 'order_item_id');
                    $this->fixup('wp_woocommerce_order_itemmeta', "order_item_id IN (SELECT order_item_id FROM `$D`.wp_woocommerce_order_items WHERE order_id IN ($list))", 'meta_id');
                    $this->fixup('wp_comments', "comment_post_ID IN ($list)", 'comment_ID');
                    $this->fixup('wp_commentmeta', "comment_id IN (SELECT comment_ID FROM `$D`.wp_comments WHERE comment_post_ID IN ($list))", 'meta_id');
                }
                $this->ledger($entity, $ch, $action);
                q($this->db, 'COMMIT');
            } catch (\Throwable $t) {
                q($this->db, 'ROLLBACK');
                fail("$entity batch starting {$ch[0]} rolled back: " . $t->getMessage());
            }
            $done += count($ch);
            out("  $entity $action " . $done . '/' . count($ids));
        }
        return $done;
    }

    public function applyUsers(array $ids, string $action): int
    {
        $ST = $this->st;
        $D = $this->dest;
        $done = 0;
        foreach (array_chunk($ids, $this->batch) as $ch) {
            $list = implode(',', $ch);
            q($this->db, 'START TRANSACTION');
            try {
                q($this->db, "REPLACE INTO `$D`.wp_users SELECT * FROM {$ST['users']} WHERE ID IN ($list)");
                q($this->db, "DELETE um FROM `$D`.wp_usermeta um WHERE um.user_id IN ($list) AND " . $this->denySql('um'));
                q($this->db, "INSERT INTO `$D`.wp_usermeta (user_id, meta_key, meta_value) SELECT user_id, meta_key, meta_value FROM {$ST['usermeta']} um WHERE um.user_id IN ($list) AND " . $this->denySql('um') . ' ORDER BY umeta_id');
                $this->fixup('wp_users', "ID IN ($list)", 'ID');
                $this->fixup('wp_usermeta', "user_id IN ($list) AND " . $this->denySql('wp_usermeta'), 'umeta_id');
                $this->ledger('users', $ch, $action);
                q($this->db, 'COMMIT');
            } catch (\Throwable $t) {
                q($this->db, 'ROLLBACK');
                fail("users batch starting {$ch[0]} rolled back: " . $t->getMessage());
            }
            $done += count($ch);
        }
        out("  users $action $done");
        return $done;
    }

    /**
     * Analytics are derived from the legacy orders we copy, so they mirror legacy's own rows:
     * per-order tables are replaced for every order whose rows differ; wc_customer_lookup is keyed by
     * customer_id and destination-only rows (derived, e.g. from an earlier partial import) are removed.
     */
    public function analytics(bool $apply): array
    {
        $ST = $this->st;
        $D = $this->dest;
        $out = [];
        foreach (['wp_wc_order_stats', 'wp_wc_order_product_lookup', 'wp_wc_order_tax_lookup', 'wp_wc_order_coupon_lookup'] as $t) {
            $cols = $this->cols($t);
            $g = fn ($X, $a) => "SELECT $a.order_id id, SUM(" . $this->h($a, $cols, $t) . ") s, COUNT(*) n FROM $X $a GROUP BY $a.order_id";
            $srcT = $ST[$t];
            $dstT = "`$D`.`$t`";
            $diff = array_map('intval', array_column(rows($this->db, "SELECT x.id FROM (SELECT id FROM ({$g($srcT,'a')}) u UNION SELECT id FROM ({$g($dstT,'b')}) v) x
                LEFT JOIN ({$g($srcT,'c')}) s ON s.id=x.id LEFT JOIN ({$g($dstT,'d')}) d ON d.id=x.id
                WHERE NOT (s.s <=> d.s AND s.n <=> d.n) AND x.id IN (SELECT ID FROM {$ST['posts']} WHERE post_type IN ('shop_order','shop_order_refund'))"), 'id'));
            $diff = array_values(array_diff($diff, $this->isolated));
            $out[$t] = count($diff);
            if ($apply) {
                foreach (array_chunk($diff, 5000) as $ch) {
                    $l = implode(',', $ch);
                    q($this->db, 'START TRANSACTION');
                    q($this->db, "DELETE FROM `$D`.`$t` WHERE order_id IN ($l)");
                    q($this->db, "INSERT INTO `$D`.`$t` SELECT * FROM {$ST[$t]} WHERE order_id IN ($l)");
                    q($this->db, 'COMMIT');
                }
            }
        }
        $k = $this->classifyKeyed('wp_wc_customer_lookup', 'customer_id');
        $out['wp_wc_customer_lookup'] = ['new' => count($k['new']), 'changed' => count($k['changed']), 'dest_only_derived_removed' => count($k['dest_only'])];
        if ($apply) {
            if ($k['dest_only']) {
                q($this->db, "DELETE FROM `$D`.wp_wc_customer_lookup WHERE customer_id IN (" . implode(',', $k['dest_only']) . ')');
            }
            $this->applyKeyed('wp_wc_customer_lookup', 'customer_id', array_merge($k['new'], $k['changed']));
        }
        return $out;
    }

    public function applyKeyed(string $table, string $pk, array $ids): int
    {
        foreach (array_chunk($ids, 5000) as $ch) {
            $list = implode(',', $ch);
            q($this->db, 'START TRANSACTION');
            q($this->db, "REPLACE INTO `{$this->dest}`.`$table` SELECT * FROM {$this->st[$table]} WHERE `$pk` IN ($list)");
            $this->fixup($table, "`$pk` IN ($list)", $pk);
            $this->ledger($table, $ch, 'upsert');
            q($this->db, 'COMMIT');
        }
        return count($ids);
    }

    /**
     * Product commercial fields for identity-verified products (same ID and type, same SKU, same
     * variation parent). Returns [changes, exceptions].
     */
    public function products(bool $apply): array
    {
        $ST = $this->st;
        $D = $this->dest;
        $pairs = rows($this->db, "SELECT s.ID id, s.post_type type, s.post_parent sp, d.post_parent dp, s.post_status sstatus, d.post_status dstatus,
            (SELECT meta_value FROM {$ST['postmeta']} WHERE post_id=s.ID AND meta_key='_sku' LIMIT 1) ssku,
            (SELECT meta_value FROM `$D`.wp_postmeta WHERE post_id=d.ID AND meta_key='_sku' LIMIT 1) dsku
            FROM {$ST['posts']} s JOIN `$D`.wp_posts d ON d.ID=s.ID AND d.post_type=s.post_type WHERE s.post_type IN ('product','product_variation')");
        $keys = implode(',', array_map(fn ($k) => e($this->db, $k), cfg()['product_commercial']));
        $ex = ['ambiguous' => [], 'status_differs' => []];
        $skuChanges = [];
        $verified = [];
        foreach ($pairs as $p) {
            $approved = cfg()['approved_sku_changes'][(int) $p['id']] ?? null;
            $skuApproved = $approved && $approved['from'] === (string) $p['dsku'] && $approved['to'] === (string) $p['ssku'];
            if ($skuApproved) {
                $skuChanges[] = ['id' => (int) $p['id'], 'key' => '_sku', 'legacy' => $p['ssku'], 'destination_before' => $p['dsku']];
            }
            if ((!$skuApproved && (string) $p['ssku'] !== (string) $p['dsku']) || ($p['type'] === 'product_variation' && $p['sp'] !== $p['dp'])) {
                $ex['ambiguous'][] = ['id' => (int) $p['id'], 'type' => $p['type'], 'reason' => (string) $p['ssku'] !== (string) $p['dsku'] ? 'SKU differs' : 'variation parent differs'];
                continue;
            }
            if ($p['sstatus'] !== $p['dstatus']) {
                $ex['status_differs'][] = ['id' => (int) $p['id'], 'type' => $p['type'], 'legacy' => $p['sstatus'], 'destination' => $p['dstatus']];
            }
            $verified[] = (int) $p['id'];
        }
        $changes = [];
        foreach (array_chunk($verified, 1000) as $ch) {
            $list = implode(',', $ch);
            $diff = rows($this->db, "SELECT k.id, k.meta_key, s.v sv, d.v dv FROM
                (SELECT p.ID id, kk.meta_key FROM {$ST['posts']} p JOIN (SELECT meta_key FROM (SELECT " . implode(' meta_key UNION SELECT ', array_map(fn ($k) => e($this->db, $k), cfg()['product_commercial'])) . " meta_key) z) kk WHERE p.ID IN ($list)) k
                LEFT JOIN (SELECT post_id, meta_key, GROUP_CONCAT(meta_value ORDER BY meta_id SEPARATOR 0x1e) v FROM {$ST['postmeta']} WHERE post_id IN ($list) AND meta_key IN ($keys) GROUP BY post_id, meta_key) s ON s.post_id=k.id AND s.meta_key=k.meta_key
                LEFT JOIN (SELECT post_id, meta_key, GROUP_CONCAT(meta_value ORDER BY meta_id SEPARATOR 0x1e) v FROM `$D`.wp_postmeta WHERE post_id IN ($list) AND meta_key IN ($keys) GROUP BY post_id, meta_key) d ON d.post_id=k.id AND d.meta_key=k.meta_key
                WHERE NOT (s.v <=> d.v)");
            foreach ($diff as $d) {
                $changes[] = ['id' => (int) $d['id'], 'key' => $d['meta_key'], 'legacy' => $d['sv'], 'destination_before' => $d['dv']];
            }
        }
        $changes = array_merge($skuChanges, $changes);
        if ($apply && $changes) {
            q($this->db, 'START TRANSACTION');
            foreach ($changes as $c) {
                q($this->db, "DELETE FROM `$D`.wp_postmeta WHERE post_id={$c['id']} AND meta_key=" . e($this->db, $c['key']));
                q($this->db, "INSERT INTO `$D`.wp_postmeta (post_id, meta_key, meta_value) SELECT post_id, meta_key, meta_value FROM {$ST['postmeta']} WHERE post_id={$c['id']} AND meta_key=" . e($this->db, $c['key']) . ' ORDER BY meta_id');
                $this->fixup('wp_postmeta', "post_id={$c['id']} AND meta_key=" . e($this->db, $c['key']), 'meta_id');
            }
            $this->syncOutOfStockTerms(array_values(array_unique(array_map(fn ($c) => $c['id'], array_filter($changes, fn ($c) => $c['key'] === '_stock_status')))));
            $this->ledger('product_commercial', array_values(array_unique(array_column($changes, 'id'))), 'update');
            q($this->db, 'COMMIT');
        }
        return [$changes, $ex];
    }

    /** product_visibility "outofstock" term follows _stock_status (WooCommerce derived data). */
    private function syncOutOfStockTerms(array $ids): void
    {
        if (!$ids) {
            return;
        }
        $D = $this->dest;
        $tt = (int) one($this->db, "SELECT tt.term_taxonomy_id FROM `$D`.wp_term_taxonomy tt JOIN `$D`.wp_terms t ON t.term_id=tt.term_id WHERE tt.taxonomy='product_visibility' AND t.slug='outofstock'");
        foreach ($ids as $id) {
            $status = one($this->db, "SELECT meta_value FROM `$D`.wp_postmeta WHERE post_id=$id AND meta_key='_stock_status' LIMIT 1");
            if ($status === 'outofstock') {
                q($this->db, "INSERT IGNORE INTO `$D`.wp_term_relationships (object_id, term_taxonomy_id, term_order) VALUES ($id, $tt, 0)");
            } else {
                q($this->db, "DELETE FROM `$D`.wp_term_relationships WHERE object_id=$id AND term_taxonomy_id=$tt");
            }
        }
    }

    /**
     * New legacy products/variations missing on the destination: matched by SKU against destination
     * products first; otherwise created as clearly marked DRAFT placeholders at their legacy ID with
     * commercial fields and SKU only (presentation stays for the team). Source status is recorded.
     */
    public function placeholders(bool $apply): array
    {
        $ST = $this->st;
        $D = $this->dest;
        $missing = rows($this->db, "SELECT s.ID id, s.post_type type, s.post_status status, s.post_parent parent, s.post_title title,
            (SELECT meta_value FROM {$ST['postmeta']} WHERE post_id=s.ID AND meta_key='_sku' LIMIT 1) sku
            FROM {$ST['posts']} s LEFT JOIN `$D`.wp_posts d ON d.ID=s.ID WHERE s.post_type IN ('product','product_variation') AND d.ID IS NULL ORDER BY s.post_type, s.ID");
        $occupied = rows($this->db, "SELECT s.ID id, s.post_type type, d.post_type dest_type FROM {$ST['posts']} s JOIN `$D`.wp_posts d ON d.ID=s.ID WHERE s.post_type IN ('product','product_variation') AND d.post_type NOT IN ('product','product_variation')");
        $out = ['inventory' => [], 'occupied' => $occupied];
        // Placeholders created by earlier runs stay on the review list until the team replaces them.
        foreach (rows($this->db, "SELECT p.ID id, p.post_type type, st.meta_value status, sk.meta_value sku, s.post_title title, r.meta_value run
            FROM `$D`.wp_posts p JOIN `$D`.wp_postmeta r ON r.post_id=p.ID AND r.meta_key='_dd_migration_placeholder'
            LEFT JOIN `$D`.wp_postmeta st ON st.post_id=p.ID AND st.meta_key='_dd_legacy_status' LEFT JOIN `$D`.wp_postmeta sk ON sk.post_id=p.ID AND sk.meta_key='_sku'
            LEFT JOIN {$ST['posts']} s ON s.ID=p.ID ORDER BY p.ID") as $e) {
            $out['inventory'][] = ['legacy_id' => (int) $e['id'], 'type' => $e['type'], 'legacy_status' => $e['status'], 'sku' => $e['sku'], 'title' => $e['title'],
                'order_items' => (int) one($this->db, "SELECT COUNT(*) FROM {$ST['woocommerce_order_itemmeta']} WHERE meta_key IN ('_product_id','_variation_id') AND meta_value=" . (int) $e['id']),
                'destination_equivalent' => null, 'action' => 'placeholder exists (created ' . $e['run'] . ')'];
        }
        foreach ($missing as $m) {
            $equiv = $m['sku'] !== null && $m['sku'] !== '' ? one($this->db, "SELECT p.ID FROM `$D`.wp_posts p JOIN `$D`.wp_postmeta pm ON pm.post_id=p.ID AND pm.meta_key='_sku' AND pm.meta_value=" . e($this->db, $m['sku']) . " WHERE p.post_type IN ('product','product_variation') LIMIT 1") : null;
            $orderItems = (int) one($this->db, "SELECT COUNT(*) FROM {$ST['woocommerce_order_itemmeta']} WHERE meta_key IN ('_product_id','_variation_id') AND meta_value=" . e($this->db, $m['id']));
            $row = ['legacy_id' => (int) $m['id'], 'type' => $m['type'], 'legacy_status' => $m['status'], 'sku' => $m['sku'], 'title' => $m['title'], 'order_items' => $orderItems,
                'destination_equivalent' => $equiv ? (int) $equiv : null, 'action' => $equiv ? 'report-equivalent (not created)' : 'placeholder'];
            $out['inventory'][] = $row;
        }
        if ($apply) {
            $keys = implode(',', array_map(fn ($k) => e($this->db, $k), array_merge(cfg()['product_commercial'], ['_sku', '_product_attributes', '_downloadable', '_virtual', '_weight', '_length', '_width', '_height'])));
            q($this->db, 'START TRANSACTION');
            foreach ($out['inventory'] as $r) {
                if ($r['action'] !== 'placeholder') {
                    continue;
                }
                $id = $r['legacy_id'];
                q($this->db, "INSERT INTO `$D`.wp_posts SELECT * FROM {$ST['posts']} WHERE ID=$id");
                q($this->db, "UPDATE `$D`.wp_posts SET post_status='draft', post_content='', post_excerpt='', post_title=CONCAT('[Migration placeholder] ', post_title), post_name=CONCAT(post_name, '-legacy-placeholder') WHERE ID=$id");
                q($this->db, "INSERT INTO `$D`.wp_postmeta (post_id, meta_key, meta_value) SELECT post_id, meta_key, meta_value FROM {$ST['postmeta']} WHERE post_id=$id AND meta_key IN ($keys) ORDER BY meta_id");
                q($this->db, "INSERT INTO `$D`.wp_postmeta (post_id, meta_key, meta_value) VALUES ($id, '_dd_migration_placeholder', " . e($this->db, $this->run) . "), ($id, '_dd_legacy_status', " . e($this->db, $r['legacy_status']) . ')');
                // product_type / product_visibility terms mapped by slug (destination term IDs)
                q($this->db, "INSERT IGNORE INTO `$D`.wp_term_relationships (object_id, term_taxonomy_id, term_order)
                    SELECT $id, dtt.term_taxonomy_id, 0 FROM {$ST['term_relationships']} sr JOIN {$ST['term_taxonomy']} stt ON stt.term_taxonomy_id=sr.term_taxonomy_id
                    JOIN {$ST['terms']} st ON st.term_id=stt.term_id JOIN `$D`.wp_terms dt ON dt.slug=st.slug JOIN `$D`.wp_term_taxonomy dtt ON dtt.term_id=dt.term_id AND dtt.taxonomy=stt.taxonomy
                    WHERE sr.object_id=$id AND stt.taxonomy IN ('product_type','product_visibility')");
                $this->ledger('placeholder_product', [$id], 'create');
            }
            q($this->db, 'COMMIT');
        }
        return $out;
    }
}

/**
 * Definitions of verified customer-group roles (config customer_group_roles): copied verbatim from the
 * source snapshot's wp_user_roles when the destination lacks the role; a differing destination
 * definition is reported as a conflict and left alone. No other role is ever touched.
 */
function group_roles(\mysqli $db, TableRef $ST, string $dest, bool $apply): array
{
    $groups = cfg()['customer_group_roles'];
    if (!$groups) {
        return [];
    }
    $key = cfg()['prefix'] . 'user_roles';
    $src = @unserialize((string) one($db, "SELECT option_value FROM {$ST['options']} WHERE option_name=" . e($db, $key)), ['allowed_classes' => false]);
    $dstRaw = (string) one($db, "SELECT option_value FROM `$dest`.wp_options WHERE option_name=" . e($db, $key));
    $dst = @unserialize($dstRaw, ['allowed_classes' => false]);
    is_array($src) && is_array($dst) || fail('cannot read role definitions (source lgcy_options / destination wp_options)');
    $out = [];
    foreach ($groups as $g) {
        if (!isset($src[$g])) {
            $out[$g] = 'absent in source (no member can hold it)';
        } elseif (!isset($dst[$g])) {
            $out[$g] = $apply ? 'added from source' : 'would be added from source';
            $dst[$g] = $src[$g];
        } else {
            $out[$g] = $dst[$g] == $src[$g] ? 'present, identical' : 'CONFLICT: destination definition differs (left unchanged)';
        }
    }
    if ($apply && in_array('added from source', $out, true)) {
        q($db, "UPDATE `$dest`.wp_options SET option_value=" . e($db, serialize($dst)) . " WHERE option_name=" . e($db, $key));
    }
    return $out;
}

function plan_and_apply(\mysqli $db, TableRef $ST, string $dest, string $run, bool $apply, array $only, int $batch, string $profile, bool $isolateProducts = false): array
{
    $sum = [];
    $rw = cfg()['url_rewrite'];
    if ($profile === 'verbatim') {
        $pairs = []; // byte-for-byte copy and comparison: no rewrite, no normalization, not even 'common'
    } elseif ($profile === 'auto') {
        $legacyHome = (string) one($db, "SELECT v FROM {$ST['dd_snapshot']} WHERE k='legacy_home'");
        $destHome = (string) one($db, "SELECT option_value FROM `$dest`.wp_options WHERE option_name='home'");
        if ($legacyHome === '' || $destHome === '') {
            fail('url profile auto needs legacy_home in the source snapshot and a destination home');
        }
        $pairs = array_merge([[$legacyHome, $destHome]], $rw['common']);
    } else {
        $pairs = array_merge($rw['profiles'][$profile] ?? fail("unknown url profile $profile"), $rw['common']);
    }
    $sum['url_profile'] = ['profile' => $profile, 'pairs' => $pairs];
    $s = new Sync($db, $ST, $dest, $run, $batch, $pairs);
    $want = fn ($x) => !$only || in_array($x, $only, true);
    $timing = [];
    $t = microtime(true);
    $lap = function (string $k) use (&$t, &$timing) { $timing[$k] = round(microtime(true) - $t, 1); $t = microtime(true); };
    $rep = report_dir();

    if ($want('users')) {
        $sum['roles'] = group_roles($db, $ST, $dest, $apply);
        $u = $s->classifyUsers();
        $lap('users_classify');
        $sum['users'] = ['new' => count($u['new']), 'changed' => count($u['changed']), 'same' => $u['same'], 'conflict' => count($u['conflict']), 'staff_skipped' => count($u['staff']), 'dest_only' => count($u['dest_only'])];
        write_csv("$rep/sync-users-conflicts.csv", ['id', 'reason'], array_map(fn ($c) => [$c['id'], $c['reason']], $u['conflict']));
        write_csv("$rep/sync-staff-checklist.csv", ['legacy_user_id', 'legacy_roles', 'destination_roles', 'exists_on_destination', 'action'],
            array_map(fn ($x) => [$x['id'], implode('+', $x['source_roles']), $x['dest_roles'] === null ? '' : implode('+', $x['dest_roles']), $x['on_dest'] ? 'yes' : 'no', 'not synced — grant destination access manually if still required; re-enrol 2FA'], $u['staff']));
        if ($apply) {
            $s->applyUsers($u['new'], 'insert');
            $s->applyUsers($u['changed'], 'replace');
            $lap('users_apply');
        }
    }
    foreach (['coupons' => ['shop_coupon'], 'orders' => ORDER_TYPES] as $entity => $types) {
        if (!$want($entity)) {
            continue;
        }
        $g = $s->classifyGraph($entity, $types);
        $lap("{$entity}_classify");
        $sum[$entity] = ['new' => count($g['new']), 'changed' => count($g['changed']), 'same' => $g['same'], 'conflict' => count($g['conflict']), 'dest_only' => count($g['dest_only'])];
        write_csv("$rep/sync-$entity-exceptions.csv", ['id', 'class', 'detail'], array_merge(
            array_map(fn ($c) => [$c['id'], 'conflict', "source {$c['src_type']} vs destination {$c['dest_type']}"], $g['conflict']),
            array_map(fn ($id) => [$id, 'destination-only', 'kept, not deleted'], $g['dest_only'])));
        // A legacy record whose ID is held by a destination product cannot be written without relocating the
        // product (never done here). With --isolate-product-conflicts it is left out — with its order-linked
        // plugin and analytics rows — and listed for decision; any other conflict still stops the run.
        $productHeld = array_values(array_filter($g['conflict'], fn ($c) => in_array($c['dest_type'], ['product', 'product_variation'], true)));
        $other = array_values(array_filter($g['conflict'], fn ($c) => !in_array($c['dest_type'], ['product', 'product_variation'], true)));
        if ($other || ($productHeld && !$isolateProducts)) {
            fail("$entity: " . count($g['conflict']) . ' ID conflicts (' . count($productHeld) . ' held by destination products) — run renumber.php first, or decide the product collisions (see sync-' . $entity . '-exceptions.csv)');
        }
        if ($productHeld) {
            $s->isolated = array_values(array_unique(array_merge($s->isolated, array_map(fn ($c) => (int) $c['id'], $productHeld))));
            $sum[$entity]['isolated_product_conflicts'] = array_map(fn ($c) => (int) $c['id'], $productHeld);
            write_csv("$rep/sync-isolated-$entity.csv", ['legacy_id', 'legacy_type', 'destination_type', 'status'],
                array_map(fn ($c) => [$c['id'], $c['src_type'], $c['dest_type'], 'isolated: not migrated, decision pending'], $productHeld));
        }
        if ($entity === 'coupons') {
            // Duplicate-code conflicts: a destination-only coupon (not a legacy record) using a legacy code.
            $destDup = [];
            foreach ($g['dest_only'] as $did) {
                $code = one($db, "SELECT post_title FROM `$dest`.wp_posts WHERE ID=$did");
                $sid = one($db, "SELECT ID FROM {$ST['posts']} WHERE post_type='shop_coupon' AND post_status<>'trash' AND post_title=" . e($db, $code) . ' LIMIT 1');
                if ($sid) {
                    $destDup[] = ['src_id' => $sid, 'dest_id' => $did];
                }
            }
            $sum['coupons']['duplicate_code_conflicts'] = count($destDup);
            write_csv("$rep/sync-coupon-code-conflicts.csv", ['legacy_id', 'destination_id'], array_map(fn ($d) => [$d['src_id'], $d['dest_id']], $destDup));
        }
        if ($apply) {
            $s->applyGraph($entity, $types, $g['new'], 'insert');
            $s->applyGraph($entity, $types, $g['changed'], 'replace');
            $lap("{$entity}_apply");
        }
    }
    if ($want('analytics')) {
        $sum['analytics'] = $s->analytics($apply);
        $lap('analytics');
    }
    if ($want('plugins')) {
        foreach (keyed_tables() as $table => $pk) {
            $k = $s->classifyKeyed($table, $pk);
            $sum['plugins'][$table] = isset($k['missing_table']) ? 'table missing on destination' : ['new' => count($k['new']), 'changed' => count($k['changed']), 'same' => $k['same'], 'dest_only' => count($k['dest_only'])];
            if ($apply && !isset($k['missing_table'])) {
                $s->applyKeyed($table, $pk, array_merge($k['new'], $k['changed']));
            }
        }
        $lap('plugins');
    }
    if (!cfg()['product_sync']) {
        $sum['products'] = 'not synced (scope 2026-10-19): destination products untouched; see product-refs.php for references';
    } elseif ($want('products')) {
        [$changes, $ex] = $s->products($apply);
        $sum['products'] = ['field_changes' => count($changes), 'products_changed' => count(array_unique(array_column($changes, 'id'))), 'ambiguous' => count($ex['ambiguous']), 'status_differs' => count($ex['status_differs'])];
        $byKey = [];
        foreach ($changes as $c) {
            $byKey[$c['key']] = ($byKey[$c['key']] ?? 0) + 1;
        }
        $sum['products']['by_field'] = $byKey;
        write_csv("$rep/sync-product-commercial-changes.csv", ['product_id', 'field', 'legacy', 'destination_before'], array_map(fn ($c) => [$c['id'], $c['key'], $c['legacy'], $c['destination_before']], $changes));
        write_csv("$rep/review-product-status-differences.csv", ['product_id', 'type', 'legacy_status', 'destination_status (kept)'], array_map(fn ($x) => array_values($x), $ex['status_differs']));
        write_csv("$rep/review-product-ambiguous.csv", ['product_id', 'type', 'reason'], array_map(fn ($x) => array_values($x), $ex['ambiguous']));
        $p = $s->placeholders($apply);
        $sum['placeholders'] = ['legacy_products_missing' => count(array_filter($p['inventory'], fn ($r) => $r['action'] === 'placeholder' || str_starts_with($r['action'], 'report-'))), 'created' => $apply ? count(array_filter($p['inventory'], fn ($r) => $r['action'] === 'placeholder')) : 0,
            'existing_placeholders' => count(array_filter($p['inventory'], fn ($r) => str_starts_with($r['action'], 'placeholder exists'))), 'occupied_by_other_type' => count($p['occupied'])];
        write_csv("$rep/review-new-legacy-products.csv", ['legacy_id', 'type', 'legacy_status', 'sku', 'title', 'order_items', 'destination_equivalent', 'action'], array_map(fn ($x) => array_values($x), $p['inventory']));
        $del = rows($db, "SELECT d.ID, d.post_type, d.post_status FROM `$dest`.wp_posts d LEFT JOIN {$ST['posts']} s ON s.ID=d.ID WHERE d.post_type IN ('product','product_variation') AND s.ID IS NULL AND NOT EXISTS (SELECT 1 FROM `$dest`.wp_postmeta m WHERE m.post_id=d.ID AND m.meta_key='_dd_migration_placeholder')");
        $sum['products']['deleted_on_legacy_kept'] = count($del);
        write_csv("$rep/review-products-deleted-on-legacy.csv", ['product_id', 'type', 'destination_status (kept)'], array_map('array_values', $del));
        $lap('products');
    }
    $sum['url_rewrite_unresolved'] = $s->report['url_rewrite_unresolved'];
    $sum['timing_seconds'] = $timing;
    return $sum;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $a = args($argv);
    $mode = $a['_'][0] ?? fail('mode required: plan|apply');
    $src = src_ref($a);
    $dest = $a['dest'] ?? fail('--dest required');
    guard_dbs($dest, $src, $a);
    $db = connect($dest);
    guard_identity($db, $dest, $src);
    // One migration run at a time per database server (also covers renumber/cleanup, same lock name).
    if ((int) one($db, "SELECT GET_LOCK('dd_migration', 0)") !== 1) {
        fail('another migration run holds the dd_migration lock');
    }
    ensure_state_tables($db, $dest);
    // Preflight on every run: ID headroom, and no destination-owned record inside legacy's ID space
    // (already-moved records are recognised through dd_mig_idmap; new ones must be renumbered first).
    $h = headroom($db, $dest, $src, false);
    if (!$h['ok']) {
        fail('headroom preflight failed: ' . json_encode(array_filter(array_map(fn ($x) => $x['problems'], $h['tables']))));
    }
    $cand = candidates($db, $dest, $src);
    $isolate = isset($a['isolate-product-conflicts']);
    $prodCand = product_candidates($db, $dest, $cand['posts']);
    $nonProduct = array_values(array_diff($cand['posts'], array_keys($prodCand)));
    if ($nonProduct || $cand['users'] || ($prodCand && !$isolate)) {
        fail('new ID collisions: ' . count($nonProduct) . ' destination posts, ' . count($prodCand) . ' destination products, ' . count($cand['users']) . ' users sit in legacy ID space — run renumber.php audit/apply first (products are never relocated: decide them, or pass --isolate-product-conflicts)');
    }
    // Every table the sync reads must exist in the source (fail before writing anything).
    $need = ['posts', 'postmeta', 'users', 'usermeta', 'comments', 'commentmeta', 'woocommerce_order_items', 'woocommerce_order_itemmeta', 'terms',
        'term_taxonomy', 'term_relationships', 'wc_order_stats', 'wc_order_product_lookup', 'wc_order_tax_lookup', 'wc_order_coupon_lookup', 'wc_customer_lookup'];
    if (cfg()['customer_group_roles']) {
        $need[] = 'options';
    }
    foreach (array_keys(keyed_tables()) as $kt) {
        if ((int) one($db, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=' . e($db, $dest) . ' AND table_name=' . e($db, $kt))) {
            $need[] = $kt;
        }
    }
    $present = array_column(rows($db, 'SELECT table_name t FROM information_schema.tables WHERE table_schema=' . e($db, $src->schema)), 't');
    $missing = array_values(array_filter($need, fn ($t) => !in_array($src->name($t), $present, true)));
    if ($missing) {
        fail('source is missing required tables: ' . implode(', ', array_map(fn ($t) => $src->name($t), $missing)));
    }
    $identity = source_identity($db, $src);
    $run = start_run($db, $dest, "sync-$mode", $a);
    $t0 = microtime(true);
    $only = isset($a['only']) ? explode(',', $a['only']) : [];
    $sum = ['source' => $identity, 'destination' => ['schema' => $dest, 'home' => one($db, "SELECT option_value FROM `$dest`.wp_options WHERE option_name='home'"), 'run' => $run]];
    $sum += plan_and_apply($db, $src, $dest, $run, $mode === 'apply', $only, (int) ($a['batch'] ?? 500), $a['profile'] ?? fail('--profile required (verbatim | auto | rehearsal | kinsta-final)'), $isolate);
    if ($mode === 'apply') {
        $after = source_identity($db, $src);
        $sum['source_unchanged_during_run'] = ($after['checksum'] ?? null) === ($identity['checksum'] ?? null);
        if (!$sum['source_unchanged_during_run']) {
            finish_run($db, $dest, $run, 'source-changed', $sum);
            fail('source tables changed during the run — take a consistent snapshot after the write freeze and rerun');
        }
    }
    $sum['seconds'] = round(microtime(true) - $t0, 1);
    finish_run($db, $dest, $run, 'ok', $sum);
    file_put_contents(report_dir() . "/sync-$mode-" . $run . '.json', json_encode($sum, JSON_PRETTY_PRINT));
    out(json_encode($sum));
}
