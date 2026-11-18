<?php
/**
 * Standalone tests for the TEST marking on printed documents.
 *
 * Runs without WordPress:
 *   docker run --rm -v "$PWD":/app -w /app php:8.2-cli php scripts/tests/test_print_marker_test.php
 *
 * WP_ENV is defined as 'production' only so the mu-plugin returns before registering any
 * WordPress hooks; every decision below is tested with an explicit environment argument.
 */

use App\DermadirectToolsMenu\PackagingLabels\PackingSlipManager;
use function DermaDirect\TestPrintMarker\blank_flexible_printing_key;
use function DermaDirect\TestPrintMarker\enabled;
use function DermaDirect\TestPrintMarker\is_enabled;
use function DermaDirect\TestPrintMarker\is_pickingpal_print_method;
use function DermaDirect\TestPrintMarker\mark_html;
use function DermaDirect\TestPrintMarker\prefix_remote_print_titles;
use function DermaDirect\TestPrintMarker\printjob_block_reason;

use const DermaDirect\TestPrintMarker\MARKER;
use const DermaDirect\TestPrintMarker\PRINTJOB_TITLE_PREFIX;
use const DermaDirect\TestPrintMarker\TEXT;

define('ABSPATH', __DIR__ . '/');
define('WP_ENV', 'production');

$root = dirname(__DIR__, 2);
require $root . '/web/app/mu-plugins/dd-test-print-marker.php';
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

/* ---------------- Constants ---------------- */

check('banner text is exact', TEXT === 'TEST ORDER - DERMADIRECT 2.0 - DO NOT PICK OR SHIP');
check('printjob prefix is exact', PRINTJOB_TITLE_PREFIX === 'TEST DD2.0 - ');
check('theme fallback text matches mu-plugin', PackingSlipManager::TEST_BANNER_TEXT === TEXT);
check('theme title prefix matches mu-plugin', PackingSlipManager::TEST_PRINTJOB_TITLE_PREFIX === PRINTJOB_TITLE_PREFIX);

/* ---------------- is_enabled matrix ---------------- */

$all_flags = [null, true, false, 'true', 'false', '0', 'off', 'no', '', 'yes', 'random', ' OFF ', 'False', 1, 0];

foreach ($all_flags as $flag) {
    check('production never marked (flag ' . json_encode($flag) . ')', !is_enabled('production', $flag));
}

foreach (['staging', 'development', null] as $env) {
    $label = json_encode($env);
    check("{$label} marked by default", is_enabled($env, null));

    foreach ([false, 'false', '0', 'off', 'no', 'FALSE', ' Off ', "no\n", 'NO'] as $flag) {
        check("{$label} off with flag " . json_encode($flag), !is_enabled($env, $flag));
    }

    foreach ([true, 'true', '', 'yes', 'on', '1', 'random', 'disabled', 1, 0] as $flag) {
        check("{$label} marked with flag " . json_encode($flag), is_enabled($env, $flag));
    }
}

check('unknown env marked by default', is_enabled('local', null));
check('enabled() false under WP_ENV production', !enabled());

/* ---------------- mark_html ---------------- */

$page = "<html><head><title>x</title></head><body class=\"slip\" data-x=\"1\">\n<h3>DERMA DIRECT</h3></body></html>";
$marked = mark_html($page);
$body_end = strpos($page, '>', strpos($page, '<body')) + 1;

check('inserts right after opening body tag', substr($marked, $body_end, strlen('<!-- ' . MARKER)) === '<!-- ' . MARKER);
check('content before body untouched', substr($marked, 0, $body_end) === substr($page, 0, $body_end));
check('original content kept after marking', substr($marked, -strlen(substr($page, $body_end))) === substr($page, $body_end));
check('marked page contains exact text', strpos($marked, TEXT) !== false);
check('banner repeats the text', substr_count($marked, TEXT) >= 2);
check('marked page contains watermark', strpos($marked, 'dd-test-print-watermark') !== false);
check('watermark is fixed', strpos($marked, 'position:fixed') !== false);
check('watermark is rotated', (bool) preg_match('/rotate\(-(3[5-9]|4[0-5])deg\)/', $marked));
check('watermark is semi-transparent', (bool) preg_match('/opacity:0\.\d+/', $marked));
check('no flexbox or grid', !preg_match('/display:\s*(flex|grid|inline-flex)/', $marked));
check('idempotent on full page', mark_html($marked) === $marked);
check('marker inserted once', substr_count(mark_html(mark_html($page)), '<!-- ' . MARKER . ' -->') === 1);

