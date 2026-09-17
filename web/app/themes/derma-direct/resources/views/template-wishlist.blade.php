{{--
  Template Name: Wishlist
--}}

@extends('layouts.app')

@section('content')
    <div class="section-wrapper-padding py-10 min-h-[60vh]">
        <div class="container">

            <h1 class="font-bold font-poppins text-3xl text-primary mb-8">
                {{ __('My Wishlist', 'sage') }}
            </h1>

            @if (!empty($wishlist_products ?? []))

                {{-- Product Grid --}}
                <div
                    class="wishlist-products-grid grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6"
                >
                    @foreach ($wishlist_products as $product)
                        <div
                            class="wishlist-product-item relative transition-all duration-300"
                            data-product-id="{{ $product->get_id() }}"
                        >
                            @include('woocommerce.content-product', [
                                'product'         => $product,
                                'used_as_partial' => true,
                            ])
                        </div>
                    @endforeach
                </div>

            @else

                {{-- Empty state (server-rendered) --}}
                <div class="wishlist-empty-state flex flex-col items-center justify-center py-24 text-center">
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="w-16 h-16 mb-5 text-grey-outline"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="1.2"
                        aria-hidden="true"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z" />
                    </svg>
                    <p class="text-secondary-grey text-lg font-medium mb-6">
                        {{ __('Your wishlist is empty.', 'sage') }}
                    </p>
                    <a
                        href="{{ esc_url(wc_get_page_permalink('shop')) }}"
                        class="inline-block bg-primary text-white font-semibold font-lato px-8 py-3 rounded-sm hover:bg-secondary-black transition-colors duration-200"
                    >
                        {{ __('Browse Products', 'sage') }}
                    </a>
                </div>

            @endif

            {{-- Hidden empty state template (shown via JS after last item removed) --}}
            @if (!empty($wishlist_products ?? []))
                <div
                    class="wishlist-empty-state hidden flex-col items-center justify-center py-24 text-center"
                    aria-live="polite"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="w-16 h-16 mb-5 text-grey-outline"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="1.2"
                        aria-hidden="true"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z" />
                    </svg>
                    <p class="text-secondary-grey text-lg font-medium mb-6">
                        {{ __('Your wishlist is empty.', 'sage') }}
                    </p>
                    <a
                        href="{{ esc_url(wc_get_page_permalink('shop')) }}"
                        class="inline-block bg-primary text-white font-semibold font-lato px-8 py-3 rounded-sm hover:bg-secondary-black transition-colors duration-200"
                    >
                        {{ __('Browse Products', 'sage') }}
                    </a>
                </div>
            @endif

        </div>
    </div>
@endsection
