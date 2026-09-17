<section
    class="shop-by-category-wrapper section-wrapper-padding py-5 md:py-10 overflow-hidden"
    role="region"
    aria-label="Shop by category section"
>

    <div class="container flex flex-col gap-4 sm:gap-6 md:gap-10 lg:gap-20">
        <div class="flex flex-col gap-5">
            <div @class([
                'flex gap-10 justify-between items-center relative',
                'flex-row-reverse' => ($flip_layout ?? false),
            ])>
                <div class="flex gap-2 [&_path]:fill-secondary-grey">
                    <button class="prev-arrow bg-grey-outline p-2 rounded-full cursor-pointer">@svg('images.arrow', 'w-4 h-4 rotate-180')</button>
                    <button class="next-arrow bg-grey-outline p-2 rounded-full cursor-pointer">@svg('images.arrow', 'w-4 h-4')</button>
                </div>
                @unless (empty($top_links ?? []))
                    <ul class="flex flex-col max-md:hidden max-md:bg-white max-md:p-2.5 max-md:rounded-md max-md:absolute max-md:right-0 max-md:top-10 max-md:z-20 md:flex-row gap-4 lg:gap-[23px]">
                        @foreach ($top_links as $top_link)
                            @continue (empty($top_link['link']['title'] ?? '') && empty($top_link['link']['url'] ?? ''))
                            <li><a href="{!! esc_attr($top_link['link']['url']) !!}" class="font-quicksand font-semibold text-base text-primary transition hover:text-primary">{{ $top_link['link']['title'] }}</a></li>
                        @endforeach
                    </ul>
                @endunless

                <button id="menu-btn" class="md:hidden text-gray-700 focus:outline-none">
                    <!-- Open Icon -->
                    <svg id="menu-icon" xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>

                    <!-- Close Icon -->
                    <svg id="close-icon" xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div @class([
                'flex gap-2 justify-between',
                'flex-col sm:flex-row-reverse' => ($flip_layout ?? false),
                'flex-col-reverse sm:flex-row' => !($flip_layout ?? false),
            ])>
                <div class="w-full sm:w-[33%] lg:w-[27%] relative">
                    @unless (empty($category_data['background_image'] ?? ''))
                        {!! wp_get_attachment_image($category_data['background_image'], 'full', false, [
                            'class' => 'max-sm:max-h-[500px] rounded-md w-full h-full object-cover',
                            'alt' => '',
                            'aria-hidden' => 'true',
                            'role' => 'presentation'
                        ]) !!}
                    @endunless
                    <div class="absolute text-white inset-0 p-2 md:p-4 lg:p-6 flex flex-col justify-end">
                        @unless (empty($category_data['icon'] ?? ''))
                            {!! wp_get_attachment_image($category_data['icon'], 'full', false, [
                                'class' => 'max-w-[50px] w-full',
                                'alt' => '',
                                'aria-hidden' => 'true',
                                'role' => 'presentation'
                            ]) !!}
                        @endunless
                        @unless (empty($category_data['name'] ?? ''))
                            <h3 class="font-poppins font-bold text-xl md:text-2xl lg:text-3xl xl:text-[32px] xl:leading-[36px] mt-1 lg:mt-2.5">{{ $category_data['name'] }}</h3>
                        @endunless
                        @unless (empty($category_data['description'] ?? ''))
                            <div class="font-quicksand font-medium text-sm mt-1 lg:mt-2">{{ wp_trim_words($category_data['description'], 20, '...') }}</div>
                        @endunless

                        @unless (empty($category_data['permalink'] ?? ''))
                            <a href="{!! esc_attr($category_data['permalink']) !!}" class="font-quicksand font-medium text-base flex gap-3 items-center justify-end mt-6 lg:mt-10">{!! __('Show more', 'sage') !!} @svg('images.arrow', 'w-3 h-3')</a>
                        @endunless
                    </div>
                </div>


                <div class="w-full sm:w-[67%] lg:w-[73%] bg-[#F0E8D5] pt-[11px] pb-[14px] rounded-md px-3 relative">
                    @unless (empty($category_data['products'] ?? []))
                        {{-- Swiper Container --}}
                        <div class="swiper product-slider h-full">
                            <div class="swiper-wrapper flex">
                                @foreach ($category_data['products'] as $product)
                                    {{-- Slide 1 --}}
                                    <div class="swiper-slide bg-white !pt-[18px] px-3 !pb-2.5 relative text-black rounded-md !flex flex-col justify-start gap-5 !h-auto">
                                        {!! wp_get_attachment_image($product['image'], 'full', false, [
                                            'class' => 'h-[150px] md:h-[213px] object-contain mx-auto',
                                            'alt' => get_post_meta($product['image'], '_wp_attachment_image_alt', true) ?: $product['title'],
                                            'aria-hidden' => 'true',
                                            'role' => 'presentation'
                                        ]) !!}

                                        @if ($product['price']['is_on_sale'] ?? false)
                                            <span class="font-lato font-normal text-xs p-2 lg:p-2.5 bg-primary rounded-md absolute left-1 top-1 text-white">-{{$product['price']['discount_pct']}}%</span>
                                        @endif
                                        <div class="flex flex-1 flex-col items-start w-full">
                                            <span class="font-quicksand font-normal text-xs text-[#272a2d] h-4">Mastelli</span>
                                            <h3 class="font-poppins font-bold text-xl md:text-2xl mt-1 line-clamp-2">{{ $product['title'] }}</h3>
                                            @unless (empty($product['short_description'] ?? ''))
                                                <p class="font-quicksand font-medium text-xs text-primary mt-1 min-h-8">{{ wp_trim_words($product['short_description'], 15, '...') }}</p>
                                            @endunless
                                            <div class="flex flex-col gap-4 mt-auto">
                                                <div class="flex gap-2 md:gap-[14px] items-center mt-2">
                                                    <h4 class="font-quicksand font-bold text-lg md:text-xl text-primary underline">{!! $product['price']['purchase_price'] !!}</h4>
                                                    @if ($product['price']['is_on_sale'] ?? false)
                                                        <span class="font-quicksand font-semibold text-sm md:text-base text-secondary-grey line-through">{!! $product['price']['regular_price'] !!}</span>
                                                    @endif
                                                </div>
                                                @includeIf('components.cta-button-group', [
                                                    'cta' => [
                                                        'title' => 'Add to cart',
                                                        'url' => $product['permalink'],
                                                        'icon_svg' => 'images.shopping-cart',
                                                    ],
                                                    'additional_button_classes' => '!text-[12px] mt-auto',
                                                ])
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endunless
                </div>
            </div>
        </div>

        @unless (empty($cta['url'] ?? '') || empty($cta['title'] ?? ''))
            <div class="flex flex-col items-center gap-3 font-quicksand">
                @includeIf('components.cta-button-group', [
                    'additional_button_classes' => 'sm:px-10 md:px-[83px] md:py-5',
                ])
                @unless (empty($punchline ?? ''))
                    <p class="font-normal text-sm sm:text-base text-secondary-grey text-center max-w-[526px]">{{ $punchline }}</p>
                @endunless
            </div>
        @endunless

    </div>
</section>
