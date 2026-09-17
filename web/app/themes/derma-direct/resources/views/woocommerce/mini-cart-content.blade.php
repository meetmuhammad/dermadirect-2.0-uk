<div class="cart-items-section h-[400px] overflow-auto">
    @if (!empty($cart_items ?? []))
        <ul class="cart-items flex flex-col gap-6">
            @foreach ($cart_items as $item)
                @continue (empty($item['id'] ?? ''))
                <li class="cart-item py-5 px-3 flex flex-col gap-6 border border-grey-outline rounded-lg" data-product-id="{{ $item['id'] }}">
                    <div class="flex flex-wrap sm:flex-nowrap gap-6 justify-start items-center">
                        <div class="min-w-[250px] flex gap-6 items-center text-black text-sm sm:text-base font-quicksand font-semibold">
                            @unless (empty($item['thumbnail_image'] ?? ''))
                            <div class="bg-theme-light-500 rounded-lg">
                                {!! wp_get_attachment_image($item['thumbnail_image'], 'full', false, [
                                    'class' => 'size-[60px] sm:size-[90px] lg:size-[120px] aspect-square object-contain',
                                    'alt' => get_post_meta($item['thumbnail_image'], '_wp_attachment_image_alt', true),
                                ]) !!}
                            </div>
                            @endunless

                            @unless (empty($item['name'] ?? ''))
                                <div class="w-full max-sm:max-w-80 sm:w-80">{{ $item['name'] }}</div>
                            @endunless
                        </div>

                        <div class="min-w-[92px] w-[92px] h-[40px] border rounded-lg bg-white flex items-center gap-2 px-3 [&_button]:cursor-pointer">
                            <button class="mini-cart-quantity-minus text-[#1E293B99]" data-product-id="{!! esc_attr($item['id']) !!}" aria-label="Product quantity minus">-</button>
                            <input
                                class="product-total-quantity mini-cart-quantity w-full !text-center text-theme-dark-900 placeholder:text-theme-dark-900 outline-0"
                                type="number"
                                data-product-id="{!! esc_attr($item['id']) !!}"
                                value="{{ $item['quantity'] }}"
                                min="1"
                                max="50"
                                inputmode="numeric"
                                aria-label="Product quantity input"
                            >
                            <button class="mini-cart-quantity-plus text-[#1E293B99]" data-product-id="{!! esc_attr($item['id']) !!}" aria-label="Product quantity plus">+</button>
                        </div>

                        <div class="w-full sm:w-[190px] flex items-center justify-between gap-2 ml-auto">
                            <div class="flex sm:flex-col gap-1 w-20 font-quicksand text-sm sm:text-base font-semibold product-total-sale-price whitespace-nowrap text-black">
                                @if ($item['is_discounted'] ?? false)
                                    <del class="text-secondary-grey product-total-regular-price">{!! $item['regular_price_total'] ?? 0 !!}</del>
                                    <div class="text-secondary-black">{!! $item['price_for_customers_total'] ?? 0 !!}</div>
                                @else
                                    <div class="text-theme-dark-900">{!! $item['price_for_customers_total'] ?? 0 !!}</div>
                                @endif
                            </div>
                            <button
                                class="min-w-[44px] w-[44px] h-[44px] border border-theme-dark-900/20 hover:bg-white rounded-[14px] flex items-center justify-center delete-product-item cursor-pointer"
                                data-product-id="{{ $item['id'] }}"
                                aria-label="Delete product from cart button"
                            >
                                {!! get_svg('images.delete', 'w-5 h-5') !!}
                            </button>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @else
        <div class="bg-theme-light-500 p-5 rounded-[8px] flex flex-wrap sm:flex-nowrap gap-6 justify-between items-center">
            <div class="min-w-[220px] flex gap-6 items-center text-theme-dark-900 text-sm sm:text-base font-quicksand">
                {!! __('Your cart is empty', 'sage') !!}
            </div>
        </div>
    @endif

    <p class="mini-cart-update-message text-theme-green text-xs"></p>
</div>

<div class="cart-details-section flex flex-col gap-4 pt-4 mt-auto">
    <div class="flex justify-between items-center font-quicksand text-sm">
        <div>{!! __('Subtotal', 'sage') !!}</div>
        <div class="cart-subtotal-price text-secondary-grey">{!! $cart_subtotal_price ?? 0 !!}</div>
    </div>

    <div class="flex justify-between items-center font-quicksand text-sm">
        <div>{!! __('Tax', 'sage') !!}</div>
        <div class="text-secondary-grey">{!! __('Calculated at checkout', 'sage') !!}</div>
    </div>
    <div class="flex justify-between items-center font-quicksand text-sm">
        <div>{!! __('Shipping', 'sage') !!}</div>
        <div class="text-secondary-grey">{!! __('Calculated at checkout', 'sage') !!}</div>
    </div>

    <div class="flex justify-between items-center font-quicksand text-sm">
        <div>{!! __('Promo code', 'sage') !!}</div>
        <button class="text-secondary-grey add-promo-code cursor-pointer">
            {!! __('Add promo code', 'sage') !!} <i class="ri-arrow-right-line"></i>
        </button>
    </div>

    <div class="flex gap-6 items-center text-black font-quicksand text-sm promo-enter hidden">
        <input class="w-full h-[48px] 2xl:h-[54px] border rounded-xl px-6 outline-0 coupon-code-input" type="text">
        <button class="w-[87px] h-[48px] 2xl:h-[54px] bg-primary text-white px-6 rounded-md apply-coupon-code">
            {!! __('Add', 'sage') !!}
        </button>
    </div>
    <div class="flex justify-between items-center text-[12px] text-primary success-coupon-msg hidden"></div>

    @unless (empty($applied_coupons ?? []))
        <div class="applied-coupons-list">
            @foreach(($applied_coupons ?? []) as $coupon)
                <div class="remove-coupon-div flex justify-between bg-grey-outline rounded-lg p-3" data-coupon="{{ $coupon['code'] }}">
                    <span class="coupon-name text-secondary-black [&_.amount]:font-black">
                        {!! __('Coupon Applied', 'sage') . ': ' . $coupon['code'] !!} - {!! $coupon['amount'] . ' discount applied' !!}
                    </span>
                    <button class="remove-coupon text-sm cursor-pointer text-red-500">Remove</button>
                </div>
            @endforeach
        </div>
    @endunless

    <div class="flex justify-between items-center text-[12px] text-primary-red failed-coupon-error hidden"></div>

    <div class="flex justify-between items-center border-t pt-6 text-black font-quicksand text-sm">
        <div>{!! __('Total', 'sage') !!}</div>
        <div class="cart-total-price text-3xl xl:text-4xl text-black font-poppins font-bold">{!! $cart_total_price ?? 0 !!}</div>
    </div>

    <a href="/checkout" class="flex items-center justify-center w-full h-[50px] bg-primary rounded-xl text-white font-quicksand text-sm proceed-to-checkout !cursor-pointer">
        {!! __('Proceed to Checkout', 'sage') !!}
    </a>
    <div class="flex justify-center items-center font-quicksand text-sm">
        <a href="/shop" class="continue-shopping flex gap-2 items-center">
            @svg('images.arrow', 'w-3 h-3 [&_path]:fill-black rotate-180') {!! __('Continue Shopping', 'sage') !!}
        </a>
    </div>
</div>
