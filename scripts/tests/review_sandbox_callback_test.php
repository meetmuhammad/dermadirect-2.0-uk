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
