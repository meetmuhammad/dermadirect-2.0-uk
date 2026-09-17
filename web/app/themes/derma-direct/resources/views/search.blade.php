@extends('layouts.app')

@section('content')
<section class="section-wrapper-padding py-10 md:py-14">
    <div class="container">

        {{-- Page heading --}}
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
            <div>
                <h1 class="font-poppins font-bold text-2xl md:text-3xl text-primary leading-tight">
                    @if (!empty($search_query))
                        {!! sprintf(
                            __('Search results for: <span class="text-secondary-black">%s</span>', 'sage'),
                            esc_html($search_query)
                        ) !!}
                    @else
                        {{ __('Search Results', 'sage') }}
                    @endif
                </h1>
                <p class="font-lato text-sm text-secondary-grey mt-1">
                    {{ $total_products }} {{ __('product(s)', 'sage') }} &amp; {{ $total_posts }} {{ __('article(s)', 'sage') }} {{ __('found', 'sage') }}
                </p>
            </div>

            {{-- Browse all products button --}}
            @if (!empty($shop_url))
                <a
                    href="{{ esc_url($shop_url) }}"
                    class="inline-flex items-center gap-2 font-quicksand font-bold text-sm text-primary border border-primary py-[11px] px-5 rounded-[6px] leading-none hover:bg-primary hover:text-white transition whitespace-nowrap self-start sm:self-auto"
                >
                    {{ __('Browse All Products', 'sage') }}
                </a>
            @endif
        </div>

        {{-- No results at all --}}
        @if ($total_products === 0 && $total_posts === 0)
            <div class="bg-white rounded-md p-8 text-center">
                <p class="font-lato text-secondary-grey text-base">
                    {{ __('No results found. Try a different search term.', 'sage') }}
                </p>
            </div>
        @else

            {{-- Tabs --}}
        @php
            $default_tab = $total_products > 0 ? 'products' : 'blogs';
        @endphp
        <div class="search-results-tabs flex flex-col gap-8" data-default-tab="{{ $default_tab }}">

            {{-- Tab buttons --}}
            <div class="flex gap-0 border-b border-grey-outline" role="tablist" aria-label="{{ __('Search result tabs', 'sage') }}">
                <button
                    id="tab-btn-products"
                    role="tab"
                    data-tab="products"
                    aria-selected="{{ $default_tab === 'products' ? 'true' : 'false' }}"
                    aria-controls="tab-panel-products"
                    class="search-tab-btn cursor-pointer font-quicksand text-sm md:text-base px-5 py-3 leading-none border-b-2 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary {{ $default_tab === 'products' ? 'border-primary text-primary font-bold' : 'border-transparent text-secondary-grey hover:text-primary' }}"
                >
                    {{ __('Products', 'sage') }}
                    @if ($total_products > 0)
                        <span class="search-tab-badge ml-2 inline-flex items-center justify-center rounded-full text-[10px] font-bold w-5 h-5 leading-none transition-colors {{ $default_tab === 'products' ? 'bg-primary text-white' : 'bg-grey-outline text-secondary-grey' }}">{{ $total_products }}</span>
                    @endif
                </button>

                <button
                    id="tab-btn-blogs"
                    role="tab"
                    data-tab="blogs"
                    aria-selected="{{ $default_tab === 'blogs' ? 'true' : 'false' }}"
                    aria-controls="tab-panel-blogs"
                    class="search-tab-btn cursor-pointer font-quicksand text-sm md:text-base px-5 py-3 leading-none border-b-2 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary {{ $default_tab === 'blogs' ? 'border-primary text-primary font-bold' : 'border-transparent text-secondary-grey hover:text-primary' }}"
                >
                    {{ __('Blogs', 'sage') }}
                    @if ($total_posts > 0)
                        <span class="search-tab-badge ml-2 inline-flex items-center justify-center rounded-full text-[10px] font-bold w-5 h-5 leading-none transition-colors {{ $default_tab === 'blogs' ? 'bg-primary text-white' : 'bg-grey-outline text-secondary-grey' }}">{{ $total_posts }}</span>
                    @endif
                </button>
            </div>

            {{-- Products tab panel --}}
            <div
                id="tab-panel-products"
                role="tabpanel"
                aria-labelledby="tab-btn-products"
                class="flex flex-col gap-10 {{ $default_tab !== 'products' ? 'hidden' : '' }}"
            >
                @if ($total_products === 0)
                    <p class="font-lato text-secondary-grey text-sm">
                        {{ __('No products found for this search.', 'sage') }}
                    </p>
                @else
                    @foreach ($products_by_category as $cat_id => $category)
                        <div class="flex flex-col gap-5">
                            {{-- Category heading --}}
                            <div class="flex items-center gap-4">
                                <h2 class="font-poppins font-semibold text-base md:text-lg text-primary whitespace-nowrap">
                                    {!! wp_kses_post($category['name']) !!}
                                    <span class="font-normal text-secondary-grey text-sm">({{ $category['count'] }})</span>
                                </h2>
                                <div class="flex-1 h-px bg-grey-outline"></div>
                            </div>

                            {{-- Product grid --}}
                            @php $page_size = 8; @endphp
                            <div
                                class="search-category-grid grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-5 font-quicksand"
                                data-page-size="{{ $page_size }}"
                                data-total="{{ $category['count'] }}"
                            >
                                @foreach ($category['products'] as $idx => $product)
                                    <div data-index="{{ $idx }}" @class(['hidden' => $idx >= $page_size, 'h-full' => true])>
                                        @include('woocommerce.content-product', ['used_as_partial' => true])
                                    </div>
                                @endforeach
                            </div>

                            {{-- Pagination controls (rendered by JS) --}}
                            @if ($category['count'] > $page_size)
                                <div class="search-cat-controls flex justify-center items-center gap-3 mt-2"></div>
                            @endif
                        </div>
                    @endforeach
                @endif
            </div>

            {{-- Blogs tab panel --}}
            <div
                id="tab-panel-blogs"
                role="tabpanel"
                aria-labelledby="tab-btn-blogs"
                class="{{ $default_tab !== 'blogs' ? 'hidden' : '' }}"
            >
                @if ($total_posts === 0)
                    <p class="font-lato text-secondary-grey text-sm">
                        {{ __('No articles found for this search.', 'sage') }}
                    </p>
                @else
                    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5">
                        @foreach ($blog_posts as $post)
                            @include('partials.search-blog-card', ['post' => $post])
                        @endforeach
                    </div>
                @endif
            </div>

        </div>
        @endif

    </div>
</section>
@endsection
