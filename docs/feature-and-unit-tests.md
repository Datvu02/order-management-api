# Feature test và Unit test

Chạy:

```bash
php artisan test
php artisan test --testsuite=Feature
php artisan test --testsuite=Unit
```

| Loại | Thư mục | Mục đích |
|------|---------|----------|
| Feature | `tests/Feature` | HTTP API, DB, transaction, event |
| Unit | `tests/Unit` | Enum, filter, service thuần, không cần HTTP |

File hiện có: `ProductApiTest`, `OrderApiTest`, `AuthApiTest`, `OrderStatusTest`.

---

## 1. Product CRUD + validation

**Feature** — `tests/Feature/ProductApiTest.php`

| Case | Method / URL | Expect |
|------|----------------|--------|
| List | `GET /api/products` | 200, `data` + `meta` |
| Show | `GET /api/products/{id}` | 200 |
| Create (staff) | `POST /api/products` | 201, không dùng `$request->all()` |
| Validate | `POST` thiếu sku/name, `price < 0` | 422 |
| Update | `PUT /api/products/{id}` | 200 |
| Delete | `DELETE /api/products/{id}` | 204, soft delete |
| Forbidden | customer `POST /api/products` | 403 |

```php
public function test_guest_can_list_products_with_meta(): void
{
    Product::factory()->count(2)->create();

    $this->getJson('/api/products')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'sku', 'name', 'price', 'is_active']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
}

public function test_store_product_validates_payload(): void
{
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff)
        ->postJson('/api/products', ['sku' => '', 'name' => '', 'price' => -1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sku', 'name', 'price']);
}
```

**Unit** — unique SKU, `is_active` boolean: để FormRequest / Feature 422 là đủ.

---

## 2. Đặt hàng `POST /api/orders`

Bắt buộc: validate, warehouse, product, tồn kho, tạo `orders` + `order_items` + `payments` + `order_status_history`, trừ `inventories`, transaction, concurrency.

**Feature** — `tests/Feature/OrderApiTest.php`

```php
public function test_customer_can_create_order_and_deduct_stock(): void
{
    [$customer, $warehouse, $product] = $this->prepareCatalog(quantity: 10, price: 100000);

    $response = $this->actingAs($customer)
        ->postJson('/api/orders', $this->orderPayload($warehouse, $product, 2));

    $response->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.total', '200000.00');

    $this->assertDatabaseHas('inventories', [
        'product_id' => $product->id,
        'quantity' => 8,
    ]);
    $this->assertDatabaseHas('order_items', [
        'product_id' => $product->id,
        'quantity' => 2,
    ]);
    $this->assertDatabaseHas('payments', [
        'order_id' => $response->json('data.id'),
        'amount' => 200000,
    ]);
    $this->assertDatabaseHas('order_status_history', [
        'order_id' => $response->json('data.id'),
        'to_status' => 'pending',
    ]);
}

public function test_cannot_create_order_when_stock_is_insufficient(): void
{
    [$customer, $warehouse, $product] = $this->prepareCatalog(quantity: 1);

    $this->actingAs($customer)
        ->postJson('/api/orders', $this->orderPayload($warehouse, $product, 5))
        ->assertUnprocessable();

    $this->assertDatabaseHas('inventories', ['quantity' => 1]);
    $this->assertDatabaseCount('orders', 0);
}

public function test_second_order_fails_when_first_took_remaining_stock(): void
{
    [$customer, $warehouse, $product] = $this->prepareCatalog(quantity: 10, price: 10000);

    $this->actingAs($customer)
        ->postJson('/api/orders', $this->orderPayload($warehouse, $product, 7))
        ->assertCreated();

    $this->actingAs($customer)
        ->postJson('/api/orders', $this->orderPayload($warehouse, $product, 4))
        ->assertUnprocessable();

    $this->assertDatabaseHas('inventories', ['quantity' => 3]);
}
```

Thêm case nên có:

| Case | Expect |
|------|--------|
| `warehouse_id` inactive / không tồn tại | 422 |
| `product_id` inactive | 422 |
| Thiếu `payment.method` | 422 |
| Hủy đơn | tồn kho cộng lại |

**Unit** — `InventoryService`: khóa `product_id` tăng dần, `WHERE quantity >= ?` rồi trừ.

```php
namespace Tests\Unit;

use App\Services\InventoryService;
use PHPUnit\Framework\TestCase;

class InventoryLockOrderTest extends TestCase
{
    public function test_items_are_normalized_and_sorted_by_product_id(): void
    {
        $service = new InventoryService;
        $method = new \ReflectionMethod(InventoryService::class, 'normalizeItems');
        $method->setAccessible(true);

        $rows = $method->invoke($service, [
            ['product_id' => 5, 'quantity' => 1],
            ['product_id' => 2, 'quantity' => 3],
            ['product_id' => 2, 'quantity' => 1],
        ]);

        $this->assertSame([2, 5], $rows->pluck('product_id')->all());
        $this->assertSame(4, $rows->firstWhere('product_id', 2)['quantity']);
    }
}
```

---

## 3. Danh sách đơn `GET /api/orders`

