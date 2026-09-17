<div class="flex flex-col sm:flex-row gap-6 justify-between items-start sm:items-center py-6 sm:py-10">
    @includeIf('blocks.heading-section', [
        ...$reviews_section['section_heading'] ?? [],
        'remove_section_padding' => true,
    ])

    <button
        id="write_review_button"
        type="button"
        class="review-btn h-fit bg-primary text-white px-5 py-2 rounded-lg cursor-pointer"
    >
        {!! __('Write a Review', 'sage') !!}
    </button>
</div>

<div id="product-reviews-wrapper">
    {{-- AJAX-loaded reviews will appear here --}}
</div>
