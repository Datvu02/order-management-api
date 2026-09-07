<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class OrderService
{
    private const DEADLOCK_RETRIES = 5;

    public function __construct(private readonly InventoryService $inventoryService) {}

    /**
     * @param  array{
     *     warehouse_id:int,
     *     shipping_name:string,
     *     shipping_phone:string,
     *     shipping_address:string,
     *     shipping_fee?:numeric,
     *     discount?:numeric,
     *     notes?:string|null,
     *     items: list<array{product_id:int, quantity:int}>
     * }  $payload
     */
    public function create(User $customer, array $payload): Order
    {
        return DB::transaction(function () use ($customer, $payload) {
            $items = $this->buildItems($payload['items']);

            $this->inventoryService->deductForOrder(
                (int) $payload['warehouse_id'],
                array_map(fn (array $item) => [
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                ], $items)
            );

            $subtotal = collect($items)->sum(fn (array $item) => $item['total_price']);
            $shippingFee = (float) ($payload['shipping_fee'] ?? 0);
            $discount = (float) ($payload['discount'] ?? 0);
            $total = max(0, $subtotal + $shippingFee - $discount);

            $order = Order::query()->create([
                'order_number' => $this->generateOrderNumber(),
                'user_id' => $customer->id,
                'warehouse_id' => $payload['warehouse_id'],
                'status' => OrderStatus::Pending,
                'subtotal' => $subtotal,
                'shipping_fee' => $shippingFee,
                'discount' => $discount,
                'total' => $total,
                'shipping_name' => $payload['shipping_name'],
                'shipping_phone' => $payload['shipping_phone'],
                'shipping_address' => $payload['shipping_address'],
                'notes' => $payload['notes'] ?? null,
            ]);

            foreach ($items as $item) {
                $order->items()->create($item);
            }

            $this->recordHistory($order, null, OrderStatus::Pending, $customer, 'Order created');

            return $order->load(['items', 'warehouse', 'user', 'payments', 'statusHistories']);
        }, self::DEADLOCK_RETRIES);
    }

    public function changeStatus(Order $order, OrderStatus $next, User $actor, ?string $note = null): Order
    {
        return DB::transaction(function () use ($order, $next, $actor, $note) {
            $order = Order::query()->with('items')->lockForUpdate()->findOrFail($order->id);
            $current = $order->status;

            if (! $current->canTransitionTo($next)) {
                throw new UnprocessableEntityHttpException(
                    "Cannot change status from {$current->value} to {$next->value}."
                );
            }

            if ($next === OrderStatus::Cancelled || $next === OrderStatus::Refunded) {
                $this->restoreStock($order);
            }

            $order->update(['status' => $next]);
            $this->recordHistory($order, $current, $next, $actor, $note);

            return $order->refresh()->load(['items', 'warehouse', 'user', 'payments', 'statusHistories']);
        }, self::DEADLOCK_RETRIES);
    }

    public function cancel(Order $order, User $actor, ?string $note = null): Order
    {
        if (! $order->status->isCancellable()) {
            throw new UnprocessableEntityHttpException(
                "Order {$order->order_number} cannot be cancelled in status {$order->status->value}."
            );
        }

        return $this->changeStatus($order, OrderStatus::Cancelled, $actor, $note ?? 'Order cancelled');
    }

    public function confirmIfPaid(Order $order): void
    {
        $paid = $order->payments()
            ->where('status', PaymentStatus::Paid->value)
            ->sum('amount');

        if ($order->status === OrderStatus::Pending && (float) $paid >= (float) $order->total) {
            $this->changeStatus($order, OrderStatus::Confirmed, $order->user, 'Auto-confirmed after full payment');
        }
    }

    /**
     * @param  list<array{product_id:int, quantity:int}>  $rawItems
     * @return list<array<string, mixed>>
     */
    private function buildItems(array $rawItems): array
    {
        $grouped = collect($rawItems)
            ->groupBy('product_id')
            ->map(fn ($rows) => [
                'product_id' => (int) $rows->first()['product_id'],
                'quantity' => (int) $rows->sum('quantity'),
            ])
            ->values();

        return $grouped->map(function (array $row) {
            $product = Product::query()->where('is_active', true)->find($row['product_id']);

            if (! $product) {
                throw new UnprocessableEntityHttpException("Product #{$row['product_id']} is not available.");
            }

            $quantity = $row['quantity'];
            $unitPrice = (float) $product->price;

            return [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => round($unitPrice * $quantity, 2),
            ];
        })->all();
    }

    private function restoreStock(Order $order): void
    {
        $order->loadMissing('items');

        $this->inventoryService->restoreForOrder(
            $order->warehouse_id,
            $order->items->map(fn (OrderItem $item) => [
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
            ])->all()
        );
    }

    private function recordHistory(
        Order $order,
        ?OrderStatus $from,
        OrderStatus $to,
        ?User $actor,
        ?string $note
    ): void {
        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $actor?->id,
            'note' => $note,
        ]);
    }

    private function generateOrderNumber(): string
    {
        do {
            $number = 'ORD'.now()->format('ymdHis').Str::upper(Str::random(6));
        } while (Order::query()->where('order_number', $number)->exists());

        return $number;
    }
}
