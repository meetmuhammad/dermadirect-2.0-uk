<?php
/**
 * Reference discovery: finds every place a candidate ID (moved destination posts/users) appears in the
 * destination database — integer columns by value, text columns by standalone numeric token.
 * Read-only. Used by renumber.php (classification) and runnable alone for exploration:
 *   php scan.php --dest=dd_rh_dest --src=dd_rh_src [--summary]
 */
declare(strict_types=1);

namespace DDMig;

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/refs.php';

/**
 * Candidate sets. posts: destination-owned posts inside legacy's ID space (above the clone cutoff,
 * below the future range) that are not the same record as the source row at that ID.
 * users: destination-only users above the user cutoff.
 */
function candidates(\mysqli $db, string $dest, TableRef|string $src): array
{
    $c = cfg();
    $S = is_string($src) ? new TableRef($src, $c['prefix']) : $src;
    $hasMap = (int) one($db, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=' . e($db, $dest) . " AND table_name='dd_mig_idmap'");
    $mapped = fn ($ns) => $hasMap ? " AND d.ID NOT IN (SELECT new_id FROM `$dest`.dd_mig_idmap WHERE ns='$ns')" : '';
    $pc = (int) $c['cutoff']['posts'];
    $pf = (int) $c['future_start']['wp_posts'];
    $uc = (int) $c['cutoff']['users'];
    $uf = (int) $c['future_start']['wp_users'];
    $posts = array_map('intval', array_column(rows($db, "SELECT d.ID FROM " . t($dest, 'posts') . " d
        LEFT JOIN {$S['posts']} s ON s.ID=d.ID AND s.post_type=d.post_type AND s.post_date_gmt=d.post_date_gmt
        WHERE d.ID > $pc AND d.ID < $pf AND s.ID IS NULL" . $mapped('posts') . " ORDER BY d.ID"), 'ID'));
    $users = array_map('intval', array_column(rows($db, "SELECT d.ID FROM " . t($dest, 'users') . " d
        LEFT JOIN {$S['users']} s ON s.ID=d.ID AND s.user_login=d.user_login AND s.user_registered=d.user_registered
        WHERE d.ID > $uc AND d.ID < $uf AND s.ID IS NULL" . $mapped('users') . " ORDER BY d.ID"), 'ID'));
    return ['posts' => $posts, 'users' => $users];
}

/**
 * Product guard shared by renumber.php and sync.php: destination products and variations are never
 * relocated (scope 2026-10-19). Returns [id => [type, status, title, sku]] for every candidate post ID
 * that is a product or variation on the destination.
 */
function product_candidates(\mysqli $db, string $dest, array $postIds): array
{
    if (!$postIds) {
        return [];
    }
    $out = [];
    foreach (array_chunk($postIds, 2000) as $ch) {
        foreach (rows($db, "SELECT p.ID id, p.post_type t, p.post_status st, p.post_title title, (SELECT meta_value FROM " . t($dest, 'postmeta') . " WHERE post_id=p.ID AND meta_key='_sku' LIMIT 1) sku
            FROM " . t($dest, 'posts') . " p WHERE p.ID IN (" . implode(',', array_map('intval', $ch)) . ") AND p.post_type IN ('product','product_variation')") as $r) {
            $out[(int) $r['id']] = [$r['t'], $r['st'], $r['title'], (string) $r['sku']];
        }
    }
    return $out;
}

/** A REGEXP prefilter that matches (at least) every decimal token in the given ID list. */
function prefilter_regex(array $ids): string
{
    $parts = [];
    foreach ($ids as $id) {
        $parts[substr((string) $id, 0, max(1, strlen((string) $id) - 3))] = strlen((string) $id);
    }
    $alts = [];
    foreach ($parts as $prefix => $len) {
        $alts[] = $prefix . '[0-9]{' . ($len - strlen((string) $prefix)) . '}';
    }
    return '(^|[^0-9])(' . implode('|', array_unique($alts)) . ')([^0-9]|$)';
}

/** Standalone decimal tokens (not part of a longer digit run or a decimal fraction) that are in $set. */
function tokens_in(string $text, array $set): array
{
    preg_match_all('/(?<![0-9.])[0-9]+(?![0-9]|\.[0-9])/', $text, $m, PREG_OFFSET_CAPTURE);
    $hits = [];
    foreach ($m[0] as [$tok, $off]) {
        if (isset($set[(int) $tok]) && (string) (int) $tok === $tok) {
            $hits[] = [(int) $tok, $off];
        }
    }
    return $hits;
}

/** Primary-key column(s) of a table. */
function pk(\mysqli $db, string $schema, string $table): array
{
    return array_column(rows($db, "SELECT column_name c FROM information_schema.key_column_usage
        WHERE table_schema=" . e($db, $schema) . " AND table_name=" . e($db, $table) . " AND constraint_name='PRIMARY' ORDER BY ordinal_position"), 'c');
}

/**
 * Yields hits: [ns, table, column, pk(json), id, context(json)] for every occurrence.
 * context carries meta_key/option_name/post_type etc. for classification.
 */
function scan(\mysqli $db, string $dest, array $cands, array $skipTables = []): \Generator
{
    $sets = [];
    foreach ($cands as $ns => $ids) {
        if ($ids) {
            $sets[$ns] = array_fill_keys($ids, true);
            q($db, "DROP TEMPORARY TABLE IF EXISTS tmp_$ns");
            q($db, "CREATE TEMPORARY TABLE tmp_$ns (id BIGINT UNSIGNED PRIMARY KEY)");
            foreach (array_chunk($ids, 1000) as $ch) {
                q($db, "INSERT INTO tmp_$ns VALUES (" . implode('),(', $ch) . ')');
            }
        }
    }
    $cols = rows($db, "SELECT c.table_name tn, c.column_name cn, c.data_type dt FROM information_schema.columns c
        JOIN information_schema.tables t ON t.table_schema=c.table_schema AND t.table_name=c.table_name AND t.table_type='BASE TABLE'
        WHERE c.table_schema=" . e($db, $dest) . " AND c.table_name LIKE " . e($db, addcslashes(cfg()['prefix'], '_') . '%') . " ORDER BY c.table_name, c.ordinal_position");
    $ctxCols = ['meta_key', 'option_name', 'post_type', 'post_status', 'taxonomy', 'comment_type', 'object_type', 'post_id', 'user_id', 'term_id', 'comment_id', 'order_item_id', 'order_id', 'hook'];
    $byTable = [];
    foreach ($cols as $c) {
        $byTable[$c['tn']][$c['cn']] = $c['dt'];
    }
    foreach ($byTable as $table => $columns) {
        if (in_array($table, $skipTables, true)) {
            continue;
        }
        $pkc = pk($db, $dest, $table);
        if (!$pkc) {
            $pkc = array_keys($columns);
        }
        $ctx = array_values(array_intersect($ctxCols, array_keys($columns)));
        $sel = implode(",", array_map(fn ($c) => "x.`$c`", array_unique(array_merge($pkc, $ctx))));
        foreach ($columns as $col => $type) {
            foreach ($sets as $ns => $set) {
                if (in_array($type, ['bigint', 'int', 'mediumint', 'smallint'], true)) {
                    if ($type === 'smallint' || ($type === 'mediumint' && max(array_keys($set)) > 16777215)) {
                        continue;
                    }
                    $res = q($db, "SELECT $sel, x.`$col` AS __v FROM `$dest`.`$table` x JOIN tmp_$ns m ON m.id = x.`$col`");
                    while ($r = $res->fetch_assoc()) {
                        yield [$ns, $table, $col, json_encode(array_intersect_key($r, array_flip($pkc))), (int) $r['__v'], json_encode(array_intersect_key($r, array_flip($ctx))), 'int'];
                    }
                } elseif (in_array($type, ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'longblob', 'blob', 'mediumblob'], true)) {
                    $re = prefilter_regex(array_keys($set));
                    $res = q($db, "SELECT $sel, x.`$col` AS __v FROM `$dest`.`$table` x WHERE x.`$col` REGEXP " . e($db, $re));
                    while ($r = $res->fetch_assoc()) {
                        foreach (\DDMig\Refs\tokens((string) $r['__v'], $set) as [$id, $off]) {
                            yield [$ns, $table, $col, json_encode(array_intersect_key($r, array_flip($pkc))), $id, json_encode(array_intersect_key($r, array_flip($ctx)) + ['off' => $off, 'snip' => substr((string) $r['__v'], max(0, $off - 40), 90)]), 'text'];
                        }
                    }
                }
            }
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $a = args($argv);
    $dest = $a['dest'] ?? fail('--dest required');
    $src = src_ref($a);
    guard_dbs($dest, $src, $a);
    $db = connect($dest);
    $c = candidates($db, $dest, $src);
    out('candidates: posts=' . count($c['posts']) . ' users=' . count($c['users']) . ' users=' . implode(',', $c['users']));
    $sum = [];
    $f = fopen(report_dir() . '/scan-hits.csv', 'w');
    fputcsv($f, ['ns', 'table', 'column', 'pk', 'id', 'context', 'kind'], ',', '"', '');
    foreach (scan($db, $dest, $c) as $h) {
        fputcsv($f, $h, ',', '"', '');
        $ctx = json_decode($h[5], true);
        $k = $h[0] . ' | ' . $h[1] . '.' . $h[2] . ' | ' . ($ctx['meta_key'] ?? $ctx['option_name'] ?? $ctx['post_type'] ?? '');
        $sum[$k] = ($sum[$k] ?? 0) + 1;
    }
    fclose($f);
    arsort($sum);
    foreach ($sum as $k => $n) {
        echo str_pad((string) $n, 7, ' ', STR_PAD_LEFT) . "  $k\n";
    }
}
