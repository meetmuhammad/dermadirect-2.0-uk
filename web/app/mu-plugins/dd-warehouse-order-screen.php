<?php
/**
 * Plugin Name:  DermaDirect Warehouse Order Screen
 * Description:  Restricts the admin order list for warehouse staff to the fulfilment statuses and
 *               the one bulk action they actually use. Replaces the legacy "Warehouse" code snippet.
 * Version:      1.0.0
 *
 * Ported from legacy UK `wp_snippets` #17 during the UK migration. Two deliberate changes:
 *
 *  1. The legacy snippet gated on `$user->ID == 18`. That is one staff change away from breaking,
 *     and it is the same class of authorisation bug as audit finding F-01. This gates on the
 *     `warehouse` role, which already exists in the UK database and is held by that same account.
 *  2. The legacy snippet hid WooCommerce's bulk-action optgroups with injected jQuery/CSS. Replacing
 *     the whole bulk-action array at a late priority leaves nothing to hide, so no JS is needed.
 *
 * This lives in mu-plugins, not the theme: it is order-management authorisation, and it must keep
 * working if the theme is switched or rebuilt.
 *
 * @see migration/reports/code-snippet-parity.md
 */

namespace DermaDirect\Warehouse;

defined('ABSPATH') || exit;

/**
 * Statuses warehouse staff may see, in the order the legacy snippet listed them.
 * `pack` and `back-order` are registered on this store but were NOT in the legacy allow-list;
 * they stay out so behaviour matches production exactly.
 */
function allowed_statuses(): array
{
    return apply_filters('dermadirect_warehouse_statuses', [
        'wc-processing',
        'wc-completed',
        'wc-ship',
        'wc-picked',
        'wc-pending-pick',
    ]);
}

/**
 * Whether the current user gets the restricted screen.
 *
 * Administrators are always excluded: locking an admin out of cancelled or refunded orders would
 * be a regression, and the legacy snippet never applied to them either.
 */
function is_warehouse_user(): bool
{
    $user = wp_get_current_user();

    if (!$user || !$user->exists() || in_array('administrator', (array) $user->roles, true)) {
        return false;
    }

    $roles = apply_filters('dermadirect_warehouse_roles', ['warehouse']);

    return (bool) array_intersect($roles, (array) $user->roles);
}

/** True on either order-list screen: legacy post storage or HPOS. */
function on_order_list_screen(): bool
{
    if (!is_admin() || !function_exists('get_current_screen')) {
        return false;
    }

    $screen = get_current_screen();

    if (!$screen) {
        return false;
    }

    return $screen->id === 'edit-shop_order' || $screen->id === 'woocommerce_page_wc-orders';
}

/**
 * Constrain the query to the allowed statuses (legacy post storage).
 */
add_action('pre_get_posts', function ($query) {
    if (!on_order_list_screen() || !is_warehouse_user()) {
        return $query;
    }

    $requested = isset($_GET['post_status']) ? sanitize_text_field(wp_unslash($_GET['post_status'])) : '';
    $allowed = allowed_statuses();

    if ($requested !== '' && !in_array($requested, $allowed, true)) {
        // Out-of-scope status asked for: send them to the default view rather than filtering to
        // nothing, which is what the legacy snippet did.
        wp_safe_redirect(admin_url('edit.php?post_status=wc-processing&post_type=shop_order'));
        exit;
    }

    $query->set('post_status', $requested !== '' ? [$requested] : $allowed);

    return $query;
});

/**
 * Same constraint on the HPOS order list.
 */
add_filter('woocommerce_order_list_table_prepare_items_query_args', function ($args) {
    if (!is_warehouse_user()) {
        return $args;
    }

    $allowed = allowed_statuses();
    $requested = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';

    $args['status'] = ($requested !== '' && in_array('wc-' . ltrim($requested, 'wc-'), $allowed, true))
        ? [$requested]
        : $allowed;

    return $args;
});

/**
 * Hide the status views warehouse staff must not use.
 */
function filter_views(array $views): array
{
    if (!is_warehouse_user()) {
        return $views;
    }

    $allowed = array_map(fn ($s) => ltrim($s, 'wc-'), allowed_statuses());

    foreach (array_keys($views) as $key) {
        // 'all' is dropped too - it would show everything and defeat the restriction.
        if ($key === 'all' || !in_array(ltrim((string) $key, 'wc-'), $allowed, true)) {
            unset($views[$key]);
        }
    }

    return $views;
}

add_filter('views_edit-shop_order', __NAMESPACE__ . '\\filter_views', 1000);
add_filter('views_woocommerce_page_wc-orders', __NAMESPACE__ . '\\filter_views', 1000);

/**
 * Reduce bulk actions to the single one the workflow uses.
 *
 * `mark_ship` is handled by WooCommerce core, which registers a generic `mark_<status>` handler for
 * every registered order status - `ship` is one of this store's 20 custom statuses - so declaring
 * it here is sufficient. Nothing is offered on the completed view: those orders are finished.
 */
function filter_bulk_actions(array $actions): array
{
    if (!is_warehouse_user()) {
        return $actions;
    }

    $status = isset($_GET['post_status'])
        ? sanitize_text_field(wp_unslash($_GET['post_status']))
        : (isset($_GET['status']) ? 'wc-' . ltrim(sanitize_text_field(wp_unslash($_GET['status'])), 'wc-') : '');

    if ($status === 'wc-completed') {
        return [];
    }

    return ['mark_ship' => __('Change status to ship', 'dermadirect')];
}

add_filter('bulk_actions-edit-shop_order', __NAMESPACE__ . '\\filter_bulk_actions', 1000);
add_filter('bulk_actions-woocommerce_page_wc-orders', __NAMESPACE__ . '\\filter_bulk_actions', 1000);
