{{--
  Product Grid with Load More
  @param WP_Query $products
  @param int $per_page
--}}

@php
    $products_found = $products->found_posts ?? 0;
@endphp

@if ($products->have_posts())
    <div
        class="woocommerce-grid grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 min-[1435px]:!grid-cols-4 gap-4"
        id="product-grid"
        data-products-found="{!! esc_attr($products_found) !!}"
    >
        @while ($products->have_posts())
            @php
                $products->the_post();
                wc_get_template_part('content', 'product');
            @endphp
        @endwhile
    </div>

    <div class="text-center mt-10">
        <button
            id="load-more"
            @class([
                'font-quicksand text-primary text-base font-bold border border-primary py-[13px] px-6 rounded-[6px] leading-none hover:bg-primary hover:text-white cursor-pointer',
                'hidden' => (($products->max_num_pages ?? 1) <= 1),
            ])
            data-page="1"
            data-max="{{ $products->max_num_pages ?? 1 }}"
        >
            {!! __('View More', 'sage') !!}
        </button>
    </div>

    @php wp_reset_postdata(); @endphp
@endif
