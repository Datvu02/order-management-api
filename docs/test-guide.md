# Hướng dẫn test theo từng mục yêu cầu

Tài liệu này map **từng mục yêu cầu → test chứng minh nó → lệnh chạy → kết quả kỳ vọng**, kèm phần nói rõ test nào *không* chứng minh được gì để tránh kết luận sai.

Tổng: **28 test, 118 assertions**, tất cả pass.

## Chuẩn bị

Test dùng SQLite in-memory, không cần MySQL hay Redis. `phpunit.xml` đã set sẵn `APP_KEY`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `CACHE_STORE=array`, `MAIL_MAILER=array`.

Mọi lệnh dưới đây chạy nguyên văn. Nếu dùng Docker thì thay `php artisan` bằng `docker compose run --rm app php artisan`.

Nếu gặp lỗi `Call to undefined function Illuminate\Support\mb_split()` (hoặc thiếu `pdo_sqlite`), xem phần "PHP cài qua winget" trong `README.md` — nguyên nhân là PHP chưa load `php.ini` nên không có extension nào.

Chạy toàn bộ:

```powershell
php artisan test
```

## Xem response và kết quả của từng test

Output mặc định của PHPUnit chỉ cho biết pass/fail, không cho thấy API thực sự trả về gì. Vì vậy mọi lời gọi API trong feature test được ghi lại vào `storage/logs/api-test.log`.

File này **tự ghi mỗi lần chạy test**, không cần thêm cờ gì:

```powershell
php artisan test
```

Mỗi test là một block: trạng thái, các request đã gọi kèm payload, và response trả về:

```
====================================================================================================
PASSED  Tests\Feature\OrderApiTest::test_paid_payment_auto_confirms_pending_order
----------------------------------------------------------------------------------------------------
[1] POST /api/orders  ->  201 Created
    request:
      {
          "warehouse_id": 1,
          "items": [ { "product_id": 1, "quantity": 1 } ],
          "payment": { "method": "cod" }
      }
    response:
      {
          "data": {
              "order_number": "ORD2609072217552OC29E",
              "status": "pending",
              "total": "50000.00",
              ...
          }
      }
```

Khi một test fail, block ghi luôn lý do fail **cùng với response thực tế đã gây ra nó** — đây là điểm hữu ích nhất khi debug:

```
====================================================================================================
FAILED  Tests\Feature\TempFailingTest::test_intentionally_failing_call
----------------------------------------------------------------------------------------------------
reason: Expected response status code [500] but received 200. Failed asserting that 200 is identical to 500.
----------------------------------------------------------------------------------------------------
[1] GET /api/products  ->  200 OK
    response:
      {
          "data": [],
          "meta": { "current_page": 1, "per_page": 20, "total": 0 }
      }
```

Cơ chế: `Tests\TestCase` override `json()` — mọi helper `getJson`/`postJson`/`putJson`/`deleteJson` đều đi qua hàm này nên chỉ cần hook một chỗ. Lời gọi `$this->artisan(...)` cũng được ghi thành một bước. Kết quả pass/fail được ghi bằng `onNotSuccessfulTest()`, và block chỉ được đẩy ra file khi test tiếp theo bắt đầu — vì PHPUnit gọi `tearDown()` **trước** `onNotSuccessfulTest()` nên không thể biết pass/fail ngay lúc test vừa kết thúc.

Log chứa 26 block, tương ứng 26 feature test. Hai unit test trong `OrderStatusTest` không xuất hiện vì chúng kế thừa `PHPUnit\Framework\TestCase` trực tiếp và không gọi HTTP.

Muốn xem danh sách kết quả dạng gọn:

```powershell
php artisan test --testdox
```

Lọc nhanh trạng thái trong log:

```powershell
Select-String -Path storage\logs\api-test.log -Pattern "^(PASSED|FAILED)"
```

File log là UTF-8. Nếu mở bằng `Get-Content` trên PowerShell 5.1, ký tự tiếng Việt sẽ hiển thị sai do console codepage — bản thân file không lỗi. Dùng editor, hoặc đọc kèm encoding:

```powershell
Get-Content storage\logs\api-test.log -Encoding UTF8
```

---



## Mục 1 — Kiểm tra và trừ tồn kho, xử lý nhiều request đồng thời

```powershell
php artisan test --filter "stock"
```

Kỳ vọng: `4 passed (18 assertions)`.


