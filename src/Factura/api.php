<?php

use Illuminate\Support\Facades\Route;
use Src\Factura\Application\Controllers\FacturaController;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('facturas', FacturaController::class)->names([
        'index' => 'api.facturas.index',
        'store' => 'api.facturas.store',
        'show' => 'api.facturas.show',
        'update' => 'api.facturas.update',
        'destroy' => 'api.facturas.destroy',
    ]);
});
