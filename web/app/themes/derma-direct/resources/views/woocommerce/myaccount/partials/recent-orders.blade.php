<section class="mt-12">
    <div class="mb-6">
        <h2 class="text-2xl font-semibold">
            Recent Orders
        </h2>
        <p class="mt-1 text-gray-600">
            View your latest purchases and quickly reorder products.
        </p>
    </div>

    @if(empty($recentOrders))
        <div class="rounded-md border border-dashed border-gray-300 bg-gray-50 p-8 text-center">
            <p class="text-gray-500">
                You haven't placed any orders yet.
            </p>
        </div>
    @else
        <div class="space-y-4">
            @foreach($recentOrders as $order)
                <div class="flex flex-col gap-6 rounded-md border border-gray-200 bg-white p-6 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h3 class="text-lg font-semibold">
                            Order #{{ esc_html($order->get_order_number()) }}
                        </h3>
                        <div class="mt-2 flex flex-wrap gap-4 text-sm text-gray-600">
                            <span class="inline-flex items-center gap-1.5">
                                @svg('images.calendar')
                                {{ esc_html(wc_format_datetime($order->get_date_created())) }}
                            </span>

                            <span class="inline-flex items-center gap-1.5">
                                @svg('images.package-status')
                                {{ esc_html(wc_get_order_status_name($order->get_status())) }}
                            </span>

                            <span class="inline-flex items-center gap-1.5">
                                @svg('images.currency-pound')
                                {!! $order->get_formatted_order_total() !!}
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <a
                            href="{{ esc_url($order->get_view_order_url()) }}"
                            class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium transition hover:bg-gray-100"
                        >
                            View Order
                        </a>

                        @if($order->has_status(['completed', 'processing', 'delivered']))
                        @php
                        $url = wp_nonce_url(
                            add_query_arg(['order_again' => $order->get_id()], wc_get_cart_url()),
                            'woocommerce-order_again',
                            '_wpnonce'
                        );
                        @endphp
                        <!-- esc_url is not used here because of double amp; added when url generated with nounce -->
                        <!-- so this format is used to escape the URL correctly and touch the hook correctly for re-ordering -->
                        <a class = "inline-flex rounded-md bg-primary px-4 py-2 text-sm font-medium text-white" href="{!! $url !!}">
                            Reorder
                        </a>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</section>
