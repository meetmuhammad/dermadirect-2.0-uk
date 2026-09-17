{{--
    This file has the UI of product header section. Which has product image, description, price,
    add to cart button etc.
--}}

<div class="flex flex-col md:flex-row gap-8">
    @unless (empty($image_ids ?? []))
        <div class="md:max-w-[400px] lg:max-w-[500px] xl:max-w-[660px] w-full md:sticky md:top-5 md:self-start">
            <div class="product-gallery-slider">
                {{-- Main Slider --}}
                <div class="swiper swiper-main mb-5">
                    <div class="swiper-wrapper [&_img]:w-auto [&_img]:max-w-full [&_img]:h-[220px] sm:[&_img]:h-[350px] md:[&_img]:h-[250px] lg:[&_img]:h-[350px] xl:[&_img]:h-[500px] [&_img]:mx-auto [&_img]:object-contain">
                        @foreach ($image_ids as $img_id)
                            <div class="swiper-slide bg-[#F4F3F1] py-10 !h-[400px] lg:!h-[572px]">
                                {!! wp_get_attachment_image($img_id, 'full', false, [
                                        'class' => 'object-contain !h-full',
                                        'alt' => get_post_meta($img_id, '_wp_attachment_image_alt', true) ?: 'Product image',
                                        'loading' => $img_loading ?? 'lazy',
                                    ])
                                !!}
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Thumbnails --}}
                @if(count($image_ids) > 1)
                    <div class="swiper swiper-thumbs">
                        <div class="swiper-wrapper gap-4 [&_div.swiper-slide-thumb-active]:opacity-100">
                            @foreach ($image_ids as $img_id)
                                <div class="swiper-slide cursor-pointer opacity-60 transition-all duration-300 !size-20 md:!size-[100px] !m-0">
                                    {!! wp_get_attachment_image($img_id, 'thumbnail', false, [
                                            'class' => 'object-contain size-20 md:size-[100px]',
                                            'alt' => get_post_meta($img_id, '_wp_attachment_image_alt', true) ?: 'Product image',
                                            'loading' => $img_loading ?? 'lazy',
                                        ])
                                    !!}
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

            </div>
        </div>
    @endunless

    <div class="w-full flex flex-col gap-6">
        @unless (empty($people_who_loved['reviewers'] ?? []))
            <div class="bg-[#003D621A] py-1 px-2 sm:px-3 rounded font-quicksand text-xs sm:text-sm text-[#272727] font-[600] flex items-center gap-5">
                <ul class="flex items-center [&_li]:size-[36px] [&_li]:rounded-full [&_li]:overflow-hidden [&_li]:-mr-2 [&_img]:size-full [&_img]:object-cover">
                    @foreach ($people_who_loved['reviewers'] as $reviewer)
                        @continue (empty($reviewer['avatar'] ?? ''))
                        <li><img src="{!! esc_attr($reviewer['avatar']) !!}" alt=""></li>
                    @endforeach
                </ul>

                @unless (empty($people_who_loved['text'] ?? ''))
                    <div>{{ $people_who_loved['text'] }}</div>
                @endunless
            </div>
        @endunless

        <div class="flex flex-col gap-4 items-start">

            @if ($product->get_rating_count() > 0)
                <span class="text-[15px] font-poppin font-medium text-[#272727] flex items-center gap-1">
                    {!! Helper::renderStarRating($product->get_average_rating()); !!} ({!! esc_attr ($product->get_rating_count()) !!})
                </span>
            @endunless

            <h2 class="text-[22px] sm:text-[28px] lg:text-[40px] leading-[1.1] lg:max-w-[600px] text-black font-poppin font-bold leading-none">{!! wp_kses_post(get_the_title()) !!}</h2>
            @unless ($product->is_in_stock())
                <span class="inline-block bg-[#E34F4F] text-white text-xs font-quicksand font-bold uppercase px-3 py-1 rounded">{{ __('Out of Stock', 'sage') }}</span>
            @endunless

            <!-- @unless (empty($product->get_short_description() ?? ''))
                <div class="text-sm sm:text-base text-black font-quicksand max-w-[457px]">{!! $product->get_short_description() !!}</div>
            @endunless -->

            @if (!empty($key_benefits))

                <div class="flex flex-col gap-3 max-w-[457px]">

                    <h3 class="text-base md:text-lg font-poppin font-bold text-[#272727]">
                        {{ __('Key Benefits', 'sage') }}
                    </h3>

                    <ul class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm sm:text-base text-black font-quicksand">

                        @foreach ($key_benefits as $benefit)

                            @if (!empty($benefit['benefit']))
                                <li class="flex items-start gap-2">
                                    <span class="text-primary font-bold">✓</span>
                                    <span>{!! wp_kses_post($benefit['benefit']) !!}</span>
                                </li>
                            @endif

                        @endforeach

                    </ul>

                </div>

            @endif

            @unless (empty($price_obj ?? []))
            <div class="flex flex-col gap-0.5">

                {{-- Ex VAT primary price --}}
                <div class="flex items-baseline gap-1.5">
                    <h3 class="text-2xl md:text-3xl lg:text-4xl text-[#E34F4F] font-instrument-sans font-bold">
                        {!! $price_obj['purchase_price_ex_vat'] !!}
                    </h3>
                    @if ($price_obj['is_on_sale'] ?? false)
                        <del class="font-instrument-sans text-[#B5B5B5] text-base md:text-lg lg:text-xl">
                            {!! $price_obj['regular_price_ex_vat']!!}
                        </del>
                    @endif
                    <span class="text-[#272727] font-quicksand font-semibold text-sm">ex. VAT</span>
                </div>

                {{-- Inc VAT secondary price --}}
                {{-- <div class="flex items-baseline gap-1.5">
                    <span class="text-sm md:text-xl text-gray-500 font-instrument-sans font-normal">
                        {!! $price_obj['purchase_price_inc_vat'] !!}
                    </span>
                    <span class="text-gray-500 font-quicksand font-semibold text-sm">inc. VAT</span>
                </div>
                --}}

            </div>
        @endunless
            @unless (empty($package_includes ?? []))
                <div class="flex flex-col gap-3 mt-2">
                    <label class="text-primary text-base font-quicksand font-[600]">{!! __('Includes', 'sage') !!}</label>
                    <ul class="w-full grid grid-cols-1 md:grid-cols-2 gap-2 [&_li]:bg-[#F0F0F0] [&_li]:py-[9px] [&_li]:px-5 [&_li]:rounded-[6px] [&_li]:leading-none text-sm md:text-base">
                        @foreach ($package_includes as $includes)
                            <li><b>{!! esc_attr($includes['item'] ?? '') !!}</b></li>
                        @endforeach
                    </ul>
                </div>
            @endunless

            @unless (empty($punch_line_before_atc ?? ''))
                <div class="flex gap-2 items-center text-sm md:text-base text-[#272727] font-quicksand">
                    @unless (empty($icon_before_atc ?? ''))
                        <img class="w-6" src="{!! esc_attr($icon_before_atc) !!}" />
                    @endunless
                    {!! wp_kses_post($punch_line_before_atc) !!}
                </div>
            @endunless

            @unless (empty($next_day_delivery_timer_banner ?? ''))
                {!! wp_kses_post($next_day_delivery_timer_banner) !!}
            @endunless

            {{-- Quantity Selector --}}
            @if ($product->is_in_stock())
                <div id="dd-qty-wrapper" class="flex items-center gap-3">
                    <span class="text-sm font-quicksand font-semibold text-[#272727]">
                        {{ __('Quantity', 'sage') }}
                    </span>
                    <div class="flex items-center border border-[#D1D5DB] rounded-lg overflow-hidden">
                        <button type="button" id="dd-qty-minus"
                            class="w-10 h-10 flex items-center justify-center text-xl font-medium text-[#272727] hover:bg-[#F0F0F0] transition-colors focus:outline-none"
                            aria-label="{{ __('Decrease quantity', 'sage') }}">
                            &minus;
                        </button>
                        <input type="number" id="dd-qty-input"
                            value="1" min="1"
                            max="{!! esc_attr(($product->get_stock_quantity() !== null && $product->get_stock_quantity() > 0) ? min(50, $product->get_stock_quantity()) : 50) !!}"
                            inputmode="numeric"
                            class="w-12 h-10 text-center border-0 text-sm font-semibold font-quicksand text-[#272727] focus:outline-none focus:ring-0"
                            aria-label="{{ __('Product quantity', 'sage') }}" />
                        <button type="button" id="dd-qty-plus"
                            class="w-10 h-10 flex items-center justify-center text-xl font-medium text-[#272727] hover:bg-[#F0F0F0] transition-colors focus:outline-none"
                            aria-label="{{ __('Increase quantity', 'sage') }}">
                            &plus;
                        </button>
                    </div>
                </div>
            @endif

            {{-- Add to cart button --}}
            <div id="dd-atc-wrapper" class="[&_.added_to_cart]:!hidden grid grid-cols-1 md:grid-cols-2 gap-2">
                @if ($product->is_in_stock())
                    {!! apply_filters('woocommerce_loop_add_to_cart_link',
                            sprintf(
                                '<a href="%s"
                                    data-quantity="%s"
                                    id="dd-atc-btn"
                                    class="flex justify-center gap-2 lg:gap-4 items-center py-3 lg:py-[20px] px-4 lg:px-[24px] rounded-md md:rounded-lg bg-primary mt-4 leading-none text-lg md:text-xl text-white font-quicksand font-bold transition add_to_cart_button ajax_add_to_cart"
                                    %s>
                                    <img src="%s" alt="cart" class="w-4 h-4">
                                    %s
                                </a>',
                                esc_url($product->add_to_cart_url()),
                                esc_attr(isset($args['quantity']) ? $args['quantity'] : 1),
                                wc_implode_html_attributes(array_filter([
                                    'data-product_id' => $product->get_id(),
                                    'data-product_sku' => $product->get_sku(),
                                    'aria-label' => $product->add_to_cart_description(),
                                ])),
                                esc_url(get_stylesheet_directory_uri() . '/resources/images/shopping-cart.svg'),
                                esc_html($product->add_to_cart_text())
                            ),
                        $product)
                    !!}
                @else
                    <button disabled class="flex justify-center gap-2 lg:gap-4 items-center py-3 lg:py-[20px] px-4 lg:px-[24px] rounded-md md:rounded-lg bg-[#B5B5B5] mt-4 leading-none text-lg md:text-xl text-white font-quicksand font-bold cursor-not-allowed opacity-70">
                        <img src="{!! esc_url(get_stylesheet_directory_uri() . '/resources/images/shopping-cart.svg') !!}" alt="cart" class="w-4 h-4">
                        {{ __('Out of Stock', 'sage') }}
                    </button>
                @endif
            </div>

            @unless (empty($punch_line_after_atc ?? ''))
                <div class="flex gap-2 items-center text-sm md:text-base text-[#272727] font-quicksand">
                    @unless (empty($icon_after_atc ?? ''))
                        <img class="w-6" src="{!! esc_attr($icon_after_atc) !!}" />
                    @endunless
                    {!! wp_kses_post($punch_line_after_atc) !!}
                </div>
            @endunless

            @unless (empty($product_notes_points ?? []))
                <ul class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm font-quicksand">
                    @foreach ($product_notes_points as $note)
                        @continue (empty($note['point'] ?? ''))

                        <li class="bg-[#F0F0F0] py-2 px-5 rounded-[6px] leading-tight">
                            {!! wp_kses_post($note['point']) !!}
                        </li>
                    @endforeach
                </ul>
            @endunless

            <div class="mt-4">
                <img
                    src="{!! get_stylesheet_directory_uri() . '/resources/images/trust-indicators-final.png' !!}"
                    alt="Trust"
                    class="max-w-[300px] w-full h-auto"
                    loading="lazy"
                >
            </div>
        </div>
    </div>
</div>
