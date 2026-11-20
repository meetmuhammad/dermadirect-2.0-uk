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
use function DermaDirect\ReviewSandbox\is_protected_webhook_id;

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

function allowed(string $uri, string $method, array $body = [], string $override = '', string $note = '', ?array $json = []): void
{
    check("{$method} {$uri} allowed" . ($note !== '' ? " ({$note})" : ''), !is_blocked_callback($uri, $method, $body, $override, $json));
}

function blocked(string $uri, string $method, array $body = [], string $override = '', string $note = '', ?array $json = []): void
{
    check("{$method} {$uri} blocked" . ($note !== '' ? " ({$note})" : ''), is_blocked_callback($uri, $method, $body, $override, $json));
}

$methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

/* ---------------- Collection: any method, any path form ---------------- */

foreach (['v1', 'v2', 'v3'] as $v) {
    foreach ($methods as $m) {
        allowed("/wp-json/wc/{$v}/webhooks", $m);
        allowed("/wp-json/wc/{$v}/webhooks/", $m);
        allowed("//wp-json/wc/{$v}/webhooks", $m);
        allowed("///wp-json//wc///{$v}/webhooks//", $m);
        allowed("/wp-json/wc/{$v}/webhooks?consumer_key=ck_x&consumer_secret=cs_y", $m);
        allowed("/?rest_route=/wc/{$v}/webhooks", $m);
        allowed("/index.php?rest_route=/wc/{$v}/webhooks/&consumer_key=ck_x", $m);
        allowed("/?rest_route=%2Fwc%2F{$v}%2Fwebhooks", $m);
    }
}
allowed('//wp-json/wc/v2/webhooks', 'GET');
allowed('//wp-json/wc/v2/webhooks', 'POST');
allowed('/wp-json/wc/v3/webhooks', 'POST', [], 'DELETE', 'override header');
allowed('/wp-json/wc/v3/webhooks?_method=DELETE', 'POST');

/* ---------------- Unprotected items: any method ---------------- */

foreach (['1', '18', '27', '28', '100'] as $id) {
    foreach ($methods as $m) {
        allowed("/wp-json/wc/v3/webhooks/{$id}", $m);
        allowed("//wp-json/wc/v2/webhooks/{$id}/", $m);
        allowed("/?rest_route=/wc/v2/webhooks/{$id}", $m);
        allowed("/wp-json/wc/v3/webhooks/{$id}/deliveries", $m);
    }
}
allowed('/wp-json/wc/v3/webhooks/27', 'DELETE');
allowed('/?rest_route=/wc/v2/webhooks/28', 'PUT');
allowed('/index.php?rest_route=/wc/v2/webhooks/28', 'PUT');
allowed('/wp-json/wc/v3/webhooks/27', 'POST', ['_method' => 'DELETE']);

/* ---------------- Protected items 19-26: blocked for any method and path form ---------------- */

foreach (range(19, 26) as $id) {
    foreach (['v1', 'v2', 'v3'] as $v) {
        foreach ($methods as $m) {
            blocked("/wp-json/wc/{$v}/webhooks/{$id}", $m, [], '', 'protected id');
            blocked("//wp-json/wc/{$v}/webhooks/{$id}/", $m, [], '', 'protected id');
            blocked("/wp-json/wc/{$v}/webhooks/{$id}/deliveries", $m, [], '', 'protected id');
            blocked("/wp-json/wc/{$v}/webhooks/{$id}/deliveries/5", $m, [], '', 'protected id');
            blocked("/?rest_route=/wc/{$v}/webhooks/{$id}", $m, [], '', 'protected id');
            blocked("/index.php?rest_route=/wc/{$v}/webhooks/{$id}/", $m, [], '', 'protected id');
            blocked("/?rest_route=%2Fwc%2F{$v}%2Fwebhooks%2F{$id}", $m, [], '', 'protected id');
        }
    }
}
foreach (['GET', 'PUT', 'DELETE'] as $m) {
    blocked('/wp-json/wc/v2/webhooks/19', $m);
}
blocked('//wp-json/wc/v3/webhooks/26/', 'GET');
blocked('/wp-json/wc/v3/webhooks/20', 'POST', ['_method' => 'DELETE'], '', '_method in body');
blocked('/wp-json/wc/v3/webhooks/20?_method=PUT', 'POST', [], '', '_method in query');
blocked('/wp-json/wc/v3/webhooks/20', 'POST', [], 'DELETE', 'override header');
blocked('/wp-json/wc/v3/webhooks/019', 'PUT', [], '', 'leading zero');
blocked('/wp-json/wc/v3/webhooks/%31%39', 'PUT', [], '', 'encoded id');
blocked('/wp-json/WC/V3/WEBHOOKS/19', 'PUT', [], '', 'case variant');
blocked('/index.php/wp-json/wc/v3/webhooks/19', 'PUT', [], '', 'path info');
blocked('/wp-json/wc/v3/webhooks/27?rest_route=/wc/v3/webhooks/19', 'PUT', [], '', 'rest_route in query');
blocked('/wp-json/wc/v3/webhooks/27', 'PUT', ['rest_route' => '/wc/v3/webhooks/19'], '', 'rest_route in body');
blocked('/shop/?rest_route=/wc/v3/webhooks/19', 'PUT', [], '', 'rest_route on any path');
check('PUT protected item with trailing newline blocked', is_blocked_callback("/wp-json/wc/v3/webhooks/19\n", 'PUT'));

