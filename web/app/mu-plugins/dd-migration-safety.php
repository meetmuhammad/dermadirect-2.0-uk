<?php
/**
 * Plugin Name:  DermaDirect Migration Safety Harness
 * Description:  Neutralises every outbound side effect on non-production copies of the UK
 *               production database. See migration/docs/staging-safety.md.
 * Version:      1.0.0
 *
 * This database is a copy of live dermadirect.co.uk: real customers, real orders, real Stripe
 * customer ids. Booting it unguarded can email 17k customers, charge cards, push stock to the
 * warehouse and fire fulfilment webhooks. This file makes that impossible.
 *
 * It is environment-gated and fails CLOSED: on production it disables itself, and anywhere else
 * it is on unless DD_SAFETY_HARNESS is explicitly 'off'.
 */

namespace DermaDirect\MigrationSafety;

defined('ABSPATH') || exit;

const LOG_OPTION = 'dd_safety_blocked_log';

/**
 * Production is identified by WP_ENV, the one value that is set per-environment and never
 * copied with the database. Anything that is not production is guarded.
 */
function is_production(): bool
{
    return defined('WP_ENV') && WP_ENV === 'production';
}

function is_active(): bool
{
    if (is_production()) {
        return false;
    }

    // Explicit opt-out exists only so a developer can deliberately test an integration.
    return strtolower((string) getenv('DD_SAFETY_HARNESS') ?: 'on') !== 'off';
}

/**
 * Hosts the site may still talk to. Loopback only - everything that matters for local QA is
 * either in-process or in the Docker network.
 */
function is_allowed_host(string $host): bool
{
    $host = strtolower($host);

    $allowed = [
        'localhost', '127.0.0.1', '::1',
        'devkinsta_db', 'devkinsta_fpm', 'devkinsta_mailhog',
    ];

    if (in_array($host, $allowed, true)) {
        return true;
    }

    // Local DevKinsta hostnames.
    return (bool) preg_match('/\.local$/', $host);
}

/**
 * Records what was blocked so QA can tell "the harness stopped it" from "the feature is broken".
 * Bounded at 200 entries - this is a breadcrumb trail, not an audit log.
 */
function record(string $kind, string $detail): void
{
    $log = get_option(LOG_OPTION, []);

    if (!is_array($log)) {
        $log = [];
    }

    array_unshift($log, [
        'time'   => gmdate('c'),
        'kind'   => $kind,
        'detail' => mb_substr($detail, 0, 300),
    ]);

    update_option(LOG_OPTION, array_slice($log, 0, 200), false);
}

if (!is_active()) {
    return;
}

/* ------------------------------------------------------------------------
 * 1. Outbound HTTP
 *
 * One filter covers most of the kill-list: Stripe and every other gateway API,
 * fulfilment and CRM webhooks, TrackShip, Advanced Shipment Tracking, Omnisend,
 * AutomateWoo, analytics, Meta/Facebook, Google (Site Kit, Listings & Ads, GTM),
 * live shipping rate lookups, stock pushes, external inventory mutation, search
 * indexing, sitemap pinging and plugin/theme update pings.
 *
 * It short-circuits before the socket opens, so nothing waits on a timeout.
 * ---------------------------------------------------------------------- */
add_filter('pre_http_request', function ($preempt, $args, $url) {
    $host = (string) parse_url($url, PHP_URL_HOST);

    if ($host === '' || is_allowed_host($host)) {
        return $preempt;
    }

    record('http', $host . ' ' . $url);

    return new \WP_Error(
        'dd_safety_harness_blocked',
        sprintf('Blocked outbound HTTP to %s - migration safety harness is active.', $host)
    );
}, 1, 3);

/* ------------------------------------------------------------------------
 * 2. Transactional email and SMS
 *
 * pre_wp_mail short-circuits ahead of PHPMailer, so it also neutralises WP Mail
 * SMTP Pro, which would otherwise hand the message straight to a live provider.
 * SMS providers are HTTP APIs and are already covered by (1).
 * ---------------------------------------------------------------------- */
add_filter('pre_wp_mail', function ($null, $atts) {
    $to = $atts['to'] ?? '';
    record('mail', (is_array($to) ? implode(',', $to) : (string) $to) . ' :: ' . ($atts['subject'] ?? ''));

    return true; // Reported as sent; never handed to a transport.
}, 1, 2);

/* ------------------------------------------------------------------------
 * 3. Payment gateways - defence in depth
 *
 * (1) already blocks the Stripe API at the socket. This additionally forces both
 * gateways into test mode and strips live secret keys from the settings WooCommerce
 * hands the SDK, so a mis-scoped allowlist still cannot reach live money.
 *
 * Note: it filters the settings in memory only. Nothing is written to the database,
 * so the real credentials survive intact for cutover.
 * ---------------------------------------------------------------------- */
