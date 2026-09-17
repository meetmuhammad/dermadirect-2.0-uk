@unless (empty($testimonial_cards ?? []))
<section class="px-5 pt-[50px] pb-0">
    <div class="max-w-[1440px] mx-auto">

        @unless (empty($heading ?? ''))
        <h3 class="text-2xl lg:text-3xl font-semibold max-w-[550px] mx-auto font-montserrat text-center mb-10">
            {{ $heading }}
        </h3>
        @endunless

        @if(!empty($testimonial_cards))
        <div class="swiper testimonial-slider relative px-[50px]! md:px-[80px]! before:content-[''] before:absolute before:top-0 before:bottom-0 before:left-[-2px] before:w-[50px] md:before:w-[80px] before:bg-[#FAFAFA] before:z-[2] after:content-[''] after:absolute after:top-0 after:bottom-0 after:right-[-2px] after:w-[50px] md:after:w-[80px] after:bg-[#FAFAFA] after:z-[2]"
>
            <div class="swiper-wrapper">

                @foreach($testimonial_cards as $card)

                @php
                    $name = $card['client_name'] ?? '';
                    $country = $card['country_or_state'] ?? '';
                    $title = $card['client_title'] ?? '';
                    $content = $card['testimonial_content'] ?? '';
                    $rating = (int) ($card['rating'] ?? 0);
                    $ratingPercentage = ($rating / 5) * 100;
                @endphp

                <!-- Slide -->
                <div class="swiper-slide !h-auto">
                    <div class="border border-[#e1e8ed] rounded-sm h-full">

                        @if(!empty($name) || !empty($country) || !empty($title) || $rating != 0)
                        <div class="p-4 pb-[6px] border-b border-[#e1e8ed] flex gap-3">
                            <div class="flex flex-col gap-1.5">
                                @if(!empty($name))
                                <h4 class="font-montserrat text-sm sm:text-base lg:text-lg font-semibold leading-tight">
                                    {!! wp_kses($name, wp_kses_allowed_html('post')) !!}
                                </h4>
                                @endif

                                @if(!empty($country))
                                <span class="font-montserrat text-sm text-[#7a7a7a] italic">
                                    {!! wp_kses($country, wp_kses_allowed_html('post')) !!}
                                </span>
                                @endif

                                @if($rating != 0)
                                <div class="relative h-5 w-[132px]">
                                    <span
                                        class="absolute overflow-hidden z-10"
                                        style="width: {{ $ratingPercentage }}%;"
                                    >
                                        @svg('images.testimonial-rating-fill')
                                    </span>

                                    @svg('images.testimonial-rating')
                                </div>
                                @endif

                                @if(!empty($title))
                                <span class="font-montserrat text-sm text-[#4a4a4a] mt-0.5">
                                    {!! wp_kses($title, wp_kses_allowed_html('post')) !!}
                                </span>
                                @endif
                            </div>
                        </div>
                        @endif

                        <!-- @if(!empty($content))
                        <div class="p-4">
                            <div class="font-montserrat">
                                {!! wp_kses($content, wp_kses_allowed_html('post')) !!}
                            </div>
                        </div>
                        @endif -->

                        @if(!empty($content))
                        <div class="p-4">
                            <div class="testimonial-content-wrapper">
                                <div class="testimonial-content line-clamp-3 font-montserrat max-sm:text-sm">
                                    {!! wp_kses($content, wp_kses_allowed_html('post')) !!}
                                </div>

                                <button
                                    type="button"
                                    class="testimonial-toggle mt-2 text-sm font-semibold underline hidden cursor-pointer"
                                >
                                    See More
                                </button>
                            </div>
                        </div>
                        @endif

                    </div>
                </div>

                @endforeach

            </div>

            <!-- Arrows -->
            <div class="swiper-button-prev max-sm:!left-0"></div>
            <div class="swiper-button-next max-sm:!right-0"></div>

        </div>
        @endif

    </div>
</section>
@endunless
