<?php

use App\Shared\Foundation\Controllers\PrivateFileController;
use Illuminate\Support\Facades\Route;

Route::get('/files/{path}', [PrivateFileController::class, 'show'])
    ->where('path', '.*');
