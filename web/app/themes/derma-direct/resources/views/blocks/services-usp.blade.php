<section
    class="services-usp-wrapper section-wrapper-padding pt-7 border-b-2 border-grey-outline pb-5"
    role="region"
    aria-label="Unique Selling Points - why choose us"
>
    <div class="container text-black">
        @unless (empty($usp_points ?? []))
            <div
                class="relative grid grid-cols-2 lg:grid-cols-4 gap-[9px]"
                role="list"
                aria-label="List of our key advantages and services"
            >
                @foreach ($usp_points as $point)
                    <div
                        class="bg-white p-4 md:py-[29px] md:pl-[23px] md:pr-[13px] flex flex-col sm:flex-row gap-3 sm:gap-5 items-start sm:items-center rounded-md justify-center"
                        role="listitem"
                    >
                        @unless (empty($point['icon'] ?? ''))
                            {!! wp_get_attachment_image($point['icon'], 'full', false, [
                                'class' => 'size-10',
                                'alt' => '',
                                'aria-hidden' => 'true',
                                'role' => 'presentation'
                            ]) !!}
                        @endunless

                        <div class="flex flex-col gap-[6px]">
                            @unless (empty($point['title'] ?? ''))
                                <h3
                                    class="font-poppins font-bold text-sm md:text-base leading-[18px]"
                                    role="heading"
                                    aria-level="3"
                                >
                                    {{ $point['title'] }}
                                </h3>
                            @endunless

                            @unless (empty($point['content'] ?? ''))
                                <p
                                    class="font-montserrat font-normal text-[10px] sm:text-xs"
                                    aria-label="Service description"
                                >
                                    {{ $point['content'] }}
                                </p>
                            @endunless
                        </div>
                    </div>
                @endforeach
            </div>
        @endunless
    </div>
</section>
