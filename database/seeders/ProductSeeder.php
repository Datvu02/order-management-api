<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Mỗi nhóm sản phẩm phục vụ một tình huống test cụ thể, xem docs/test-data.md.
     */
    public function run(): void
    {
        $catalog = [
            ['AO-001', 'Ao thun nam co tron', 150000],
            ['AO-002', 'Ao so mi trang dai tay', 285000],
            ['AO-003', 'Ao khoac gio chong nuoc', 560000],
            ['QU-001', 'Quan jean nam slim fit', 420000],
            ['QU-002', 'Quan jean nu ong rong', 390000],
            ['QU-003', 'Quan short thun the thao', 160000],
            ['GI-001', 'Giay sneaker trang', 650000],
            ['GI-002', 'Giay chay bo de em', 890000],
            ['TU-001', 'Tui tote canvas', 180000],
            ['TU-002', 'Balo laptop 15 inch', 520000],
            ['PK-001', 'Non luoi trai basic', 120000],
            ['PK-002', 'Vi da nam gap doi', 340000],
        ];

        foreach ($catalog as [$sku, $name, $price]) {
            $this->make($sku, $name, $price, true);
        }

        // Tình huống tồn kho: hết hàng hoàn toàn và sắp hết (xem InventorySeeder).
        $this->make('HET-001', 'San pham het hang', 250000, true);
        $this->make('SAP-001', 'San pham sap het hang', 199000, true);

        // Đã ngừng bán sẵn: dùng để test filter is_active=false.
        $this->make('NGUNG-001', 'San pham da ngung ban', 99000, false);
        $this->make('NGUNG-002', 'San pham ngung ban lau roi', 149000, false);

        // Hàng cũ 3 năm, không có đơn nào trong 2 năm -> products:deactivate-stale phải tắt.
        $this->make('CU-001', 'Hang ton 3 nam khong ban duoc', 210000, true, 3);
        $this->make('CU-002', 'Hang ton 3 nam khong ban duoc 2', 175000, true, 3);

        // Hàng cũ nhưng đơn gần đây đều đã huỷ -> vẫn phải bị tắt (đơn cancelled không tính là bán được).
        $this->make('CU-003', 'Hang cu chi co don da huy', 230000, true, 3);

        // Hàng cũ nhưng có đơn delivered gần đây -> phải giữ nguyên is_active = true.
        $this->make('CU-004', 'Hang cu nhung van ban duoc', 265000, true, 3);
    }

    private function make(string $sku, string $name, float $price, bool $isActive, ?int $yearsOld = null): void
    {
        $product = new Product([
            'sku' => $sku,
            'name' => $name,
            'description' => $name.' - du lieu demo',
            'price' => $price,
            'is_active' => $isActive,
        ]);

        if ($yearsOld !== null) {
            $product->created_at = now()->subYears($yearsOld);
            $product->updated_at = now()->subYears($yearsOld);
        }

        $product->save();
    }
}
