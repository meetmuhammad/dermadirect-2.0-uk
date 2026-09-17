<section class="py-10 lg:pt-10 lg:pb-[80px]">
    <div class="container flex flex-col gap-5 justify-center sm:gap-10 md:gap-15 lg:gap-20 font-quicksand">
        {{-- Products grid --}}
        @unless (empty($products ?? []))
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                @foreach ($products as $product_id)
                    @continue (empty($product_id ?? ''))
                    @includeIf('woocommerce.content-product', [
                        'product' => wc_get_product( $product_id ),
                        'used_as_partial' => true,
                    ])
                @endforeach
            </div>
        @endunless
    </div>
</section>
