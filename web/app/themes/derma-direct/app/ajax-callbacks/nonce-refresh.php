<?php
/**
 * AJAX: Issue a fresh 'ajax-nonce' for the current request's session.
 *
 * Used by client-side code to recover from a stale/expired nonce (e.g. a
 * long-lived tab, bfcache restore, or session/login change after page load)
 * without forcing a full page reload. Intentionally has no nonce check of
 * its own — it only hands out a token tied to the current session, analogous
 * to WP core's Heartbeat API nonce refresh.
 */

declare(strict_types=1);

add_action('wp_ajax_refresh_ajax_nonce', 'refresh_ajax_nonce');
add_action('wp_ajax_nopriv_refresh_ajax_nonce', 'refresh_ajax_nonce');

function refresh_ajax_nonce() {
    wp_send_json_success([
        'nonce' => wp_create_nonce('ajax-nonce'),
    ]);
}
