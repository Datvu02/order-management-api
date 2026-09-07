# Truy vấn đơn hàng trên ~5 triệu bản ghi

## Câu lệnh đang xét

```php
Order::where('user_id', $userId)
    ->where('status', 'completed')
    ->orderBy('created_at', 'desc')
    ->get();
```

Trong hệ thống này trạng thái hoàn tất đơn là `delivered` (không có `completed`). Vấn đề hiệu năng bên dưới không đổi dù tên status khác.

## Vì sao quá tải

`get()` kéo **toàn bộ** dòng khớp điều kiện vào RAM PHP. Với bảng `orders` ~5 triệu dòng:

- Optimizer vẫn phải lọc trên index rồi trả về mọi đơn `completed` của user. Một khách hàng cũ có thể có hàng nghìn đến hàng chục nghìn đơn.
- Mỗi model Eloquent giữ attributes, casts, relations placeholder → tốn RAM và CPU hơn nhiều so với một mảng thô.
- `orderBy('created_at', 'desc')` trên tập lớn sẽ filesort nếu không có index đúng thứ tự cột.
- Không phân trang → latency tăng tuyến tính theo số đơn của user, dễ timeout và làm đầy bộ nhớ worker.
- Nếu sau đó gọi `$order->items` / `$order->warehouse` mà không `with()`, phát sinh N+1 (thêm hàng nghìn query).

Index `(user_id, status)` **không đủ** cho câu này. MySQL có thể dùng prefix `(user_id, status)` rồi sort `created_at` trong memory/disk. Index phù hợp là `(user_id, status, created_at)`.

## Đề xuất

1. **Không dùng `get()` cho danh sách.** Dùng `paginate()` hoặc cursor pagination (`orderBy('id', 'desc')->cursorPaginate()`) để chỉ đọc một trang (ví dụ 20 dòng). API `GET /api/orders` đã làm theo hướng này.
2. **Index đúng query:** `(user_id, status, created_at)`. Migration `0001_01_01_000008_add_orders_user_status_created_at_index` bổ sung index này.
3. **Chỉ select cột cần** trên list; eager load `items`, `warehouse` bằng `with()` và cột cụ thể — không load `statusHistories` trên list.
4. **Filter có cấu trúc** (`status`, `warehouse_id`, `from`, `to`) để thu hẹp range trước khi sort.
5. Khi cần “toàn bộ lịch sử” (export): chạy job queue, đọc theo chunk/`lazy()`, ghi file — không trả JSON một phát.
6. Quy mô lớn hơn: read replica cho list, partition `orders` theo `created_at`, cache trang đầu theo `user_id + status`.

## Câu nên dùng (minh họa)

```php
Order::query()
    ->select(['id', 'order_number', 'user_id', 'warehouse_id', 'status', 'total', 'created_at'])
    ->with(['items:id,order_id,product_id,quantity,total_price', 'warehouse:id,code,name'])
    ->where('user_id', $userId)
    ->where('status', 'delivered')
    ->orderByDesc('created_at')
    ->paginate(20);
```
