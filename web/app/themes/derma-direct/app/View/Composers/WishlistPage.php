<?php

declare(strict_types=1);

namespace App\View\Composers;

use Roots\Acorn\View\Composer;
use App\Services\WishlistService;

class WishlistPage extends Composer
{
    protected static $views = [
        'template-wishlist',
    ];

    public function override(): array
    {
        $product_ids = WishlistService::getItems();
        $products    = [];

        foreach ($product_ids as $id) {
            $product = wc_get_product($id);

            if ($product && $product->is_visible()) {
                $products[] = $product;
            }
        }

        return [
            'wishlist_products' => $products,
        ];
    }
}
