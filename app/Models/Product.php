<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'sku',
        'name',
        'description',
        'price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function scopeStaleSince(Builder $query, Carbon $cutoff): Builder
    {
        $ignoredStatuses = [
            OrderStatus::Cancelled->value,
            OrderStatus::Refunded->value,
        ];

        return $query
            ->where('is_active', true)
            ->where('created_at', '<=', $cutoff)
            ->whereDoesntHave('orderItems', function (Builder $items) use ($cutoff, $ignoredStatuses) {
                $items->whereHas('order', function (Builder $order) use ($cutoff, $ignoredStatuses) {
                    $order
                        ->where('created_at', '>=', $cutoff)
                        ->whereNotIn('status', $ignoredStatuses);
                });
            });
    }
}
