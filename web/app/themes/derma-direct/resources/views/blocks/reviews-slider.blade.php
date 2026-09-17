<section class="reviews-slider-section-wrapper py-5 md:py-10 overflow-hidden">
    @unless (empty( $reviews ?? []))
        <div class="product-review-slider flex flex-col gap-[18px]">
            <div class="swiper marquee-left">
                <div class="swiper-wrapper">
                    @foreach (($reviews['first_half'] ?? []) as $review)
                        @includeIf('blocks.partials.review-card')
                    @endforeach
                </div>
            </div>

            <div class="swiper marquee-right">
                <div class="swiper-wrapper">
                    @foreach (($reviews['second_half'] ?? []) as $review)
                        @includeIf('blocks.partials.review-card')
                    @endforeach
                </div>
            </div>
        </div>
    @endunless
</section>
