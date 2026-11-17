<?php
/**
 * Restores the hosted site's staff accounts after Action A replaced its database — by IDENTITY, never
 * by numeric ID alone. A migrated (legacy) account is never overwritten, merged or given staff rights.
 *
 *   identity --dest=<db> --out=<tsv>         digest of every account in <db> (packaging: the package's accounts)
 *   save     --dest=<db> --out=<json>        hosted staff accounts (users row + all usermeta), before import
 *   plan     --staff=<json> --identity=<tsv> pre-import decision against the package; exit 1 on any conflict
 *   apply    --dest=<db> --staff=<json> [--expect=<plan.json>]   after import; idempotent
 *   verify   --dest=<db> --staff=<json> --identity=<tsv>          after apply (and in initial-verify)
 *   [MIG_HOSTED=1 --confirm-dest=<db> on Kinsta]
 *
 * Identity = case-insensitive login AND email (SHA-256 digests; no personal data in the package file).
 * Per hosted staff account S at ID n, against the account A the package holds at n:
 *   no A                          -> insert S at n
 *   A same login+email, not customer -> in place: S's row and usermeta replace A's (hosted access wins)
 *   A same login+email, customer  -> CONFLICT (a staff member with a legacy customer account: decide manually)
 *   A only login or only email    -> CONFLICT (ambiguous identity)
 *   A different person            -> relocate S to the first free ID from n + users relocate_offset, below the
 *                                    users headroom start; recorded in dd_mig_idmap (ns 'staff')
 * Any other package account sharing S's login or email is a CONFLICT. References: the package's own
 * references to relocated local staff were already remapped by renumber.php (same offset); the tool
 * reports post/comment author counts at each target so this is visible.
 */
declare(strict_types=1);

namespace DDMig;

require_once __DIR__ . '/lib.php';


$a = args($argv);
$mode = $a['_'][0] ?? fail('mode required: identity|save|plan|apply|verify');
$P = cfg()['prefix'];
$capKey = $P . 'capabilities';
$offset = (int) cfg()['relocate_offset']['users'];
$ceiling = (int) cfg()['future_start']['wp_users'];

/** 'customer' | 'staff' | 'none' from a serialized capabilities value (shared rule: lib.php). */
function cls(?string $caps): string
{
    if (!caps_roles($caps)) {
        return 'none';
    }
    return is_customer_caps($caps) ? 'customer' : 'staff';
}

/** id => [lh, eh, ph, class, capsdigest] computed in SQL so both sides hash identically. */
function identity_db(\mysqli $db, TableRef $D, string $capKey): array
{
    $out = [];
    $r = q($db, "SELECT u.ID, SHA2(LOWER(TRIM(u.user_login)),256) lh, SHA2(LOWER(TRIM(u.user_email)),256) eh, SHA2(u.user_pass,256) ph,
        m.meta_value caps FROM {$D['users']} u LEFT JOIN {$D['usermeta']} m ON m.user_id=u.ID AND m.meta_key=" . e($db, $capKey));
    while ($x = $r->fetch_assoc()) {
        $out[(int) $x['ID']] = [$x['lh'], $x['eh'], $x['ph'], cls($x['caps']), hash('sha256', (string) $x['caps'])];
    }
    return $out;
}

function identity_tsv(string $f): array
{
    $out = [];
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: fail("cannot read $f") as $line) {
        $c = explode("\t", $line);
        count($c) === 6 || fail("malformed identity line in $f");
        $out[(int) $c[0]] = array_slice($c, 1);
    }
    return $out ?: fail("$f is empty");
}

function load_staff(string $f): array
{
    $s = json_decode((string) @file_get_contents($f), true) ?: fail("cannot read staff file $f");
    return $s['staff'] ?? fail("$f has no staff list");
}

/**
 * Decides where each hosted staff account goes. $existing: ns 'staff' mappings already applied (rerun).
 * Returns [decisions, conflicts].
 */
