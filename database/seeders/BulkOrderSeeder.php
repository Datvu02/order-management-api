<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Sinh nhiều bản ghi `orders` để kiểm chứng index bằng EXPLAIN (mục 6 - overload).
 *
 * Không chạy trong DatabaseSeeder. Gọi riêng:
 *   php artisan db:seed --class=BulkOrderSeeder
 *
 * Số lượng đặt qua env BULK_ORDERS (mặc định 50000). Seeder này chỉ ghi bảng `orders`
 * vì câu query cần phân tích chỉ đọc bảng đó — không sinh order_items/payments.
 */
class BulkOrderSeeder extends Seeder
{
    private const CHUNK = 1000;

    public function run(): void
    {
        $target = (int) env('BULK_ORDERS', 50000);

        $userIds = User::query()->pluck('id')->all();
        $warehouseIds = Warehouse::query()->where('is_active', true)->pluck('id')->all();

        if ($userIds === [] || $warehouseIds === []) {
            $this->command?->error('Chua co user/warehouse. Chay "php artisan db:seed" truoc.');

            return;
        }

        $statuses = array_map(fn (OrderStatus $s) => $s->value, OrderStatus::cases());
        $offset = (int) DB::table('orders')->count();

        $this->command?->info("Dang chen {$target} don hang...");
        $bar = $this->command?->getOutput()->createProgressBar($target);

        for ($start = 0; $start < $target; $start += self::CHUNK) {
            $rows = [];
            $size = min(self::CHUNK, $target - $start);

            for ($i = 0; $i < $size; $i++) {
                $n = $offset + $start + $i;
                $subtotal = 100000 + ($n % 40) * 25000;
                $createdAt = now()->subDays($n % 1095)->subMinutes($n % 1440);

                $rows[] = [
                    'order_number' => 'BULK'.str_pad((string) $n, 10, '0', STR_PAD_LEFT),
                    'user_id' => $userIds[$n % count($userIds)],
                    'warehouse_id' => $warehouseIds[$n % count($warehouseIds)],
                    'status' => $statuses[$n % count($statuses)],
                    'subtotal' => $subtotal,
                    'shipping_fee' => 30000,
                    'discount' => 0,
                    'total' => $subtotal + 30000,
                    'shipping_name' => 'Khach hang '.$n,
                    'shipping_phone' => '09'.str_pad((string) ($n % 100000000), 8, '0', STR_PAD_LEFT),
                    'shipping_address' => 'Dia chi giao hang so '.$n,
                    'notes' => null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                    'deleted_at' => null,
                ];
            }

            DB::table('orders')->insert($rows);
            $bar?->advance($size);
        }

        $bar?->finish();
        $this->command?->newLine(2);
        $this->command?->info('Tong don hang hien tai: '.DB::table('orders')->count());
    }
}
