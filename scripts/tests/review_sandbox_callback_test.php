<?php
/**
 * Standalone tests for the review sandbox callback guard.
 *
 * Runs without WordPress:
 *   docker run --rm -v "$PWD":/app -w /app php:8.2-cli php scripts/tests/review_sandbox_callback_test.php
 *
 * Only the pure guard file is loaded; the mu-plugin itself is never required.
 */

use function DermaDirect\ReviewSandbox\is_blocked_callback;
use function DermaDirect\ReviewSandbox\min_editable_webhook_id;
use function DermaDirect\ReviewSandbox\resolve_min_editable_webhook_id;

define('ABSPATH', __DIR__ . '/');

require dirname(__DIR__, 2) . '/web/app/mu-plugins/dd-review-sandbox/callback-guard.php';

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

function allowed(string $uri, string $method, array $body = [], string $override = ''): void
{
    check("{$method} {$uri} allowed", !is_blocked_callback($uri, $method, $body, $override));
}

function blocked(string $uri, string $method, array $body = [], string $override = '', string $note = ''): void
{
    check("{$method} {$uri} blocked" . ($note !== '' ? " ({$note})" : ''), is_blocked_callback($uri, $method, $body, $override));
}

/* ---------------- Collection route exception ---------------- */

foreach (['v1', 'v2', 'v3'] as $v) {
    foreach (['GET', 'POST'] as $m) {
        allowed("/wp-json/wc/{$v}/webhooks", $m);
        allowed("/wp-json/wc/{$v}/webhooks/", $m);
        allowed("/wp-json/wc/{$v}/webhooks?consumer_key=ck_x&consumer_secret=cs_y", $m);
        allowed("/wp-json/wc/{$v}/webhooks/?per_page=100", $m);
    }
}

/* ---------------- Collection route, other methods ---------------- */

foreach (['PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'get', ''] as $m) {
    blocked('/wp-json/wc/v3/webhooks', $m);
}
blocked('/wp-json/wc/v3/webhooks', 'POST', [], 'DELETE', 'override header');
blocked('/wp-json/wc/v3/webhooks?_method=DELETE', 'POST');
blocked('/wp-json/wc/v3/webhooks', 'POST', ['_method' => 'DELETE'], '', '_method in body');
blocked('/wp-json/wc/v3/webhooks', 'POST', ['rest_route' => '/wc/v3/webhooks/19'], '', 'rest_route in body');
blocked('/wp-json/wc/v3/webhooks?rest_route=/wc/v3/webhooks/19', 'POST');
blocked('/wp-json/wc/v3/webhooks?rest_route=/wc/v3/orders', 'GET');

/* ---------------- Item, batch, deliveries ---------------- */

foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $m) {
    blocked('/wp-json/wc/v3/webhooks/19', $m);
}
blocked('/wp-json/wc/v3/webhooks/19/', 'GET');
blocked('/wp-json/wc/v3/webhooks/batch', 'POST');
blocked('/wp-json/wc/v2/webhooks/batch', 'POST');
blocked('/wp-json/wc/v3/webhooks/19/deliveries', 'GET');
blocked('/wp-json/wc/v1/webhooks/19/deliveries/5', 'GET');
blocked('/wp-json/wc/v3/webhooks//', 'POST');
blocked('//wp-json/wc/v3/webhooks', 'POST');
blocked('/wp-json/wc/v4/webhooks', 'POST');
blocked('/wp-json/wc/v3/webhooks-extra', 'POST');
blocked('/wp-json/wc/v3/webhooksx', 'POST');
blocked('/wp-json/wc/v3/webhook', 'POST');
blocked('/wp-json/WC/v3/webhooks', 'POST', [], '', 'case variant');
blocked('/blog/wp-json/wc/v3/webhooks', 'POST');
check('POST collection with trailing newline blocked', is_blocked_callback("/wp-json/wc/v3/webhooks\n", 'POST'));

/* ---------------- Item edit exception (id >= minimum editable id, default 27) ---------------- */

