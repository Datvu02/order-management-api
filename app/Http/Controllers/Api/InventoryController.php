<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\UpdateInventoryRequest;
use App\Http\Requests\Inventory\UpsertInventoryRequest;
use App\Http\Resources\InventoryResource;
use App\Models\Inventory;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $inventoryService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $inventories = Inventory::query()
            ->with(['warehouse', 'product'])
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->integer('warehouse_id')))
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->integer('product_id')))
            ->latest()
            ->paginate(20);

        return InventoryResource::collection($inventories);
    }

    public function store(UpsertInventoryRequest $request): JsonResponse
    {
        $inventory = $this->inventoryService->adjust(
            $request->integer('warehouse_id'),
            $request->integer('product_id'),
            $request->integer('quantity')
        )->load(['warehouse', 'product']);

        return (new InventoryResource($inventory))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Inventory $inventory): InventoryResource
    {
        return new InventoryResource($inventory->load(['warehouse', 'product']));
    }

    public function update(UpdateInventoryRequest $request, Inventory $inventory): InventoryResource
    {
        $inventory->update([
            'quantity' => $request->integer('quantity'),
        ]);

        return new InventoryResource($inventory->load(['warehouse', 'product']));
    }
}
