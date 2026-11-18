<?php
/**
 * Plugin Name:  DermaDirect database indexes
 * Description:  Declares the custom database indexes the site needs and manages them with WP-CLI
 *               (wp dd db-indexes status|ensure), so every environment has the same schema.
 * Version:      1.0.0
 *
 * Why: Woo Discount Rules Pro's "first order" condition runs a WP_Query on shop_order posts joined to postmeta with
 * meta_key = '_customer_user' (or '_billing_email') AND meta_value = <value>. Core wp_postmeta only indexes meta_key,
 * so MySQL scans every _customer_user row (10 to 30 s per uncached product page, cart or REST request). Production
 * has a composite index meta_key_value (meta_key(64), meta_value(64)); a rebuilt or imported database does not.
 *
 * Safety: the ALTER only ever runs from WP-CLI (`wp dd db-indexes ensure`), never on a web request. It only creates
 * missing indexes, refuses to touch an index that exists with a different definition, and does nothing if the table
 * is missing or SHOW INDEX fails. The optional admin notice is for administrators in wp-admin only, and its
 * SHOW INDEX check is cached in a transient for 12 hours.
 */

namespace DermaDirect\DbIndexes;

defined('ABSPATH') || exit;

/** Bump when required_indexes() changes, so `status` shows whether an environment has run the latest `ensure`. */
const SCHEMA_VERSION = 1;

/** Option recording the schema version of the last successful `ensure` (autoload no). */
const VERSION_OPTION = 'dd_db_indexes_version';

/** Transient caching the admin notice check. */
const NOTICE_TRANSIENT = 'dd_db_indexes_check';
const NOTICE_TTL = 12 * 3600;

const STATE_PRESENT = 'present';
const STATE_MISSING = 'missing';
const STATE_DIFFERENT = 'different';
const STATE_ERROR = 'error';

/**
 * The indexes every environment must have. Each entry:
 *   table   Table name without the WordPress prefix.
 *   name    Index name.
 *   columns List of [column, prefix length]; prefix length null means the whole column.
 *   why     One line shown in docs and notices.
 *
 * @return list<array{table:string,name:string,columns:list<array{0:string,1:?int}>,why:string}>
 */
function required_indexes(): array
{
    return [
        [
            'table' => 'postmeta',
            'name' => 'meta_key_value',
            'columns' => [['meta_key', 64], ['meta_value', 64]],
            'why' => 'Woo Discount Rules first-order lookup (_customer_user / _billing_email on shop_order postmeta)',
        ],
    ];
}

/** True for a safe SQL identifier (letters, digits, underscore). Empty is allowed only when $allowEmpty. */
function is_safe_identifier(string $name, bool $allowEmpty = false): bool
{
    if ($name === '') {
        return $allowEmpty;
    }

    return (bool) preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name);
}

/** Full table name for a prefix and a table suffix, e.g. ('wp_', 'postmeta') => 'wp_postmeta'. */
function table_name(string $prefix, string $suffix): string
{
    if (!is_safe_identifier($prefix, true) || !is_safe_identifier($suffix)) {
        throw new \InvalidArgumentException('Unsafe table prefix or name.');
    }

    $table = $prefix . $suffix;
    if (!is_safe_identifier($table)) {
        throw new \InvalidArgumentException('Table name too long.');
    }

    return $table;
}

/**
 * Pure: the ALTER statement that creates $index on the prefixed table, online and without locking writes.
 *
 * @param array{table:string,name:string,columns:list<array{0:string,1:?int}>} $index
 */
