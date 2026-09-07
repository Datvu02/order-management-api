<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
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
}
