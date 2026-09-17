@props(['product_id'])

@php
    /**
     * Wishlist button component.
     *
     * Props:
     *   $product_id (int) – WooCommerce product ID.
     */
    $is_in_wishlist  = \App\Services\WishlistService::isInWishlist((int) $product_id);
    $label_add       = __('Add to wishlist', 'sage');
    $label_remove    = __('Remove from wishlist', 'sage');
    $current_label   = $is_in_wishlist ? $label_remove : $label_add;
@endphp

<button
    type="button"
    class="wishlist-btn group absolute top-3 right-3 z-10 p-[7px] rounded-full bg-white shadow-sm transition-all duration-200 hover:scale-110 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
    data-product-id="{{ (int) $product_id }}"
    data-label-add="{{ $label_add }}"
    data-label-remove="{{ $label_remove }}"
    aria-label="{{ $current_label }}"
    aria-pressed="{{ $is_in_wishlist ? 'true' : 'false' }}"
>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        class="wishlist-heart w-5 h-5 transition-all duration-200 {{ $is_in_wishlist ? 'fill-red-500 stroke-red-500' : 'fill-white stroke-current' }}"
        stroke-width="1.8"
        aria-hidden="true"
        focusable="false"
    >
        <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z"
        />
    </svg>
</button>
