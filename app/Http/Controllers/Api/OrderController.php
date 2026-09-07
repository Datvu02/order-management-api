<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Requests\Order\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orderService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $orders = Order::query()
            ->with(['items', 'warehouse'])
            ->when(! $user->isStaff(), fn ($q) => $q->where('user_id', $user->id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('user_id') && $user->isStaff(), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->latest()
            ->paginate(20);

        return OrderResource::collection($orders);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->orderService->create($request->user(), $request->validated());

        return (new OrderResource($order))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Order $order): OrderResource
    {
        $this->authorizeOrder($request, $order);

        return new OrderResource(
            $order->load(['items', 'warehouse', 'user', 'payments', 'statusHistories'])
        );
    }

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): OrderResource
    {
        $order = $this->orderService->changeStatus(
            $order,
            $request->enum('status', OrderStatus::class),
            $request->user(),
            $request->input('note')
        );

        return new OrderResource($order);
    }

    public function cancel(Request $request, Order $order): OrderResource
    {
        $this->authorizeOrder($request, $order);

        $order = $this->orderService->cancel(
            $order,
            $request->user(),
            $request->input('note')
        );

        return new OrderResource($order);
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        $user = $request->user();

        if (! $user->isStaff() && $order->user_id !== $user->id) {
            abort(403, 'Forbidden.');
        }
    }
}