$upper = mark_html('<HTML><BODY>x</BODY></HTML>');
check('uppercase BODY tag handled', strpos($upper, '<BODY><!-- ' . MARKER) !== false);
check('tbody not mistaken for body', strpos(mark_html('<table><tbody><tr><td>x</td></tr></tbody></table>'), '<!-- ' . MARKER) === 0);

$fragment = '<div class="wfte_invoice-main">[wfte_order_number]</div>';
$marked_fragment = mark_html($fragment);
check('prepends to fragment without body', strpos($marked_fragment, '<!-- ' . MARKER) === 0);
check('fragment content kept at end', substr($marked_fragment, -strlen($fragment)) === $fragment);
check('fragment contains exact text', strpos($marked_fragment, TEXT) !== false);
check('idempotent on fragment', mark_html($marked_fragment) === $marked_fragment);
check('empty string gets marking', strpos(mark_html(''), TEXT) !== false);
check('style block present for fragments', strpos($marked_fragment, '<style') !== false);

/* ---------------- PrintNode printjob guard ---------------- */

$url = 'https://api.printnode.com/printjobs';
$ok_body = json_encode(['printerId' => 1, 'title' => 'TEST DD2.0 - Packing Slip - Order #1', 'content' => 'x']);

check('TEST-prefixed JSON title allowed', printjob_block_reason($url, 'POST', $ok_body) === null);
check('lowercase method still checked', printjob_block_reason($url, 'post', json_encode(['title' => 'x'])) !== null);
check('unprefixed title blocked', printjob_block_reason($url, 'POST', json_encode(['title' => 'Packing Slip - Order #1'])) !== null);
check('prefix not at start blocked', printjob_block_reason($url, 'POST', json_encode(['title' => 'Packing Slip TEST DD2.0 - 1'])) !== null);
check('prefix without trailing space blocked', printjob_block_reason($url, 'POST', json_encode(['title' => 'TEST DD2.0 -Packing'])) !== null);
check('lowercase prefix blocked', printjob_block_reason($url, 'POST', json_encode(['title' => 'test dd2.0 - x'])) !== null);
check('missing title blocked', printjob_block_reason($url, 'POST', json_encode(['printerId' => 1])) !== null);
check('non-string title blocked', printjob_block_reason($url, 'POST', json_encode(['title' => ['TEST DD2.0 - x']])) !== null);
check('non-JSON body blocked', printjob_block_reason($url, 'POST', 'title=TEST+DD2.0+-+x') !== null);
check('empty body blocked', printjob_block_reason($url, 'POST', '') !== null);
check('null body blocked', printjob_block_reason($url, 'POST', null) !== null);
check('JSON scalar body blocked', printjob_block_reason($url, 'POST', '"TEST DD2.0 - x"') !== null);
check('array body with TEST title allowed', printjob_block_reason($url, 'POST', ['title' => 'TEST DD2.0 - invoice_1']) === null);
check('array body without TEST title blocked', printjob_block_reason($url, 'POST', ['title' => 'invoice_1']) !== null);
check('trailing slash path checked', printjob_block_reason($url . '/', 'POST', json_encode(['title' => 'x'])) !== null);
check('uppercase host checked', printjob_block_reason('https://API.PrintNode.com/printjobs', 'POST', json_encode(['title' => 'x'])) !== null);
check('http scheme checked', printjob_block_reason('http://api.printnode.com/printjobs', 'POST', json_encode(['title' => 'x'])) !== null);
check('URL with query checked', printjob_block_reason($url . '?a=1', 'POST', json_encode(['title' => 'x'])) !== null);
check('nested printjobs path checked', printjob_block_reason('https://api.printnode.com/printers/5/printjobs', 'POST', json_encode(['title' => 'x'])) !== null);

check('GET printjobs ignored', printjob_block_reason($url, 'GET', null) === null);
check('DELETE printjobs ignored', printjob_block_reason($url, 'DELETE', null) === null);
check('printers endpoint ignored', printjob_block_reason('https://api.printnode.com/printers', 'POST', 'x') === null);
check('whoami endpoint ignored', printjob_block_reason('https://api.printnode.com/whoami', 'GET', null) === null);
check('other host ignored', printjob_block_reason('https://api.stripe.com/printjobs', 'POST', 'x') === null);
check('lookalike host ignored', printjob_block_reason('https://api.printnode.com.evil.com/printjobs', 'POST', 'x') === null);
check('subdomain host ignored', printjob_block_reason('https://x.api.printnode.com/printjobs', 'POST', 'x') === null);
check('garbage URL ignored', printjob_block_reason('not a url', 'POST', 'x') === null);

