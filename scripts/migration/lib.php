<?php
/**
 * Shared helpers for the DermaDirect migration tools. Plain PHP + mysqli: WordPress is never
 * bootstrapped by the data phases, so no hooks, emails, webhooks or stock logic can run.
 */
declare(strict_types=1);

namespace DDMig;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function cfg(): array
{
    static $c;
    return $c ??= require __DIR__ . '/config.php';
}

function args(array $argv): array
{
    $o = [];
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/', $a, $m)) {
            $o[$m[1]] = $m[2] ?? true;
        } else {
            $o['_'][] = $a;
        }
    }
    return $o;
}

function out(string $msg): void
{
    fwrite(STDOUT, '[' . gmdate('H:i:s') . "] $msg\n");
}

function fail(string $msg): never
{
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
}

/**
 * DB credentials.
 * - Hosted: MIG_BEDROCK_ROOT=<project root> loads the project's own config/application.php, so the
 *   tools use exactly what WordPress uses (on Kinsta: SERVER_SECRET_* injected by the platform, which
 *   take precedence over .env). Nothing is printed. connect() refuses if that database is not --dest.
 * - Local: MIG_DB_* environment variables (least-privilege dd_migrate user).
 */
function db_credentials(): array
{
    $root = getenv('MIG_BEDROCK_ROOT');
    if ($root) {
        if (!defined('DB_NAME')) {
            require_once $root . '/vendor/autoload.php';
            require_once $root . '/config/application.php';
            \Roots\WPConfig\Config::apply();
        }
        return [DB_HOST, DB_USER, DB_PASSWORD, DB_NAME];
    }
    return [getenv('MIG_DB_HOST') ?: 'db', getenv('MIG_DB_USER') ?: '', getenv('MIG_DB_PASS') ?: '', null];
}

function connect(?string $defaultDb = null): \mysqli
{
    [$host, $user, $pass, $appDb] = db_credentials();
    if ($appDb !== null && $defaultDb !== null && $appDb !== $defaultDb) {
        fail("the site's configured database is not the requested --dest ($defaultDb) — refusing");
    }
    $port = null;
    $socket = null;
    if (str_contains($host, ':')) {
        [$host, $x] = explode(':', $host, 2);
        ctype_digit($x) ? $port = (int) $x : $socket = $x;
    }
    $db = new \mysqli($host, $user, $pass, $defaultDb ?? '', $port, $socket);
    $db->set_charset('utf8mb4');
    $db->query("SET SESSION group_concat_max_len = 67108864, SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
    return $db;
}

/**
 * A set of WordPress tables: schema + table prefix. `$T['posts']`, `$T['wp_posts']` and `$T[$full]`
 * all render as `schema`.`<prefix>posts`, so SQL strings can interpolate "{$T['posts']}".
 * The source may live in its own schema (local copies) or under its own prefix inside the
 * destination database (hosted, where Kinsta allows one database per site).
 */
final class TableRef implements \ArrayAccess
{
    public function __construct(public readonly string $schema, public readonly string $prefix)
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $schema) || !preg_match('/^[a-z0-9]+(_[a-z0-9]+)*_$/', $prefix)) {
            fail("invalid table reference $schema/$prefix");
        }
    }

    public function name(string $table): string
    {
        return $this->prefix . (str_starts_with($table, 'wp_') ? substr($table, 3) : $table);
    }

    public function offsetGet(mixed $table): string
    {
        return '`' . $this->schema . '`.`' . $this->name((string) $table) . '`';
    }

    public function offsetExists(mixed $o): bool { return true; }
    public function offsetSet(mixed $o, mixed $v): void { fail('TableRef is read-only'); }
    public function offsetUnset(mixed $o): void { fail('TableRef is read-only'); }
}

/** Source tables from --src=<schema> [--src-prefix=<prefix>] (default: the WordPress prefix). */
function src_ref(array $a): TableRef
{
    return new TableRef($a['src'] ?? fail('--src required'), $a['src-prefix'] ?? cfg()['prefix']);
}

function dest_ref(string $dest): TableRef
{
    return new TableRef($dest, cfg()['prefix']);
}

/**
 * Refuses anything but an allowed destination and a distinct, non-overlapping source.
 * Rehearsal destinations match dest_pattern. A hosted destination is allowed only with
 * MIG_HOSTED=1 and --confirm-dest=<exact name>, and must look like the new site (Sage theme,
 * migration map present) — never the legacy store.
 */
