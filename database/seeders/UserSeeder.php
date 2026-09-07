<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['Admin', 'admin@example.com', '0900000001', UserRole::Admin],
            ['Staff', 'staff@example.com', '0900000002', UserRole::Staff],
            ['Customer', 'customer@example.com', '0900000003', UserRole::Customer],
        ];

        foreach ($accounts as [$name, $email, $phone, $role]) {
            User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => 'password',
                'phone' => $phone,
                'role' => $role,
            ]);
        }

        $extraCustomers = [
            ['Tran Thi Bich', 'bich@example.com', '0901110001'],
            ['Le Van Cuong', 'cuong@example.com', '0901110002'],
            ['Pham Thi Dung', 'dung@example.com', '0901110003'],
            ['Hoang Van Em', 'em@example.com', '0901110004'],
            ['Vo Thi Giang', 'giang@example.com', '0901110005'],
            ['Dang Van Hung', 'hung@example.com', '0901110006'],
            ['Bui Thi Lan', 'lan@example.com', '0901110007'],
        ];

        foreach ($extraCustomers as [$name, $email, $phone]) {
            User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => 'password',
                'phone' => $phone,
                'role' => UserRole::Customer,
            ]);
        }
    }
}