| Test                                                       | Chứng minh                                                        |
| ---------------------------------------------------------- | ----------------------------------------------------------------- |
| `customer_can_create_order_and_deduct_stock`               | Tồn kho 10, đặt 2 → DB còn đúng 8                                 |
| `cannot_create_order_when_stock_is_insufficient`           | Tồn kho 1, đặt 5 → 422, tồn kho vẫn 1, `orders` = 0 bản ghi       |
| `second_order_fails_when_first_order_took_remaining_stock` | Đơn 1 lấy 7/10, đơn 2 đòi 4 → bị chặn, tồn kho còn 3 chứ không âm |
| `cancel_restores_stock`                                    | Huỷ đơn → hoàn kho về 5                                           |


Test thứ hai là bằng chứng cho **transaction rollback**: nếu thiếu transaction thì `order` đã được tạo trước khi trừ kho thất bại, và assert `orders` = 0 sẽ fail.

Test thứ ba là bằng chứng cho điều kiện `WHERE quantity >= ?` trong `InventoryService`: tổng số lượng bán ra không bao giờ vượt tồn kho ban đầu.

**Không chứng minh được:** hai request *thật sự chạy song song*. PHPUnit chạy tuần tự và SQLite không có row-level lock, nên `SELECT ... FOR UPDATE` và cơ chế retry deadlock chưa được kiểm chứng ở đây. Xem mục "Kiểm chứng concurrency thật" ở cuối.

Logic chuyển trạng thái (unit test, không cần DB):

```powershell
php artisan test --filter "OrderStatusTest"
```



## Mục 2 — Migration: quan hệ, foreign key, index, unique, kiểu dữ liệu, timestamps, soft delete

```powershell
php artisan test --filter "DatabaseSchemaTest"
```

Kỳ vọng: `8 passed (21 assertions)`.


| Test                                            | Chứng minh                                                                                         |
| ----------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| `product_sku_must_be_unique`                    | Unique key trên `products.sku` (insert trùng → `QueryException`)                                   |
| `warehouse_code_must_be_unique`                 | Unique key trên `warehouses.code`                                                                  |
| `user_email_must_be_unique`                     | Unique key trên `users.email`                                                                      |
| `inventory_is_unique_per_warehouse_and_product` | Unique composite `(warehouse_id, product_id)` — chặn một sản phẩm có 2 dòng tồn kho trong cùng kho |
| `inventory_rejects_product_that_does_not_exist` | Foreign key thực sự có hiệu lực, không chỉ là cột số                                               |
| `soft_deleted_product_stays_in_table`           | Xoá mềm: bản ghi còn trong bảng, query thường không thấy, `withTrashed()` thấy                     |
| `tables_needing_soft_deletes_have_deleted_at`   | `users`, `products`, `warehouses`, `orders` đều có `deleted_at`                                    |
| `every_table_tracks_created_and_updated_at`     | Cả 8 bảng đều có `created_at` + `updated_at`                                                       |


Unique composite của `inventories` là ràng buộc quan trọng nhất ở đây: nó là tiền đề để logic trừ kho đúng, vì nếu một sản phẩm có 2 dòng tồn kho trong cùng kho thì lock dòng nào cũng sai.

**Không chứng minh được:** index *phi-unique* (ví dụ `(user_id, status, created_at)`) — index chỉ ảnh hưởng tốc độ nên không có hành vi nào để assert. Muốn kiểm tra thì dùng `EXPLAIN` ở mục 6. Kiểu dữ liệu `decimal(12,2)` cũng không được assert trực tiếp, nhưng test mục 4 assert `total` = `'200000.00'` nên gián tiếp xác nhận scale 2.

## Mục 3 — CRUD + validation cho `api/products`

```powershell
php artisan test --filter "create_update_and_delete_product|validates_payload|cannot_create_product|list_products_with_meta"
```

Kỳ vọng: `4 passed (28 assertions)`.


| Test                                         | Chứng minh                                                                          |
| -------------------------------------------- | ----------------------------------------------------------------------------------- |
| `staff_can_create_update_and_delete_product` | POST → 201, GET → 200, PUT → 200, DELETE → 204, và xoá là soft delete               |
| `store_product_validates_payload`            | Form Request chặn `sku` rỗng, `name` rỗng, `price` âm → 422 kèm `errors` đúng field |
| `guest_can_list_products_with_meta`          | GET công khai → 200, response có `data` + `meta`                                    |
| `customer_cannot_create_product`             | Phân quyền: customer POST → 403                                                     |


HTTP status code là phần được assert chặt nhất: 201 cho create, 204 (no content) cho delete, 422 cho validation, 403 cho sai quyền.

**Không chứng minh được:** yêu cầu "không dùng `->all()` tuỳ tiện". Đây là ràng buộc về cách viết code, không phải hành vi runtime — `StoreProductRequest::payload()` và `UpdateProductRequest::payload()` chỉ trả về đúng các field cho phép, cần review code chứ không test được.

## Mục 4 — API đặt hàng `POST api/orders`