check('theme title prefixed when marked', PackingSlipManager::printJobTitle(123, true) === 'TEST DD2.0 - Packing Slip - Order #123');
check('theme title unchanged when not marked', PackingSlipManager::printJobTitle(123, false) === 'Packing Slip - Order #123');
check('marked theme job passes guard', printjob_block_reason($url, 'POST', json_encode(['title' => PackingSlipManager::printJobTitle(9, true)])) === null);
check('unmarked theme job blocked by guard', printjob_block_reason($url, 'POST', json_encode(['title' => PackingSlipManager::printJobTitle(9, false)])) !== null);

/* ---------------- WebToffee PrintNode titles ---------------- */

$docs = prefix_remote_print_titles([
    'invoice'     => ['html' => '<p>x</p>', 'pdf_file' => '/tmp/a.pdf', 'title' => 'Invoice 1'],
    'packinglist' => ['html' => '<p>x</p>', 'pdf_file' => '/tmp/b.pdf'],
    'creditnote'  => [],
    'already'     => ['pdf_file' => '/tmp/c.pdf', 'title' => 'TEST DD2.0 - done'],
], 77);
check('WebToffee title prefixed', $docs['invoice']['title'] === 'TEST DD2.0 - Invoice 1');
check('WebToffee fallback title prefixed', $docs['packinglist']['title'] === 'TEST DD2.0 - packinglist_77');
check('WebToffee empty doc left alone', $docs['creditnote'] === []);
check('WebToffee title prefixed once', $docs['already']['title'] === 'TEST DD2.0 - done');
check('WebToffee prefixed title passes guard', printjob_block_reason($url, 'POST', ['title' => $docs['packinglist']['title']]) === null);
check('WebToffee non-array passthrough', prefix_remote_print_titles(null, 1) === null);

/* ---------------- Flexible Printing ---------------- */

$settings = ['print_node_api_key' => 'secret-value', 'default_printer' => '42', 'auto_print' => '1'];
$blanked = blank_flexible_printing_key($settings);
check('Flexible Printing key blanked', $blanked['print_node_api_key'] === '');
check('Flexible Printing other settings kept', $blanked['default_printer'] === '42' && $blanked['auto_print'] === '1');
check('Flexible Printing no keys added or removed', array_keys($blanked) === array_keys($settings));
check('Flexible Printing key blanked when absent', blank_flexible_printing_key(['a' => 'b']) === ['a' => 'b', 'print_node_api_key' => '']);
check('Flexible Printing non-array passthrough', blank_flexible_printing_key(false) === false);

/* ---------------- PickingPal print requests ---------------- */

foreach (['pickingpal_print_packing_slip', 'pickingpal_print_pick_list', 'pickingpal_print_pick_ticket', 'pickingpal_print_unpicked_slip', 'pickingpal_print_barcode', 'pickingpal_print_exact_barcode', 'pickingpal_print_pick_reset_ovr_sku_barcode'] as $method) {
    check("PickingPal {$method} wrapped", is_pickingpal_print_method($method));
}

foreach ([null, '', 'pickingpal_print', 'pickingpal_print_packing_slip_x', 'print_slip', 'edit', ['pickingpal_print_pick_list']] as $method) {
    check('not wrapped: ' . json_encode($method), !is_pickingpal_print_method($method));
}

/* ---------------- Theme fallback (mu-plugin missing) ---------------- */

$fallback = PackingSlipManager::fallbackMarkHtml($page);
check('fallback banner after body', strpos($fallback, '<body class="slip" data-x="1"><div id="dd-test-print-fallback"') !== false);
check('fallback contains exact text', strpos($fallback, TEXT) !== false);
check('fallback idempotent', PackingSlipManager::fallbackMarkHtml($fallback) === $fallback);
check('fallback prepends without body', strpos(PackingSlipManager::fallbackMarkHtml('<p>x</p>'), '<div id="dd-test-print-fallback"') === 0);
check('theme uses mu-plugin marking when present', PackingSlipManager::markTestHtml($page) === mark_html($page));
check('theme marking off under WP_ENV production', !PackingSlipManager::testMarkingEnabled());

echo "\n{$passes} passed, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
