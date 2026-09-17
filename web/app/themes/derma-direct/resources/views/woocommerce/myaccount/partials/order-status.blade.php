<section class="mt-12">
    <div class="mb-6">
        <h2 class="text-2xl font-semibold">
            Latest Order Status
        </h2>
        <p class="text-gray-600">
            Track the progress of your most recent order.
        </p>
    </div>

    @if($latestOrder)
        <div class="rounded-md border border-gray-200 bg-white p-6">
            @if($isException)
                @php
                    $exception = $exceptionStatuses[$status];
                    $colorClasses = $exceptionColors[$exception['color']] ?? $exceptionColors['gray'];
                @endphp

                <div class="flex items-center gap-4 rounded-md border p-5 {{ esc_attr($colorClasses) }}">
                    <div>
                        <h3 class="font-semibold">
                            {{ esc_html($exception['label']) }}
                        </h3>
                        <p class="mt-1 text-sm opacity-80">
                            This order requires attention or is no longer progressing through fulfilment.
                            Contact us if you have any questions.
                        </p>
                    </div>
                </div>

            @elseif($currentStepIndex >= 0)
                <div class="relative mb-8">
                    <div class="relative top-5 mx-10 h-1">
                        <div class="absolute inset-0 rounded bg-gray-200"></div>
                        <div
                            class="absolute inset-y-0 left-0 rounded bg-primary transition-all duration-300"
                            style="width: {{ esc_attr($progressPercent) }}%;"
                        ></div>
                    </div>

                    <div class="relative -mt-1 flex items-start justify-between">
                        @foreach($steps as $stepKey => $label)
                            @php
                                $stepIndex = array_search($stepKey, array_keys($steps), true);
                                $active = $stepIndex <= $currentStepIndex;
                                $completed = $stepIndex < $currentStepIndex;
                            @endphp
                            <div class="flex flex-col items-center" style="width:5rem;">
                                <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full border-4 border-white {{ $active ? 'bg-primary text-white' : 'bg-gray-200 text-gray-500' }}">
                                    @if($completed)
                                        @svg('images.check-step')
                                    @else
                                        {{ esc_html($stepIndex + 1) }}
                                    @endif

                                </div>
                                <span class="mt-2 text-center text-xs font-medium sm:text-sm {{ $active ? 'text-gray-900' : 'text-gray-400' }}">
                                    {{ esc_html($label) }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                <div class="rounded-md border border-gray-200 bg-gray-50 p-5 text-center text-sm text-gray-600">
                    Status:
                    <strong>{{ esc_html(wc_get_order_status_name($status)) }}</strong>
                </div>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-4 border-t pt-4 {{ ($isException || $currentStepIndex >= 0) ? 'mt-6' : 'mt-4' }}">
                <div>
                    <p class="font-semibold">
                        Order #{{ esc_html($latestOrder->get_order_number()) }}
                    </p>
                    <p class="text-sm text-gray-500">
                        {{ esc_html(wc_format_datetime($latestOrder->get_date_created())) }}
                    </p>
                </div>

                <div class="text-right">
                    <span class="inline-flex rounded-md px-3 py-1 text-sm font-medium {{ $isException ? ($exceptionColors[$exceptionStatuses[$status]['color']] ?? 'bg-gray-100 text-gray-700') : 'bg-green-100 text-green-700' }}">
                        {{ esc_html(wc_get_order_status_name($status)) }}
                    </span>
                    <div class="mt-2">
                        <a
                            href="{{ esc_url($latestOrder->get_view_order_url()) }}"
                            class="text-sm font-medium text-primary hover:underline"
                        >
                            View Order
                        </a>
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="rounded-md border border-dashed border-gray-300 bg-gray-50 p-8 text-center">
            <p class="text-gray-500">
                No orders found.
            </p>
        </div>
    @endif
</section>