foreach (['v1', 'v2', 'v3'] as $v) {
    foreach (['27', '100'] as $id) {
        foreach (['GET', 'PUT', 'PATCH'] as $m) {
            allowed("/wp-json/wc/{$v}/webhooks/{$id}", $m);
            allowed("/wp-json/wc/{$v}/webhooks/{$id}/", $m);
            allowed("/wp-json/wc/{$v}/webhooks/{$id}?consumer_key=ck_x&consumer_secret=cs_y", $m);
        }
        blocked("/wp-json/wc/{$v}/webhooks/{$id}", 'DELETE');
        blocked("/wp-json/wc/{$v}/webhooks/{$id}", 'POST', [], '', 'POST to an item');
    }
}
foreach (['19', '20', '26', '1', '0'] as $id) {
    foreach (['GET', 'PUT', 'PATCH', 'DELETE', 'POST'] as $m) {
        blocked("/wp-json/wc/v2/webhooks/{$id}", $m, [], '', 'protected id');
        blocked("/wp-json/wc/v3/webhooks/{$id}", $m, [], '', 'protected id');
    }
}
foreach (['HEAD', 'OPTIONS', 'put', 'patch', ''] as $m) {
    blocked('/wp-json/wc/v2/webhooks/27', $m);
}
blocked('/wp-json/wc/v2/webhooks/27', 'PUT', [], 'DELETE', 'override header');
blocked('/wp-json/wc/v2/webhooks/27', 'POST', [], 'PUT', 'override header');
blocked('/wp-json/wc/v2/webhooks/27?_method=DELETE', 'PUT');
blocked('/wp-json/wc/v2/webhooks/27', 'PUT', ['_method' => 'DELETE'], '', '_method in body');
blocked('/wp-json/wc/v2/webhooks/27', 'PUT', ['rest_route' => '/wc/v2/webhooks/19'], '', 'rest_route in body');
blocked('/wp-json/wc/v2/webhooks/27?rest_route=/wc/v2/webhooks/19', 'PUT');
foreach (['GET', 'PUT', 'PATCH', 'POST', 'DELETE'] as $m) {
    blocked('/wp-json/wc/v3/webhooks/27/deliveries', $m);
    blocked('/wp-json/wc/v3/webhooks/27/deliveries/5', $m);
    blocked('/wp-json/wc/v3/webhooks/batch', $m);
    blocked('/wp-json/wc/v2/webhooks/batch/', $m);
    blocked('/wp-json/wc/v3/webhooks/abc', $m, [], '', 'non-numeric id');
    blocked('/wp-json/wc/v3/webhooks/27abc', $m, [], '', 'non-numeric id');
    blocked('/wp-json/wc/v3/webhooks/2.7e1', $m, [], '', 'non-numeric id');
    blocked('/wp-json/wc/v3/webhooks/-27', $m, [], '', 'negative id');
    blocked('/wp-json/wc/v3/webhooks/027', $m, [], '', 'leading zero');
    blocked('/wp-json/wc/v3/webhooks/%32%37', $m, [], '', 'encoded id');
    blocked('/wp-json/wc/v3/webhooks/27/19', $m);
}
blocked('/wp-json/wc/v3/webhooks/27//', 'PUT');
blocked('//wp-json/wc/v3/webhooks/27', 'PUT');
blocked('/wp-json/wc/v4/webhooks/27', 'PUT');
blocked('/wp-json/WC/v3/webhooks/27', 'PUT', [], '', 'case variant');
blocked('/wp-json/wc/v3/WEBHOOKS/27', 'PUT', [], '', 'case variant');
blocked('/blog/wp-json/wc/v3/webhooks/27', 'PUT');
check('PUT item with trailing newline blocked', is_blocked_callback("/wp-json/wc/v3/webhooks/27\n", 'PUT'));
blocked('/wp-json/wc/v3/webhooks/99999999999', 'PUT', [], '', 'oversized id');

/* ---------------- Minimum editable id ---------------- */

