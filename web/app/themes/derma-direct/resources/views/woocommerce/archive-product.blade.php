{{--
The Template for displaying product archives, including the main shop page which is a post type archive

This template can be overridden by copying it to yourtheme/woocommerce/archive-product.php.

HOWEVER, on occasion WooCommerce will need to update template files and you
(the theme developer) will need to copy the new files to your theme to
maintain compatibility. We try to do this as little as possible, but it does
happen. When this occurs the version of the template file will be bumped and
the readme will list any important changes.

@see https://docs.woocommerce.com/document/template-structure/
@package WooCommerce/Templates
@version 3.4.0
--}}

@extends('layouts.app')

@section('content')

    @php
        global $wp_query;
    @endphp

    <section class="section-wrapper-padding py-15">
        <div class="container">
            <div class="flex flex-col lg:flex-row gap-4 lg:items-start relative">
                @includeIf('woocommerce.partials.shop-page-filters')

                <div class="flex-1 font-quicksand flex flex-col gap-4 xl:gap-6">

                    {{-- Product Controls --}}
                    @includeIf('woocommerce.partials.product-controls', [
                        'products_found' => $products_found ?? 0,
                    ])

                    {{-- Product Grid --}}
                    @if (isset($products) && $products->have_posts())
                        @includeIf('woocommerce.partials.product-grid', [
                            'products' => $products,
                            'per_page' => $per_page ?? 9,
                        ])
                    @endif

                </div>
            </div>
        </div>
    </section>

    {{-- Product loop start --}}
    @if (false && woocommerce_product_loop())
        @php
            do_action('woocommerce_before_shop_loop');
            woocommerce_product_loop_start();
        @endphp

        @if (wc_get_loop_prop('total'))
        @while (have_posts())
            @php
                the_post();
                do_action('woocommerce_shop_loop');
                wc_get_template_part('content', 'product');
            @endphp
        @endwhile
        @endif

        @php
            woocommerce_product_loop_end();
            do_action('woocommerce_after_shop_loop');
        @endphp
    @endif
    {{-- Product loop end --}}

    @php
        do_action('woocommerce_after_main_content');
        do_action('get_sidebar', 'shop');
        do_action('get_footer', 'shop');
    @endphp
@endsection
