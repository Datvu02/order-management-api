<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Filters\OrderFilter;
use App\Http\Requests\Order\IndexOrderRequest;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Requests\Order\UpdateOrderStatusRequest;
use App\Http\Resources\OrderCollection;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orderService) {}

    public function index(IndexOrderRequest $request): OrderCollection
    {
        $user = $request->user();
        $filters = $request->filters();

        if (! $user->isStaff()) {
            $filters['user_id'] = $user->id;
        }

        $orders = Order::query()
            ->select([
                'id',
                'order_number',
                'user_id',
                'warehouse_id',
                'status',
                'subtotal',
                'shipping_fee',
                'discount',
                'total',
                'shipping_name',
                'shipping_phone',
                'shipping_address',
                'notes',
                'created_at',
                'updated_at',
            ])
            ->with([
                'items:id,order_id,product_id,product_name,sku,quantity,unit_price,total_price',
                'warehouse:id,code,name,address,is_active,created_at,updated_at',
            ])
            ->filter(new OrderFilter($filters))
            ->latest('created_at')
            ->paginate($request->perPage());

        return new OrderCollection($orders);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->orderService->create($request->user(), $request->payload());

        return (new OrderResource($order))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        $order->load([
            'items',
            'warehouse',
            'user',
            'payments',
            'statusHistories',
        ]);

        return (new OrderResource($order))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): JsonResponse
    {
        $order = $this->orderService->changeStatus(
            $order,
            $request->enum('status', OrderStatus::class),
            $request->user(),
            $request->validated('note')
        );

        return (new OrderResource($order))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        $order = $this->orderService->cancel(
            $order,
            $request->user(),
            $request->input('note')
        );

        return (new OrderResource($order))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        $user = $request->user();

        if (! $user->isStaff() && $order->user_id !== $user->id) {
            abort(Response::HTTP_FORBIDDEN, 'Forbidden.');
        }
    }
}
