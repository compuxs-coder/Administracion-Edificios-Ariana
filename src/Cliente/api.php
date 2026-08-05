<?php

use Illuminate\Support\Facades\Route;
use Src\Cliente\Application\Controllers\ClienteController;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('clientes', ClienteController::class)->names([
        'index' => 'api.clientes.index',
        'store' => 'api.clientes.store',
        'show' => 'api.clientes.show',
        'update' => 'api.clientes.update',
        'destroy' => 'api.clientes.destroy',
    ]);
});
