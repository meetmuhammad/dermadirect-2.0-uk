<section class="who-we-are-section-wrapper section-wrapper-padding py-5 md:py-10">
    <div class="container relative pt-5 sm:pt-10 md:pt-[20%] lg:pt-[200px] pb-5 px-4">
        @includeIf('blocks.partials.background-cover')
        <div class="max-w-[1107px] mx-auto w-full max-sm:mt-[180px] relative z-20 flex flex-col gap-6 sm:gap-12">
            <div class="max-w-[300px] md:max-w-[693px] w-full">
                @unless (empty($sub_heading_top ?? ''))
                    <h3 class="font-poppins font-semibold text-base sm:text-xl md:text-2xl text-primary uppercase lg:tracking-[-0.5px] leading-[1.2]">{{ $sub_heading_top }}</h3>
                @endunless

                @unless (empty($heading ?? ''))
                    <h2 class="mt-[10px] sm:mt-5 md:mt-8 lg:mt-10 font-poppins font-bold text-lg sm:text-2xl md:text-3xl lg:text-[32px] leading-none lg:leading-[38px] text-primary uppercase">{{ $heading }}</h2>
                @endunless

                @unless (empty($content ?? ''))
                    <p class="mt-2 sm:mt-5 font-quicksand text-xs sm:text-base md:text-lg md:text-xl text-primary/80 sm:pr-5">{{ $content }}</p>
                @endunless
            </div>

            @unless (empty($our_usps ?? []))
                <div class="max-md:grid max-sm:grid-cols-1 max-md:grid-cols-2  flex flex-col sm:flex-row justify-between gap-4 md:gap-6 xl:gap-10">
                    @foreach ($our_usps as $point)
                        <div class="flex sm:flex-col lg:flex-row gap-2 sm:gap-5 items-start sm:max-w-[340px] w-full">
                            <div class="max-w-10 max-h-10 sm:max-w-[55px] sm:max-h-[55px] md:max-w-[70px] md:max-h-[70px] lg:max-w-20 lg:max-h-20 h-full w-full flex justify-center items-center p-2 sm:p-2.5 bg-primary rounded-md">
                                @unless (empty($point['icon'] ?? ''))
                                    {!! wp_get_attachment_image($point['icon'], 'full', false) !!}
                                @endunless
                            </div>
                            <div class="flex flex-col gap-2">
                                @unless (empty($point['heading'] ?? ''))
                                    <h3 class="font-poppins font-bold text-base sm:text-xl md:text-2xl leading-[1.2] text-primary tracking-[-1px] uppercase">{{ $point['heading'] }}</h3>
                                @endunless

                                @unless (empty($point['content'] ?? ''))
                                    <p class="font-quicksand font-medium text-xs lg:text-base text-secondary-grey leading-[1.3]">{{ $point['content'] }}</p>
                                @endunless
                            </div>
                        </div>
                    @endforeach
                </div>
            @endunless
        </div>
    </div>
</section>
