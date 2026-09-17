<section class="mt-12">
    <div class="mb-6">
        <h2 class="text-2xl font-semibold">
            Recommended Products
        </h2>
        <p class="text-gray-600">
            Products frequently purchased alongside your recent orders.
        </p>
    </div>

    @if(empty($recommendedProducts))
        <p class="text-gray-500">
            No recommendations available.
        </p>
    @else

        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-4">
            @foreach($recommendedProducts as $product)
                <div class="rounded-md border border-gray-200 bg-white p-5">
                    <div class="mb-4 flex justify-center">
                        {!! $product->get_image('thumbnail') !!}
                    </div>
                    <h3 class="font-semibold">
                        {{ esc_html($product->get_name()) }}
                    </h3>
                    <div class="mt-2 text-lg font-bold">
                        {!! $product->get_price_html() !!}
                    </div>
                    <a
                        href="{{ esc_url($product->add_to_cart_url()) }}"
                        class="mt-4 inline-flex rounded-md bg-primary px-4 py-2 text-sm font-medium text-white"
                    >
                        Add to Cart
                    </a>
                </div>
            @endforeach
        </div>
    @endif
</section>