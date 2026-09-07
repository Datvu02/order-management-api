<?php

namespace App\Http\Requests\Order;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where(function ($query) {
                    $query->where('is_active', true)->whereNull('deleted_at');
                }),
            ],
            'shipping_name' => ['required', 'string', 'max:255'],
            'shipping_phone' => ['required', 'string', 'max:20'],
            'shipping_address' => ['required', 'string', 'max:1000'],
            'shipping_fee' => ['sometimes', 'numeric', 'min:0'],
            'discount' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('products', 'id')->where(function ($query) {
                    $query->where('is_active', true)->whereNull('deleted_at');
                }),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'payment' => ['required', 'array'],
            'payment.method' => ['required', Rule::enum(PaymentMethod::class)],
            'payment.transaction_id' => ['nullable', 'string', 'max:100'],
            'payment.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function payload(): array
    {
        return $this->safe()->only([
            'warehouse_id',
            'shipping_name',
            'shipping_phone',
            'shipping_address',
            'shipping_fee',
            'discount',
            'notes',
            'items',
            'payment',
        ]);
    }
}
