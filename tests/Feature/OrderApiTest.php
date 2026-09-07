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
        $customer = User::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create(['price' => 100000]);

        Inventory::factory()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 10,
        ]);

        $response = $this->actingAs($customer)->postJson('/api/orders', [
            'warehouse_id' => $warehouse->id,
            'shipping_name' => 'Nguyen Van A',
            'shipping_phone' => '0901234567',
            'shipping_address' => '1 Nguyen Hue, Q1',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', OrderStatus::Pending->value)
            ->assertJsonPath('data.total', '200000.00');

        $this->assertDatabaseHas('inventories', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 8,
        ]);
    }

    public function test_cannot_create_order_when_stock_is_insufficient(): void
    {
        $customer = User::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();

        Inventory::factory()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $this->actingAs($customer)
            ->postJson('/api/orders', [
                'warehouse_id' => $warehouse->id,
                'shipping_name' => 'Nguyen Van A',
                'shipping_phone' => '0901234567',
                'shipping_address' => '1 Nguyen Hue, Q1',
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 5],
                ],
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('inventories', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 1,
        ]);
    }

    public function test_second_order_fails_when_first_order_took_remaining_stock(): void
    {
        $customer = User::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create(['price' => 10000]);

        Inventory::factory()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 10,
        ]);

        $payload = [
            'warehouse_id' => $warehouse->id,
            'shipping_name' => 'Nguyen Van A',
            'shipping_phone' => '0901234567',
            'shipping_address' => '1 Nguyen Hue, Q1',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 7],
            ],
        ];

        $this->actingAs($customer)->postJson('/api/orders', $payload)->assertCreated();

        $payload['items'][0]['quantity'] = 4;

        $this->actingAs($customer)
            ->postJson('/api/orders', $payload)
            ->assertStatus(422);

        $this->assertDatabaseHas('inventories', [
            'product_id' => $product->id,
            'quantity' => 3,
        ]);
    }

    public function test_cancel_restores_stock(): void
    {
        $customer = User::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create(['price' => 10000]);

        Inventory::factory()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 5,
        ]);

        $orderId = $this->actingAs($customer)
            ->postJson('/api/orders', [
                'warehouse_id' => $warehouse->id,
                'shipping_name' => 'Nguyen Van A',
                'shipping_phone' => '0901234567',
                'shipping_address' => '1 Nguyen Hue, Q1',
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 2],
                ],
            ])
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

    public function test_paid_payment_auto_confirms_pending_order(): void
    {
        $customer = User::factory()->create();
        $staff = User::factory()->staff()->create();
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create(['price' => 50000]);

        Inventory::factory()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 5,
        ]);

        $orderId = $this->actingAs($customer)
            ->postJson('/api/orders', [
                'warehouse_id' => $warehouse->id,
                'shipping_name' => 'Nguyen Van A',
                'shipping_phone' => '0901234567',
                'shipping_address' => '1 Nguyen Hue, Q1',
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ])
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
}
