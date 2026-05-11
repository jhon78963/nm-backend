<?php

declare(strict_types=1);

use App\Modules\Administration\Infrastructure\Http\Controllers\StoreController;
use App\Modules\Identity\Infrastructure\Http\Controllers\AuthController;
use App\Modules\Shared\Domain\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/stores', StoreController::class);

    Route::get('/tenant/current-context-id', function (): array {
        return [
            'tenant_id' => TenantContext::id(),
        ];
    });
});
