<section
    class="sale-banner-section-wrapper section-wrapper-padding py-5 md:py-10"
    role="region"
    aria-label="Sales and discount announcements"
>
    <div class="container grid grid-cols-1 md:grid-cols-2 gap-4">

        {{-- Left Banner — solid color --}}
        <div
            class="bg-[#F0E8D5] rounded-md flex justify-between items-center pl-5 xl:pl-[50px]"
            role="group"
            aria-label="Sale highlight with product image"
        >
            <div class="flex-1 flex flex-col gap-2 sm:gap-5 font-quicksand py-5 md:py-10">
                @unless (empty($banner_with_image['subtitle_top'] ?? ''))
                    <span
                        class="font-normal text-sm sm:text-base md:text-xl lg:text-2xl text-primary"
                        aria-label="Sale subtitle"
                    >
                        {{ $banner_with_image['subtitle_top'] }}
                    </span>
                @endunless

                @unless (empty($banner_with_image['title'] ?? ''))
                    <h3
                        class="max-w-[150px] sm:max-w-[180px] md:max-w-[220px] xl:max-w-[280px] font-poppins font-bold leading-[1.2] text-[22px] sm:text-[30px] md:text-[36px] xl:text-[44px] text-primary uppercase"
                    >
                        {{ $banner_with_image['title'] }}
                    </h3>
                @endunless

                @unless (empty($banner_with_image['content'] ?? ''))
                    <p
                        class="font-medium text-xs sm:text-sm lg:text-base lg:leading-[28px] text-[#253D4E] max-w-40 md:max-w-[200px] xl:max-w-[260px]"
                        aria-label="Sale details"
                    >
                        {{ $banner_with_image['content'] }}
                    </p>
                @endunless
            </div>

            @unless (empty($banner_with_image['image'] ?? ''))
                {!! wp_get_attachment_image(
                    $banner_with_image['image'],
                    'full',
                    false,
                    [
                        'class' => 'w-30 sm:w-[160px] md:w-[200px] lg:w-[238px] md:-mr-5 -mt-3',
                        'alt'   => $banner_with_image['title'] ?? 'Sale banner image',
                    ]
                ) !!}
            @endunless
        </div>

        {{-- Right Banner — background image --}}
        <div
            class="flex flex-col justify-between relative before:content-[''] before:absolute before:inset-0 before:bg-primary/80 before:bg-opacity-[80%] before:z-10 p-6 sm:p-8 lg:p-10 xl:pt-[43px] xl:pb-[34px] xl:pr-[50px] xl:pl-[48px] rounded-[8px] overflow-hidden"
            role="group"
            aria-label="Highlighted sale with background image"
        >
            @unless (empty($banner_with_bg_image['background_image'] ?? ''))
                {!! wp_get_attachment_image(
                    $banner_with_bg_image['background_image'],
                    'full',
                    false,
                    [
                        'class' => 'absolute inset-0 w-full h-full object-cover',
                        'alt'   => '',
                        'aria-hidden' => 'true',
                    ]
                ) !!}
            @endunless

            <div class="flex gap-5 justify-between items-start relative text-white z-20">
                @unless (empty($banner_with_bg_image['subtitle_top'] ?? ''))
                    <p
                        class="font-quicksand font-medium text-base md:text-lg lg:text-xl lg:leading-[1.6] lg:tracking-[0.1px] lg: max-w-[276px]"
                        aria-label="Sale announcement"
                    >
                        {{ $banner_with_bg_image['subtitle_top'] }}
                    </p>
                @endunless

                @svg('images.dots', 'h-10 lg:h-[50px] object-contain', ['aria-hidden' => 'true'])
            </div>

            <div class="flex flex-col sm:flex-row justify-between gap-5 sm:items-end relative text-white z-20 mt-5">
                <div>
                    @unless (empty($banner_with_bg_image['title'] ?? ''))
                        <h3
                            class="flex items-end font-poppins font-bold text-[60px] sm:text-[80px] md:text-[7vw] lg:text-[102px] leading-none lg:leading-[1.5] relative lg:-left-3 lg:top-7"
                        >
                            {{ $banner_with_bg_image['title'] }}
                            @unless (empty($banner_with_bg_image['title_suffix'] ?? ''))
                                <span
                                    class="font-normal text-[30px] sm:text-[50px] md:text-[4vw] lg:text-[64px] leading-[1.3] lg:leading-[2]"
                                >
                                    {{ $banner_with_bg_image['title_suffix'] }}
                                </span>
                            @endunless
                        </h3>
                    @endunless

                    @unless (empty($banner_with_bg_image['content'] ?? ''))
                        <p
                            class="font-quicksand font-medium text-lg lg:text-xl max-w-[150px] relative lg:-left-2 lg:top-1"
                            aria-label="Sale description"
                        >
                            {{ $banner_with_bg_image['content'] }}
                        </p>
                    @endunless
                </div>

                @includeIf('components.cta-button-group', [
                    'cta' => [
                        ...$banner_with_bg_image['cta_button'],
                        'button_color' => 'light',
                        'aria_label' => 'Shop sale now'
                    ],
                    'additional_button_classes' => 'sm:px-10 leading-[1.1] lg:tracking-[-0.2px] md:px-[38px] md:py-5 xl:mr-2.5',
                ])
            </div>
        </div>
    </div>
</section>
