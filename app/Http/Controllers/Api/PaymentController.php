<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function __construct(private readonly OrderService $orderService) {}

    public function index(Request $request, Order $order): AnonymousResourceCollection
    {
        $this->authorizeOrder($request, $order);

        return PaymentResource::collection($order->payments()->latest()->get());
    }

    public function store(StorePaymentRequest $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        $payment = DB::transaction(function () use ($request, $order) {
            $status = PaymentStatus::from($request->input('status', PaymentStatus::Pending->value));

            $payment = $order->payments()->create([
                'amount' => $request->input('amount'),
                'method' => $request->input('method'),
                'status' => $status,
                'transaction_id' => $request->input('transaction_id'),
                'notes' => $request->input('notes'),
                'paid_at' => $status === PaymentStatus::Paid ? now() : null,
            ]);

            if ($status === PaymentStatus::Paid) {
                $this->orderService->confirmIfPaid($order->fresh('payments'));
            }

            return $payment;
        });

        return (new PaymentResource($payment))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Payment $payment): PaymentResource
    {
        $this->authorizeOrder($request, $payment->order);

        return new PaymentResource($payment);
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        $user = $request->user();

        if (! $user->isStaff() && $order->user_id !== $user->id) {
            abort(403, 'Forbidden.');
        }
    }
}
