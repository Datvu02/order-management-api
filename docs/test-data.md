# Dữ liệu mẫu để test

Dataset này được thiết kế để **mỗi mục yêu cầu đều có case kiểm chứng được**, kể cả các case biên dễ bỏ sót (hết hàng, kho tạm đóng, sản phẩm cũ chỉ có đơn đã huỷ).

Tài liệu liên quan: `docs/test-guide.md` (test tự động theo từng mục).

## Chạy seed

```powershell
php artisan migrate:fresh --seed
```

Muốn chạy lại chỉ một phần:

```powershell
php artisan db:seed --class=ProductSeeder
```

Nếu chưa có MySQL, đặt `DB_CONNECTION=sqlite` trong `.env` là chạy được ngay (Laravel tự dùng `database/database.sqlite`).

## Dataset sau khi seed

| Bảng | Số bản ghi |
|---|---|
| `users` | 10 |
| `warehouses` | 3 |
| `products` | 20 |
| `inventories` | 40 |
| `orders` | 62 |
| `order_items` | 76 |
| `payments` | 62 |
| `order_status_history` | 244 |

Đơn hàng phủ đủ 8 trạng thái: `delivered` 17, `pending` 8, `cancelled` 7, `confirmed` 7, `processing` 7, `shipped` 6, `packed` 5, `refunded` 5.

### Tài khoản

Tất cả mật khẩu là `password`.

| Email | Role | Ghi chú |
|---|---|---|
| `admin@example.com` | admin | Xem được `/api/users` |
| `staff@example.com` | staff | Quản lý sản phẩm, kho, đổi trạng thái đơn |
| `customer@example.com` | customer | **Khách chính: 27 đơn** (13 delivered, 3 pending) |
| `bich@example.com` … `lan@example.com` | customer | 7 khách phụ, 5 đơn mỗi người |

Khách chính có nhiều đơn nhất để test phân trang và filter cho ra kết quả rõ ràng.

### Kho

| Code | Tên | `is_active` | Mục đích |
|---|---|---|---|
| `WH-HN` | Kho Hà Nội | true | Kho chính, có tồn kho |
| `WH-HCM` | Kho Hồ Chí Minh | true | Test đặt hàng nhiều kho |
| `WH-DN` | Kho Đà Nẵng | **false** | Test validate từ chối kho đang tạm đóng |

`WH-DN` **không có tồn kho** — đây là chủ ý, để chứng minh validate chặn ở tầng request chứ không phải "may mắn" hết hàng.

### Sản phẩm

| Nhóm | SKU | Mục đích test |
|---|---|---|
| Bán thường | `AO-001`…`PK-002` (12 SKU) | CRUD, filter, đặt hàng bình thường |
| Hết hàng | `HET-001` | Tồn kho = 0 ở cả 2 kho → đặt hàng phải bị chặn |
| Sắp hết | `SAP-001` | HN = 3, HCM = 0 → test đặt vượt tồn kho và test concurrency |
| Đã ngừng bán | `NGUNG-001`, `NGUNG-002` | `is_active = false` → test filter `?is_active=0` |
| Hàng cũ không bán được | `CU-001`, `CU-002` | Tạo 3 năm trước, không có đơn nào → phải bị tắt |
| Hàng cũ chỉ có đơn huỷ | `CU-003` | Có đơn `cancelled` cách đây 90 ngày → **vẫn phải bị tắt** |
| Hàng cũ vẫn bán được | `CU-004` | Có đơn `delivered` cách đây 180 ngày → phải giữ `is_active = true` |

`CU-003` là case quan trọng nhất của nhóm này: nếu logic chỉ kiểm tra "có `order_items` gần đây hay không" mà không loại trạng thái `cancelled`/`refunded`, sản phẩm này sẽ bị bỏ sót.

## Kịch bản test thủ công theo từng mục

Lấy token trước:

```powershell
$login = Invoke-RestMethod -Method Post -Uri "http://127.0.0.1:8000/api/auth/login" `
  -ContentType "application/json" `
  -Body '{"email":"customer@example.com","password":"password"}'
$h = @{ Authorization = "Bearer $($login.token)" }
```

