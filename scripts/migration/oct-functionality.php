<?php
/**
 * October 2026 business functionality: the destination configuration it needs, as an explicit change set.
 *   php oct-functionality.php plan  --dest=<db> --legacy-rules=<db with lgcy_wdr_rules> --src=<snapshot db> --src-prefix=lgcy_
 *   php oct-functionality.php apply --dest=<db> --legacy-rules=<db> --src=<snapshot db> --src-prefix=lgcy_
 * plan  compares every legacy Discount Rules rule with the destination's (matched by title, field by field)
 *       and checks PRODERMIS category membership per product. Writes nothing.
 * apply adds ONLY the approved rules (APPROVED_RULES, copied verbatim from legacy; the legacy id is kept when
 *       free) and only when absent. Category membership is never changed here: differences are listed as a
 *       proposed change for review (oct-functionality-proposed.csv). Every other rule difference is reported.
 * Role definition and memberships come from sync.php (customer_group_roles); the rules' runtime code is
 * mu-plugins/dd-commerce-rules.php.
 */
declare(strict_types=1);

namespace DDMig;

require_once __DIR__ . '/lib.php';

const APPROVED_RULES = ['Nexfill Switch - Prosculpt Buy 5 Get 5 £205 ex VAT'];  // live since 2026-10-07 (Paul)
const PRODERMIS_SLUG = 'prodermis%ef%b8%8f';
const RUNTIME_COLS = ['id', 'created_by', 'created_on', 'modified_by', 'modified_on', 'used_limits'];

$a = args($argv);
$mode = $a['_'][0] ?? fail('mode required: plan|apply');
$dest = $a['dest'] ?? fail('--dest required');
$L = new TableRef($a['legacy-rules'] ?? fail('--legacy-rules required'), 'lgcy_');
$S = src_ref($a);
guard_dbs($dest, $S, $a);
$db = connect($dest);
$D = dest_ref($dest);
$rep = report_dir();
$cols = array_column(rows($db, "SELECT column_name c FROM information_schema.columns WHERE table_schema=" . e($db, $dest) . " AND table_name='wp_wdr_rules' ORDER BY ordinal_position"), 'c');
$lcols = array_column(rows($db, "SELECT column_name c FROM information_schema.columns WHERE table_schema=" . e($db, $L->schema) . " AND table_name='lgcy_wdr_rules' ORDER BY ordinal_position"), 'c');
$cols === $lcols || fail('Discount Rules table schema differs between legacy and destination: ' . json_encode([array_diff($lcols, $cols), array_diff($cols, $lcols)]));
$cmp = array_values(array_diff($cols, RUNTIME_COLS));

$byTitle = function (TableRef $T) use ($db): array {
    $o = [];
    foreach (rows($db, "SELECT * FROM {$T['wdr_rules']} ORDER BY id") as $r) {
        $o[$r['title']][] = $r;
    }
    return $o;
};
$lr = $byTitle($L);
$dr = $byTitle($D);
$csv = [];
$sum = ['legacy_rules' => array_sum(array_map('count', $lr)), 'destination_rules' => array_sum(array_map('count', $dr)), 'identical' => 0, 'differ' => 0, 'legacy_only' => 0, 'destination_only' => 0, 'duplicate_titles' => 0];
foreach ($lr as $title => $ls) {
    if (count($ls) > 1 || count($dr[$title] ?? []) > 1) {
        $sum['duplicate_titles']++;
        $csv[] = [$title, implode('/', array_column($ls, 'id')), implode('/', array_column($dr[$title] ?? [], 'id')), 'duplicate title (compare by hand)', ''];
        continue;
    }
    $l = $ls[0];
    $d = $dr[$title][0] ?? null;
    if (!$d) {
        $sum['legacy_only']++;
        $csv[] = [$title, $l['id'], '', 'legacy only', ($l['enabled'] && !$l['deleted'] ? 'active' : 'inactive') . (in_array($title, APPROVED_RULES, true) ? '; APPROVED to add' : '')];
        continue;
    }
    $diff = array_values(array_filter($cmp, fn ($c) => (string) $l[$c] !== (string) $d[$c]));
    $diff ? $sum['differ']++ : $sum['identical']++;
    if ($diff) {
        $csv[] = [$title, $l['id'], $d['id'], 'differs', implode('+', $diff)];
    }
}
foreach ($dr as $title => $ds) {
    if (!isset($lr[$title])) {
        $sum['destination_only'] += count($ds);
        $csv[] = [$title, '', implode('/', array_column($ds, 'id')), 'destination only', ''];
    }
}
write_csv("$rep/oct-functionality-rules.csv", ['title', 'legacy_id', 'destination_id', 'class', 'detail'], $csv);

