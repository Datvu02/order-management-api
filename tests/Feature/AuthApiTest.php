<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_register_and_login(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'phone' => '0901111222',
        ])->assertCreated()->assertJsonPath('data.role', 'customer');

        $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ])->assertOk()->assertJsonStructure(['token', 'data' => ['id', 'email']]);
    }

    public function test_customer_cannot_create_product(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)
            ->postJson('/api/products', [
                'sku' => 'X-001',
                'name' => 'Blocked',
                'price' => 1000,
            ])
            ->assertForbidden();
    }
}
