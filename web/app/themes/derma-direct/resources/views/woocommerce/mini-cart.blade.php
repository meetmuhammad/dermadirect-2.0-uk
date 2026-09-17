{{--
    NOTE: This template file will be used for both the minicart (which comes from the side) and for the
    cart page.
--}}
<div class="minicart-overlay fixed inset-0 bg-black/80 backdrop-blur-sm z-[98] opacity-0 invisible transition-opacity duration-300"></div>

<div id="minicart_slide" class="close fixed top-0 w-[95%] sm:w-[600px] bg-white p-6 z-[99]">

    <div class="mini-cart-header flex items-center justify-between mb-6">
        <h4 class="font-quicksand font-semibold text-black text-xl md:text-2l xl:text-3xl flex items-center gap-2">
            @svg('images.shopping-cart', 'size-5 md:size-6 [&_path]:fill-secondary-black')
            <span>{!! __('Order summary', 'sage') !!}</span>
        </h4>
        <button id="close_minicart_slide" class="cursor-pointer" aria-label="Close cart">@svg('images.close', 'size-5')</button>
    </div>

    <div class="w-full h-[calc(100vh_-_100px)] overflow-auto">
        {{-- This is the fragment container --}}
        <div id="minicart_content" class="flex flex-col h-full">
            @include('woocommerce.mini-cart-content', [
                'cart_items' => $cart_items ?? [],
                'cart_subtotal_price' => $cart_subtotal_price ?? 0,
                'cart_total_price' => $cart_total_price ?? 0,
            ])
        </div>
    </div>
</div>
