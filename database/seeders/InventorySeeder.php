<?php

namespace Database\Seeders;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    /**
     * Chỉ hai kho đang hoạt động có tồn kho. Kho WH-DN để trống vì đang tạm đóng.
     */
    public function run(): void
    {
        $hanoi = Warehouse::query()->where('code', 'WH-HN')->firstOrFail();
        $saigon = Warehouse::query()->where('code', 'WH-HCM')->firstOrFail();

        // sku => [tồn kho Hà Nội, tồn kho HCM]
        $special = [
            'HET-001' => [0, 0],
            'SAP-001' => [3, 0],
        ];

        foreach (Product::query()->orderBy('id')->get() as $product) {
            [$hn, $hcm] = $special[$product->sku] ?? [120, 80];

            Inventory::query()->create([
                'warehouse_id' => $hanoi->id,
                'product_id' => $product->id,
                'quantity' => $hn,
            ]);

            Inventory::query()->create([
                'warehouse_id' => $saigon->id,
                'product_id' => $product->id,
                'quantity' => $hcm,
            ]);
        }
    }
}
