# Order Management API

Laravel 11 API-only cho hệ thống quản lý đơn hàng thương mại điện tử.

## Chạy bằng Docker

Chỉ cần Docker Desktop, không cần cài PHP / Composer / MySQL trên máy.

```bash
cd order-management-api
copy .env.example .env
docker compose build
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
docker compose up -d
docker compose exec app php artisan migrate --seed
```

API chạy tại `http://127.0.0.1:8000/api`.

Chạy test (dùng SQLite in-memory, không cần MySQL):

```bash
docker compose run --rm app php artisan test
```

Compose gồm 3 service: `app` (PHP 8.3 CLI), `mysql` (8.0), `redis` (7) cho rate limit. Biến `DB_HOST=mysql`, `REDIS_HOST=redis` được set trong `docker-compose.yml` nên không cần sửa `.env`.

Dừng: `docker compose down` (thêm `-v` để xóa luôn dữ liệu MySQL).

## Chạy trực tiếp trên máy

Cần PHP 8.2+, Composer, MySQL 8 (hoặc MariaDB 10.4+), extension `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`.

### PHP cài qua winget

Bản PHP cài bằng `winget` **không kèm `php.ini`**, nên không extension nào được load và mọi lệnh Artisan/Composer sẽ lỗi:

```
Call to undefined function Illuminate\Support\mb_split()
```

Kiểm tra bằng `php --ini` — nếu thấy `Loaded Configuration File: (none)` thì đúng là lỗi này.

Cách sửa: tạo file `php.ini` **ngay cạnh `php.exe`** (PHP tự đọc, không cần biến môi trường). Tìm đường dẫn bằng `where.exe php`, rồi tạo file với nội dung:

```ini
extension_dir = "<thu-muc-chua-php.exe>\ext"

extension=curl
extension=fileinfo
extension=mbstring
extension=openssl
extension=pdo_mysql
extension=pdo_sqlite
extension=sqlite3
extension=zip

date.timezone = Asia/Ho_Chi_Minh
memory_limit = 512M
```

Sau đó `php artisan test` chạy được ở mọi terminal. Nếu nâng cấp PHP qua winget làm mất file này thì tạo lại là xong.

Cách tạm thời cho một session (không cần sửa gì): trỏ `PHPRC` vào một file ini bất kỳ — biến này áp dụng cho cả các sub-process mà Composer/Artisan spawn.

```powershell
$env:PHPRC = "$PWD\php.local.ini"
```

```bash
composer install
copy .env.example .env
php artisan key:generate
```

Tạo database MySQL `order_management`, rồi chỉnh `DB_*` trong `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=order_management
DB_USERNAME=root
DB_PASSWORD=
```

```bash
php artisan migrate --seed
php artisan serve
```

## Tài khoản mẫu (sau `migrate --seed`)

| Role     | Email                 | Password |
|----------|-----------------------|----------|
| admin    | admin@example.com     | password |
| staff    | staff@example.com     | password |
| customer | customer@example.com  | password |

Ngoài ra còn 7 khách hàng phụ (`bich@example.com` … `lan@example.com`), cùng mật khẩu.

Seed tạo 20 sản phẩm, 3 kho và 62 đơn hàng phủ đủ 8 trạng thái, kèm các case biên (hết hàng, kho tạm đóng, hàng tồn 3 năm). Chi tiết dataset và kịch bản test thủ công: `docs/test-data.md`.

Header xác thực: `Authorization: Bearer {token}`

## Mô hình dữ liệu

```text
users 1──* orders 1──* order_items *──1 products
                │
                ├──* payments
                ├──* order_status_history
                └──* warehouses 1──* inventories *──1 products
```

### Bảng

| Bảng | Mục đích |
|------|----------|
| `users` | Khách hàng, nhân viên, admin |
| `products` | Sản phẩm (SKU, giá) |
| `warehouses` | Kho hàng |
| `inventories` | Tồn kho theo kho + sản phẩm (`quantity`) |
| `orders` | Đơn hàng |
| `order_items` | Chi tiết đơn (snapshot tên/SKU/giá) |
| `payments` | Thanh toán của đơn |
| `order_status_history` | Lịch sử đổi trạng thái |

