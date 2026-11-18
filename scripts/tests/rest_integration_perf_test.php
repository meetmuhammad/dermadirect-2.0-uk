<?php
/**
 * Standalone tests for the REST integration performance mu-plugin.
 *
 * Runs without WordPress:
 *   docker run --rm -v "$PWD":/app -w /app php:8.2-cli php scripts/tests/rest_integration_perf_test.php
 *
 * add_filter is not defined here, so loading the mu-plugin registers no hooks. remove_filter is stubbed to record
 * calls, and a stand-in Wdr\App\Router is declared for the removal tests.
 */

namespace Wdr\App {
    class Router
    {
        public static $manage_discount;
    }
}

namespace {

use function DermaDirect\RestIntegrationPerf\has_api_key_credentials;
use function DermaDirect\RestIntegrationPerf\remove_discount_price_html_filters;
use function DermaDirect\RestIntegrationPerf\should_skip_discount_price_html;

define('ABSPATH', __DIR__ . '/');

$removed = [];
function remove_filter(string $hook, $callback, int $priority = 10): bool
{
    global $removed;
    $removed[] = [$hook, is_array($callback) ? $callback[1] : $callback, $priority, is_array($callback) ? $callback[0] : null];
    return true;
}

require dirname(__DIR__, 2) . '/web/app/mu-plugins/dd-rest-integration-perf.php';

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

function skip(string $route): void
{
    check("{$route} with API key: skipped", should_skip_discount_price_html($route, true));
    check("{$route} without API key: untouched", !should_skip_discount_price_html($route, false));
}

function untouched(string $route): void
{
    check("{$route} with API key: untouched", !should_skip_discount_price_html($route, true));
    check("{$route} without API key: untouched", !should_skip_discount_price_html($route, false));
}

/* ---------------- Route decision ---------------- */

foreach (['v1', 'v2', 'v3'] as $v) {
    skip("/wc/{$v}/products");
    skip("/wc/{$v}/products/");
    skip("/wc/{$v}/products/123");
    skip("/wc/{$v}/products/123/variations");
    skip("/wc/{$v}/products/123/variations/456");
}
skip('/WC/V3/Products/123');

untouched('');
untouched('/');
untouched('/wc/v3/orders');
untouched('/wc/v3/orders/1000007');
untouched('/wc/v3/webhooks');
untouched('/wc/v3/productsx');
untouched('/wc/v3/products-extra');
untouched('/wc/v4/products');
untouched('/wc/v3');
untouched('/wc/store/v1/products');
untouched('/wc/store/v1/cart');
untouched('/wc/store/v1/checkout');
untouched('/wc/store/products');
untouched('/wp/v2/product');
untouched('/wp/v2/products');
untouched('/wc-admin/products');
untouched('/wc-analytics/products');
untouched('/x/wc/v3/products');
untouched("/wc/v3/products\n");

/* ---------------- API key credential detection ---------------- */

$basic = static fn (string $userPass): array => ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode($userPass)];

check('PHP_AUTH_USER ck_ key', has_api_key_credentials(['PHP_AUTH_USER' => 'ck_abc', 'PHP_AUTH_PW' => 'cs_def'], []));
check('Authorization Basic ck_ key', has_api_key_credentials($basic('ck_abc:cs_def'), []));
check('Authorization basic lower-case scheme', has_api_key_credentials(['HTTP_AUTHORIZATION' => 'basic ' . base64_encode('ck_abc:cs_def')], []));
check('REDIRECT_HTTP_AUTHORIZATION Basic ck_ key', has_api_key_credentials(['REDIRECT_HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('ck_abc:cs_def')], []));
check('consumer_key param', has_api_key_credentials([], ['consumer_key' => 'ck_abc', 'consumer_secret' => 'cs_def']));
check('oauth_consumer_key param', has_api_key_credentials([], ['oauth_consumer_key' => 'ck_abc']));
check('OAuth Authorization header', has_api_key_credentials(['HTTP_AUTHORIZATION' => 'OAuth oauth_consumer_key="ck_abc", oauth_signature="x"'], []));

check('no credentials', !has_api_key_credentials([], []));
check('cookie request with nonce only', !has_api_key_credentials(['HTTP_COOKIE' => 'wordpress_logged_in_x=y', 'HTTP_X_WP_NONCE' => 'abc'], ['_wpnonce' => 'abc']));
check('application password (Basic, not ck_)', !has_api_key_credentials($basic('admin:abcd efgh ijkl'), []));
check('PHP_AUTH_USER not ck_', !has_api_key_credentials(['PHP_AUTH_USER' => 'admin'], []));
check('consumer_key without ck_ prefix', !has_api_key_credentials([], ['consumer_key' => 'abc']));
check('consumer_key bare prefix', !has_api_key_credentials([], ['consumer_key' => 'ck_']));
check('consumer_key upper-case prefix', !has_api_key_credentials([], ['consumer_key' => 'CK_abc']));
check('consumer_key array', !has_api_key_credentials([], ['consumer_key' => ['ck_abc']]));
check('Bearer token', !has_api_key_credentials(['HTTP_AUTHORIZATION' => 'Bearer ck_abc'], []));
check('Basic with invalid base64', !has_api_key_credentials(['HTTP_AUTHORIZATION' => 'Basic !!!'], []));
check('Basic password is ck_, user is not', !has_api_key_credentials($basic('admin:ck_abc'), []));

/* ---------------- Filter removal ---------------- */

// Woo Discount Rules not active (no Wdr\App\Router class): checked in a child PHP process.
$plugin = var_export(dirname(__DIR__, 2) . '/web/app/mu-plugins/dd-rest-integration-perf.php', true);
$code = "define('ABSPATH','/');\$n=0;function remove_filter(){global \$n;\$n++;return true;}require {$plugin};"
    . "\\DermaDirect\\RestIntegrationPerf\\remove_discount_price_html_filters();echo 'removed=',\$n;";
check('plugin not active: nothing removed, no error', trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1')) === 'removed=0');

$removed = [];
remove_discount_price_html_filters();
check('plugin loaded but no manage_discount object: nothing removed', $removed === []);

$manage = new stdClass();
\Wdr\App\Router::$manage_discount = $manage;
$removed = [];
remove_discount_price_html_filters();
check('removes exactly two callbacks', count($removed) === 2);
check('removes getPriceHtml at 100', in_array(['woocommerce_get_price_html', 'getPriceHtml', 100, $manage], $removed, true));
check('removes getPriceHtmlSalePriceAdjustment at 9', in_array(['woocommerce_get_price_html', 'getPriceHtmlSalePriceAdjustment', 9, $manage], $removed, true));

echo "\n{$passes} passed, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
}
