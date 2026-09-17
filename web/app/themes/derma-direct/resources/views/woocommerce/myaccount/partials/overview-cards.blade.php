<div class="space-y-8">
    <div>
        <h1 class="text-3xl font-semibold">
            Welcome back, {{ esc_html($customer->display_name) }}
        </h1>
        <p class="mt-2 text-gray-600">
            Here's a quick overview of your account.
        </p>
    </div>

    <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-4">
        {{-- Latest Order --}}
        <div class="rounded-md border border-gray-200 bg-white p-6">

            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                Latest Order
            </p>
            @if($latestOrder)
                <h3 class="mt-3 text-2xl font-bold">
                    #{{ esc_html($latestOrder->get_order_number()) }}
                </h3>
                <p class="mt-2 text-sm text-gray-600">
                    {{ esc_html(wc_format_datetime($latestOrder->get_date_created())) }}
                </p>

                <p class="mt-1 text-sm font-medium">
                    {{ esc_html(wc_get_order_status_name($latestOrder->get_status())) }}
                </p>

                <a
                    href="{{ esc_url($latestOrder->get_view_order_url()) }}"
                    class="mt-4 inline-flex items-center text-sm font-bold text-primary hover:underline"
                >
                    <span>View Order</span>
                    @svg('images.arrow-order')
                </a>

            @else
                <p class="mt-3 text-sm text-gray-500">
                    No orders yet.
                </p>

            @endif
        </div>

        {{-- Outstanding Orders --}}
        <div class="rounded-md border border-gray-200 bg-white p-6">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                Outstanding Orders
            </p>

            <h3 class="mt-3 text-2xl font-bold">
                {{ esc_html(count($outstandingOrders)) }}
            </h3>

            <p class="mt-2 text-sm text-gray-600">
                Processing / Pending / On Hold
            </p>

            <a
                href="{{ esc_url(wc_get_account_endpoint_url('orders')) }}"
                class="mt-4 inline-flex items-center text-sm font-bold text-primary hover:underline"
            >
                <span>View Orders</span>
                @svg('images.arrow-order')
            </a>
        </div>

        {{-- Saved Addresses --}}
        <div class="rounded-md border border-gray-200 bg-white p-6">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                Saved Addresses
            </p>
            <div class="mt-4 space-y-2 text-sm">
                <div>
                    Billing:
                    <span class="font-medium">
                        {{ $billingAddress ? 'Added' : 'Not Added' }}
                    </span>
                </div>

                <div>
                    Shipping:
                    <span class="font-medium">
                        {{ $shippingAddress ? 'Added' : 'Not Added' }}
                    </span>
                </div>
            </div>

            <a
                href="{{ esc_url(wc_get_account_endpoint_url('edit-address')) }}"
                class="mt-4 inline-flex items-center text-sm font-bold text-primary hover:underline"
            >
                <span>Manage Addresses</span>

                @svg('images.arrow-order')
            </a>

        </div>

        {{-- Trade Account --}}
        <div class="rounded-md border border-gray-200 bg-white p-6">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                Trade Account
            </p>
            <h3 class="mt-3 text-xl font-bold">
                {{ esc_html($tradeStatus) }}
            </h3>
            <p class="mt-2 text-sm text-gray-600">
                Contact us if you wish to upgrade your trade account.
            </p>
        </div>
    </div>
</div>