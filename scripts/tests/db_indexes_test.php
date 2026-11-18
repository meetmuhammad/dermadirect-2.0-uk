<?php
/**
 * Standalone tests for the database indexes mu-plugin (pure functions only).
 *
 * Runs without WordPress:
 *   docker run --rm -v "$PWD":/app -w /app php:8.2-cli php scripts/tests/db_indexes_test.php
 *
 * add_action and WP_CLI are not defined here, so loading the mu-plugin registers no hooks or commands.
 */

use function DermaDirect\DbIndexes\build_add_index_sql;
use function DermaDirect\DbIndexes\compare_index;
use function DermaDirect\DbIndexes\equivalent_index_names;
use function DermaDirect\DbIndexes\required_indexes;
use function DermaDirect\DbIndexes\table_name;

define('ABSPATH', __DIR__ . '/');

require dirname(__DIR__, 2) . '/web/app/mu-plugins/dd-db-indexes.php';

$failures = 0;
$passes = 0;

function check(string $name, bool $ok): void
{
    global $failures, $passes;

    if ($ok) {
        $passes++;
        echo "PASS  {$name}\n";
    } else {
        $failures++;
        echo "FAIL  {$name}\n";
    }
}

function throws(callable $fn): bool
{
    try {
        $fn();
    } catch (\InvalidArgumentException $e) {
        return true;
    }

    return false;
}

/** SHOW INDEX row as $wpdb ARRAY_A returns it (numbers as strings, Sub_part NULL for whole column). */
function row(string $key, int $seq, string $column, $subPart, string $nonUnique = '1'): array
{
    return [
        'Table' => 'wp_postmeta',
        'Non_unique' => $nonUnique,
        'Key_name' => $key,
        'Seq_in_index' => (string) $seq,
        'Column_name' => $column,
        'Sub_part' => $subPart === null ? null : (string) $subPart,
        'Index_type' => 'BTREE',
    ];
}

/** Core wp_postmeta indexes. */
function core_rows(): array
{
    return [
        row('PRIMARY', 1, 'meta_id', null, '0'),
        row('post_id', 1, 'post_id', null),
        row('meta_key', 1, 'meta_key', 191),
    ];
}

/* ---------------- Index list sanity ---------------- */

$list = required_indexes();
check('index list is a non-empty list', is_array($list) && $list !== [] && array_is_list($list));

$seen = [];
$allValid = true;
foreach ($list as $idx) {
    $allValid = $allValid
        && is_string($idx['table'] ?? null) && preg_match('/^[a-z0-9_]+$/', $idx['table'])
        && is_string($idx['name'] ?? null) && preg_match('/^[a-z0-9_]+$/', $idx['name'])
        && is_array($idx['columns'] ?? null) && $idx['columns'] !== []
        && is_string($idx['why'] ?? null) && $idx['why'] !== '';
    foreach ($idx['columns'] ?? [] as $col) {
        $allValid = $allValid && is_string($col[0] ?? null) && (($col[1] ?? null) === null || (is_int($col[1]) && $col[1] > 0));
    }
    $seen[$idx['table'] . '.' . $idx['name']] = ($seen[$idx['table'] . '.' . $idx['name']] ?? 0) + 1;
    $allValid = $allValid && !throws(fn () => build_add_index_sql('wp_', $idx));
}
check('every index entry is well formed and builds SQL', $allValid);
check('no duplicate table.index entries', max($seen) === 1);

$mkv = null;
foreach ($list as $idx) {
    if ($idx['table'] === 'postmeta' && $idx['name'] === 'meta_key_value') {
        $mkv = $idx;
    }
}
check('postmeta.meta_key_value is declared', $mkv !== null);
check('meta_key_value matches production (meta_key(64), meta_value(64))', $mkv['columns'] === [['meta_key', 64], ['meta_value', 64]]);

/* ---------------- SQL builder ---------------- */

check(
    'exact ALTER statement for wp_',
    build_add_index_sql('wp_', $mkv)
        === 'ALTER TABLE wp_postmeta ADD INDEX meta_key_value (meta_key(64), meta_value(64)), ALGORITHM=INPLACE, LOCK=NONE'
);
check(
    'custom prefix used',
    build_add_index_sql('dd7x_', $mkv)
        === 'ALTER TABLE dd7x_postmeta ADD INDEX meta_key_value (meta_key(64), meta_value(64)), ALGORITHM=INPLACE, LOCK=NONE'
);
check(
    'empty prefix allowed',
    build_add_index_sql('', $mkv)
        === 'ALTER TABLE postmeta ADD INDEX meta_key_value (meta_key(64), meta_value(64)), ALGORITHM=INPLACE, LOCK=NONE'
);
check(
    'whole-column entry has no prefix length',
    build_add_index_sql('wp_', ['table' => 'posts', 'name' => 'x', 'columns' => [['post_type', null], ['post_status', 20]]])
        === 'ALTER TABLE wp_posts ADD INDEX x (post_type, post_status(20)), ALGORITHM=INPLACE, LOCK=NONE'
);
check('multisite style prefix', table_name('wp_2_', 'postmeta') === 'wp_2_postmeta');

