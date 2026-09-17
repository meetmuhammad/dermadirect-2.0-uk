
<div class="swiper-slide bg-white p-5 md:py-10 md:pl-10 md:pr-[30px] rounded-md max-sm:!max-w-[250px] max-md:!max-w-[300px] !h-auto !flex flex-col">
    @unless (empty($review['rating'] ?? 0))
        {!! \App\Helper\Helper::renderStarRating($review['rating']); !!}
    @endunless
    @unless (empty($review['text'] ?? ''))
        <p class="font-quicksand font-medium text-sm sm:text-base md:text-[17px] text-[#1B222B] leading-[1.4] mt-4 md:mt-[34px] max-h-[120px] overflow-y-scroll">{{ $review['text'] }}</p>
    @endunless
    <div class="flex gap-[12px] md:gap-[17px] items-center pt-4 md:pt-[42px] mt-auto">
        @unless (empty($review['image'] ?? ''))
            <img class="h-10 w-10 md:h-12 md:w-12 rounded-full" src="{!! esc_attr($review['image']) !!}" alt="">
        @endunless
        <div class="flex flex-col font-inter font-normal text-xs md:text-base text-[#1B222B]">
            @unless (empty($review['name'] ?? ''))
                <span>{{ $review['name'] }}</span>
            @endunless

            @unless (empty($review['date'] ?? ''))
                <span>{{ $review['date'] }}</span>
            @endunless
        </div>
    </div>
</div>
