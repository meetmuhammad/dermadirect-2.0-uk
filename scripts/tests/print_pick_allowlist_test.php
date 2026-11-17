<?php
/**
 * Standalone tests for the print/pick allow-list and packing slip print guards.
 *
 * Runs without WordPress:
 *   docker run --rm -v "$PWD":/app -w /app php:8.2-cli php scripts/tests/print_pick_allowlist_test.php
 *
 * WP_ENV is defined as 'production' only so the harness file returns before registering any
 * WordPress hooks; every guard below is tested with an explicit environment argument.
 */

use App\DermadirectToolsMenu\PackagingLabels\PackingSlipManager;
use function DermaDirect\MigrationSafety\is_allow_listed_url;
use function DermaDirect\MigrationSafety\parse_allow_hosts;
use function DermaDirect\MigrationSafety\url_for_log;

define('ABSPATH', __DIR__ . '/');
define('WP_ENV', 'production');

$root = dirname(__DIR__, 2);
require $root . '/web/app/mu-plugins/dd-migration-safety.php';
require $root . '/web/app/themes/derma-direct/app/DermadirectToolsMenu/PackagingLabels/PackingSlipManager.php';

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

/* ---------------- Harness allow-list ---------------- */

check('empty allow-list parses to nothing', parse_allow_hosts('') === []);
check('whitespace-only allow-list parses to nothing', parse_allow_hosts("  \n\t , ") === []);
check('empty allow-list blocks printnode', !is_allow_listed_url('https://api.printnode.com/printjobs', []));
check('empty allow-list blocks pickingpal', !is_allow_listed_url('https://pickingpal.com/', parse_allow_hosts('')));

$allow = parse_allow_hosts('api.printnode.com, pickingpal.com www.pickingpal.com');
check('parses comma and space separated list', $allow === ['api.printnode.com', 'pickingpal.com', 'www.pickingpal.com']);
check('exact host allowed', is_allow_listed_url('https://api.printnode.com/printjobs', $allow));
check('exact host with query allowed', is_allow_listed_url('https://www.pickingpal.com/?wc-api=x&instance=y', $allow));
check('unlisted host blocked', !is_allow_listed_url('https://api.stripe.com/v1/charges', $allow));
check('prefix-extended host blocked', !is_allow_listed_url('https://evil-api.printnode.com/printjobs', $allow));
check('suffix-extended host blocked', !is_allow_listed_url('https://api.printnode.com.evil.com/printjobs', $allow));
check('subdomain of listed host blocked', !is_allow_listed_url('https://x.api.printnode.com/', $allow));
check('parent of listed host blocked', !is_allow_listed_url('https://printnode.com/', $allow));
check('http scheme blocked', !is_allow_listed_url('http://api.printnode.com/printjobs', $allow));
check('non-http scheme blocked', !is_allow_listed_url('ftp://api.printnode.com/', $allow));
check('userinfo trick blocked', !is_allow_listed_url('https://api.printnode.com@evil.com/', $allow));
check('embedded credentials blocked', !is_allow_listed_url('https://user:pass@api.printnode.com/', $allow));
check('garbage URL blocked', !is_allow_listed_url('not a url', $allow));

check('wildcard entry rejected', parse_allow_hosts('*.printnode.com') === []);
check('wildcard entry does not allow subdomain', !is_allow_listed_url('https://api.printnode.com/', parse_allow_hosts('*.printnode.com')));
check('scheme entry rejected', parse_allow_hosts('https://api.printnode.com') === []);
check('path entry rejected', parse_allow_hosts('api.printnode.com/printjobs') === []);
check('port entry rejected', parse_allow_hosts('api.printnode.com:443') === []);
check('leading dot entry rejected', parse_allow_hosts('.printnode.com') === []);
check('underscore entry rejected', parse_allow_hosts('api_printnode.com') === []);
check('single label entry rejected', parse_allow_hosts('localhost') === []);
check('invalid entries dropped, valid kept', parse_allow_hosts('*.evil.com api.printnode.com http://x.com') === ['api.printnode.com']);

$upper = parse_allow_hosts('API.PrintNode.COM');
check('entries lowercased', $upper === ['api.printnode.com']);
check('case-insensitive host match', is_allow_listed_url('https://Api.PRINTNODE.com/printjobs', $upper));
check('case-insensitive scheme match', is_allow_listed_url('HTTPS://api.printnode.com/printjobs', $upper));
check('duplicates collapsed', parse_allow_hosts('pickingpal.com PICKINGPAL.com,pickingpal.com') === ['pickingpal.com']);

check('log URL drops query and fragment', url_for_log('https://www.pickingpal.com/path?token=secret#frag') === 'https://www.pickingpal.com/path');
check('log URL drops credentials', url_for_log('https://user:pass@api.printnode.com/x') === 'https://api.printnode.com/x');

/* ---------------- Packing slip print guards ---------------- */

foreach (['74857961', '71895499', '73994577', 'pn_73994577', 'PN_74857961', ' 71895499 '] as $id) {
    check("production printer {$id} detected", PackingSlipManager::isProductionPrinter($id));
    check("production printer {$id} refused on staging", PackingSlipManager::printBlockReason('staging', $id) !== null);
    check("production printer {$id} refused with override", PackingSlipManager::autoPrintBlockReason('staging', $id, '1', true) !== null);
}