function guard_dbs(string $dest, TableRef|string|null $src = null, array $a = []): void
{
    $c = cfg();
    $hosted = getenv('MIG_HOSTED') === '1';
    if (in_array($dest, $c['protected_dbs'], true)) {
        fail("destination '$dest' is protected");
    }
    if (!preg_match($c['dest_pattern'], $dest)) {
        if (!$hosted || ($a['confirm-dest'] ?? null) !== $dest) {
            fail("destination '$dest' is not a rehearsal database (hosted use needs MIG_HOSTED=1 and --confirm-dest=$dest)");
        }
    }
    if ($src === null) {
        return;
    }
    $src = is_string($src) ? new TableRef($src, $c['prefix']) : $src;
    if (tables_overlap($src, dest_ref($dest))) {
        fail("source {$src->schema}/{$src->prefix} overlaps destination $dest/{$c['prefix']} — use a distinct prefix such as lgcy_");
    }
}

/** Two table sets overlap if they share a schema and one prefix is a prefix of the other. */
function tables_overlap(TableRef $a, TableRef $b): bool
{
    return $a->schema === $b->schema && (str_starts_with($a->prefix, $b->prefix) || str_starts_with($b->prefix, $a->prefix));
}

/** Identity checks that need a connection: destination is the new site, source is legacy data. */
function guard_identity(\mysqli $db, string $dest, TableRef $src): void
{
    $D = dest_ref($dest);
    $theme = one($db, "SELECT option_value FROM {$D['options']} WHERE option_name='stylesheet'");
    if ($theme !== 'derma-direct') {
        fail("destination '$dest' does not run the new Sage theme (stylesheet=$theme) — refusing");
    }
    $has = (int) one($db, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=' . e($db, $src->schema) . ' AND table_name=' . e($db, $src->name('posts')));
    if (!$has) {
        fail("source tables {$src['posts']} not found");
    }
}

/**
 * Source snapshot identity (included in every report). Uses the exporter's
 * <prefix>dd_snapshot table when present, plus live counts and table checksums.
 */
function source_identity(\mysqli $db, TableRef $S, bool $checksums = true): array
{
    $meta = [];
    if ((int) one($db, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=' . e($db, $S->schema) . ' AND table_name=' . e($db, $S->name('dd_snapshot')))) {
        foreach (rows($db, "SELECT k, v FROM {$S['dd_snapshot']}") as $r) {
            $meta[$r['k']] = $r['v'];
        }
    }
    $id = [
        'schema' => $S->schema, 'prefix' => $S->prefix, 'snapshot' => $meta,
        'orders' => (int) one($db, "SELECT COUNT(*) FROM {$S['posts']} WHERE post_type IN ('shop_order','shop_order_refund')"),
        'max_order_id' => (int) one($db, "SELECT MAX(ID) FROM {$S['posts']} WHERE post_type IN ('shop_order','shop_order_refund')"),
        'latest_order_date' => one($db, "SELECT MAX(post_date) FROM {$S['posts']} WHERE post_type='shop_order'"),
        'latest_order_modified_gmt' => one($db, "SELECT MAX(post_modified_gmt) FROM {$S['posts']} WHERE post_type IN ('shop_order','shop_order_refund')"),
        'users' => (int) one($db, "SELECT COUNT(*) FROM {$S['users']}"),
        'max_user_id' => (int) one($db, "SELECT MAX(ID) FROM {$S['users']}"),
        'observed_at_utc' => gmdate('c'),
    ];
    if ($checksums) {
        $names = array_map(fn ($t) => $S[$t], ['posts', 'postmeta', 'users', 'usermeta', 'comments', 'woocommerce_order_items', 'woocommerce_order_itemmeta']);
        foreach (table_checksums($db, $names) as $r) {
            $id['checksum'][preg_replace('/^.*\./', '', $r['Table'])] = $r['Checksum'];
        }
    }
    return $id;
}

/** CHECKSUM TABLE rows for $tables; fails closed (never compares) on NULL, empty or missing checksums. */
function table_checksums(\mysqli $db, array $tables): array
{
    $rows = rows($db, 'CHECKSUM TABLE ' . implode(',', $tables));
    $err = checksum_error($rows, count($tables));
    return $err === null ? $rows : fail($err);
}

/** Pure check behind table_checksums(): null when every row has a non-negative integer checksum. */
function checksum_error(array $rows, int $want): ?string
{
    if (count($rows) !== $want) {
        return 'CHECKSUM TABLE returned ' . count($rows) . " rows for $want tables";
    }
    foreach ($rows as $r) {
        if (!preg_match('/^\d+$/D', (string) ($r['Checksum'] ?? ''))) {
            return 'CHECKSUM TABLE ' . ($r['Table'] ?? '?') . ': no numeric checksum (' . var_export($r['Checksum'] ?? null, true) . ') — table missing or unreadable; refusing to compare';
        }
    }
    return null;
}

function q(\mysqli $db, string $sql): \mysqli_result|bool
{
    return $db->query($sql);
}

function rows(\mysqli $db, string $sql): array
{
    return q($db, $sql)->fetch_all(MYSQLI_ASSOC);
}

function one(\mysqli $db, string $sql): mixed
{
    $r = q($db, $sql)->fetch_row();
    return $r[0] ?? null;
}

function e(\mysqli $db, mixed $v): string
{
    return $v === null ? 'NULL' : "'" . $db->real_escape_string((string) $v) . "'";
}

function t(string $db, string $table): string
{
    return "`$db`.`" . cfg()['prefix'] . "$table`";
}

/** Persistent, auditable old->new ID map kept in the destination database. */
function ensure_state_tables(\mysqli $db, string $dest): void
{
    q($db, "CREATE TABLE IF NOT EXISTS `$dest`.`dd_mig_idmap` (
        ns VARCHAR(32) NOT NULL, old_id BIGINT UNSIGNED NOT NULL, new_id BIGINT UNSIGNED NOT NULL,
        kind VARCHAR(64) NOT NULL, reason VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (ns, old_id), UNIQUE KEY new_id (ns, new_id)) ENGINE=InnoDB");
    q($db, "CREATE TABLE IF NOT EXISTS `$dest`.`dd_mig_ledger` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, run_id VARCHAR(32) NOT NULL, entity VARCHAR(32) NOT NULL,
        entity_id BIGINT UNSIGNED NOT NULL, action VARCHAR(24) NOT NULL, src_hash VARCHAR(40) NULL, dst_hash_before VARCHAR(40) NULL,
        note VARCHAR(255) NULL, at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY e (entity, entity_id), KEY r (run_id)) ENGINE=InnoDB");
    // provenance of every key/FK cell renumber rewrote (reversible record; residual checks re-occupied IDs against it)
    q($db, "CREATE TABLE IF NOT EXISTS `$dest`.`dd_mig_refs` (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, run_id VARCHAR(32) NOT NULL, ns VARCHAR(32) NOT NULL, tbl VARCHAR(64) NOT NULL,
        col VARCHAR(64) NOT NULL, pk VARCHAR(512) NOT NULL, old_id BIGINT UNSIGNED NOT NULL, new_id BIGINT UNSIGNED NOT NULL, KEY n (ns, new_id)) ENGINE=InnoDB");
    q($db, "CREATE TABLE IF NOT EXISTS `$dest`.`dd_mig_runs` (
        run_id VARCHAR(32) PRIMARY KEY, tool VARCHAR(32) NOT NULL, args TEXT NOT NULL, started_at DATETIME NOT NULL,
        finished_at DATETIME NULL, status VARCHAR(16) NOT NULL, summary LONGTEXT NULL) ENGINE=InnoDB");
}

function start_run(\mysqli $db, string $dest, string $tool, array $args): string
{
    $id = gmdate('Ymd\THis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    q($db, "INSERT INTO `$dest`.dd_mig_runs VALUES (" . e($db, $id) . ',' . e($db, $tool) . ',' . e($db, json_encode($args)) . ", UTC_TIMESTAMP(), NULL, 'running', NULL)");
    return $id;
}

function finish_run(\mysqli $db, string $dest, string $run, string $status, array $summary): void
{
    q($db, "UPDATE `$dest`.dd_mig_runs SET finished_at=UTC_TIMESTAMP(), status=" . e($db, $status) . ', summary=' . e($db, json_encode($summary)) . ' WHERE run_id=' . e($db, $run));
}

/** Role keys a serialized capabilities value grants (truthy entries only). */
function caps_roles(?string $caps): array
{
    $c = $caps === null ? false : @unserialize($caps, ['allowed_classes' => false]);
    return is_array($c) ? array_map('strval', array_keys(array_filter($c))) : [];
}

/**
 * The single customer rule used by every tool: every role is a base customer role or a verified
 * customer-group role (config), and at least one base customer role is present. Unknown roles,
 * staff roles and mixed customer + staff accounts are NOT customers (staff policy applies).
 */
function is_customer_caps(?string $caps): bool
{
    $roles = caps_roles($caps);
    $base = cfg()['customer_roles'];
    $allowed = array_merge($base, cfg()['customer_group_roles']);
    return $roles !== [] && !array_diff($roles, $allowed) && (bool) array_intersect($roles, $base);
}

function report_dir(): string
{
    $d = getenv('MIG_REPORT_DIR') ?: __DIR__ . '/../../migration-reports';
    if (!is_dir($d)) {
        mkdir($d, 0775, true);
    }
    return rtrim($d, '/');
}

function write_csv(string $path, array $header, iterable $rows): int
{
    $f = fopen($path, 'w');
    fputcsv($f, $header, ',', '"', '');
    $n = 0;
    foreach ($rows as $r) {
        fputcsv($f, $r, ',', '"', '');
        $n++;
    }
    fclose($f);
    return $n;
}
