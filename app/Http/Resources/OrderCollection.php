<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class OrderCollection extends ResourceCollection
{
    public $collects = OrderResource::class;

    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return [
            'meta' => [
                'current_page' => $paginated['current_page'],
                'from' => $paginated['from'],
                'last_page' => $paginated['last_page'],
                'per_page' => $paginated['per_page'],
                'to' => $paginated['to'],
                'total' => $paginated['total'],
            ],
        ];
    }
}
