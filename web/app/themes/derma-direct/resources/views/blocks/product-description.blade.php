<section class="product-description-wrapper pt-10 md:pt-15 lg:pt-20 font-quicksand">
    <div id="description-toggle" class="flex justify-between items-center bg-primary border border-primary px-5 lg:px-10 py-2 cursor-pointer">
        <h3 class="font-bold text-lg md:text-xl leading-[1.3] text-white">{!! __('Description', 'sage') !!}</h3>
        @svg('images.chevron', 'toggle-arrow w-3 h-3 transition-transform duration-300')
    </div>

    {{-- Description (hidden by default) --}}
    <div
        id="description-text"
        class="border border-primary overflow-hidden transition-all duration-700 ease-in-out"
    >
        <div class="p-5 flex flex-col gap-4 sm:gap-6 md:gap-8 lg:gap-10">
            <h2 class="font-poppins font-bold text-2xl md:text-3xl lg:text-[32px] leading-[1.2] text-primary">{!! get_the_title() !!} {!! __('description', 'sage') !!}</h2>

            <InnerBlocks
                allowedBlocks="{{ wp_json_encode($allowed_blocks) }}"
                template="{{ wp_json_encode($template) }}"
            />

        </div>
    </div>
</section>
