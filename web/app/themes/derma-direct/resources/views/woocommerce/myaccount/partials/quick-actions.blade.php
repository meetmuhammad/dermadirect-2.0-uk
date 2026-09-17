<section class="mt-8 space-y-6">
    <div>
        <h2 class="text-2xl font-semibold">
            Quick Actions
        </h2>
        <p class="text-gray-600">
            Quickly access the most common account tasks.
        </p>
    </div>

    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
        {{-- View Orders --}}
        <a
            href="{{ esc_url(wc_get_account_endpoint_url('orders')) }}"
            class="group rounded-md border border-gray-200 bg-white p-6 hover:border-primary"
        >
            <div class="mb-5 flex h-14 w-14 items-center justify-center rounded-full bg-primary/10 text-primary">
                @svg('images.view-orders')
            </div>

            <h3 class="text-lg font-semibold group-hover:text-primary">
                View Orders
            </h3>

            <p class="mt-2 text-sm text-gray-600">
                View order history and track existing orders.
            </p>
        </a>

        {{-- Buy Again --}}
        <a
            href="#buy-again"
            class="group rounded-md border border-gray-200 bg-white p-6 hover:border-primary"
        >
            <div class="mb-5 flex h-14 w-14 items-center justify-center rounded-full bg-primary/10 text-primary">
                @svg('images.buy-again')
            </div>

            <h3 class="text-lg font-semibold group-hover:text-primary">
                Buy Again
            </h3>

            <p class="mt-2 text-sm text-gray-600">
                Reorder products you've purchased previously.
            </p>
        </a>

        {{-- Download Invoices --}}
        <a
            href="{{ esc_url(wc_get_account_endpoint_url('downloads')) }}"
            class="group rounded-md border border-gray-200 bg-white p-6 hover:border-primary"
        >
            <div class="mb-5 flex h-14 w-14 items-center justify-center rounded-full bg-primary/10 text-primary">
                @svg('images.download-invoices')
            </div>

            <h3 class="text-lg font-semibold group-hover:text-primary">
                Download Invoices
            </h3>

            <p class="mt-2 text-sm text-gray-600">
                Access invoices from your completed orders.
            </p>
        </a>

        {{-- Addresses --}}
        <a
            href="{{ esc_url(wc_get_account_endpoint_url('edit-address')) }}"
            class="group rounded-md border border-gray-200 bg-white p-6 hover:border-primary"
        >
            <div class="mb-5 flex h-14 w-14 items-center justify-center rounded-full bg-primary/10 text-primary">
                @svg('images.address')
            </div>

            <h3 class="text-lg font-semibold group-hover:text-primary">
                Manage Addresses
            </h3>

            <p class="mt-2 text-sm text-gray-600">
                Update billing and shipping addresses.
            </p>
        </a>

        {{-- Payment Methods --}}
        <a
            href="{{ esc_url(wc_get_account_endpoint_url('payment-methods')) }}"
            class="group rounded-md border border-gray-200 bg-white p-6 hover:border-primary"
        >
            <div class="mb-5 flex h-14 w-14 items-center justify-center rounded-full bg-primary/10 text-primary">
                @svg('images.payment-method')
            </div>

            <h3 class="text-lg font-semibold group-hover:text-primary">
                Payment Methods
            </h3>

            <p class="mt-2 text-sm text-gray-600">
                Manage your saved payment methods.
            </p>
        </a>

        {{-- Account Details --}}
        <a
            href="{{ esc_url(wc_get_account_endpoint_url('edit-account')) }}"
            class="group rounded-md border border-gray-200 bg-white p-6 hover:border-primary"
        >
            <div class="mb-5 flex h-14 w-14 items-center justify-center rounded-full bg-primary/10 text-primary">
                @svg('images.account-details')
            </div>

            <h3 class="text-lg font-semibold group-hover:text-primary">
                Account Details
            </h3>

            <p class="mt-2 text-sm text-gray-600">
                Update your personal information and password.
            </p>
        </a>

    </div>

</section>