foreach (['woocommerce_stripe_settings', 'woocommerce_stripe_cc_settings', 'woocommerce_stripe_api_settings'] as $option) {
    add_filter("option_{$option}", function ($value) {
        if (!is_array($value)) {
            return $value;
        }

        $value['testmode'] = 'yes';
        $value['enabled']  = $value['enabled'] ?? 'no';

        foreach (['secret_key', 'publishable_key', 'webhook_secret'] as $key) {
            if (isset($value[$key])) {
                $value[$key] = '';
            }
        }

        return $value;
    }, PHP_INT_MAX);
}

/* ------------------------------------------------------------------------
 * 4. Scheduled work
 *
 * The imported database arrives with a live Action Scheduler queue - 526k actions,
 * including AutomateWoo campaigns, TrackShip polls and fulfilment retries. Left
 * alone it starts draining the moment the site is first loaded.
 *
 * Queue runners are stopped rather than the actions deleted, so the queue stays
 * intact for inspection and for cutover.
 * ---------------------------------------------------------------------- */
add_filter('action_scheduler_run_queue', '__return_false', PHP_INT_MAX);
add_filter('action_scheduler_allow_async_request_runner', '__return_false', PHP_INT_MAX);
add_filter('pre_option_woocommerce_queue_flush_rewrite_rules', '__return_false');

/* ------------------------------------------------------------------------
 * 4b. Order-status automation
 *
 * Found the hard way. On the destination's very first boot, WooCommerce cancelled
 * three real pending orders (695545, 695555, 695560) - the "hold stock (minutes)"
 * feature, which cancels unpaid orders once they age past the threshold. Every
 * imported pending order is, by definition, older than the threshold.
 *
 * Blocking outbound HTTP does not stop this: it is a purely local status write.
 * Setting hold_stock_minutes empty is WooCommerce's own documented off switch.
 *
 * The option is filtered in memory only; the stored production value is untouched.
 * ---------------------------------------------------------------------- */
add_filter('pre_option_woocommerce_hold_stock_minutes', '__return_empty_string', PHP_INT_MAX);

add_action('init', function () {
    remove_action('woocommerce_cancel_unpaid_orders', 'wc_cancel_unpaid_orders');
    wp_clear_scheduled_hook('woocommerce_cancel_unpaid_orders');
}, 1);

/* ------------------------------------------------------------------------
 * 4d. Usage telemetry
 *
 * `woocommerce_allow_tracking` is "yes" on the UK source, so it arrives switched on. A local or
 * staging copy must not report a production store's usage, and WooCommerce Tracks additionally
 * fataled the order-edit screen when its pixel request came back as a WP_Error from the outbound
 * block above.
 *
 * Filtered in memory: the stored production preference is untouched.
 * ---------------------------------------------------------------------- */
add_filter('pre_option_woocommerce_allow_tracking', fn () => 'no', PHP_INT_MAX);
add_filter('woocommerce_apply_tracking', '__return_false', PHP_INT_MAX);
add_filter('jetpack_active_modules', '__return_empty_array', PHP_INT_MAX);

/* ------------------------------------------------------------------------
 * 5. Search engines
 * ---------------------------------------------------------------------- */
add_filter('pre_option_blog_public', '__return_zero');

/* ------------------------------------------------------------------------
 * 6. Make the harness impossible to miss
 * ---------------------------------------------------------------------- */
add_action('admin_notices', function () {
    printf(
        '<div class="notice notice-warning"><p><strong>Migration safety harness active.</strong> '
        . 'Outbound HTTP, email, live payments, Action Scheduler and indexing are disabled '
        . '(WP_ENV: <code>%s</code>). %d side effects blocked so far.</p></div>',
        esc_html(defined('WP_ENV') ? WP_ENV : 'undefined'),
        count((array) get_option(LOG_OPTION, []))
    );
});

if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('dd safety', function ($args) {
        $sub = $args[0] ?? 'status';
        $log = (array) get_option(LOG_OPTION, []);

        if ($sub === 'clear') {
            delete_option(LOG_OPTION);
            \WP_CLI::success('Blocked-side-effect log cleared.');

            return;
        }

        \WP_CLI::log('Harness:  ACTIVE');
        \WP_CLI::log('WP_ENV:   ' . (defined('WP_ENV') ? WP_ENV : 'undefined'));
        \WP_CLI::log('Blocked:  ' . count($log) . ' side effects');

        if ($log) {
            \WP_CLI\Utils\format_items('table', array_slice($log, 0, 25), ['time', 'kind', 'detail']);
        }
    });
}
