<?php
/**
 * ID headroom (Decision 2): keeps destination-generated IDs out of legacy's ID space.
 *   php headroom.php check --dest=dd_rh_dest --src=dd_rh_src    report; exit 1 if any rule fails
 *   php headroom.php apply --dest=dd_rh_dest --src=dd_rh_src    raise AUTO_INCREMENT (never lowers)
 * Rules per table: legacy max + margin < future_start; destination AUTO_INCREMENT >= future_start;
 * no destination-generated row sits between legacy max and future_start (except rows that are
 * legacy records at their original IDs); relocated range untouched by legacy.
 * Explicit inserts of legacy IDs below future_start never lower InnoDB's counter, so imports are safe.
 */
declare(strict_types=1);

namespace DDMig;

require_once __DIR__ . '/lib.php';

function headroom(\mysqli $db, string $dest, TableRef $S, bool $apply): array
{
    $c = cfg();
    $pkOf = ['wp_posts' => 'ID', 'wp_users' => 'ID', 'wp_comments' => 'comment_ID', 'wp_woocommerce_order_items' => 'order_item_id',
        'wp_woocommerce_payment_tokens' => 'token_id', 'wp_webis_pbet' => 'id', 'wp_webis_pbet_order' => 'id', 'wp_webis_pbet_order_item' => 'id',
        'wp_woocommerce_bis_notifications' => 'id', 'wp_gens_raf' => 'id', 'wp_wc_customer_lookup' => 'customer_id'];
    $out = [];
    $ok = true;
    foreach ($c['future_start'] as $table => $start) {
        $pk = $pkOf[$table];
        $srcMax = (int) one($db, "SELECT COALESCE(MAX(`$pk`),0) FROM {$S[$table]}");
        $ai = (int) one($db, 'SELECT AUTO_INCREMENT FROM information_schema.tables WHERE table_schema=' . e($db, $dest) . ' AND table_name=' . e($db, $table));
        $ns = ['wp_posts' => 'posts', 'wp_users' => 'users'][$table] ?? null;
        // the relocated band is the offset mappings only; explicit --product-map targets are verified legacy IDs
        $reloc = $ns ? rows($db, "SELECT MIN(new_id) lo, MAX(new_id) hi FROM `$dest`.dd_mig_idmap WHERE ns='$ns' AND new_id = old_id + " . (int) $c['relocate_offset'][$ns])[0] : ['lo' => null, 'hi' => null];
        $nextBand = $reloc['lo'] !== null ? (int) $reloc['lo'] : $start;
        $excl = $ns ? " AND `$pk` NOT IN (SELECT new_id FROM `$dest`.dd_mig_idmap WHERE ns='$ns')" : '';
        // Destination-generated rows in legacy's growth range (above legacy max, below the future range),
        // excluding the approved relocated band: legacy would grow into them.
        $gap = (int) one($db, "SELECT COUNT(*) FROM `$dest`.`$table` WHERE `$pk` > $srcMax AND `$pk` < $start$excl");
        $inFuture = (int) one($db, "SELECT COUNT(*) FROM `$dest`.`$table` WHERE `$pk` >= $start");
        $margin = max((int) ceil($srcMax * $c['headroom_margin_ratio']), (int) $c['headroom_margin_min']);
        $r = ['legacy_max' => $srcMax, 'future_start' => $start, 'dest_auto_increment_before' => $ai, 'dest_rows_in_growth_gap' => $gap,
            'dest_rows_in_future_range' => $inFuture, 'days_of_headroom_hint' => null, 'problems' => []];
        if ($srcMax + $margin >= $nextBand) {
            $r['problems'][] = "legacy max $srcMax (+margin $margin) reaches the next reserved band at $nextBand";
        }
        if ($reloc['hi'] !== null && (int) $reloc['hi'] >= $start) {
            $r['problems'][] = "relocated band ends at {$reloc['hi']}, not below future_start $start";
        }
        $r['relocated_band'] = $reloc;
        if ($gap > 0) {
            $r['problems'][] = "$gap destination rows sit in legacy's growth range ($srcMax..$start) — renumber them first";
        }
        if ($apply && !$r['problems'] && $ai < $start) {
            q($db, "ALTER TABLE `$dest`.`$table` AUTO_INCREMENT = $start");
        }
        $r['dest_auto_increment_after'] = (int) one($db, 'SELECT AUTO_INCREMENT FROM information_schema.tables WHERE table_schema=' . e($db, $dest) . ' AND table_name=' . e($db, $table));
        if ($r['dest_auto_increment_after'] < $start) {
            $r['problems'][] = "AUTO_INCREMENT {$r['dest_auto_increment_after']} below future_start $start";
        }
        $ok = $ok && !$r['problems'];
        $out[$table] = $r;
    }
    return ['ok' => $ok, 'tables' => $out];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $a = args($argv);
    $mode = $a['_'][0] ?? 'check';
    $dest = $a['dest'] ?? fail('--dest required');
    $src = src_ref($a);
    guard_dbs($dest, $src, $a);
    $db = connect($dest);
    $r = headroom($db, $dest, $src, $mode === 'apply');
    foreach ($r['tables'] as $t => $x) {
        out(str_pad($t, 34) . sprintf('legacy_max=%-9d future=%-9d ai %d->%d gap=%d %s', $x['legacy_max'], $x['future_start'], $x['dest_auto_increment_before'], $x['dest_auto_increment_after'], $x['dest_rows_in_growth_gap'], $x['problems'] ? 'PROBLEM: ' . implode('; ', $x['problems']) : 'ok'));
    }
    file_put_contents(report_dir() . "/headroom-$mode.json", json_encode($r, JSON_PRETTY_PRINT));
    out($r['ok'] ? 'HEADROOM OK' : 'HEADROOM FAILED');
    exit($r['ok'] ? 0 : 1);
}
