<?php

declare(strict_types=1);

namespace App\Modules\Administration\Infrastructure\Http\Controllers;

use App\Modules\Administration\Domain\Models\Store;
use Illuminate\Http\JsonResponse;

final class StoreController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(
            Store::query()
                ->orderBy('id')
                ->get(['id', 'tenant_id', 'name', 'address', 'is_active'])
                ->values(),
        );
    }
}
