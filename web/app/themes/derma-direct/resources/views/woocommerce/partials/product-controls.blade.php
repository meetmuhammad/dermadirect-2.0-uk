{{--
  Product Controls (Count & Sort)
  @param int $products_found
--}}

<div class="flex flex-col-reverse sm:flex-row justify-between sm:items-center max-sm:gap-4">
    @php
        $paged = get_query_var('paged') ? absint(get_query_var('paged')) : 1;
        $from  = (($paged - 1) * $per_page) + 1;
        $to    = min($paged * $per_page, $products_found);
        $sort_labels = [
            'best_selling' => 'Best Selling',
            'featured'     => 'Featured',
            'newest'       => 'Newest',
            'price_asc'    => 'Price: Low to High',
            'price_desc'   => 'Price: High to Low',
            'name_asc'     => 'A-Z',
        ];

        $current_sort = request('sort', 'newest');
    @endphp
    <p class="product-count-text font-quicksand font-medium text-[13px] text-[#777777]">
        Showing {{ $from }}–{{ $to }} of {{ $products_found }} products
        <span>|</span>
        Sorted by Newest
    </p>
    <div class="flex gap-2.5">
        <div class="py-[12px] pl-[17px] pr-5 bg-white border border-grey-outline rounded sm:rounded-[10px] flex gap-2.5 items-center font-quicksand font-medium text-[13px] text-[#777777]">
            @svg('images.filter-icon-2', 'size-[14px]')
            <div class="flex gap-0 items-center">
                <label for="show">Sort by:</label>
                <select id="short_by_filter" class="outline-none" name="sort" autocomplete="off">
                    <option value="best_selling">Best Selling</option>
                    <option value="featured">Featured</option>
                    <option value="newest" selected>Newest</option>
                    <option value="price_asc">Price: Low to High</option>
                    <option value="price_desc">Price: High to Low</option>
                    <option value="name_asc">A-Z</option>
                </select>
            </div>
        </div>
    </div>
</div>
