<?php

declare(strict_types=1);

namespace App\Services;

/**
 * WishlistService
 *
 * Manages wishlist items for both logged-in users (user_meta) and guests (cookie).
 * Compatible with WooCommerce HPOS.
*/
class WishlistService
{
    public const META_KEY    = 'dd_wishlist_items';
    public const COOKIE_NAME = 'dd_wishlist';

    private const EXPIRY_DAYS = 30;

    /**
     * Get all wishlist product IDs for the current user/guest.
     *
     * @return int[]
     */
    public static function getItems(): array
    {
        if (is_user_logged_in()) {
            $items = get_user_meta(get_current_user_id(), self::META_KEY, true);
            return is_array($items) ? array_map('intval', $items) : [];
        }

        if (empty($_COOKIE[self::COOKIE_NAME])) {
            return [];
        }

        $decoded = json_decode(stripslashes((string) $_COOKIE[self::COOKIE_NAME]), true);

        return is_array($decoded) ? array_map('intval', $decoded) : [];
    }

    /**
     * Check whether a product is in the current wishlist.
     */
    public static function isInWishlist(int $product_id): bool
    {
        return in_array($product_id, self::getItems(), true);
    }

    /**
     * Toggle a product in/out of the wishlist.
     *
     * @return array{in_wishlist: bool, count: int}
     */
    public static function toggle(int $product_id): array
    {
        $items = self::getItems();

        if (in_array($product_id, $items, true)) {
            $items       = array_values(array_diff($items, [$product_id]));
            $in_wishlist = false;
        } else {
            $items[]     = $product_id;
            $in_wishlist = true;
        }

        self::persist($items);

        return [
            'in_wishlist' => $in_wishlist,
            'count'       => count($items),
        ];
    }

    /**
     * Get the total number of items in the wishlist.
     */
    public static function getCount(): int
    {
        return count(self::getItems());
    }

    /**
     * Merge a guest cookie wishlist into the logged-in user's meta.
     * Call this on login to preserve the guest session.
     */
    public static function mergeGuestWishlist(): void
    {
        if (!is_user_logged_in() || empty($_COOKIE[self::COOKIE_NAME])) {
            return;
        }

        $decoded = json_decode(stripslashes((string) $_COOKIE[self::COOKIE_NAME]), true);

        if (!is_array($decoded) || empty($decoded)) {
            return;
        }

        $guest_ids   = array_map('intval', $decoded);
        $user_items  = self::getItems();
        $merged      = array_values(array_unique(array_merge($user_items, $guest_ids)));

        update_user_meta(get_current_user_id(), self::META_KEY, $merged);

        // Clear the cookie
        setcookie(self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => COOKIEPATH ?: '/',
            'domain'   => COOKIE_DOMAIN ?: '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        unset($_COOKIE[self::COOKIE_NAME]);
    }

    /**
     * Persist the items array for the current user or guest.
     *
     * @param int[] $items
     */
    private static function persist(array $items): void
    {
        if (is_user_logged_in()) {
            update_user_meta(get_current_user_id(), self::META_KEY, $items);
            return;
        }

        $expiry  = time() + (self::EXPIRY_DAYS * DAY_IN_SECONDS);
        $encoded = wp_json_encode($items);

        setcookie(self::COOKIE_NAME, $encoded, [
            'expires'  => $expiry,
            'path'     => COOKIEPATH ?: '/',
            'domain'   => COOKIE_DOMAIN ?: '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // Update superglobal so getItems() reflects the change within the same request.
        $_COOKIE[self::COOKIE_NAME] = $encoded;
    }
}
