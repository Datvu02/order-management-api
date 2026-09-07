<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_create_order_and_deduct_stock(): void
    {
        [$customer, $warehouse, $product] = $this->prepareCatalog(quantity: 10, price: 100000);

        $response = $this->actingAs($customer)->postJson('/api/orders', $this->orderPayload($warehouse, $product, 2));

        $response->assertCreated()
            ->assertJsonPath('data.status', OrderStatus::Pending->value)
            ->assertJsonPath('data.total', '200000.00')
            ->assertJsonPath('data.payments.0.method', PaymentMethod::Cod->value)
            ->assertJsonPath('data.payments.0.status', PaymentStatus::Pending->value);

        $orderId = $response->json('data.id');

        $this->assertDatabaseHas('inventories', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 8,
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('payments', [
            'order_id' => $orderId,
            'amount' => 200000,
        ]);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $orderId,
            'to_status' => OrderStatus::Pending->value,
        ]);
    }

    public function test_cannot_create_order_when_stock_is_insufficient(): void
    {
        [$customer, $warehouse, $product] = $this->prepareCatalog(quantity: 1);

        $this->actingAs($customer)
            ->postJson('/api/orders', $this->orderPayload($warehouse, $product, 5))
            ->assertUnprocessable();

        $this->assertDatabaseHas('inventories', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 1,
        ]);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_second_order_fails_when_first_order_took_remaining_stock(): void
    {
        [$customer, $warehouse, $product] = $this->prepareCatalog(quantity: 10, price: 10000);

        $this->actingAs($customer)
            ->postJson('/api/orders', $this->orderPayload($warehouse, $product, 7))
            ->assertCreated();

        $this->actingAs($customer)
            ->postJson('/api/orders', $this->orderPayload($warehouse, $product, 4))
            ->assertUnprocessable();

        $this->assertDatabaseHas('inventories', [
            'product_id' => $product->id,
            'quantity' => 3,
        ]);
    }

    public function test_cancel_restores_stock(): void
    {
        [$customer, $warehouse, $product] = $this->prepareCatalog(quantity: 5, price: 10000);

        $orderId = $this->actingAs($customer)
            ->postJson('/api/orders', $this->orderPayload($warehouse, $product, 2))
            ->json('data.id');

        $this->actingAs($customer)
            ->postJson("/api/orders/{$orderId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value);

        $this->assertDatabaseHas('inventories', [
            'product_id' => $product->id,
            'quantity' => 5,
        ]);
    }

    public function test_order_list_returns_paginated_data_and_meta_without_n_plus_one(): void
    {
        [$customer, $warehouse, $product] = $this->prepareCatalog(quantity: 20, price: 10000);

        $this->actingAs($customer)->postJson('/api/orders', $this->orderPayload($warehouse, $product, 1))->assertCreated();
        $this->actingAs($customer)->postJson('/api/orders', $this->orderPayload($warehouse, $product, 1))->assertCreated();

        $this->actingAs($customer)
            ->getJson('/api/orders?per_page=1&status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonStructure([
                'data' => [
                    ['id', 'order_number', 'status', 'items', 'warehouse'],
                ],
                'meta' => ['current_page', 'from', 'last_page', 'per_page', 'to', 'total'],
            ]);
    }

    public function test_paid_payment_auto_confirms_pending_order(): void
    {
        [$customer, $warehouse, $product] = $this->prepareCatalog(quantity: 5, price: 50000);
        $staff = User::factory()->staff()->create();

        $orderId = $this->actingAs($customer)
            ->postJson('/api/orders', $this->orderPayload($warehouse, $product, 1))
            ->json('data.id');

        $this->actingAs($staff)
            ->postJson("/api/orders/{$orderId}/payments", [
                'amount' => 50000,
                'method' => PaymentMethod::BankTransfer->value,
                'status' => PaymentStatus::Paid->value,
                'transaction_id' => 'TXN-001',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => OrderStatus::Confirmed->value,
        ]);
    }

    /**
     * @return array{0: User, 1: Warehouse, 2: Product}
     */
    private function prepareCatalog(int $quantity, float $price = 10000): array
    {
        $customer = User::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create(['price' => $price]);

        Inventory::factory()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
        ]);

        return [$customer, $warehouse, $product];
    }

    private function orderPayload(Warehouse $warehouse, Product $product, int $quantity): array
    {
        return [
            'warehouse_id' => $warehouse->id,
            'shipping_name' => 'Nguyen Van A',
            'shipping_phone' => '0901234567',
            'shipping_address' => '1 Nguyen Hue, Q1',
            'items' => [
                ['product_id' => $product->id, 'quantity' => $quantity],
            ],
            'payment' => [
                'method' => PaymentMethod::Cod->value,
            ],
        ];
    }
}
