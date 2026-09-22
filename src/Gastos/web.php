<?php

use Illuminate\Support\Facades\Route;
use Src\Gastos\Application\Controllers\ContratoProveedorWebController;
use Src\Gastos\Application\Controllers\CuentaPorPagarWebController;
use Src\Gastos\Application\Controllers\DesembolsoWebController;
use Src\Gastos\Application\Controllers\GastoWebController;
use Src\Gastos\Application\Controllers\ProveedorWebController;

Route::middleware('auth')->group(function (): void {
    Route::get('proveedores', [ProveedorWebController::class, 'index'])->name('proveedores.index');
    Route::get('proveedores/create', [ProveedorWebController::class, 'create'])->name('proveedores.create');
    Route::post('edificios/{edificio}/proveedores', [ProveedorWebController::class, 'store'])->whereUuid('edificio')->name('proveedores.store');
    Route::get('edificios/{edificio}/proveedores/{proveedor}', [ProveedorWebController::class, 'show'])->whereUuid('edificio')->whereUuid('proveedor')->name('proveedores.show');
    Route::get('edificios/{edificio}/proveedores/{proveedor}/edit', [ProveedorWebController::class, 'edit'])->whereUuid('edificio')->whereUuid('proveedor')->name('proveedores.edit');
    Route::put('edificios/{edificio}/proveedores/{proveedor}', [ProveedorWebController::class, 'update'])->whereUuid('edificio')->whereUuid('proveedor')->name('proveedores.update');
    Route::patch('edificios/{edificio}/proveedores/{proveedor}/estado', [ProveedorWebController::class, 'changeStatus'])->whereUuid('edificio')->whereUuid('proveedor')->name('proveedores.estado');

    Route::get('contratos-proveedor', [ContratoProveedorWebController::class, 'index'])->name('contratos-proveedor.index');
    Route::get('contratos-proveedor/create', [ContratoProveedorWebController::class, 'create'])->name('contratos-proveedor.create');
    Route::post('edificios/{edificio}/contratos-proveedor', [ContratoProveedorWebController::class, 'store'])->whereUuid('edificio')->name('contratos-proveedor.store');
    Route::get('edificios/{edificio}/contratos-proveedor/{contrato}', [ContratoProveedorWebController::class, 'show'])->whereUuid('edificio')->whereUuid('contrato')->name('contratos-proveedor.show');
    Route::get('edificios/{edificio}/contratos-proveedor/{contrato}/edit', [ContratoProveedorWebController::class, 'edit'])->whereUuid('edificio')->whereUuid('contrato')->name('contratos-proveedor.edit');
    Route::put('edificios/{edificio}/contratos-proveedor/{contrato}', [ContratoProveedorWebController::class, 'update'])->whereUuid('edificio')->whereUuid('contrato')->name('contratos-proveedor.update');
    Route::patch('edificios/{edificio}/contratos-proveedor/{contrato}/registrar', [ContratoProveedorWebController::class, 'register'])->whereUuid('edificio')->whereUuid('contrato')->name('contratos-proveedor.register');
    Route::patch('edificios/{edificio}/contratos-proveedor/{contrato}/anular', [ContratoProveedorWebController::class, 'cancel'])->whereUuid('edificio')->whereUuid('contrato')->name('contratos-proveedor.cancel');

    Route::get('gastos', [GastoWebController::class, 'index'])->name('gastos.index');
    Route::get('gastos/create', [GastoWebController::class, 'create'])->name('gastos.create');
    Route::post('edificios/{edificio}/gastos', [GastoWebController::class, 'store'])->whereUuid('edificio')->name('gastos.store');
    Route::get('edificios/{edificio}/gastos/{gasto}', [GastoWebController::class, 'show'])->whereUuid('edificio')->whereUuid('gasto')->name('gastos.show');
    Route::get('edificios/{edificio}/gastos/{gasto}/edit', [GastoWebController::class, 'edit'])->whereUuid('edificio')->whereUuid('gasto')->name('gastos.edit');
    Route::put('edificios/{edificio}/gastos/{gasto}', [GastoWebController::class, 'update'])->whereUuid('edificio')->whereUuid('gasto')->name('gastos.update');
    Route::patch('edificios/{edificio}/gastos/{gasto}/registrar', [GastoWebController::class, 'register'])->whereUuid('edificio')->whereUuid('gasto')->name('gastos.register');
    Route::patch('edificios/{edificio}/gastos/{gasto}/anular', [GastoWebController::class, 'cancel'])->whereUuid('edificio')->whereUuid('gasto')->name('gastos.cancel');

    Route::get('cuentas-por-pagar', [CuentaPorPagarWebController::class, 'index'])->name('cuentas-por-pagar.index');
    Route::get('edificios/{edificio}/cuentas-por-pagar/{cuenta}', [CuentaPorPagarWebController::class, 'show'])->whereUuid('edificio')->whereUuid('cuenta')->name('cuentas-por-pagar.show');

    Route::get('desembolsos', [DesembolsoWebController::class, 'index'])->name('desembolsos.index');
    Route::get('desembolsos/create', [DesembolsoWebController::class, 'create'])->name('desembolsos.create');
    Route::post('edificios/{edificio}/desembolsos', [DesembolsoWebController::class, 'store'])->whereUuid('edificio')->name('desembolsos.store');
    Route::get('edificios/{edificio}/desembolsos/{desembolso}', [DesembolsoWebController::class, 'show'])->whereUuid('edificio')->whereUuid('desembolso')->name('desembolsos.show');
    Route::patch('edificios/{edificio}/desembolsos/{desembolso}/anular', [DesembolsoWebController::class, 'cancel'])->whereUuid('edificio')->whereUuid('desembolso')->name('desembolsos.cancel');
});
