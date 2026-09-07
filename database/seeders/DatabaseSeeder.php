<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'password',
            'phone' => '0900000001',
            'role' => UserRole::Admin,
        ]);

        User::query()->create([
            'name' => 'Staff',
            'email' => 'staff@example.com',
            'password' => 'password',
            'phone' => '0900000002',
            'role' => UserRole::Staff,
        ]);

        User::query()->create([
            'name' => 'Customer',
            'email' => 'customer@example.com',
            'password' => 'password',
            'phone' => '0900000003',
            'role' => UserRole::Customer,
        ]);

        $hanoi = Warehouse::query()->create([
            'code' => 'WH-HN',
            'name' => 'Kho Ha Noi',
            'address' => 'Cau Giay, Ha Noi',
            'is_active' => true,
        ]);

        $hcm = Warehouse::query()->create([
            'code' => 'WH-HCM',
            'name' => 'Kho Ho Chi Minh',
            'address' => 'Quan 1, TP.HCM',
            'is_active' => true,
        ]);

        $products = [
            ['sku' => 'AO-001', 'name' => 'Ao thun nam', 'price' => 150000],
            ['sku' => 'QU-002', 'name' => 'Quan jean nu', 'price' => 320000],
            ['sku' => 'GI-003', 'name' => 'Giay sneaker', 'price' => 650000],
            ['sku' => 'TU-004', 'name' => 'Tui tote canvas', 'price' => 180000],
        ];

        foreach ($products as $item) {
            $product = Product::query()->create([
                ...$item,
                'description' => $item['name'].' - hang mau demo',
                'is_active' => true,
            ]);

            Inventory::query()->create([
                'warehouse_id' => $hanoi->id,
                'product_id' => $product->id,
                'quantity' => 50,
            ]);

            Inventory::query()->create([
                'warehouse_id' => $hcm->id,
                'product_id' => $product->id,
                'quantity' => 80,
            ]);
        }
    }
}