check('prefix with backtick rejected', throws(fn () => build_add_index_sql('wp`_', $mkv)));
check('prefix with space rejected', throws(fn () => build_add_index_sql('wp_ ; DROP TABLE x; ', $mkv)));
check('prefix with dot rejected', throws(fn () => build_add_index_sql('db.wp_', $mkv)));
check('unsafe index name rejected', throws(fn () => build_add_index_sql('wp_', ['name' => 'a-b'] + $mkv)));
check('unsafe column name rejected', throws(fn () => build_add_index_sql('wp_', ['columns' => [['meta_key)', 64]]] + $mkv)));
check('zero prefix length rejected', throws(fn () => build_add_index_sql('wp_', ['columns' => [['meta_key', 0]]] + $mkv)));
check('string prefix length rejected', throws(fn () => build_add_index_sql('wp_', ['columns' => [['meta_key', '64']]] + $mkv)));
check('no columns rejected', throws(fn () => build_add_index_sql('wp_', ['columns' => []] + $mkv)));
check('over-long table name rejected', throws(fn () => table_name(str_repeat('p', 60), 'postmeta')));

/* ---------------- Compare ---------------- */

$present = array_merge(core_rows(), [row('meta_key_value', 1, 'meta_key', 64), row('meta_key_value', 2, 'meta_value', 64)]);
check('present: production definition', compare_index($present, $mkv) === 'present');
check('present: rows out of order in result', compare_index(array_reverse($present), $mkv) === 'present');
check('present: rows as objects', compare_index(array_map(fn ($r) => (object) $r, $present), $mkv) === 'present');
check('present: integer Sub_part and Seq_in_index', compare_index([
    ['Key_name' => 'meta_key_value', 'Seq_in_index' => 1, 'Column_name' => 'meta_key', 'Sub_part' => 64],
    ['Key_name' => 'meta_key_value', 'Seq_in_index' => 2, 'Column_name' => 'meta_value', 'Sub_part' => 64],
], $mkv) === 'present');
check('present: column name case ignored', compare_index([
    row('meta_key_value', 1, 'META_KEY', 64), row('meta_key_value', 2, 'Meta_Value', 64),
], $mkv) === 'present');

check('missing: core indexes only', compare_index(core_rows(), $mkv) === 'missing');
check('missing: no rows at all', compare_index([], $mkv) === 'missing');
check('missing: similar name is not a match', compare_index(array_merge(core_rows(), [
    row('meta_key_value2', 1, 'meta_key', 64), row('meta_key_value2', 2, 'meta_value', 64),
]), $mkv) === 'missing');

check('different: wrong prefix length on meta_value', compare_index(array_merge(core_rows(), [
    row('meta_key_value', 1, 'meta_key', 64), row('meta_key_value', 2, 'meta_value', 32),
]), $mkv) === 'different');
check('different: wrong prefix length on meta_key', compare_index(array_merge(core_rows(), [
    row('meta_key_value', 1, 'meta_key', 191), row('meta_key_value', 2, 'meta_value', 64),
]), $mkv) === 'different');
check('different: whole column instead of prefix', compare_index(array_merge(core_rows(), [
    row('meta_key_value', 1, 'meta_key', null), row('meta_key_value', 2, 'meta_value', 64),
]), $mkv) === 'different');
check('different: wrong column order', compare_index(array_merge(core_rows(), [
    row('meta_key_value', 1, 'meta_value', 64), row('meta_key_value', 2, 'meta_key', 64),
]), $mkv) === 'different');
check('different: extra column', compare_index(array_merge(core_rows(), [
    row('meta_key_value', 1, 'meta_key', 64), row('meta_key_value', 2, 'meta_value', 64), row('meta_key_value', 3, 'post_id', null),
]), $mkv) === 'different');
check('different: missing second column', compare_index(array_merge(core_rows(), [
    row('meta_key_value', 1, 'meta_key', 64),
]), $mkv) === 'different');
check('different: wrong column', compare_index(array_merge(core_rows(), [
    row('meta_key_value', 1, 'meta_key', 64), row('meta_key_value', 2, 'post_id', null),
]), $mkv) === 'different');
check('different: unique index with same columns', compare_index(array_merge(core_rows(), [
    row('meta_key_value', 1, 'meta_key', 64, '0'), row('meta_key_value', 2, 'meta_value', 64, '0'),
]), $mkv) === 'different');

/* ---------------- Equivalent index under another name ---------------- */

$renamed = array_merge(core_rows(), [row('mkv', 1, 'meta_key', 64), row('mkv', 2, 'meta_value', 64)]);
check('equivalent index under another name found', equivalent_index_names($renamed, $mkv) === ['mkv']);
check('no equivalent on core table', equivalent_index_names(core_rows(), $mkv) === []);
check('index itself is not its own equivalent', equivalent_index_names($present, $mkv) === []);
check('different prefix is not equivalent', equivalent_index_names(array_merge(core_rows(), [
    row('mkv', 1, 'meta_key', 64), row('mkv', 2, 'meta_value', 191),
]), $mkv) === []);

echo "\n{$passes} passed, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