check('test printer not detected as production', !PackingSlipManager::isProductionPrinter('12345678'));
check('pn_ prefix stripped', PackingSlipManager::normalisePrinterId('pn_12345678') === '12345678');
check('lookalike printer id not denied', !PackingSlipManager::isProductionPrinter('739945770'));
check('production printer refused when WP_ENV undefined', PackingSlipManager::printBlockReason(null, '74857961') !== null);
check('test printer allowed on staging', PackingSlipManager::printBlockReason('staging', '12345678') === null);

check('review order prints on staging', PackingSlipManager::autoPrintBlockReason('staging', '12345678', '1', false) === null);
check('non-review order skipped on staging', PackingSlipManager::autoPrintBlockReason('staging', '12345678', '', false) !== null);
check('review meta must be exactly "1"', PackingSlipManager::autoPrintBlockReason('staging', '12345678', 1, false) !== null);
check('non-review order skipped on development', PackingSlipManager::autoPrintBlockReason('development', '12345678', '', false) !== null);
check('override prints non-review order on staging', PackingSlipManager::autoPrintBlockReason('staging', '12345678', '', true) === null);

check('guards apply on staging', PackingSlipManager::guardsApply('staging'));
check('guards apply when WP_ENV undefined', PackingSlipManager::guardsApply(null));
check('guards skipped on production', !PackingSlipManager::guardsApply('production'));
check('production prints non-review order', PackingSlipManager::autoPrintBlockReason('production', '12345678', '', false) === null);
check('production prints to warehouse printer', PackingSlipManager::autoPrintBlockReason('production', '74857961', '', false) === null);
check('production manual print not blocked', PackingSlipManager::printBlockReason('production', 'pn_73994577') === null);

/* ---------------- Allow production printers flag ---------------- */

check('allow flag off by default', !PackingSlipManager::allowProductionPrinters());
check('unset constant is not enabled', !PackingSlipManager::flagEnabled('DD_TEST_UNDEFINED_FLAG'));
define('DD_TEST_STRING_FLAG', 'true');
check('string "true" constant is not enabled', !PackingSlipManager::flagEnabled('DD_TEST_STRING_FLAG'));
define('DD_TEST_BOOL_FLAG', true);
check('boolean true constant is enabled', PackingSlipManager::flagEnabled('DD_TEST_BOOL_FLAG'));

check('deny-list blocks by default (manual)', PackingSlipManager::printBlockReason('staging', '74857961') !== null);
check('deny-list blocks with allow flag false', PackingSlipManager::printBlockReason('staging', '74857961', false) !== null);
check('deny-list blocks by default (auto)', PackingSlipManager::autoPrintBlockReason('staging', '74857961', '1', false) !== null);

foreach (['74857961', '71895499', '73994577', 'pn_73994577', 'PN_74857961', ' 71895499 '] as $id) {
    check("allow flag lets production printer {$id} through on staging", PackingSlipManager::printBlockReason('staging', $id, true) === null);
    check("allow flag auto-prints review order to {$id} on staging", PackingSlipManager::autoPrintBlockReason('staging', $id, '1', false, true) === null);
}

check('allow flag lets production printer through when WP_ENV undefined', PackingSlipManager::printBlockReason(null, '74857961', true) === null);
check('allow flag does not bypass review-order rule', PackingSlipManager::autoPrintBlockReason('staging', '74857961', '', false, true) !== null);
check('allow flag does not bypass review-order rule for test printer', PackingSlipManager::autoPrintBlockReason('staging', '12345678', '', false, true) !== null);
check('print-all alone still blocks production printer', PackingSlipManager::autoPrintBlockReason('staging', '74857961', '', true, false) !== null);
check('both flags print non-review order to production printer', PackingSlipManager::autoPrintBlockReason('staging', '74857961', '', true, true) === null);
check('both flags print non-review order to pn_ production printer', PackingSlipManager::autoPrintBlockReason('development', 'pn_71895499', '', true, true) === null);

foreach ([[false, false], [true, false], [false, true], [true, true]] as [$all, $allow]) {
    $label = 'print_all=' . var_export($all, true) . ' allow=' . var_export($allow, true);
    check("production manual print unaffected ({$label})", PackingSlipManager::printBlockReason('production', '74857961', $allow) === null);
    check("production prints non-review order to warehouse printer ({$label})", PackingSlipManager::autoPrintBlockReason('production', 'pn_73994577', '', $all, $allow) === null);
    check("production prints non-review order to test printer ({$label})", PackingSlipManager::autoPrintBlockReason('production', '12345678', '', $all, $allow) === null);
}

check('unprinted order not printed',!PackingSlipManager::isPrinted(''));
check('printed meta detected', PackingSlipManager::isPrinted(['time' => '2026-11-18T10:00:00+00:00', 'printer_id' => '12345678']));
check('empty printed meta not printed', !PackingSlipManager::isPrinted([]));

echo "\n{$passes} passed, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