function build_add_index_sql(string $prefix, array $index): string
{
    $table = table_name($prefix, (string) ($index['table'] ?? ''));
    $name = (string) ($index['name'] ?? '');
    if (!is_safe_identifier($name)) {
        throw new \InvalidArgumentException('Unsafe index name.');
    }

    $columns = $index['columns'] ?? [];
    if (!is_array($columns) || $columns === []) {
        throw new \InvalidArgumentException('Index has no columns.');
    }

    $parts = [];
    foreach ($columns as $column) {
        [$col, $len] = [(string) ($column[0] ?? ''), $column[1] ?? null];
        if (!is_safe_identifier($col)) {
            throw new \InvalidArgumentException('Unsafe column name.');
        }
        if ($len !== null && (!is_int($len) || $len < 1)) {
            throw new \InvalidArgumentException('Invalid prefix length.');
        }
        $parts[] = $len === null ? $col : "{$col}({$len})";
    }

    return "ALTER TABLE {$table} ADD INDEX {$name} (" . implode(', ', $parts) . '), ALGORITHM=INPLACE, LOCK=NONE';
}

/**
 * Pure: the columns of index $name from SHOW INDEX rows, in Seq_in_index order, as [lowercase column, ?int prefix].
 * Rows may be associative arrays or objects. Returns [] when the index does not exist.
 *
 * @param iterable<array<string,mixed>|object> $rows
 * @return list<array{0:string,1:?int}>
 */
function index_columns_from_rows(iterable $rows, string $name): array
{
    $found = [];
    foreach ($rows as $row) {
        $row = (array) $row;
        if (strcasecmp((string) ($row['Key_name'] ?? ''), $name) !== 0) {
            continue;
        }
        $sub = $row['Sub_part'] ?? null;
        $found[(int) ($row['Seq_in_index'] ?? 0)] = [
            strtolower((string) ($row['Column_name'] ?? '')),
            ($sub === null || $sub === '') ? null : (int) $sub,
        ];
    }
    ksort($found);

    return array_values($found);
}

/** Pure: an index definition's columns normalised the same way as index_columns_from_rows(). */
function definition_columns(array $index): array
{
    return array_map(
        static fn ($c) => [strtolower((string) $c[0]), isset($c[1]) ? (int) $c[1] : null],
        $index['columns'] ?? []
    );
}

/**
 * Pure: compare SHOW INDEX rows for one table to an index definition.
 *
 * present   an index with this name exists with exactly these columns, order and prefix lengths, and is not UNIQUE
 * missing   no index with this name
 * different an index with this name exists with other columns, order, prefix lengths or uniqueness
 *
 * @param iterable<array<string,mixed>|object> $rows
 */
function compare_index(iterable $rows, array $index): string
{
    $rows = is_array($rows) ? $rows : iterator_to_array($rows, false);
    $name = (string) ($index['name'] ?? '');
    $actual = index_columns_from_rows($rows, $name);

    if ($actual === []) {
        return STATE_MISSING;
    }

    foreach ($rows as $row) {
        $row = (array) $row;
        if (strcasecmp((string) ($row['Key_name'] ?? ''), $name) === 0
            && array_key_exists('Non_unique', $row) && (int) $row['Non_unique'] === 0) {
            return STATE_DIFFERENT;
        }
    }

    return $actual === definition_columns($index) ? STATE_PRESENT : STATE_DIFFERENT;
}

/**
 * Pure: names of other indexes on the table with exactly the same columns and prefix lengths as $index.
 * Used to avoid building a duplicate when the same index exists under another name.
 *
 * @param iterable<array<string,mixed>|object> $rows
 * @return list<string>
 */
function equivalent_index_names(iterable $rows, array $index): array
{
    $rows = is_array($rows) ? $rows : iterator_to_array($rows, false);
    $want = definition_columns($index);
    $names = [];
    foreach ($rows as $row) {
        $key = (string) (((array) $row)['Key_name'] ?? '');
        if ($key !== '' && strcasecmp($key, (string) $index['name']) !== 0) {
            $names[$key] = true;
        }
    }

    $out = [];
    foreach (array_keys($names) as $key) {
        if (index_columns_from_rows($rows, (string) $key) === $want) {
            $out[] = (string) $key;
        }
    }

    return $out;
}