function plan(array $staff, array $ident, array $existing, int $offset, int $ceiling): array
{
    $dec = [];
    $conf = [];
    $taken = [];
    foreach ($staff as $s) {
        $n = (int) $s['user']['ID'];
        $same = fn (?array $r) => $r && $r[0] === $s['lh'] && $r[1] === $s['eh'];
        $a = $ident[$n] ?? null;
        if (isset($existing[$n])) {
            $t = (int) $existing[$n];
            if (isset($ident[$t]) && !$same($ident[$t])) {
                $conf[] = "staff $n: mapped target $t now holds a different account";
                continue;
            }
            $action = 'relocate';
        } elseif ($a === null) {
            [$t, $action] = [$n, 'insert'];
        } elseif ($same($a)) {
            if ($a[3] === 'customer') {
                $conf[] = "staff $n: same login and email as a migrated CUSTOMER account at the same ID — decide manually";
                continue;
            }
            [$t, $action] = [$n, 'in-place'];
        } elseif ($a[0] === $s['lh'] || $a[1] === $s['eh']) {
            $conf[] = "staff $n: only the " . ($a[0] === $s['lh'] ? 'login' : 'email') . ' matches the migrated account at the same ID — ambiguous';
            continue;
        } else {
            $action = 'relocate';
            for ($t = $n + $offset; $t < $ceiling && ((isset($ident[$t]) && !$same($ident[$t])) || isset($taken[$t])); $t++);
            if ($t >= $ceiling) {
                $conf[] = "staff $n: no free ID below $ceiling";
                continue;
            }
        }
        foreach ($ident as $id => $r) {
            if ($id !== $t && ($r[0] === $s['lh'] || $r[1] === $s['eh'])) {
                $conf[] = "staff $n: " . ($r[0] === $s['lh'] ? 'login' : 'email') . " is also used by migrated account $id ({$r[3]}) — ambiguous";
            }
        }
        $taken[$t] = true;
        $dec[] = ['staff_id' => $n, 'target' => $t, 'action' => $action, 'displaced' => $action === 'relocate' && $a ? $a[3] : null];
    }
    return [$dec, $conf];
}

