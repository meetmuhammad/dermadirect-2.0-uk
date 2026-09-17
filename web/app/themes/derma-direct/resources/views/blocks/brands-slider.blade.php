@unless (empty($brands_slides ?? []))
    <section class="section-wrapper-padding py-3 md:py-6">
        <div class="max-w-[1440px] mx-auto">
            @unless (empty($heading ?? ''))
                <h3 class="text-2xl lg:text-3xl font-semibold max-w-[550px] mx-auto font-montserrat text-center mb-1">{{ $heading }}</h3>
            @endunless

            <div class="swiper brand-slider" data-autoplay="{{ $autoplay ? 'true' : 'false' }}" data-autoplay-delay="{{ $autoplay_delay }}">
                <div
                    @class([
                        'swiper-wrapper',
                        'flex overflow-scroll' => (is_admin() ?? false),
                    ])
                >

                    @foreach($brands_slides as $slide)
                        @continue(empty($slide['brand_image'] ?? ''))

                        @php
                            $link = $slide['link'] ?? null;
                            $has_link = !empty($link['url']);
                            $tag = $has_link ? 'a' : 'div';
                        @endphp

                        <div class="swiper-slide">
                            <<?= $tag ?>
                                @if($has_link)
                                    href="{!! esc_url($link['url']) !!}"
                                    target="{!! esc_attr($link['target'] ?? '_self') !!}"
                                @endif
                            >
                                {!! wp_get_attachment_image($slide['brand_image'], 'full') !!}
                            </<?= $tag ?>>
                        </div>
                    @endforeach

                </div>

                <div class="swiper-pagination"></div>
            </div>
        </div>
    </section>
@endunless