check('explicit minimum 30: 27 PUT blocked', is_blocked_callback('/wp-json/wc/v2/webhooks/27', 'PUT', [], '', 30));
check('explicit minimum 30: 30 PUT allowed', !is_blocked_callback('/wp-json/wc/v2/webhooks/30', 'PUT', [], '', 30));
check('explicit minimum 30: rest_route 29 PATCH blocked', is_blocked_callback('/?rest_route=/wc/v2/webhooks/29', 'PATCH', [], '', 30));
check('DD_WEBHOOK_MIN_EDITABLE_ID undefined: minimum is 27', min_editable_webhook_id() === 27);

// A constant cannot be redefined here, so check the defined-constant path in a child PHP process.
function min_id_with_constant(string $phpValue): string
{
    $guard = var_export(dirname(__DIR__, 2) . '/web/app/mu-plugins/dd-review-sandbox/callback-guard.php', true);
    $code = "define('ABSPATH','/');define('DD_WEBHOOK_MIN_EDITABLE_ID',{$phpValue});require {$guard};"
        . "echo \\DermaDirect\\ReviewSandbox\\min_editable_webhook_id(),' ',"
        . "(int)\\DermaDirect\\ReviewSandbox\\is_blocked_callback('/wp-json/wc/v2/webhooks/27','PUT');";
    return trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code)));
}
check('DD_WEBHOOK_MIN_EDITABLE_ID 30: minimum 30, 27 PUT blocked', min_id_with_constant('30') === '30 1');
check('DD_WEBHOOK_MIN_EDITABLE_ID 27: minimum 27, 27 PUT allowed', min_id_with_constant('27') === '27 0');
check('DD_WEBHOOK_MIN_EDITABLE_ID 0: falls back to 27', min_id_with_constant('0') === '27 0');
check('DD_WEBHOOK_MIN_EDITABLE_ID "x": falls back to 27', min_id_with_constant("'x'") === '27 0');
foreach ([
    'null' => [null, 27],
    'int 40' => [40, 40],
    'int 1' => [1, 1],
    'int 0' => [0, 27],
    'int -5' => [-5, 27],
    'string "40"' => ['40', 40],
    'string "0"' => ['0', 27],
    'string "-1"' => ['-1', 27],
    'string "abc"' => ['abc', 27],
    'string "40abc"' => ['40abc', 27],
    'string ""' => ['', 27],
    'float 40.0' => [40.0, 27],
    'bool true' => [true, 27],
    'array' => [[40], 27],
] as $label => [$value, $expected]) {
    check("resolve minimum from {$label} is {$expected}", resolve_min_editable_webhook_id($value) === $expected);
}

/* ---------------- Other webhook-like and wc-api URIs ---------------- */

blocked('/wp-json/wc-stripe/v1/webhook', 'POST');
blocked('/wp-json/wc-stripe/v1/webhook', 'GET');
blocked('/wp-json/shiptheory/v1/webhook', 'POST');
blocked('/wp-json/wc/v3/webhooks?wc-api=foo', 'POST');
blocked('/?wc-api=foo', 'GET');
blocked('/?wc-api=foo', 'POST');
blocked('/wc-api/v3/webhooks', 'POST');
blocked('/?WC-API=wc_stripe', 'POST', [], '', 'uppercase');

/* ---------------- rest_route query style ---------------- */