```powershell
php artisan test --filter "OrderApiTest"
```

Kỳ vọng: `9 passed (47 assertions)`.

Ngoài 4 test tồn kho ở mục 1, nhóm này chứng minh:


| Test                                         | Chứng minh                                                                                 |
| -------------------------------------------- | ------------------------------------------------------------------------------------------ |
| `customer_can_create_order_and_deduct_stock` | Tạo đủ 4 bảng trong 1 request: `orders`, `order_items`, `payments`, `order_status_history` |
| `paid_payment_auto_confirms_pending_order`   | Payment `paid` → đơn tự chuyển `pending` → `confirmed`, có ghi lịch sử                     |
| `order_created_event_is_dispatched`          | Event `OrderCreated` được bắn sau khi commit                                               |
| `creating_order_notifies_the_customer`       | Listener chạy và gửi `OrderCreatedNotification`                                            |


Test đầu tiên assert cả 4 `assertDatabaseHas` trong một request, nên nếu thiếu bất kỳ bảng nào trong luồng tạo đơn thì fail ngay.

Validate đầu vào (thiếu `warehouse_id`, `items` rỗng, sản phẩm/kho không tồn tại hoặc `is_active = false`) do `StoreOrderRequest` xử lý; nhánh "hết hàng → 422" đã có test ở mục 1.

**Không chứng minh được:** nhánh ghi log khi lỗi nghiêm trọng. `OrderService` có `Log::error` trong `catch`, nhưng chưa có test nào giả lập exception hạ tầng (mất kết nối DB) để assert `Log::shouldReceive('error')`. Đây là gap còn lại nếu bạn muốn coverage đầy đủ.

## Mục 5 — API danh sách đơn hàng

```powershell
php artisan test --filter "order_list_returns_paginated"
```

Kỳ vọng: `1 passed (20 assertions)`.

Một test nhưng gánh 20 assertions, chứng minh cả 5 yêu cầu của mục này:

- `data` **+** `meta`: assert `meta` có đủ `current_page`, `from`, `last_page`, `per_page`, `to`, `total`.
- **Phân trang thật**: tạo 2 đơn, gọi `?per_page=1` → `data` chỉ 1 phần tử nhưng `meta.total` = 2. Nếu code load hết rồi cắt trong PHP thì `total` vẫn đúng nhưng `assertJsonCount(1, 'data')` sẽ fail nếu phân trang không hoạt động.
- **Filter có cấu trúc**: `?status=pending` đi qua `IndexOrderRequest` + `OrderFilter`.
- **Eager loading / không N+1**: đây là phần đáng chú ý nhất. `AppServiceProvider` bật `Model::preventLazyLoading(! isProduction())`, nên trong môi trường test **mọi lazy load sẽ throw** `LazyLoadingViolationException`. Test này assert `data[].items` và `data[].warehouse` có trong response — nếu controller quên `with()`, test fail bằng exception chứ không âm thầm chạy chậm.
- **Không load cả dataset**: hệ quả của `paginate()` ở trên.

Chính test này là test đã phát hiện bug double-wrapping (`data` trả về object `{data, meta}` thay vì array), nên nó có giá trị thật chứ không chỉ để cho đẹp.

## Mục 6 — Tình huống overload 5 triệu record

Mục này **không kiểm chứng bằng PHPUnit**. Lý do: index không thay đổi hành vi, chỉ thay đổi tốc độ; và SQLite in-memory với vài bản ghi thì mọi query đều nhanh, nên một test "pass" ở đây sẽ không nói lên điều gì.

Cách kiểm chứng đúng là đọc kế hoạch thực thi trên MySQL có dữ liệu thật:

```powershell
docker compose up -d mysql
docker compose exec app php artisan migrate
```

```sql
EXPLAIN SELECT id, order_number, status, total, created_at
FROM orders
WHERE user_id = 1 AND status = 'delivered'
ORDER BY created_at DESC
LIMIT 20;
```

Cần thấy trong output:

- `key` = `orders_user_id_status_created_at_index` (index từ migration `0001_01_01_000008`), không phải `NULL` hay full scan.
- `rows` nhỏ (cỡ số dòng của trang), không phải hàng triệu.
- Cột `Extra` **không** có `Using filesort` — vì `created_at` là cột cuối trong index nên MySQL đọc sẵn theo thứ tự.

Phân tích chi tiết và các bước mở rộng (read replica, partition, cache trang đầu) ở `docs/order-query-overload.md`.

## Mục 7 — Tự động ngừng bán sản phẩm không bán được 2 năm

```powershell
php artisan test --filter "stale|inactive_manually|sold_within_two_years"
```

Kỳ vọng: `4 passed (10 assertions)`.


