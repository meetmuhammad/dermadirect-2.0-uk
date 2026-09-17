<section
    class="video-section-wrapper section-wrapper-padding pt-5 md:pt-10 pb-10 md:pb-15 lg:pb-20"
    role="region"
    aria-label="Video section"
>
    <div class="container relative rounded-md flex gap-5 justify-between items-center max-md:py-6 pt-6 sm:pt-10 md:pt-[70px] px-4 sm:px-10 md:px-15 max-sm:h-[180px] max-md:h-[300px]">
        @includeIf('blocks.partials.background-cover', [
            'additional_img_classes' => 'rounded-md',
        ])

        <div class="relative flex gap-0 z-20">
            <div class="flex flex-col gap-2 sm:gap-[14px] max-w-[300px] sm:max-w-[370px]">
                @unless (empty($heading ?? ''))
                    <h2 class="max-md:max-w-[200px] font-poppins font-bold text-2xl sm:text-3xl md:text-4xl lg:text-[64px] leading-[1.2] lg:tracking-[-1.5px] text-primary">
                        {{ $heading }}
                    </h2>
                @endunless

                @unless (empty($content ?? ''))
                    <p class="font-quicksand font-medium text-sm sm:text-base md:text-xl lg:text-2xl text-primary-light">
                        {{ $content }}
                    </p>
                @endunless
            </div>

            @unless (empty($supporting_image ?? ''))
                <div class="hidden md:block max-sm:mt-[120px] max-lg:mt-[70px] -ml-[140px] sm:-ml-[230px] md:-ml-[150px] lg:-ml-[100px] lg:-ml-[120px] xl:-ml-[148px] xl:-mt-2 -mb-5 md:-mb-10 lg:-mb-15">
                    {!! wp_get_attachment_image($supporting_image, 'full', false, [
                        'class' => 'max-w-[200px] sm:max-w-[300px] md:max-w-[380px] lg:max-w-[420px] xl:max-w-[480px] w-full',
                        'alt'   => esc_attr($heading ?? 'Supporting image for video section'),
                        'aria-hidden' => 'false'
                    ]) !!}
                </div>
            @endunless
        </div>

        @unless (empty($video ?? []) || empty($video['url'] ?? ''))
            <div
                class="flex-1 flex items-center justify-center relative z-20 md:-mt-10 xl:mt-[-100px] xl:ml-[-39px]"
                role="group"
                aria-label="Video player controls"
            >
                <button
                    class="video-play-button hover:cursor-pointer"
                    type="button"
                    data-video-url="{!! esc_attr($video['url']) !!}"
                    aria-label="Play video{{ !empty($heading) ? ': ' . esc_attr($heading) : '' }}"
                    aria-haspopup="dialog"
                    aria-controls="video-popup"
                    aria-expanded="false"
                >
                    @svg('images.play-icon', 'size-15 md:size-20 lg:size-[97px]', ['role' => 'img', 'aria-hidden' => 'true'])
                </button>
            </div>
        @endunless
    </div>
</section>