foreach (['v1', 'v2', 'v3'] as $v) {
    foreach (['GET', 'POST'] as $m) {
        allowed("/?rest_route=/wc/{$v}/webhooks", $m);
        allowed("/?rest_route=/wc/{$v}/webhooks/", $m);
        allowed("/index.php?rest_route=/wc/{$v}/webhooks&consumer_key=ck_x", $m);
        allowed("/?rest_route=%2Fwc%2F{$v}%2Fwebhooks", $m);
    }
}
foreach (['PUT', 'PATCH', 'DELETE'] as $m) {
    blocked('/?rest_route=/wc/v3/webhooks', $m);
}
blocked('/?rest_route=/wc/v3/webhooks', 'POST', [], 'DELETE', 'override header');
blocked('/?rest_route=/wc/v3/webhooks&_method=DELETE', 'POST');
blocked('/?rest_route=/wc/v3/webhooks', 'POST', ['rest_route' => '/wc/v3/webhooks/19'], '', 'body rest_route');
blocked('/?rest_route=/wc/v3/webhooks/19', 'GET');
blocked('/?rest_route=/wc/v3/webhooks/19', 'DELETE');
blocked('/?rest_route=%2Fwc%2Fv3%2Fwebhooks%2F19', 'POST');
blocked('/?rest_route=/wc/v3/webhooks/batch', 'POST');
blocked('/?rest_route=/wc/v3/webhooks/19/deliveries', 'GET');
blocked('/?rest_route=/wc-stripe/v1/webhook', 'POST');
blocked('/?rest_route=/wc/v3/WEBHOOKS', 'POST', [], '', 'case variant');
blocked('/?rest_route[]=/wc/v3/webhooks', 'POST', [], '', 'array rest_route');
blocked('/shop/?rest_route=/wc/v3/webhooks', 'POST', [], '', 'non-root path');
blocked('/?rest_route=/wc/v3/webhooks&wc-api=foo', 'POST');

foreach (['v1', 'v2', 'v3'] as $v) {
    foreach (['27', '100'] as $id) {
        foreach (['GET', 'PUT', 'PATCH'] as $m) {
            allowed("/?rest_route=/wc/{$v}/webhooks/{$id}", $m);
            allowed("/?rest_route=/wc/{$v}/webhooks/{$id}/", $m);
            allowed("/index.php?rest_route=/wc/{$v}/webhooks/{$id}&consumer_key=ck_x", $m);
            allowed("/?rest_route=%2Fwc%2F{$v}%2Fwebhooks%2F{$id}", $m);
        }
        blocked("/?rest_route=/wc/{$v}/webhooks/{$id}", 'DELETE');
        blocked("/?rest_route=/wc/{$v}/webhooks/{$id}", 'POST', [], '', 'POST to an item');
    }
}
foreach (['19', '20', '26'] as $id) {
    foreach (['GET', 'PUT', 'PATCH', 'DELETE'] as $m) {
        blocked("/?rest_route=/wc/v2/webhooks/{$id}", $m, [], '', 'protected id');
        blocked("/?rest_route=%2Fwc%2Fv2%2Fwebhooks%2F{$id}", $m, [], '', 'protected id');
    }
}
blocked('/?rest_route=/wc/v2/webhooks/27', 'PUT', [], 'DELETE', 'override header');
blocked('/?rest_route=/wc/v2/webhooks/27&_method=DELETE', 'PUT');
blocked('/?rest_route=/wc/v2/webhooks/27', 'PUT', ['rest_route' => '/wc/v2/webhooks/19'], '', 'body rest_route');
blocked('/?rest_route=/wc/v2/webhooks/27/deliveries', 'GET');
blocked('/?rest_route=/wc/v2/webhooks/batch', 'PUT');
blocked('/?rest_route=/wc/v2/webhooks/abc', 'PUT', [], '', 'non-numeric id');
blocked('/?rest_route=/wc/v2/WEBHOOKS/27', 'PUT', [], '', 'case variant');
blocked('/?rest_route[]=/wc/v2/webhooks/27', 'PUT', [], '', 'array rest_route');
blocked('/shop/?rest_route=/wc/v2/webhooks/27', 'PUT', [], '', 'non-root path');
blocked('/?rest_route=/wc/v2/webhooks/27&wc-api=foo', 'PUT');
blocked('/wp-json/wc/v2/webhooks?rest_route=/wc/v2/webhooks/27', 'PUT', [], '', 'mixed path and rest_route');

/* ---------------- Normal URIs untouched ---------------- */

foreach (['GET', 'POST', 'PUT', 'DELETE'] as $m) {
    allowed('/wp-json/wc/v3/orders', $m);
}
allowed('/shop/', 'GET');
allowed('/', 'GET');
allowed('/?rest_route=/wc/v3/orders', 'GET');
allowed('/wp-json/wc/v3/products?per_page=10', 'GET');
allowed('/checkout/', 'POST');

echo "\n{$passes} passed, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
