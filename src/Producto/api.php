<?php

use Illuminate\Support\Facades\Route;
use Src\Producto\Application\Controllers\ProductoController;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('productos', ProductoController::class)->names([
        'index' => 'api.productos.index',
        'store' => 'api.productos.store',
        'show' => 'api.productos.show',
        'update' => 'api.productos.update',
        'destroy' => 'api.productos.destroy',
    ]);
});
