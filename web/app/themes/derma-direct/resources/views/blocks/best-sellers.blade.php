<section class="best-seller-section-wrapper section-wrapper-padding py-3 md:py-6">
    <div class="container grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-[17px]">
        @unless (empty($best_seller_products ?? []))
            @foreach ($best_seller_products as $product)
                @continue (empty($product['title'] ?? '') || empty($product['link'] ?? '') || empty($product['featured_image'] ?? ''))
                <div class="flex justify-between items-start gap-5 bg-white py-5 md:py-10 lg:py-[53px] px-4 lg:pl-[28px] lg:pr-[25px] rounded-md">
                    <div class="h-full flex flex-col items-start gap-3">
                        <div class="flex flex-col">
                            <span class="font-quicksand font-normal text-[10px] sm:text-xs text-[#253D4E] h-4">{{ $product['brands'] }}</span>
                            <h3 class="font-poppins font-bold text-base sm:text-lg lg:text-xl text-secondary-black leading-none max-md:my-1">{{ $product['title'] }}</h3>
                        </div>

                        @unless (empty($product['short_description'] ?? ''))
                            <p class="font-quicksand font-medium text-sm sm:tex-md text-primary">{!! wp_trim_words($product['short_description'], 15, '...') !!}</p>
                        @endunless

                        @includeIf('components.cta-button-group', [
                            'cta' => [
                                'title' => 'Shop Now',
                                'url' => $product['link'],
                                'icon_svg' => 'images.arrow',
                                'reverse_icon_direction' => true,
                            ],
                            'additional_button_classes' => '!text-[12px] mt-auto',
                        ])
                    </div>
                    <div class="max-w-[70px] md:max-w-[100px] lg:max-w-[120px] w-full">
                        {!! wp_get_attachment_image($product['featured_image'], 'full', false, [
                            'class' => '',
                            'alt' => get_post_meta($product['featured_image'], '_wp_attachment_image_alt', true) ?: $product['title'],
                        ]) !!}
                    </div>
                </div>
            @endforeach
        @endunless
    </div>
</section>