function existing_map(\mysqli $db, string $dest): array
{
    if (!one($db, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=" . e($db, $dest) . " AND table_name='dd_mig_idmap'")) {
        return [];
    }
    return array_map('intval', array_column(rows($db, "SELECT old_id, new_id FROM `$dest`.dd_mig_idmap WHERE ns='staff'"), 'new_id', 'old_id'));
}

/** Fingerprint of every account except the given IDs: users rows + all their usermeta. */
function others_fingerprint(\mysqli $db, TableRef $D, array $except): array
{
    $ex = $except ? implode(',', array_map('intval', $except)) : '0';
    return [
        'users' => one($db, "SELECT CONCAT(COUNT(*),':',SUM(CRC32(CONCAT_WS('|',ID,user_login,user_email,user_pass,user_nicename,display_name,user_status)))) FROM {$D['users']} WHERE ID NOT IN ($ex)"),
        'usermeta' => one($db, "SELECT CONCAT(COUNT(*),':',SUM(CRC32(CONCAT_WS('|',umeta_id,user_id,meta_key,meta_value)))) FROM {$D['usermeta']} WHERE user_id NOT IN ($ex)"),
    ];
}

/** Returns a list of failures (empty = OK). */
function verify(\mysqli $db, TableRef $D, string $dest, array $staff, array $pkg, string $capKey, int $offset, int $ceiling): array
{
    $bad = [];
    $now = identity_db($db, $D, $capKey);
    $map = existing_map($db, $dest);
    [$dec] = plan($staff, $pkg, [], $offset, $ceiling);
    $target = array_column($dec, 'target', 'staff_id');
    foreach ($staff as $s) {
        $n = (int) $s['user']['ID'];
        $t = $map[$n] ?? $target[$n] ?? null;
        $r = $t === null ? null : ($now[$t] ?? null);
        if (!$r || $r[0] !== $s['lh'] || $r[1] !== $s['eh'] || $r[2] !== hash('sha256', $s['user']['user_pass']) || $r[3] !== 'staff') {
            $bad[] = "staff $n not restored intact at " . ($t ?? '?');
        }
    }
    $targets = array_flip(array_map('intval', array_values($target + $map)));
    foreach ($pkg as $id => $p) {
        if (isset($targets[$id])) {
            continue; // restored staff (in place) — checked above
        }
        $r = $now[$id] ?? null;
        if (!$r) {
            $bad[] = "migrated account $id missing";
        } elseif ($r !== $p) {
            $f = ['login', 'email', 'pass', 'class', 'caps'];
            $bad[] = "migrated account $id changed: " . implode(',', array_keys(array_filter(array_combine($f, array_map(fn ($i) => $r[$i] !== $p[$i], range(0, 4))))));
        }
    }
    foreach ($now as $id => $r) {
        if ($r[3] === 'staff' && !isset($targets[$id]) && ($pkg[$id][3] ?? null) !== 'staff') {
            $bad[] = "account $id has staff capabilities but is neither hosted staff nor package staff";
        }
    }
    $lvl = rows($db, "SELECT user_id FROM {$D['usermeta']} WHERE meta_key=" . e($db, "{$D->prefix}user_level") . " AND CAST(meta_value AS UNSIGNED)>0");
    foreach ($lvl as $x) {
        $id = (int) $x['user_id'];
        if (!isset($targets[$id]) && ($pkg[$id][3] ?? null) !== 'staff') {
            $bad[] = "account $id has user_level>0 but is not staff";
        }
    }
    return $bad;
}

switch ($mode) {
    case 'identity':
    case 'save':
    case 'apply':
    case 'verify':
        $dest = $a['dest'] ?? fail('--dest required');
        guard_dbs($dest, null, $a);
        $db = connect($dest);
        $D = dest_ref($dest);
        if (one($db, "SELECT option_value FROM {$D['options']} WHERE option_name='stylesheet'") !== 'derma-direct') {
            fail("$dest is not the new site — refusing");
        }
}

switch ($mode) {
    case 'identity':
        $f = fopen($a['out'] ?? fail('--out required'), 'w');
        foreach (identity_db($db, $D, $capKey) as $id => $r) {
            fwrite($f, $id . "\t" . implode("\t", $r) . "\n");
        }
        fclose($f);
        out('identity digest written');
        break;

    case 'save':
        $users = rows($db, "SELECT u.*, SHA2(LOWER(TRIM(u.user_login)),256) lh, SHA2(LOWER(TRIM(u.user_email)),256) eh FROM {$D['users']} u
            JOIN {$D['usermeta']} m ON m.user_id=u.ID AND m.meta_key=" . e($db, $capKey) . ' ORDER BY u.ID');
        $staff = [];
        foreach ($users as $u) {
            if (cls(one($db, "SELECT meta_value FROM {$D['usermeta']} WHERE user_id={$u['ID']} AND meta_key=" . e($db, $capKey))) !== 'staff') {
                continue;
            }
            [$lh, $eh] = [$u['lh'], $u['eh']];
            unset($u['lh'], $u['eh']);
            $meta = rows($db, "SELECT meta_key, meta_value FROM {$D['usermeta']} WHERE user_id={$u['ID']} ORDER BY umeta_id");
            $staff[] = ['user' => $u, 'meta' => $meta, 'lh' => $lh, 'eh' => $eh];
        }
        $staff || fail('no hosted staff accounts found — refusing (would lock you out)');
        $out = $a['out'] ?? fail('--out required');
        file_put_contents($out, json_encode(['saved_utc' => gmdate('c'), 'database' => $dest, 'staff' => $staff], JSON_PRETTY_PRINT)) || fail("cannot write $out");
        chmod($out, 0600);
        out('hosted staff saved: ' . count($staff));
        break;

    case 'plan':
        [$dec, $conf] = plan(load_staff($a['staff'] ?? fail('--staff required')), identity_tsv($a['identity'] ?? fail('--identity required')), [], $offset, $ceiling);
        $res = ['decisions' => $dec, 'conflicts' => $conf];
        if (isset($a['out'])) {
            file_put_contents($a['out'], json_encode($res, JSON_PRETTY_PRINT));
        }
        foreach ($dec as $d) {
            out("staff {$d['staff_id']}: {$d['action']}" . ($d['action'] === 'relocate' ? " -> {$d['target']} (ID {$d['staff_id']} holds a migrated {$d['displaced']} account)" : ''));
        }
        foreach ($conf as $c) {
            out("CONFLICT $c");
        }
        exit($conf ? 1 : 0);

    case 'apply':
        $staff = load_staff($a['staff'] ?? fail('--staff required'));
        if ((int) one($db, "SELECT GET_LOCK('dd_migration', 0)") !== 1) {
            fail('another migration run holds the dd_migration lock');
        }
        ensure_state_tables($db, $dest);
        [$dec, $conf] = plan($staff, identity_db($db, $D, $capKey), existing_map($db, $dest), $offset, $ceiling);
        $conf && fail("conflicts — nothing changed:\n  " . implode("\n  ", $conf));
        if (isset($a['expect'])) {
            $exp = json_decode((string) file_get_contents($a['expect']), true)['decisions'] ?? fail('bad --expect file');
            $key = fn ($l) => array_map(fn ($d) => $d['staff_id'] . '>' . $d['target'], $l);
            // on a rerun, first-run inserts/relocations are seen as already-mapped relocations or in-place
            $key($dec) === $key($exp) || fail('post-import plan differs from the pre-import plan — nothing changed');
        }
        $targets = array_column($dec, 'target');
        $before = others_fingerprint($db, $D, $targets);
        q($db, 'START TRANSACTION');
        foreach ($staff as $s) {
            $d = current(array_filter($dec, fn ($x) => $x['staff_id'] === (int) $s['user']['ID']));
            $t = (int) $d['target'];
            q($db, "DELETE FROM {$D['usermeta']} WHERE user_id=$t");
            q($db, "DELETE FROM {$D['users']} WHERE ID=$t");
            $u = ['ID' => $t] + $s['user'];
            q($db, "INSERT INTO {$D['users']} (" . implode(',', array_map(fn ($k) => "`$k`", array_keys($u))) . ') VALUES (' . implode(',', array_map(fn ($v) => e($db, $v), $u)) . ')');
            foreach ($s['meta'] as $m) {
                q($db, "INSERT INTO {$D['usermeta']} (user_id, meta_key, meta_value) VALUES ($t," . e($db, $m['meta_key']) . ',' . e($db, $m['meta_value']) . ')');
            }
            if ($d['action'] === 'relocate') {
                q($db, "INSERT INTO `$dest`.dd_mig_idmap (ns, old_id, new_id, kind, reason) VALUES ('staff', {$d['staff_id']}, $t, 'hosted-staff',
                    'hosted staff ID holds a migrated account') ON DUPLICATE KEY UPDATE new_id=VALUES(new_id)");
            }
        }
        $after = others_fingerprint($db, $D, $targets);
        if ($after !== $before) {
            q($db, 'ROLLBACK');
            fail('an account other than the restored staff would change — rolled back, nothing changed');
        }
        q($db, 'COMMIT');
        foreach ($dec as $d) {
            $t = (int) $d['target'];
            out("staff {$d['staff_id']}: {$d['action']}" . ($d['action'] === 'relocate' ? " -> $t" : '')
                . ' (authored posts ' . one($db, "SELECT COUNT(*) FROM {$D['posts']} WHERE post_author=$t") . ', comments ' . one($db, "SELECT COUNT(*) FROM {$D['comments']} WHERE user_id=$t") . ')');
        }
        out('other accounts unchanged: ' . $after['users'] . ' users / ' . $after['usermeta'] . ' usermeta (count:crc)');
        break;

    case 'verify':
        $bad = verify($db, $D, $dest, load_staff($a['staff'] ?? fail('--staff required')), identity_tsv($a['identity'] ?? fail('--identity required')), $capKey, $offset, $ceiling);
        foreach ($bad as $b) {
            out("FAIL $b");
        }
        out($bad ? 'STAFF VERIFY FAILED' : 'STAFF VERIFY OK: hosted staff intact at their IDs; every migrated account identical to the package; no unexpected privileged account');
        exit($bad ? 1 : 0);

    default:
        fail("unknown mode $mode");
}
