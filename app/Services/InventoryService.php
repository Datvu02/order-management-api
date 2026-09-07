<?php

namespace App\Services;

use App\Models\Inventory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class InventoryService
{
    /**
     * Kiểm tra tồn kho rồi trừ. Phải gọi trong DB transaction.
     * Khóa các dòng tồn kho theo product_id tăng dần để tránh deadlock khi nhiều request.
     *
     * @param  list<array{product_id:int, quantity:int}>  $items
     */
    public function deductForOrder(int $warehouseId, array $items): void
    {
        $rows = $this->normalizeItems($items);
        $inventories = $this->lockRows($warehouseId, $rows->pluck('product_id')->all());

        foreach ($rows as $item) {
            $inventory = $inventories->get($item['product_id']);

            if (! $inventory) {
                throw new UnprocessableEntityHttpException(
                    "Inventory not found for product #{$item['product_id']} in warehouse #{$warehouseId}."
                );
            }

            if ($inventory->quantity < $item['quantity']) {
                throw new UnprocessableEntityHttpException(
                    "Insufficient stock for product #{$item['product_id']} in warehouse #{$warehouseId}."
                );
            }
        }

        foreach ($rows as $item) {
            $affected = Inventory::query()
                ->where('id', $inventories->get($item['product_id'])->id)
                ->where('quantity', '>=', $item['quantity'])
                ->update([
                    'quantity' => DB::raw('quantity - '.(int) $item['quantity']),
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new UnprocessableEntityHttpException(
                    "Insufficient stock for product #{$item['product_id']} in warehouse #{$warehouseId}."
                );
            }
        }
    }

    /**
     * @param  list<array{product_id:int, quantity:int}>  $items
     */
    public function restoreForOrder(int $warehouseId, array $items): void
    {
        $rows = $this->normalizeItems($items);
        $inventories = $this->lockRows($warehouseId, $rows->pluck('product_id')->all());

        foreach ($rows as $item) {
            $inventory = $inventories->get($item['product_id']);

            if (! $inventory) {
                throw new UnprocessableEntityHttpException(
                    "Inventory not found for product #{$item['product_id']} in warehouse #{$warehouseId}."
                );
            }

            $inventory->increment('quantity', $item['quantity']);
        }
    }

    public function adjust(int $warehouseId, int $productId, int $quantity): Inventory
    {
        return Inventory::query()->updateOrCreate(
            [
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
            ],
            [
                'quantity' => $quantity,
            ]
        );
    }

    /**
     * @param  list<int>  $productIds
     * @return Collection<int, Inventory>
     */
    private function lockRows(int $warehouseId, array $productIds): Collection
    {
        $ids = collect($productIds)->unique()->sort()->values()->all();

        return Inventory::query()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $ids)
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('product_id');
    }

    /**
     * @param  list<array{product_id:int, quantity:int}>  $items
     * @return Collection<int, array{product_id:int, quantity:int}>
     */
    private function normalizeItems(array $items): Collection
    {
        return collect($items)
            ->groupBy('product_id')
            ->map(fn ($rows) => [
                'product_id' => (int) $rows->first()['product_id'],
                'quantity' => (int) $rows->sum('quantity'),
            ])
            ->sortBy('product_id')
            ->values();
    }
}
