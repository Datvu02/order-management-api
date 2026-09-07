<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Product;
use Illuminate\Support\Carbon;

class ProductService
{
    public function deactivateStale(int $years = 2): int
    {
        $cutoff = Carbon::now()->subYears($years);
        $deactivated = 0;

        Product::query()
            ->staleSince($cutoff)
            ->orderBy('id')
            ->chunkById(200, function ($products) use (&$deactivated) {
                $ids = $products->pluck('id');

                $deactivated += Product::query()
                    ->whereIn('id', $ids)
                    ->update(['is_active' => false, 'updated_at' => now()]);
            });

        return $deactivated;
    }
}
