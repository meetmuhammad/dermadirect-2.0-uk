<?php
/**
 * Plugin Name:  DermaDirect REST integration performance
 * Description:  Skips Woo Discount Rules price HTML on WooCommerce REST product requests made with REST API keys,
 *               so integrations such as Shiptheory get a response before they time out.
 * Version:      1.0.0
 *
 * Why: after a product's stock changes, WooCommerce rebuilds its REST response. Building price_html runs
 * get_price_html(), which runs Woo Discount Rules (ManageDiscount::getPriceHtml at 100 and
 * getPriceHtmlSalePriceAdjustment at 9). Its Pro "first order" condition then runs an unindexed order query for
 * the API key's user, measured at 30-32 s per request. Integrations do not need discounted price HTML.
 *
 * Scope: only REST requests that are (a) authenticated with WooCommerce REST API keys and (b) to the WooCommerce
 * products routes (/wc/v1|v2|v3/products and its sub-routes). Storefront, cart, checkout, admin and logged-in browser
 * (cookie) REST requests are never touched. Active in every environment. Does nothing if Woo Discount Rules is not
 * active.
 */

namespace DermaDirect\RestIntegrationPerf;

defined('ABSPATH') || exit;

/** WooCommerce REST API consumer keys always start with this prefix. */
const CONSUMER_KEY_PREFIX = 'ck_';

/**
 * Pure decision: should Woo Discount Rules price HTML be skipped for this request?
 *
 * @param string $route      REST route as WordPress sees it, e.g. /wc/v3/products or /wc/v3/products/123/variations.
 * @param bool   $apiKeyAuth True only when the request is authenticated with a WooCommerce REST API key.
 */
function should_skip_discount_price_html(string $route, bool $apiKeyAuth): bool
{
    if (!$apiKeyAuth) {
        return false;
    }

    // WordPress matches REST routes case-insensitively, so do the same here.
    return (bool) preg_match('~^/wc/v[123]/products(?:/.*)?$~iD', $route);
}

/**
 * Pure check: does the request carry WooCommerce REST API key credentials?
 *
 * Mirrors where WC_REST_Authentication reads the consumer key: HTTP Basic auth (PHP_AUTH_USER or an Authorization
 * header), the consumer_key query/body parameter, and OAuth 1.0a (oauth_consumer_key). Only a ck_ key counts.
 *
 * @param array<string,mixed> $server $_SERVER.
 * @param array<string,mixed> $params Request query and body parameters.
 */
function has_api_key_credentials(array $server, array $params): bool
{
    $candidates = [
        $server['PHP_AUTH_USER'] ?? null,
        $params['consumer_key'] ?? null,
        $params['oauth_consumer_key'] ?? null,
    ];

    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $header) {
        $value = $server[$header] ?? null;
        if (is_string($value) && preg_match('~^\s*Basic\s+(\S+)~i', $value, $m)) {
            $decoded = base64_decode($m[1], true);
            if (is_string($decoded)) {
                $candidates[] = strstr($decoded, ':', true) ?: $decoded;
            }
        } elseif (is_string($value) && preg_match('~^\s*OAuth\s.*oauth_consumer_key="([^"]*)"~i', $value, $m)) {
            $candidates[] = rawurldecode($m[1]);
        }
    }

    foreach ($candidates as $key) {
        if (is_string($key) && str_starts_with($key, CONSUMER_KEY_PREFIX) && strlen($key) > strlen(CONSUMER_KEY_PREFIX)) {
            return true;
        }
    }

    return false;
}

/**
 * Remove Woo Discount Rules' two woocommerce_get_price_html callbacks, the same way the plugin itself does
 * (WDRAjax uses Router::$manage_discount). No-op if the plugin is not active.
 */
function remove_discount_price_html_filters(): void
{
    if (!class_exists('\Wdr\App\Router', false) || !property_exists('\Wdr\App\Router', 'manage_discount')) {
        return;
    }

    $manageDiscount = \Wdr\App\Router::$manage_discount;
    if (!is_object($manageDiscount)) {
        return;
    }

    remove_filter('woocommerce_get_price_html', [$manageDiscount, 'getPriceHtml'], 100);
    remove_filter('woocommerce_get_price_html', [$manageDiscount, 'getPriceHtmlSalePriceAdjustment'], 9);
}

/**
 * rest_pre_dispatch: the route is known and authentication has run (WooCommerce resolves the API key user on
 * determine_current_user). API-key auth = a logged-in user AND ck_ credentials on the request. A cookie-authenticated
 * browser request carries no consumer key, so it is never matched.
 *
 * @param mixed            $result
 * @param \WP_REST_Server  $server
 * @param \WP_REST_Request $request
 * @return mixed Always the unchanged $result.
 */
function maybe_skip_discount_price_html($result, $server, $request)
{
    if (!$request instanceof \WP_REST_Request) {
        return $result;
    }

    $params = array_merge(
        (array) $request->get_query_params(),
        (array) $request->get_body_params()
    );
    $apiKeyAuth = is_user_logged_in() && has_api_key_credentials($_SERVER, $params);

    if (should_skip_discount_price_html((string) $request->get_route(), $apiKeyAuth)) {
        remove_discount_price_html_filters();
    }

    return $result;
}

if (function_exists('add_filter')) {
    // After WooCommerce's own rest_pre_dispatch permission check (priority 10).
    add_filter('rest_pre_dispatch', __NAMESPACE__ . '\maybe_skip_discount_price_html', 20, 3);
}