/* ------------------------------------------------------------------------
 * Database access (needs WordPress)
 * ---------------------------------------------------------------------- */

/**
 * Read SHOW INDEX for a table. Fail safe: never throws, reports why it could not read.
 *
 * @return array{ok:bool,rows:list<array<string,mixed>>,error:string}
 */
function read_index_rows(\wpdb $wpdb, string $table): array
{
    $suppress = $wpdb->suppress_errors(true);
    try {
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($exists !== $table) {
            return ['ok' => false, 'rows' => [], 'error' => $wpdb->last_error !== '' ? $wpdb->last_error : "table {$table} not found"];
        }

        $rows = $wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A);
        if (!is_array($rows) || $rows === [] || $wpdb->last_error !== '') {
            return ['ok' => false, 'rows' => [], 'error' => $wpdb->last_error !== '' ? $wpdb->last_error : 'SHOW INDEX returned nothing'];
        }

        return ['ok' => true, 'rows' => $rows, 'error' => ''];
    } finally {
        $wpdb->suppress_errors($suppress);
    }
}

/**
 * State of every required index in this environment.
 *
 * @return list<array{table:string,index:string,state:string,detail:string,def:array}>
 */
function current_states(\wpdb $wpdb): array
{
    $out = [];
    $cache = [];
    foreach (required_indexes() as $index) {
        $table = table_name($wpdb->prefix, $index['table']);
        $cache[$table] ??= read_index_rows($wpdb, $table);
        $read = $cache[$table];

        if (!$read['ok']) {
            $out[] = ['table' => $table, 'index' => $index['name'], 'state' => STATE_ERROR, 'detail' => $read['error'], 'def' => $index];
            continue;
        }

        $state = compare_index($read['rows'], $index);
        $detail = '';
        if ($state === STATE_MISSING && ($same = equivalent_index_names($read['rows'], $index))) {
            $detail = 'same columns exist as ' . implode(', ', $same);
        } elseif ($state === STATE_DIFFERENT) {
            $detail = 'existing: ' . describe_columns(index_columns_from_rows($read['rows'], $index['name']))
                . '; wanted: ' . describe_columns(definition_columns($index));
        }
        $out[] = ['table' => $table, 'index' => $index['name'], 'state' => $state, 'detail' => $detail, 'def' => $index];
    }

    return $out;
}

/** @param list<array{0:string,1:?int}> $columns */
function describe_columns(array $columns): string
{
    return '(' . implode(', ', array_map(static fn ($c) => $c[1] === null ? $c[0] : "{$c[0]}({$c[1]})", $columns)) . ')';
}

/* ------------------------------------------------------------------------
 * WP-CLI: wp dd db-indexes status|ensure
 * ---------------------------------------------------------------------- */

