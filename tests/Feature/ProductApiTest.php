<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_list_products_with_meta(): void
    {
        Product::factory()->count(2)->create();

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    ['id', 'sku', 'name', 'price', 'is_active'],
                ],
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            ]);
    }

    public function test_staff_can_create_update_and_delete_product(): void
    {
        $staff = User::factory()->staff()->create();

        $created = $this->actingAs($staff)->postJson('/api/products', [
            'sku' => 'AO-100',
            'name' => 'Ao thun',
            'description' => 'Hang demo',
            'price' => 150000,
            'is_active' => true,
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.sku', 'AO-100');

        $id = $created->json('data.id');

        $this->actingAs($staff)
            ->getJson("/api/products/{$id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Ao thun');

        $this->actingAs($staff)
            ->putJson("/api/products/{$id}", [
                'name' => 'Ao thun nam',
                'price' => 160000,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Ao thun nam');

        $this->actingAs($staff)
            ->deleteJson("/api/products/{$id}")
            ->assertNoContent();

        $this->assertSoftDeleted('products', ['id' => $id]);
    }

    public function test_store_product_validates_payload(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->postJson('/api/products', [
                'sku' => '',
                'name' => '',
                'price' => -1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku', 'name', 'price']);
    }

    public function test_stale_unsold_products_are_deactivated_automatically(): void
    {
        $stale = Product::factory()->create([
            'is_active' => true,
            'created_at' => now()->subYears(3),
            'updated_at' => now()->subYears(3),
        ]);
        $tooNew = Product::factory()->create(['is_active' => true]);

        $this->artisan('products:deactivate-stale')->assertSuccessful();

        $this->assertFalse($stale->refresh()->is_active);
        $this->assertTrue($tooNew->refresh()->is_active);
    }

    public function test_product_sold_within_two_years_stays_active(): void
    {
        $customer = User::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $sold = Product::factory()->create([
            'is_active' => true,
            'created_at' => now()->subYears(3),
            'updated_at' => now()->subYears(3),
        ]);

        Inventory::factory()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $sold->id,
            'quantity' => 5,
        ]);

        $this->actingAs($customer)->postJson('/api/orders', [
            'warehouse_id' => $warehouse->id,
            'shipping_name' => 'Nguyen Van A',
            'shipping_phone' => '0901234567',
            'shipping_address' => '1 Nguyen Hue, Q1',
            'items' => [
                ['product_id' => $sold->id, 'quantity' => 1],
            ],
            'payment' => [
                'method' => PaymentMethod::Cod->value,
            ],
        ])->assertCreated();

        $this->artisan('products:deactivate-stale')->assertSuccessful();

        $this->assertTrue($sold->refresh()->is_active);
    }

    public function test_staff_can_set_recent_product_inactive_manually(): void
    {
        $staff = User::factory()->staff()->create();
        $product = Product::factory()->create(['is_active' => true]);

        $this->actingAs($staff)
            ->putJson("/api/products/{$product->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_staff_can_run_stale_deactivation_via_api(): void
    {
        $staff = User::factory()->staff()->create();
        Product::factory()->create([
            'is_active' => true,
            'created_at' => now()->subYears(3),
            'updated_at' => now()->subYears(3),
        ]);

        $this->actingAs($staff)
            ->postJson('/api/products/deactivate-stale')
            ->assertOk()
            ->assertJsonPath('data.deactivated', 1);
    }
}
