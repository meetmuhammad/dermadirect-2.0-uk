<section class="hero-section-wrapper relative pt-[9%] pb-[5%] lg:pt-[100px] lg:pb-14 px-4 sm:px-5">
    @includeIf('blocks.partials.background-cover', [ 'img_loading' => 'eager' ])

    @if ($show_blue_tint ?? false)
        <div class="absolute inset-0 w-full h-full bg-[#C3FBFACC]/80"></div>
    @endif

    <div class="max-w-[1370px] mx-auto w-full flex flex-col items-center justify-center gap-5 sm:gap-8 md:gap-10 lg:gap-20 font-quicksand relative">
        @unless (empty($supporting_image ?? ''))
            {!!
                wp_get_attachment_image($supporting_image, 'full', false, [
                    'class' => 'w-[30vw] lg:w-[32vw] xl:!max-w-[460px] xl:w-full absolute left-0 2xl:-left-20 -top-10 lg:-top-26 hidden sm:block',
                    'alt' => '',
                ])
            !!}
        @endunless

        <div class="max-w-[80vw] sm:max-w-[50vw] 2xl:max-w-[743px] mx-auto flex flex-col items-center gap-[1vw] gap-5 sm:gap-8 md:gap-10 font-quicksand">
            <div class="flex flex-col gap-2 sm:gap-4 md:gap-[22px]">
                <div class="flex flex-col gap-2">
                    @unless (empty($subheading_top ?? ''))
                        <span class="text-center text-[4vw] sm:text-[2.2vw] 2xl:text-[32px] font-normal">{{ $subheading_top }}</span>
                    @endunless

                    @unless (empty($heading ?? ''))
                        <h1 class="text-center font-poppins text-[9vw] sm:text-[5.6vw] 2xl:text-[80px] font-bold text-primary leading-[1.1] 2xl:leading-[1.2] uppercase">{{ $heading }}</h1>
                    @endunless
                </div>

                @unless (empty($subheading_bottom ?? ''))
                    <h2 class="text-center font-medium text-[3.4vw] sm:text-[1.8vw] 2xl:text-[32px] ">{!! $subheading_bottom !!}</h2>
                @endunless
            </div>

            @includeIf('components.cta-button-group', [
                'additional_button_classes' => 'sm:px-10 md:px-[58px] md:py-5',
            ])
        </div>
    </div>
</section>