final class Command
{
    /**
     * Show the state of every required database index.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp dd db-indexes status
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        global $wpdb;

        $states = current_states($wpdb);
        \WP_CLI\Utils\format_items($assoc['format'] ?? 'table', array_map(
            static fn ($s) => ['table' => $s['table'], 'index' => $s['index'], 'state' => $s['state']],
            $states
        ), ['table', 'index', 'state']);

        foreach ($states as $s) {
            if ($s['detail'] !== '') {
                \WP_CLI::log("{$s['table']}.{$s['index']}: {$s['detail']}");
            }
        }

        $stored = get_option(VERSION_OPTION, null);
        \WP_CLI::log('Schema version: code ' . SCHEMA_VERSION . ', stored ' . ($stored === null || $stored === false ? 'none' : (string) $stored));
    }

    /**
     * Create missing required indexes. Never changes or drops an existing index.
     *
     * Runs ALTER TABLE ... ALGORITHM=INPLACE, LOCK=NONE. On a large table this takes minutes; reads and writes
     * continue meanwhile. Run once per environment after a database import.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Print the SQL that would run, change nothing.
     *
     * ## EXAMPLES
     *
     *     wp dd db-indexes ensure --dry-run
     *     wp dd db-indexes ensure
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function ensure(array $args, array $assoc): void
    {
        global $wpdb;

        $dryRun = (bool) \WP_CLI\Utils\get_flag_value($assoc, 'dry-run', false);
        $problems = 0;

        foreach (current_states($wpdb) as $s) {
            $label = "{$s['table']}.{$s['index']}";

            if ($s['state'] === STATE_PRESENT) {
                \WP_CLI::log("{$label}: present, nothing to do.");
                continue;
            }
            if ($s['state'] === STATE_ERROR) {
                \WP_CLI::warning("{$label}: cannot read indexes ({$s['detail']}). Skipped.");
                $problems++;
                continue;
            }
            if ($s['state'] === STATE_DIFFERENT) {
                \WP_CLI::warning("{$label}: exists with a different definition, not touched. {$s['detail']}");
                $problems++;
                continue;
            }
            if ($s['detail'] !== '') {
                \WP_CLI::warning("{$label}: missing but {$s['detail']}; not adding a duplicate. Skipped.");
                $problems++;
                continue;
            }

            $sql = build_add_index_sql($wpdb->prefix, $s['def']);
            if ($dryRun) {
                \WP_CLI::log("{$sql};");
                continue;
            }

            \WP_CLI::log("{$label}: creating...");
            $start = microtime(true);
            $result = $wpdb->query($sql);
            $seconds = microtime(true) - $start;

            if ($result === false) {
                \WP_CLI::warning(sprintf('%s: failed after %.1f s: %s', $label, $seconds, $wpdb->last_error));
                $problems++;
                continue;
            }
            \WP_CLI::log(sprintf('%s: created in %.1f s.', $label, $seconds));
        }

        if ($dryRun) {
            \WP_CLI::success('Dry run, nothing changed.');
            return;
        }

        if (function_exists('delete_transient')) {
            delete_transient(NOTICE_TRANSIENT);
        }

        // Record the version only when every required index is now confirmed present.
        $allPresent = !array_filter(current_states($wpdb), static fn ($s) => $s['state'] !== STATE_PRESENT);
        if ($problems === 0 && $allPresent) {
            update_option(VERSION_OPTION, SCHEMA_VERSION, false);
            \WP_CLI::success('All required indexes present (schema version ' . SCHEMA_VERSION . ').');
            return;
        }

        \WP_CLI::error("{$problems} index(es) need attention; see warnings above. Schema version not recorded.");
    }
}

if (defined('WP_CLI') && WP_CLI && class_exists('\WP_CLI')) {
    \WP_CLI::add_command('dd db-indexes', Command::class);
}

/* ------------------------------------------------------------------------
 * Admin notice (wp-admin, administrators only, cached 12 hours)
 * ---------------------------------------------------------------------- */

function maybe_admin_notice(): void
{
    if (!is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || !current_user_can('manage_options')) {
        return;
    }

    $missing = get_transient(NOTICE_TRANSIENT);
    if (!is_array($missing)) {
        global $wpdb;
        $missing = [];
        foreach (current_states($wpdb) as $s) {
            if ($s['state'] !== STATE_PRESENT) {
                $missing[] = "{$s['table']}.{$s['index']} ({$s['state']})";
            }
        }
        set_transient(NOTICE_TRANSIENT, $missing, NOTICE_TTL);
    }

    if ($missing === []) {
        return;
    }

    printf(
        '<div class="notice notice-warning"><p><strong>Database index check:</strong> %s. '
        . 'Run <code>wp dd db-indexes status</code> and <code>wp dd db-indexes ensure</code> from WP-CLI. '
        . 'Without it, Woo Discount Rules order lookups are very slow.</p></div>',
        esc_html(implode(', ', $missing))
    );
}

if (function_exists('add_action')) {
    add_action('admin_notices', __NAMESPACE__ . '\maybe_admin_notice');
}