/* ---------------- Batch ---------------- */

$batch = '/wp-json/wc/v3/webhooks/batch';
blocked($batch, 'POST', ['delete' => [21]], '', 'delete [21]');
blocked($batch, 'POST', [], '', 'JSON delete [21]', ['delete' => [21]]);
blocked($batch, 'POST', [], '', 'JSON delete ["19"]', ['delete' => ['19']]);
blocked($batch, 'POST', [], '', 'JSON update id 26', ['update' => [['id' => 26, 'status' => 'active']]]);
blocked($batch, 'POST', [], '', 'JSON update id "20"', ['update' => [['id' => '20']]]);
blocked($batch, 'POST', [], '', 'mixed delete', ['delete' => [27, 28, 22]]);
blocked($batch, 'POST', [], '', 'unparseable body', null);
blocked($batch, 'POST', [], '', 'delete not a list', ['delete' => '21']);
blocked($batch . '?delete[]=21', 'POST', [], '', 'ids in query');
blocked('//wp-json/wc/v2/webhooks/batch/', 'PUT', [], '', 'JSON delete [21]', ['delete' => [21]]);
blocked('/?rest_route=/wc/v3/webhooks/batch', 'POST', [], '', 'JSON delete [24]', ['delete' => [24]]);
blocked($batch, 'POST', [], 'PATCH', 'override header', ['delete' => [25]]);
allowed($batch, 'POST', [], '', 'JSON delete [27, 28]', ['delete' => [27, 28]]);
allowed($batch, 'POST', [], '', 'JSON update id 30', ['update' => [['id' => 30, 'status' => 'paused']]]);
allowed($batch, 'POST', [], '', 'JSON create', ['create' => [['name' => 'x', 'topic' => 'order.updated']]]);
allowed($batch, 'POST', ['delete' => [18, 27]]);
allowed($batch, 'POST');

/* ---------------- Protected id helper ---------------- */

foreach ([19, 20, 26, '19', '026', 19.0, [19], null] as $id) {
    check('protected id ' . var_export($id, true), is_protected_webhook_id($id));
}
foreach ([0, 1, 18, 27, 28, '27', 'abc', '', false] as $id) {
    check('unprotected id ' . var_export($id, true), !is_protected_webhook_id($id));
}

/* ---------------- Other webhook-like and wc-api URIs ---------------- */

blocked('//wc-api/x', 'GET');
blocked('//wc-api/x', 'POST');
blocked('/wp-json/wc-stripe/v1/webhook', 'POST');
blocked('/wp-json/shiptheory/v1/webhook', 'POST');
blocked('/wp-json/wc/v3/webhooks?wc-api=foo', 'POST');
blocked('/?wc-api=foo', 'GET');
blocked('/wc-api/v3/webhooks', 'POST');
blocked('/?WC-API=wc_stripe', 'POST', [], '', 'uppercase');
blocked('/?rest_route=/wc-stripe/v1/webhook', 'POST');
blocked('/wp-json/wc/v3/webhooksx', 'POST');
blocked('/?rest_route[]=/wc/v3/webhooks', 'POST', [], '', 'array rest_route');

/* ---------------- Normal URIs untouched ---------------- */

foreach (['GET', 'POST', 'PUT', 'DELETE'] as $m) {
    allowed('/wp-json/wc/v3/orders', $m);
    allowed('/wp-json/wc/v3/orders/19', $m);
}
allowed('/shop/', 'GET');
allowed('/', 'GET');
allowed('/?rest_route=/wc/v3/orders', 'GET');
allowed('/wp-json/wc/v3/products?per_page=10', 'GET');
allowed('/checkout/', 'POST');
allowed('/wp-json/wc/v3/orders', 'POST', [], '', 'unparseable body', null);

echo "\n{$passes} passed, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
