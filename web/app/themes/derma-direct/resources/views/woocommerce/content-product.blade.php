@php
    if (!($used_as_partial ?? false)) {
        global $product;
    }

    $product_id       = $product->get_id();
    $price_obj        = \App\Helper\Helper::getProductPricingInfo($product_id);
    $product_image_id = get_post_thumbnail_id($product_id);
    $cat_list         = wc_get_product_category_list($product_id);
@endphp

@if ($product && $product->is_visible())
    <div class="group relative flex flex-col gap-4 justify-start bg-white px-6 py-1 lg:p-4 rounded-md {{ ($used_as_partial ?? false) ? 'h-full' : '' }}">

        {{-- Sale Badge & Percentage --}}
        @if ($price_obj['is_on_sale'] ?? false)
            <div class="absolute top-3 right-3 z-10 flex items-center gap-2">
                <span class="text-[12px] font-quicksand font-semibold text-[#E34F4F] leading-none whitespace-nowrap">
                    ({{ $price_obj['saving_percent'] }}% off)
                </span>
                <span class="bg-[#E34F4F] text-white text-[10px] font-quicksand font-bold uppercase px-2 py-1 rounded">
                    Sale
                </span>
            </div>
        @endif

        {{-- Product image --}}
        <div class="flex justify-center items-center relative">
            @unless (empty($product_image_id ?? ''))
            <a href="{!! esc_url(get_permalink($product_id)) !!}">
                {!! wp_get_attachment_image($product_image_id, 'full', false, [
                    'class' => 'product-image !h-[150px] sm:!h-[130px] lg:!h-[150px] object-contain',
                    'alt'   => get_post_meta($product_image_id, '_wp_attachment_image_alt', true) ?: $product->get_name(),
                ]) !!}
            </a>
            @endunless
        </div>

        <div class="flex flex-col gap-4 lg:gap-4 justify-between h-full">

            {{-- Product name --}}
            <div class="flex flex-col gap-2 lg:gap-2">
                <div class="flex flex-col gap-[6px] min-h-[48px] lg:min-h-[56px]">
                    <a href="{!! esc_attr(get_permalink($product_id)) !!}"
                       class="font-poppins font-medium text-base lg:text-lg text-primary text-left leading-1.4 line-clamp-2">
                        {!!esc_html($product->get_name()) !!}
                    </a>
                </div>
            </div>

            <div class="flex gap-[8px] justify-between lg:items-center mt-auto">

                {{-- Price block --}}
                <div class="flex gap-0.5 {{ ($price_obj['is_on_sale'] ?? false) ? 'flex-col' : '' }}">

                    @if ($price_obj['is_on_sale'] ?? false)
                        {{-- Struck-through original price --}}
                        <span class="font-poppins text-sm text-secondary-grey line-through leading-none">
                            {!! $price_obj['regular_price_ex_vat'] !!}
                        </span>
                    @endif

                    {{-- Ex VAT price --}}
                    <h4 class="font-poppins font-bold text-lg xl:text-base flex items-center gap-1 {{ ($price_obj['is_on_sale'] ?? false) ? 'text-[#E34F4F]' : 'text-secondary-black' }} leading-none">
                        {!! $price_obj['purchase_price_ex_vat'] !!}
                        <span class="text-xs font-normal text-secondary-grey">Ex VAT</span>
                    </h4>

                </div>

                {{-- Add to Cart button --}}
                <div class="[&_.added_to_cart]:!hidden">
                    {!! apply_filters('woocommerce_loop_add_to_cart_link',
                        sprintf(
                            '<a href="%s"
                                data-quantity="%s"
                                class="add_to_cart_button ajax_add_to_cart
                                        flex items-center justify-center gap-2
                                        w-full font-semibold text-xs leading-none
                                        py-3 px-2 lg:px-3 text-white
                                        bg-primary rounded-sm hover:bg-secondary-black transition"
                                %s>
                                <img src="%s" alt="cart" class="w-4 h-4 flex-shrink-0">
                                <span class="whitespace-nowrap">%s</span>
                            </a>',
                            esc_url($product->add_to_cart_url()),
                            esc_attr(isset($args['quantity']) ? $args['quantity'] : 1),
                            wc_implode_html_attributes(array_filter([
                                'data-product_id'  => $product->get_id(),
                                'data-product_sku' => $product->get_sku(),
                                'aria-label'       => $product->add_to_cart_description(),
                            ])),
                            esc_url(get_stylesheet_directory_uri() . '/resources/images/shopping-cart.svg'),
                            esc_html($product->add_to_cart_text())
                        ),
                    $product)
                    !!}
                </div>
            </div>
        </div>
    </div>
@endif
