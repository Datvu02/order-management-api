<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_sku_must_be_unique(): void
    {
        Product::factory()->create(['sku' => 'DUP-001']);

        $this->expectException(QueryException::class);

        Product::factory()->create(['sku' => 'DUP-001']);
    }

    public function test_warehouse_code_must_be_unique(): void
    {
        Warehouse::factory()->create(['code' => 'WH-DUP']);

        $this->expectException(QueryException::class);

        Warehouse::factory()->create(['code' => 'WH-DUP']);
    }

    public function test_inventory_is_unique_per_warehouse_and_product(): void
    {
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();

        Inventory::factory()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
        ]);

        $this->expectException(QueryException::class);

        Inventory::factory()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_inventory_rejects_product_that_does_not_exist(): void
    {
        $warehouse = Warehouse::factory()->create();

        $this->expectException(QueryException::class);

        Inventory::factory()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => 999999,
        ]);
    }

    public function test_soft_deleted_product_stays_in_table(): void
    {
        $product = Product::factory()->create();

        $product->delete();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertDatabaseCount('products', 1);
        $this->assertNull(Product::query()->find($product->id));
        $this->assertNotNull(Product::withTrashed()->find($product->id));
    }

    public function test_tables_needing_soft_deletes_have_deleted_at(): void
    {
        foreach (['users', 'products', 'warehouses', 'orders'] as $table) {
            $this->assertTrue(
                Schema::hasColumn($table, 'deleted_at'),
                "Bảng {$table} thiếu cột deleted_at"
            );
        }
    }

    public function test_every_table_tracks_created_and_updated_at(): void
    {
        $tables = [
            'users', 'products', 'warehouses', 'inventories',
            'orders', 'order_items', 'payments', 'order_status_history',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(
                Schema::hasColumns($table, ['created_at', 'updated_at']),
                "Bảng {$table} thiếu created_at/updated_at"
            );
        }
    }

    public function test_user_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'dup@example.com']);

        $this->expectException(QueryException::class);

        User::factory()->create(['email' => 'dup@example.com']);
    }
}
