<?php

use Illuminate\Support\Facades\Route;
use Symfony\Component\Finder\Finder;

// header('Access-Control-Allow-Origin: *');
// header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTION');
// header('Access-Control-Allow-Headers: Origin, Content-Type, Accept, Authorization, X-Auth-Token');

/*
|--------------------------------------------------------------------------
| 1. Cargar Rutas Públicas (public_api.php)
|--------------------------------------------------------------------------
| Busca en app/ cualquier archivo 'public_api.php' que esté dentro
| de una carpeta llamada 'Routes', sin importar la profundidad.
*/
$publicFiles = Finder::create()
    ->in(app_path())       // Busca dentro de la carpeta app
    ->name('public_api.php') // Solo archivos con este nombre
    ->path('Routes');      // Que estén dentro de una carpeta Routes

foreach ($publicFiles as $file) {
    require $file->getRealPath();
}

/*
|--------------------------------------------------------------------------
| 2. Cargar Rutas Protegidas (api.php)
|--------------------------------------------------------------------------
| Lo mismo, pero para 'api.php' y envuelto en el middleware auth:sanctum
*/
Route::group(['middleware' => 'auth:sanctum'], function () {

    $protectedFiles = Finder::create()
        ->in(app_path())
        ->name('api.php')
        ->path('Routes');

    foreach ($protectedFiles as $file) {
        require $file->getRealPath();
    }
});