| Test                                                  | Chứng minh                                                                      |
| ----------------------------------------------------- | ------------------------------------------------------------------------------- |
| `stale_unsold_products_are_deactivated_automatically` | Sản phẩm 3 năm không bán → `is_active = false`; sản phẩm mới → vẫn `true`       |
| `product_sold_within_two_years_stays_active`          | Sản phẩm cũ 3 năm **nhưng có đơn gần đây** → không bị tắt                       |
| `staff_can_set_recent_product_inactive_manually`      | Tắt tay vẫn hoạt động, không bị batch ghi đè                                    |
| `staff_can_run_stale_deactivation_via_api`            | Chạy thủ công qua `POST /api/products/deactivate-stale`, trả về số lượng đã tắt |


Test thứ hai là test quan trọng nhất của mục này: nó phân biệt "sản phẩm cũ" với "sản phẩm không bán được". Nếu logic chỉ nhìn `created_at` mà không join `order_items` thì test này fail.

Chạy trực tiếp command:

```powershell
php artisan products:deactivate-stale
```

Giải thích cơ chế batch và scheduler ở `docs/rate-limit-and-order-events.md`.

## Mục 8 — Rate limit Redis + event đặt hàng

```powershell
php artisan test --filter "rate_limited|notifies_the_customer|event_is_dispatched"
```

Kỳ vọng: `3 passed (7 assertions)`.


| Test                                   | Chứng minh                                                                            |
| -------------------------------------- | ------------------------------------------------------------------------------------- |
| `order_creation_is_rate_limited`       | Hạ `order_rate_limit` xuống 2, gọi 3 lần → lần thứ 3 trả 429                          |
| `order_created_event_is_dispatched`    | `Event::fake()` + `assertDispatched(OrderCreated::class)`                             |
| `creating_order_notifies_the_customer` | `Notification::fake()` + `assertSentTo(...)` — chứng minh listener có đăng ký và chạy |


Hai test event/notification tách riêng có lý do: nếu chỉ test notification mà nó fail, bạn không biết là event không bắn hay listener không đăng ký. Tách ra thì lỗi chỉ đúng một chỗ.

**Không chứng minh được:** rate limit chạy trên **Redis**. Test dùng `CACHE_STORE=array` nên chỉ xác nhận logic throttle đúng, không xác nhận backend Redis. Muốn kiểm chứng thật:

```powershell
docker compose up -d redis
```

rồi set `CACHE_STORE=redis` trong `.env` và gọi API vượt hạn mức.

---



## Kiểm chứng concurrency thật (ngoài PHPUnit)

Phần chống trừ kho trùng khi có request đồng thời là điểm mà test hiện tại **chưa** phủ được, vì SQLite không có row-level lock. Muốn kiểm chứng, cần MySQL và request song song thật:

```powershell
docker compose up -d mysql
docker compose exec app php artisan migrate --seed
docker compose up -d app
```

Đặt tồn kho một sản phẩm = 10, rồi bắn 20 request đặt 1 sản phẩm cùng lúc:

```powershell
1..20 | ForEach-Object -Parallel {
  Invoke-RestMethod -Method Post -Uri "http://127.0.0.1:8000/api/orders" `
    -Headers @{ Authorization = "Bearer $using:token" } `
    -ContentType "application/json" -Body $using:body
} -ThrottleLimit 20
```

Kết quả đúng: **đúng 10 đơn thành công, 10 đơn bị từ chối**, và `inventories.quantity` = 0 — không âm, không có đơn nào "lọt" quá tồn kho. Nhớ tạm nâng `ORDER_RATE_LIMIT` trong `.env` trước khi chạy, nếu không rate limit sẽ chặn trước và bạn sẽ nhận 429 thay vì kiểm tra được lock.

## Tổng kết coverage


| Mục yêu cầu              | Số test     | Trạng thái                                    |
| ------------------------ | ----------- | --------------------------------------------- |
| 1. Tồn kho + concurrency | 4 (+2 unit) | Tồn kho đầy đủ; concurrency thật cần MySQL    |
| 2. Migration             | 8           | Đầy đủ (trừ index phi-unique, dùng `EXPLAIN`) |
| 3. CRUD + validation     | 4           | Đầy đủ                                        |
| 4. API đặt hàng          | 9           | Thiếu test nhánh ghi log lỗi nghiêm trọng     |
| 5. Danh sách đơn hàng    | 1           | Đầy đủ cả 5 yêu cầu con                       |
| 6. Overload              | 0           | Kiểm chứng bằng `EXPLAIN`, không bằng PHPUnit |
| 7. Stale products        | 4           | Đầy đủ                                        |
| 8. Rate limit + event    | 3           | Logic đầy đủ; backend Redis cần kiểm riêng    |


