<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class OrderSeeder extends Seeder
{
    private int $counter = 0;

    /** @var Collection<string, Product> */
    private Collection $products;

    private Warehouse $hanoi;

    private Warehouse $saigon;

    private User $staff;

    public function run(): void
    {
        $this->products = Product::query()->get()->keyBy('sku');
        $this->hanoi = Warehouse::query()->where('code', 'WH-HN')->firstOrFail();
        $this->saigon = Warehouse::query()->where('code', 'WH-HCM')->firstOrFail();
        $this->staff = User::query()->where('email', 'staff@example.com')->firstOrFail();

        $main = User::query()->where('email', 'customer@example.com')->firstOrFail();

        $this->seedMainCustomer($main);
        $this->seedStaleScenarios($main);
        $this->seedOtherCustomers();
    }

    /**
     * Khách hàng chính có nhiều đơn delivered rải trong 20 tháng để test filter theo
     * status/khoảng ngày và để có dữ liệu cho câu query ở docs/order-query-overload.md.
     */
    private function seedMainCustomer(User $customer): void
    {
        $deliveredDaysAgo = [600, 540, 480, 420, 360, 300, 240, 180, 120, 90, 60, 30];

        foreach ($deliveredDaysAgo as $index => $daysAgo) {
            $this->createOrder(
                customer: $customer,
                warehouse: $index % 2 === 0 ? $this->hanoi : $this->saigon,
                lines: [
                    ['AO-001', 1 + ($index % 3)],
                    [$index % 2 === 0 ? 'GI-001' : 'TU-002', 1],
                ],
                status: OrderStatus::Delivered,
                daysAgo: $daysAgo,
            );
        }

        $recent = [
            [OrderStatus::Pending, 1, [['AO-002', 2]]],
            [OrderStatus::Pending, 2, [['QU-001', 1], ['PK-001', 2]]],
            [OrderStatus::Pending, 4, [['GI-002', 1]]],
            [OrderStatus::Confirmed, 6, [['TU-001', 3]]],
            [OrderStatus::Confirmed, 9, [['QU-002', 1], ['PK-002', 1]]],
            [OrderStatus::Processing, 11, [['AO-003', 1]]],
            [OrderStatus::Processing, 14, [['GI-001', 2]]],
            [OrderStatus::Packed, 16, [['QU-003', 2]]],
            [OrderStatus::Shipped, 18, [['TU-002', 1]]],
            [OrderStatus::Shipped, 21, [['AO-001', 4]]],
            [OrderStatus::Cancelled, 25, [['GI-002', 1]]],
            [OrderStatus::Cancelled, 40, [['PK-002', 2]]],
            [OrderStatus::Refunded, 70, [['TU-001', 1]]],
        ];

        foreach ($recent as [$status, $daysAgo, $lines]) {
            $this->createOrder($customer, $this->hanoi, $lines, $status, $daysAgo);
        }
    }

    /**
     * Hai case quyết định tính đúng của products:deactivate-stale.
     */
    private function seedStaleScenarios(User $customer): void
    {
        // CU-004 có đơn delivered trong 2 năm -> phải giữ is_active = true.
        $this->createOrder($customer, $this->hanoi, [['CU-004', 1]], OrderStatus::Delivered, 180);

        // CU-003 chỉ có đơn đã huỷ -> vẫn bị coi là không bán được.
        $this->createOrder($customer, $this->hanoi, [['CU-003', 1]], OrderStatus::Cancelled, 90);
    }

    private function seedOtherCustomers(): void
    {
        $emails = [
            'bich@example.com', 'cuong@example.com', 'dung@example.com',
            'em@example.com', 'giang@example.com', 'hung@example.com', 'lan@example.com',
        ];

        $statuses = [
            OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Processing,
            OrderStatus::Packed, OrderStatus::Shipped, OrderStatus::Delivered,
            OrderStatus::Cancelled, OrderStatus::Refunded,
        ];

        $skus = ['AO-001', 'AO-002', 'QU-001', 'GI-001', 'TU-001', 'PK-001', 'QU-003', 'TU-002'];

        foreach ($emails as $userIndex => $email) {
            $customer = User::query()->where('email', $email)->firstOrFail();

            for ($i = 0; $i < 5; $i++) {
                $seed = $userIndex * 5 + $i;

                $this->createOrder(
                    customer: $customer,
                    warehouse: $seed % 2 === 0 ? $this->hanoi : $this->saigon,
                    lines: [[$skus[$seed % count($skus)], 1 + ($seed % 3)]],
                    status: $statuses[$seed % count($statuses)],
                    daysAgo: 3 + $seed * 7,
                );
            }
        }
    }

    /**
     * @param  list<array{0: string, 1: int}>  $lines
     */
    private function createOrder(
        User $customer,
        Warehouse $warehouse,
        array $lines,
        OrderStatus $status,
        int $daysAgo,
    ): Order {
        $date = now()->subDays($daysAgo)->setTime(9 + ($this->counter % 10), ($this->counter * 7) % 60);
        $this->counter++;

        $items = [];
        $subtotal = 0.0;

        foreach ($lines as [$sku, $quantity]) {
            $product = $this->products[$sku];
            $totalPrice = (float) $product->price * $quantity;
            $subtotal += $totalPrice;

            $items[] = [
                'product' => $product,
                'quantity' => $quantity,
                'unit_price' => (float) $product->price,
                'total_price' => $totalPrice,
            ];
        }

        $shippingFee = 30000.0;
        $total = $subtotal + $shippingFee;

        $order = new Order([
            'order_number' => 'ORD'.$date->format('ymd').str_pad((string) $this->counter, 6, '0', STR_PAD_LEFT),
            'user_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'status' => $status,
            'subtotal' => $subtotal,
            'shipping_fee' => $shippingFee,
            'discount' => 0,
            'total' => $total,
            'shipping_name' => $customer->name,
            'shipping_phone' => $customer->phone ?? '0900000000',
            'shipping_address' => $warehouse->code === 'WH-HN'
                ? 'So 12 Tran Duy Hung, Cau Giay, Ha Noi'
                : 'So 45 Le Loi, Quan 1, TP.HCM',
        ]);

        $order->created_at = $date;
        $order->updated_at = $date;
        $order->save();

        foreach ($items as $item) {
            /** @var Product $product */
            $product = $item['product'];

            $orderItem = new OrderItem([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'total_price' => $item['total_price'],
            ]);

            $orderItem->created_at = $date;
            $orderItem->updated_at = $date;
            $orderItem->save();

            if ($this->holdsStock($status)) {
                $this->deductStock($warehouse, $product, $item['quantity']);
            }
        }

        $this->createPayment($order, $status, $date);
        $this->createHistory($order, $status, $date, $customer);

        return $order;
    }

    private function holdsStock(OrderStatus $status): bool
    {
        return ! in_array($status, [OrderStatus::Cancelled, OrderStatus::Refunded], true);
    }

    private function deductStock(Warehouse $warehouse, Product $product, int $quantity): void
    {
        $inventory = Inventory::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->first();

        if ($inventory === null) {
            return;
        }

        $inventory->decrement('quantity', min($quantity, $inventory->quantity));
    }

    private function createPayment(Order $order, OrderStatus $status, Carbon $date): void
    {
        $methods = [PaymentMethod::Cod, PaymentMethod::BankTransfer, PaymentMethod::Ewallet, PaymentMethod::Card];
        $method = $methods[$this->counter % count($methods)];

        [$paymentStatus, $paidAt] = match ($status) {
            OrderStatus::Pending => [PaymentStatus::Pending, null],
            OrderStatus::Cancelled => [PaymentStatus::Failed, null],
            OrderStatus::Refunded => [PaymentStatus::Refunded, $date],
            default => [PaymentStatus::Paid, $date],
        };

        $needsTransactionId = in_array($paymentStatus, [PaymentStatus::Paid, PaymentStatus::Refunded], true);

        $payment = new Payment([
            'order_id' => $order->id,
            'amount' => $order->total,
            'method' => $method,
            'status' => $paymentStatus,
            'transaction_id' => $needsTransactionId
                ? 'TXN-'.str_pad((string) $this->counter, 6, '0', STR_PAD_LEFT)
                : null,
            'paid_at' => $paidAt,
        ]);

        $payment->created_at = $date;
        $payment->updated_at = $date;
        $payment->save();
    }

    private function createHistory(Order $order, OrderStatus $status, Carbon $date, User $customer): void
    {
        $chain = $this->statusChain($status);
        $previous = null;

        foreach ($chain as $index => $current) {
            $history = new OrderStatusHistory([
                'order_id' => $order->id,
                'from_status' => $previous,
                'to_status' => $current,
                'changed_by' => $index === 0 ? $customer->id : $this->staff->id,
                'note' => $index === 0 ? 'Khach dat hang' : 'Cap nhat trang thai',
            ]);

            $moment = $date->copy()->addHours($index);
            $history->created_at = $moment;
            $history->updated_at = $moment;
            $history->save();

            $previous = $current;
        }
    }

    /**
     * @return list<OrderStatus>
     */
    private function statusChain(OrderStatus $status): array
    {
        return match ($status) {
            OrderStatus::Pending => [OrderStatus::Pending],
            OrderStatus::Confirmed => [OrderStatus::Pending, OrderStatus::Confirmed],
            OrderStatus::Processing => [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Processing],
            OrderStatus::Packed => [
                OrderStatus::Pending, OrderStatus::Confirmed,
                OrderStatus::Processing, OrderStatus::Packed,
            ],
            OrderStatus::Shipped => [
                OrderStatus::Pending, OrderStatus::Confirmed,
                OrderStatus::Processing, OrderStatus::Packed, OrderStatus::Shipped,
            ],
            OrderStatus::Delivered => [
                OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Processing,
                OrderStatus::Packed, OrderStatus::Shipped, OrderStatus::Delivered,
            ],
            OrderStatus::Cancelled => [OrderStatus::Pending, OrderStatus::Cancelled],
            OrderStatus::Refunded => [
                OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Processing,
                OrderStatus::Packed, OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Refunded,
            ],
        };
    }
}