Expect: `data` + `meta`, paginate (không `get()`), eager load, filter, không N+1.

**Feature**

```php
public function test_order_list_returns_paginated_data_and_meta(): void
{
    [$customer, $warehouse, $product] = $this->prepareCatalog(quantity: 20, price: 10000);

    $this->actingAs($customer)->postJson('/api/orders', $this->orderPayload($warehouse, $product, 1));
    $this->actingAs($customer)->postJson('/api/orders', $this->orderPayload($warehouse, $product, 1));

    $this->actingAs($customer)
        ->getJson('/api/orders?per_page=1&status=pending')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonStructure([
            'data' => [['id', 'order_number', 'status', 'items', 'warehouse']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
}
```

N+1: `Model::preventLazyLoading` bật khi không phải production. List phải `with(['items', 'warehouse'])`. Test fail nếu resource truy cập relation chưa load.

**Unit** — `OrderFilter`

```php
namespace Tests\Unit;

use App\Http\Filters\OrderFilter;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_filter_applies_status_and_warehouse(): void
    {
        $sql = Order::query()
            ->filter(new OrderFilter([
                'status' => 'pending',
                'warehouse_id' => 3,
            ]))
            ->toSql();

        $this->assertStringContainsString('status', $sql);
        $this->assertStringContainsString('warehouse_id', $sql);
    }
}
```

Không viết test load 5 triệu row. Query overload: `docs/order-query-overload.md`. Index `(user_id, status, created_at)` kiểm tra bằng migration, không bằng `get()` trên dataset lớn.

---

## 4. Sản phẩm không bán 2 năm

Hai đường: job tự động + API/PUT tay.

**Feature** — `ProductApiTest`

```php
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
    // tạo product cũ + order mới → command không tắt
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
```

**Unit** — scope `staleSince`: `is_active`, `created_at <= cutoff`, không có order (trừ cancelled/refunded) trong 2 năm.

---

## 5. Redis rate limit

phpunit dùng `CACHE_STORE=array` (không cần Redis). Limiter `orders` = 10 `POST /api/orders` / phút / user.

**Feature**

```php
public function test_order_endpoint_is_rate_limited(): void
{
    [$customer, $warehouse, $product] = $this->prepareCatalog(quantity: 100, price: 1000);

    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($customer)
            ->postJson('/api/orders', $this->orderPayload($warehouse, $product, 1))
            ->assertCreated();
    }

    $this->actingAs($customer)
        ->postJson('/api/orders', $this->orderPayload($warehouse, $product, 1))
        ->assertStatus(429);
}
```

Test này tốn tồn kho; có thể `RateLimiter::clear('orders')` / tách class. Production: `CACHE_STORE=redis` — xem `docs/rate-limit-and-order-events.md`.

---

## 6. Event `OrderCreated` + notification

Event **sau** commit. Listener `SendOrderCreatedNotification` → `OrderCreatedNotification` (mail).

**Feature**

```php
use App\Notifications\OrderCreatedNotification;
use Illuminate\Support\Facades\Notification;

public function test_creating_order_notifies_the_customer(): void
{
    Notification::fake();

    [$customer, $warehouse, $product] = $this->prepareCatalog(quantity: 5, price: 10000);

    $this->actingAs($customer)
        ->postJson('/api/orders', $this->orderPayload($warehouse, $product, 1))
        ->assertCreated();

    Notification::assertSentTo($customer, OrderCreatedNotification::class);
}

public function test_failed_order_does_not_notify(): void
{
    Notification::fake();

    [$customer, $warehouse, $product] = $this->prepareCatalog(quantity: 1);

    $this->actingAs($customer)
        ->postJson('/api/orders', $this->orderPayload($warehouse, $product, 9))
        ->assertUnprocessable();

    Notification::assertNothingSent();
}
```

**Unit** — `OrderCreatedNotification::via()` trả `['mail']`.

```php
public function test_notification_uses_mail_channel(): void
{
    $order = new Order(['order_number' => 'ORD1', 'total' => 1000]);
    $notification = new OrderCreatedNotification($order);

    $this->assertSame(['mail'], $notification->via(new User));
}
```

---

## 7. Auth (phụ)

`POST /api/auth/register` 201, `POST /api/auth/login` 200 + `token`. Customer không `POST /api/products` (403).

---

## Checklist map với code

| Chức năng | Feature | Unit |
|-----------|---------|------|
| Product CRUD | `ProductApiTest` | — |
| Đặt hàng + trừ kho | `OrderApiTest` | `InventoryLockOrderTest` (nên thêm) |
| List đơn + meta | `OrderApiTest` | `OrderFilterTest` (nên thêm) |
| Hết hàng / concurrency tuần tự | `OrderApiTest` | — |
| Stale 2 năm + PUT inactive | `ProductApiTest` | scope `staleSince` |
| Rate limit 429 | nên thêm | — |
| OrderCreated notify | `OrderApiTest` | `via()` mail |
| Query 5 triệu row | không load full table | — |

`test_failed_order_does_not_notify` và rate limit 429 chưa có trong repo; nên bổ sung khi viết tiếp test.
