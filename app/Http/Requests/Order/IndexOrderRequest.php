<?php

namespace App\Http\Requests\Order;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(OrderStatus::class)],
            'warehouse_id' => ['sometimes', 'integer', 'exists:warehouses,id'],
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function filters(): array
    {
        return $this->safe()->only([
            'status',
            'warehouse_id',
            'user_id',
            'from',
            'to',
        ]);
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 20);
    }
}
