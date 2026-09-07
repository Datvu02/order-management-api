<?php

namespace App\Http\Filters;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;

class OrderFilter
{
    /**
     * @param  array{
     *     status?: string,
     *     warehouse_id?: int,
     *     user_id?: int,
     *     from?: string,
     *     to?: string
     * }  $filters
     */
    public function __construct(private readonly array $filters) {}

    public function apply(Builder $query): Builder
    {
        return $query
            ->when($this->value('status'), function (Builder $query, string $status) {
                $query->where('status', OrderStatus::from($status)->value);
            })
            ->when($this->value('warehouse_id'), function (Builder $query, int|string $warehouseId) {
                $query->where('warehouse_id', (int) $warehouseId);
            })
            ->when($this->value('user_id'), function (Builder $query, int|string $userId) {
                $query->where('user_id', (int) $userId);
            })
            ->when($this->value('from'), function (Builder $query, string $from) {
                $query->whereDate('created_at', '>=', $from);
            })
            ->when($this->value('to'), function (Builder $query, string $to) {
                $query->whereDate('created_at', '<=', $to);
            });
    }

    private function value(string $key): mixed
    {
        return $this->filters[$key] ?? null;
    }
}
