<?php

use Illuminate\Support\Facades\Route;
use Src\Categoria\Application\Controllers\CategoriaController;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('categorias', CategoriaController::class)->names([
        'index' => 'api.categorias.index',
        'store' => 'api.categorias.store',
        'show' => 'api.categorias.show',
        'update' => 'api.categorias.update',
        'destroy' => 'api.categorias.destroy',
    ]);
});
