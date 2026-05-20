<?php

use App\Shared\Image\Controllers\ImageController;
use Illuminate\Support\Facades\Route;

Route::controller(ImageController::class)->group(function (): void {
    Route::post('/images', 'create')->middleware('permission:image.create');
    Route::delete('/images/{image}', 'delete')->middleware('permission:image.delete');
    Route::get('/images', 'getAll')->middleware('permission:image.getAll');
    Route::get('/images/{image}', 'get')->middleware('permission:image.get');
});