## Luồng nghiệp vụ

**Tồn kho**

1. Tạo đơn: khóa dòng tồn kho (`SELECT ... FOR UPDATE`), kiểm tra rồi trừ `quantity` trong 1 transaction (retry khi deadlock)
2. Hủy / hoàn tiền: cộng lại `quantity`

**Trạng thái đơn**

`pending` → `confirmed` → `processing` → `packed` → `shipped` → `delivered` → `refunded`

Có thể `cancelled` từ `pending` / `confirmed` / `processing` / `packed`.

Thanh toán đủ (`payments.status = paid` và tổng ≥ `orders.total`) sẽ tự chuyển đơn `pending` → `confirmed`.

## API

### Auth

| Method | Path | Auth |
|--------|------|------|
| POST | `/api/auth/register` | Public |
| POST | `/api/auth/login` | Public |
| GET | `/api/auth/me` | Token |
| POST | `/api/auth/logout` | Token |

### Catalog & kho

| Method | Path | Auth |
|--------|------|------|
| GET | `/api/products` | Public |
| POST / PUT / DELETE | `/api/products` | admin, staff |
| GET | `/api/warehouses` | Public |
| POST / PUT / DELETE | `/api/warehouses` | admin, staff |
| GET / POST / PUT | `/api/inventories` | admin, staff |

### Đơn hàng & thanh toán

| Method | Path | Auth |
|--------|------|------|
| GET / POST | `/api/orders` | Token (customer chỉ thấy đơn của mình) |
| GET | `/api/orders/{id}` | Token |
| POST | `/api/orders/{id}/status` | admin, staff |
| POST | `/api/orders/{id}/cancel` | Chủ đơn hoặc staff |
| GET / POST | `/api/orders/{id}/payments` | Token |
| GET | `/api/payments/{id}` | Token |
| GET | `/api/users` | admin |

### Ví dụ tạo đơn

```bash
curl -X POST http://127.0.0.1:8000/api/orders ^
  -H "Authorization: Bearer TOKEN" ^
  -H "Content-Type: application/json" ^
  -d "{\"warehouse_id\":1,\"shipping_name\":\"Nguyen Van A\",\"shipping_phone\":\"0901234567\",\"shipping_address\":\"1 Nguyen Hue, Q1\",\"items\":[{\"product_id\":1,\"quantity\":2}],\"payment\":{\"method\":\"cod\"}}"
```

`GET /api/orders` trả `{ "data": [...], "meta": { "current_page", "per_page", "total", ... } }`. Filter: `status`, `warehouse_id`, `from`, `to`, `per_page`.

Phân tích query ~5 triệu bản ghi: `docs/order-query-overload.md`.

### Ví dụ đổi trạng thái

```json
{ "status": "confirmed", "note": "Da xac nhan don" }
```

Giá trị `status`: `pending`, `confirmed`, `processing`, `packed`, `shipped`, `delivered`, `cancelled`, `refunded`.

### Ví dụ thanh toán

```json
{
  "amount": 300000,
  "method": "bank_transfer",
  "status": "paid",
  "transaction_id": "TXN-001"
}
```

`method`: `cod`, `bank_transfer`, `ewallet`, `card`  
`status`: `pending`, `paid`, `failed`, `refunded`

## Cấu trúc chính

```text
app/
  Enums/           OrderStatus, Payment*, UserRole
  Http/Controllers/Api/
  Http/Requests/
  Http/Resources/
  Models/
  Services/        OrderService, InventoryService
database/migrations/
routes/api.php
```

## Test

```bash
docker compose run --rm app php artisan test
```

Hoặc `php artisan test` nếu chạy trực tiếp trên máy. Hiện có 28 test / 118 assertions.

Mỗi lần chạy test, request/response và kết quả pass/fail của từng feature test được ghi vào `storage/logs/api-test.log` để xem API thực sự trả về gì.

- `docs/test-guide.md` — cách chạy test theo từng mục yêu cầu, kèm lệnh `--filter` và kết quả kỳ vọng.
- `docs/test-data.md` — dữ liệu mẫu và kịch bản test thủ công qua API.
- `docs/feature-and-unit-tests.md` — danh sách case chi tiết.