### Mục 1 + 4 — Tồn kho và đặt hàng

Đặt sản phẩm hết hàng `HET-001` (`product_id` lấy từ `/api/products?q=HET-001`):

```powershell
Invoke-RestMethod -Method Post -Uri "http://127.0.0.1:8000/api/orders" -Headers $h `
  -ContentType "application/json" -Body (@{
    warehouse_id = 1; shipping_name = "Test"; shipping_phone = "0901234567"
    shipping_address = "1 ABC"; items = @(@{ product_id = 13; quantity = 1 })
    payment = @{ method = "cod" }
  } | ConvertTo-Json)
```

Kết quả đúng: **422** với `Insufficient stock for product #13 in warehouse #1.`

Đặt vào kho đang tạm đóng (`warehouse_id = 3`) → **422** với lỗi validate `warehouse_id`, và lỗi này phải xảy ra **trước** khi chạm tới tồn kho.

Đặt `SAP-001` ở `WH-HN` số lượng 2 → thành công, tồn kho còn 1. Đặt tiếp số lượng 2 → 422.

### Mục 5 — Danh sách đơn hàng

```powershell
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/orders?status=delivered&per_page=3" -Headers $h
```

Kết quả đúng: `meta` = `{"current_page":1,"from":1,"last_page":5,"per_page":3,"to":3,"total":13}`, `data` có 3 phần tử, mỗi phần tử kèm sẵn `items` và `warehouse`.

Con số `total: 13` và `last_page: 5` là bằng chứng phân trang chạy ở tầng DB: khách chính có 27 đơn nhưng chỉ 13 đơn `delivered`, và API chỉ trả 3 dòng mỗi trang.

Các filter khác: `?status=pending` (3 đơn), `?warehouse_id=1`, `?from=2026-08-01&to=2026-09-07`.

### Mục 3 — Sản phẩm

```powershell
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/products?per_page=5"
```

`meta.total` = 20, `last_page` = 4. Lọc hàng đã ngừng bán: `?is_active=0` → `total` = 2. Tìm kiếm: `?q=AO` → các SKU `AO-*`.

### Mục 7 — Ngừng bán hàng tồn

```powershell
php artisan products:deactivate-stale
```

Kết quả đúng: **tắt đúng 3 sản phẩm** — `CU-001`, `CU-002`, `CU-003`. `CU-004` giữ nguyên `is_active = true`, và không sản phẩm bán chạy nào bị ảnh hưởng.

Chạy lại lần thứ hai phải trả về 0 (đã tắt hết rồi, không tắt trùng).

### Mục 6 — Overload và `EXPLAIN`

62 đơn thì query nào cũng nhanh, không nói lên gì. Sinh thêm dữ liệu lớn:

```powershell
$env:BULK_ORDERS = "500000"
php artisan db:seed --class=BulkOrderSeeder
```

`BulkOrderSeeder` chỉ ghi bảng `orders` (chèn theo lô 1000 dòng) vì câu query cần phân tích chỉ đọc bảng đó, nên chạy nhanh hơn nhiều so với sinh cả `order_items`/`payments`. Mặc định 50.000 nếu không set `BULK_ORDERS`.

Sau đó chạy `EXPLAIN` trên MySQL theo hướng dẫn ở `docs/test-guide.md` (mục 6). Lưu ý `EXPLAIN` trên SQLite không cho thông tin hữu ích như MySQL — phần này nên chạy bằng `docker compose up -d mysql`.

Xoá dữ liệu bulk:

```powershell
php artisan migrate:fresh --seed
```

## Lưu ý về tính nhất quán của tồn kho

`OrderSeeder` trừ tồn kho cho các đơn đang giữ hàng và **không** trừ cho đơn `cancelled`/`refunded`, đúng như logic thật của `InventoryService`. Ví dụ `AO-001` bắt đầu với 120 (HN) / 80 (HCM), sau khi seed còn 94 / 68.

Nghĩa là bạn có thể đối chiếu `sum(order_items.quantity)` với phần tồn kho đã giảm mà không thấy lệch — nếu sau này sửa logic trừ kho mà quên nhánh huỷ đơn, số liệu seed sẽ lệch ngay.
