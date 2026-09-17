@unless (empty($slides ?? []))
<section class="hero-banner-slider-wrapper" aria-label="Hero banner slider">
    <div class="swiper hero-banner-swiper w-full"
        data-autoplay="{{ $autoplay ? 'true' : 'false' }}"
        data-videoslidedelay="{{ $video_slide_delay ?? 5 }}"
        data-imageslidedelay="{{ $image_slide_delay ?? 3 }}">

        <div class="swiper-wrapper">
            @foreach ($slides as $index => $slide)
                @continue (empty($slide['desktop_image'] ?? ''))
                <div class="swiper-slide hero-banner-slide relative w-full !h-auto" data-video="{{ !empty($slide['enable_video']) ? 'true' : 'false' }}">
                    @php
                        $link = $slide['link'] ?? null;
                        $layout = $slide['content_layout'] ?? 'content_left';
                        $heading = $slide['heading'] ?? '';
                        $description = $slide['description'] ?? '';
                        $bullets = $slide['bullets'] ?? [];
                        $ctaUrl = $link['url'] ?? '';
                        $ctaText = $link['title'] ?? '';
                        $ctaTarget = $link['target'] ?? '';
                        $textColor = $slide['content_text_color'] ?? '#000000';
                    @endphp

                    @if (!empty($slide['enable_video']))
                        @include('blocks.video-banner', [
                            'desktop_background_image' => $slide['desktop_image'],
                            'mobile_background_image' => $slide['mobile_image'],
                            'video' => $slide['video'],
                            'video_captions' => $slide['video_captions'] ?? [],
                            'full_width' => true,
                            'link' => $link,
                            'layout' => $layout,
                            'heading' => $heading,
                            'description' => $description,
                            'bullets' => $bullets,
                            'text_color' => $textColor,
                        ])
                    @else
                        <div class="absolute inset-0 w-full h-full">
                            {!!
                                wp_get_attachment_image($slide['desktop_image'], 'full', false, [
                                    'class' => 'absolute inset-0 w-full h-full object-cover z-0 hidden md:block',
                                ])
                            !!}
                            {!!
                                wp_get_attachment_image($slide['mobile_image'], 'full', false, [
                                    'class' => 'absolute inset-0 w-full h-full object-cover z-0 block md:hidden',
                                ])
                            !!}
                        </div>
                        <div class="px-6 py-10 max-lg:pb-18 lg:px-16 lg:py-12 flex items-center h-full">
                            <div class="relative z-10 w-full h-auto max-w-[1440px] mx-auto">

                                    <div class="flex h-full {{ $layout === 'content_right' ? 'flex-col lg:flex-row' : 'flex-col-reverse lg:flex-row max-lg:items-center' }} items-stretch justify-center gap-6 md:gap-12 lg:gap-16">

                                        @if ($layout === 'content_right' && !empty($slide['content_image']))
                                            <div class="flex-1 w-full h-full flex items-center justify-center">
                                                {!! wp_get_attachment_image($slide['content_image'], 'large', false, [
                                                    'class' => 'hero-banner-media rounded-2xl shadow-lg',
                                                ]) !!}
                                            </div>
                                        @endif

                                        <div class="max-md:max-w-[430px] max-lg:max-w-[530px] w-full lg:w-[55%] flex flex-col items-center lg:items-start justify-center gap-4 sm:gap-5 lg:gap-6 font-quicksand" style="color: {{ $textColor }};">
                                            @if (!empty($heading))
                                                <h2 class="text-3xl sm:text-4xl md:text-5xl lg:text-6xl font-bold leading-[1.05] font-poppins text-center lg:text-left">{{ $heading }}</h2>
                                            @endif
                                            @if (!empty($description))
                                                <p class="text-base sm:text-lg lg:text-xl leading-relaxed text-center lg:text-left">{{ $description }}</p>
                                            @endif
                                            @if (!empty($bullets))
                                                <ul class="flex flex-col sm:grid grid-cols-2 gap-x-5 gap-y-2">
                                                    @foreach ($bullets as $bullet)
                                                        @continue (empty($bullet['bullet_text'] ?? ''))
                                                        <li class="text-left relative pl-5 text-sm sm:text-base lg:text-lg before:absolute before:left-0 before:top-1/2 before:h-2 before:w-2 before:-translate-y-1/2 before:rounded-full before:bg-current">
                                                            {{ $bullet['bullet_text'] }}
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                            @if (!empty($ctaUrl))
                                                <a href="{!! esc_url($ctaUrl) !!}" @if (!empty($ctaTarget)) target="{{ $ctaTarget }}" rel="noopener noreferrer" @endif class="flex items-center justify-center gap-4 bg-black text-white text-sm sm:text-base lg:text-lg font-quicksand font-semibold py-3.5 px-7 sm:py-4 sm:px-8 rounded-md leading-none uppercase tracking-wide mt-2 hover:opacity-90 transition-opacity">
                                                    {{ $ctaText ?: 'Shop Now' }}
                                                    @svg('images.slider_arrow', 'size-4 sm:size-5')
                                                </a>
                                            @endif
                                        </div>

                                        @if ($layout !== 'content_right' && !empty($slide['content_image']))
                                            <div class="w-full sm:w-[80%] md:w-[70%] lg:w-[45%] h-full flex items-center justify-center">
                                                {!! wp_get_attachment_image($slide['content_image'], 'large', false, [
                                                    'class' => 'hero-banner-media rounded-2xl shadow-lg h-[210px] sm:h-[320px]. lg:h-auto lg:!object-cover',
                                                ]) !!}
                                            </div>
                                        @endif
                                    </div>

                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        @if (count($slides) > 1)
            <div class="swiper-pagination hero-banner-pagination max-lg:!bottom-5 !bottom-4 z-10 [&_.swiper-pagination-bullet]:h-4 [&_.swiper-pagination-bullet]:w-4 [&_.swiper-pagination-bullet]:bg-white [&_.swiper-pagination-bullet]:opacity-60 [&_.swiper-pagination-bullet-active]:!bg-black [&_.swiper-pagination-bullet-active]:!opacity-100" aria-label="Slide navigation"></div>
        @endif
    </div>
</section>
@endunless