// PRODERMIS membership: legacy product IDs in the category vs the destination's (same IDs = same products,
// identity checked by SKU in product-refs.php); never changed here.
$lt = rows($db, "SELECT t.term_id FROM {$S['terms']} t JOIN {$S['term_taxonomy']} tt USING(term_id) WHERE tt.taxonomy='product_cat' AND t.slug=" . e($db, PRODERMIS_SLUG))[0]['term_id'] ?? null;
$dt = rows($db, "SELECT t.term_id FROM {$D['terms']} t JOIN {$D['term_taxonomy']} tt USING(term_id) WHERE tt.taxonomy='product_cat' AND t.slug=" . e($db, PRODERMIS_SLUG))[0]['term_id'] ?? null;
$members = fn (TableRef $T, $tid) => $tid ? array_map('intval', array_column(rows($db, "SELECT r.object_id id FROM {$T['term_relationships']} r JOIN {$T['term_taxonomy']} tt ON tt.term_taxonomy_id=r.term_taxonomy_id WHERE tt.term_id=" . (int) $tid . " ORDER BY 1"), 'id')) : [];
$lm = $members($S, $lt);
$dm = $members($D, $dt);
$proposed = array_map(fn ($id) => ['add product to PRODERMIS (product_cat ' . $dt . ')', $id, (string) one($db, "SELECT post_title FROM {$D['posts']} WHERE ID=$id"), (string) one($db, "SELECT post_status FROM {$D['posts']} WHERE ID=$id")], array_values(array_diff($lm, $dm)));
foreach (array_values(array_diff($dm, $lm)) as $id) {
    $proposed[] = ['in destination PRODERMIS but not legacy (review)', $id, (string) one($db, "SELECT post_title FROM {$D['posts']} WHERE ID=$id"), ''];
}
write_csv("$rep/oct-functionality-proposed.csv", ['proposed change (NOT applied)', 'product_id', 'title', 'destination_status'], $proposed);
$sum['prodermis'] = ['legacy_term' => $lt, 'destination_term' => $dt, 'legacy_members' => $lm, 'destination_members' => $dm, 'proposed_changes' => count($proposed)];

$applied = [];
if ($mode === 'apply') {
    foreach (APPROVED_RULES as $title) {
        if (isset($dr[$title])) {
            $applied[] = "$title: already present (destination id " . implode('/', array_column($dr[$title], 'id')) . ')';
            continue;
        }
        $l = $lr[$title][0] ?? fail("approved rule '$title' not found in legacy rules");
        $row = $l;
        $free = !(int) one($db, "SELECT COUNT(*) FROM {$D['wdr_rules']} WHERE id=" . (int) $l['id']);
        if (!$free) {
            unset($row['id']);
        }
        q($db, "INSERT INTO {$D['wdr_rules']} (" . implode(',', array_map(fn ($c) => "`$c`", array_keys($row))) . ') VALUES (' . implode(',', array_map(fn ($v) => e($db, $v), $row)) . ')');
        $applied[] = "$title: added (id " . ($free ? $l['id'] : $db->insert_id) . ', copied verbatim from legacy)';
    }
}
$sum['applied'] = $applied;
file_put_contents("$rep/oct-functionality-$mode-" . gmdate('Ymd\THis') . '.json', json_encode($sum, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
out(json_encode($sum, JSON_UNESCAPED_UNICODE));
