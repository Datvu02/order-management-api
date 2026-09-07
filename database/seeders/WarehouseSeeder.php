<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        $warehouses = [
            ['WH-HN', 'Kho Ha Noi', 'So 1 Cau Giay, Ha Noi', true],
            ['WH-HCM', 'Kho Ho Chi Minh', 'So 10 Nguyen Hue, Quan 1, TP.HCM', true],
            ['WH-DN', 'Kho Da Nang (tam dong)', 'So 5 Bach Dang, Da Nang', false],
        ];

        foreach ($warehouses as [$code, $name, $address, $isActive]) {
            Warehouse::query()->create([
                'code' => $code,
                'name' => $name,
                'address' => $address,
                'is_active' => $isActive,
            ]);
        }
    }
}
