<?php

namespace App\View\Composers;

use Roots\Acorn\View\Composer;

class MyAccountDashboard extends Composer
{
    protected static $views = [
        'woocommerce.myaccount.dashboard',
    ];

    public function with(): array
    {
        $customerId = get_current_user_id();

        $latestOrder = $this->latestOrder($customerId);

        $buyAgainProducts = $this->buyAgainProducts($customerId);

        return array_merge([
            'customer' => wp_get_current_user(),
            'latestOrder'       => $latestOrder,
            'outstandingOrders' => $this->outstandingOrders($customerId),
            'billingAddress'    => WC()->customer->get_billing_address_1(),
            'shippingAddress'   => WC()->customer->get_shipping_address_1(),
            'tradeStatus'       => 'Standard Customer',
            'buyAgainProducts' => $buyAgainProducts,
            'visibleLimit'     => 3,
            'totalProducts'    => count($buyAgainProducts),
            'recentOrders' => $this->recentOrders($customerId),
            'notifications' => $this->notificationsData(
                $latestOrder,
                WC()->customer->get_billing_address_1()
            ),
            'recommendedProducts' => $this->recommendedProducts($customerId),

        ], $this->orderStatusData($latestOrder));
    }

    private function latestOrder(int $customerId)
    {
        $orders = wc_get_orders([
            'customer_id' => $customerId,
            'limit'       => 1,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);

        return $orders[0] ?? null;
    }

    private function recentOrders(int $customerId): array
    {
        return wc_get_orders([
            'customer_id' => $customerId,
            'limit'       => 3,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);
    }

    private function outstandingOrders(int $customerId): array
    {
        return wc_get_orders([
            'customer_id' => $customerId,
            'status' => [
                'wc-processing',
                'wc-on-hold',
                'wc-pending',
            ],
            'return' => 'ids',
        ]);
    }

    private function buyAgainProducts(int $customerId): array
    {
        $orders = wc_get_orders([
            'customer_id' => $customerId,
            'limit'       => 10,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);

        $products = [];

        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if (! $product) {
                    continue;
                }
                $productId = $product->get_id();
                if (! isset($products[$productId])) {
                    $products[$productId] = [
                        'product'       => $product,
                        'count'         => 1,
                        'last_ordered'  => $order->get_date_created(),
                    ];
                } else {
                    $products[$productId]['count']++;
                    if (
                        $order->get_date_created()->getTimestamp() >
                        $products[$productId]['last_ordered']->getTimestamp()
                    ) {
                        $products[$productId]['last_ordered'] = $order->get_date_created();
                    }
                }
            }
        }

        $favourites = get_customer_favourite_products($customerId);

        foreach ($products as $productId => &$item) {
            $item['is_favourite'] = in_array(
                $productId,
                $favourites,
                true
            );
        }

        unset($item);

        uasort($products, function ($a, $b) {

            if ($a['is_favourite'] === $b['is_favourite']) {
                return 0;
            }
            return $a['is_favourite'] ? -1 : 1;
        });

        return $products;
    }
        private function recommendedProducts(int $customerId): array
    {
        $orders = wc_get_orders([
            'customer_id' => $customerId,
            'limit'       => 10,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);

        $sourceProductId = null;

        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if ($product) {
                    $sourceProductId = $product->get_id();
                    break 2;
                }
            }
        }

        if (! $sourceProductId) {
            return [];
        }
        return array_filter(
            array_map(
                'wc_get_product',
                wc_get_related_products($sourceProductId, 4)
            )
        );
    }

    private function notificationsData($latestOrder, string $billingAddress): array
    {
        return [
            'latestOrder'          => $latestOrder,
            'showBillingReminder'  => empty($billingAddress),
        ];
    }

    private function orderStatusData($latestOrder): array
    {
        $steps = [
            'pending'     => 'Pending',
            'processing'  => 'Processing',
            'picked'      => 'Picked',
            'dispatched'  => 'Dispatched',
            'delivered'   => 'Delivered',
        ];

        $exceptionStatuses = [
            'cancelled' => ['label' => 'Cancelled', 'color' => 'red'],
            'paid-cancelled' => ['label' => 'Paid - Cancelled Order', 'color' => 'red'],
            'refunded' => ['label' => 'Refunded', 'color' => 'gray'],
            'failed' => ['label' => 'Payment Failed', 'color' => 'red'],
            'void' => ['label' => 'Void', 'color' => 'gray'],
            'it-issue' => ['label' => 'IT Issue', 'color' => 'yellow'],
            'internal-hold' => ['label' => 'Internal Hold', 'color' => 'yellow'],
            'chargeback' => ['label' => 'Chargeback', 'color' => 'red'],
        ];

        $exceptionColors = [
            'red'    => 'bg-red-50 border-red-200 text-red-700',
            'yellow' => 'bg-yellow-50 border-yellow-200 text-yellow-700',
            'gray'   => 'bg-gray-50 border-gray-200 text-gray-700',
        ];

        $status = $latestOrder?->get_status();
        $isException = isset($exceptionStatuses[$status]);

        $currentStep = match ($status) {
            'pending','pending-payment','pending-review' => 'pending',
            'processing','on-hold','first-order','international-ord','pack','pending-pick','back-order' => 'processing',
            'picked' => 'picked',
            'ship' => 'dispatched',
            'completed','delivered' => 'delivered',
            default => null,
        };

        $currentStepIndex = (!$isException && $currentStep)
            ? array_search($currentStep, array_keys($steps), true)
            : -1;

        $progressPercent = $currentStepIndex >= 0
            ? ($currentStepIndex / (count($steps) - 1)) * 100
            : 0;

        return [
            'latestOrder'       => $latestOrder,
            'steps'             => $steps,
            'status'            => $status,
            'isException'       => $isException,
            'currentStepIndex'  => $currentStepIndex,
            'progressPercent'   => $progressPercent,
            'exceptionStatuses' => $exceptionStatuses,
            'exceptionColors'   => $exceptionColors,
        ];
    }
}