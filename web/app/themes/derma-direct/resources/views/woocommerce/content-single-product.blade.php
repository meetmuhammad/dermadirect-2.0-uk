@php
    use App\Helper\Helper;

    $product_id = get_the_ID();
    $product = wc_get_product($product_id);

    $price_obj = Helper::getProductPricingInfo($product_id);
@endphp

<section class="product-single-page-content-wrapper section-wrapper-padding pt-10 lg:pt-20">
    <div class="container">
        @php wc_print_notices(); @endphp

        {{-- ====================================
            Product-head
        ==================================== --}}
        @includeIf('woocommerce.partials.product-header')

        {{-- ====================================
            Product Description
        ==================================== --}}
        @includeIf('woocommerce.partials.product-description')

        {{-- ====================================
            Reviews section
        ==================================== --}}
        <div id="product_reviews_section_wrapper">
            @includeIf('woocommerce.product-review-section')
        </div>

        {{-- ====================================
            Recommended Products section
        ==================================== --}}
        @unless (empty($recommended_products['products'] ?? []))
            @includeIf('blocks.heading-section', [
                ...$recommended_products['section_heading'] ?? [],
            ])

            @includeIf('blocks.recommended-products', [
                'products' => $recommended_products['products'] ?? [],
            ])
        @endunless
    </div>
</section>

@includeIf('woocommerce.partials.write-review')
