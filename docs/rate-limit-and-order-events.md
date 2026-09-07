# Redis rate limit và OrderCreated notification

## 1. Redis rate limit

Laravel `RateLimiter` **không nói chuyện trực tiếp với Redis**. Nó ghi counter vào **cache store**. Muốn rate limit chạy trên Redis thì `CACHE_STORE=redis`.

### Vì sao Redis

- Atomic `INCR` + TTL: nhiều request đồng thời không vượt quota vì race trên file cache.
- Dùng được khi API chạy nhiều worker / nhiều server (file cache mỗi máy một bộ đếm).
- TTL hết hạn đúng cửa sổ 60 giây.

### Cấu hình

`.env`:

```env
CACHE_STORE=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
API_RATE_LIMIT=60
ORDER_RATE_LIMIT=10
```

Cần extension `phpredis` hoặc package `predis/predis`. Store Redis nằm trong `config/cache.php` (`stores.redis`).

Limiter khai báo tại `AppServiceProvider`:

- `api`: 60 request/phút theo `user id` (đã login) hoặc IP (guest). Gắn sẵn trên nhóm route `api` (`throttle:api`).
- `orders`: 10 `POST /api/orders` / phút theo user. Gắn `throttle:orders` riêng vì đặt hàng tốn tồn kho, dễ bị spam.

Vượt hạn mức: HTTP **429**, header `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`.

Test/local không Redis: giữ `CACHE_STORE=array|file`. phpunit đang dùng `array`.

## 2. Event OrderCreated + Listener gửi notification

Luồng:

1. `OrderService::create()` commit transaction (order, items, payment, history, trừ kho).
2. `OrderCreated::dispatch($order)` **sau** commit. Rollback thì không gửi mail.
3. `SendOrderCreatedNotification` nhận event, `$order->user->notify(new OrderCreatedNotification($order))`.
4. Notification channel `mail` (local: `MAIL_MAILER=log` ghi `storage/logs`).

Listener và notification implement `ShouldQueue`: production đặt `QUEUE_CONNECTION=redis|database` + `php artisan queue:work` để HTTP không chờ SMTP.

File:

- `app/Events/OrderCreated.php`
- `app/Listeners/SendOrderCreatedNotification.php` (đăng ký trong `AppServiceProvider`)
- `app/Notifications/OrderCreatedNotification.php`

Không bắn event trong transaction để tránh “đơn không lưu nhưng user đã nhận mail”.
