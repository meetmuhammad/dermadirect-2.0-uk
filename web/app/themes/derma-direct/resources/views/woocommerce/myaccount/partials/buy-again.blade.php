<section id="buy-again" class="mt-12">
    {{-- Accordion Header --}}
    <button
        type="button"
        id="buy-again-toggle"
        class="flex w-full items-center justify-between rounded-md border border-gray-200 bg-white p-6 hover:border-primary"
        aria-expanded="false"
    >
        <div class="text-left">
            <h2 class="text-2xl font-semibold">
                Buy Again
            </h2>
            <p class="mt-1 text-gray-600">
                Quickly reorder products you've purchased before

                @if($totalProducts)

                    ({{ esc_html($totalProducts) }}
                    {{ Str::plural('item', $totalProducts) }})

                @endif

            </p>

        </div>

        @svg('images.accordian-arrow')

    </button>

    {{-- Accordion Body --}}
    <div
        id="buy-again-panel"
        class="overflow-hidden transition-all duration-300 ease-in-out"
        style="max-height:0;"
    >

        <div class="mt-5">
            @if(empty($buyAgainProducts))
                <div class="rounded-md border border-dashed border-gray-300 bg-gray-50 p-8 text-center">
                    <p class="text-gray-500">
                        No previous purchases found.
                    </p>
                </div>
            @else
                <div
                    id="buy-again-list"
                    class="space-y-5"
                >
                @foreach($buyAgainProducts as $item)

                    @php
                        $product = $item['product'];
                        $isExtra = $loop->index >= $visibleLimit;
                    @endphp

                    <div
                        class="buy-again-item flex flex-col gap-6 rounded-md border bg-white p-6 lg:flex-row lg:items-center {{ $isExtra ? 'hidden' : '' }} border-gray-200"
                        data-product-id="{{ esc_attr($product->get_id()) }}"
                        @if($isExtra)
                            data-buy-again-extra="true"
                        @endif
                    >

                        {{-- Product Image --}}
                        <div class="h-16 w-16 flex-shrink-0 overflow-hidden rounded-md border border-gray-200 bg-white">
                            {!! $product->get_image(
                                'thumbnail',
                                [
                                    'class'   => 'h-full w-full object-cover',
                                    'loading' => 'lazy',
                                ]
                            ) !!}
                        </div>

                        {{-- Product Details --}}
                        <div class="flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-lg font-semibold">
                                    {{ esc_html($product->get_name()) }}
                                </h3>
                                <button
                                    type="button"
                                    class="favourite-toggle-btn flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-md border transition {{ $item['is_favourite'] ? 'border-primary bg-primary text-white' : 'border-gray-300 bg-white text-gray-400 hover:border-primary hover:text-primary' }}"
                                    data-product-id="{{ esc_attr($product->get_id()) }}"
                                    data-favourite="{{ esc_attr($item['is_favourite'] ? 'true' : 'false') }}"
                                    aria-label="{{ esc_attr($item['is_favourite'] ? 'Remove from favourites' : 'Add to favourites') }}"
                                    title="{{ esc_attr($item['is_favourite'] ? 'Remove from favourites' : 'Pin as favourite') }}"
                                >
                                    @svg('images.favourite-button')
                                </button>

                            </div>

                            <div class="mt-2 text-sm text-gray-600">
                                Purchased
                                <strong>
                                    {{ esc_html($item['count']) }}
                                </strong>
                                {{ Str::plural('time', $item['count']) }}
                            </div>

                            <div class="mt-1 text-sm text-gray-600">
                                Last ordered
                                <strong>
                                    {{ esc_html(wc_format_datetime($item['last_ordered'])) }}
                                </strong>
                            </div>
                        </div>

                        {{-- Price / CTA --}}
                        <div class="text-right">
                            <div class="mb-4 text-2xl font-bold">
                                {!! $product->get_price_html() !!}
                            </div>

                            <a
                            href="{{ esc_url($product->add_to_cart_url()) }}"
                                data-quantity="1"
                                data-product_id="{{ esc_attr($product->get_id()) }}"
                                data-product_sku="{{ esc_attr($product->get_sku()) }}"
                                aria-label="{{ esc_attr($product->add_to_cart_text()) }}"
                                rel="nofollow"
                                class="button product_type_{{ esc_attr($product->get_type()) }} ajax_add_to_cart add_to_cart_button inline-flex items-center rounded-md bg-primary px-6 py-3 text-sm font-semibold text-white transition hover:opacity-90 buy-again-item"
                            >
                                Buy Again
                            </a>
                        </div>
                    </div>

                @endforeach
                </div>

                @if($totalProducts > $visibleLimit)
                    <div class="mt-5 flex justify-center">
                        <button
                            type="button"
                            id="buy-again-view-more"
                            class="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 transition hover:border-primary hover:text-primary"
                        >
                            <span id="buy-again-view-more-text">
                                View
                                {{ esc_html($totalProducts - $visibleLimit) }}
                                more
                                {{ Str::plural('item', $totalProducts - $visibleLimit) }}
                            </span>
                            @svg('images.accordian-arrow')
                        </button>
                    </div>
                @endif
            @endif
        </div>
    </div>
</section>